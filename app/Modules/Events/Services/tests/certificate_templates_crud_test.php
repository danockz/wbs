<?php

declare(strict_types=1);

/**
 * Certificate templates: per-group resolution (group → ancestors → org),
 * version-on-edit, retire, HTML preview.
 *
 *   php app/Modules/Events/Services/tests/certificate_templates_crud_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
        public function affectedRows(): int { return $this->affected; }
    }
}
namespace Fake {
    class RS {
        public function __construct(private array $rows) {}
        public function getRowArray() { return $this->rows[0] ?? null; }
        public function getResultArray() { return $this->rows; }
    }
    class QB {
        private array $conds = [];
        private ?string $orderKey = null;
        private string $orderDir = 'ASC';
        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}
        public function select($f) { return $this; }
        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (preg_match('/^(\S+)\s*(>=|<=|!=|>|<)$/', $k, $m) === 1) {
                $this->conds[] = ['k' => $m[1], 'op' => $m[2], 'v' => $v];
            } else {
                $this->conds[] = ['k' => $k, 'op' => '=', 'v' => $v];
            }
            return $this;
        }
        public function orderBy($k, $dir = 'ASC') { $this->orderKey = trim((string) $k); $this->orderDir = strtoupper((string) $dir); return $this; }
        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                $k = $this->orderKey; $d = $this->orderDir;
                usort($rows, static fn ($a, $b) => $d === 'DESC' ? (($b[$k] ?? '') <=> ($a[$k] ?? '')) : (($a[$k] ?? '') <=> ($b[$k] ?? '')));
            }
            return new RS($rows);
        }
        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }
        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) { $this->db->rows[$this->t][$i] = array_merge($r, $set); }
            }
            return true;
        }
        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    '>' => (string) $rv > (string) $c['v'],
                    default => (string) $rv === (string) $c['v'],
                };
                if (! $ok) { return false; }
            }
            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\CertificateService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/ImageDataUri.php';
    require_once $root . '/app/Modules/Events/Services/CertificateRenderer.php';
    require_once $root . '/app/Modules/Events/Services/CertificateService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $l, bool $ok, string $d = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $l . ($ok ? '' : ' — ' . $d) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC')));

    $db = new BaseConnection();
    $db->rows['certificate_templates'] = [];
    $db->rows['event_certificates'] = [];
    $db->rows['event_attendance'] = [
        ['event_id' => 'ev-1', 'user_id' => 'u-1', 'status' => 'present'],
    ];
    $db->rows['events'] = [
        ['id' => 'ev-1', 'organization_id' => 'org-1', 'group_id' => 'g-cell', 'type' => 'gathering', 'title' => 'Cell night'],
    ];
    $db->rows['group_closure'] = [
        ['ancestor_id' => 'g-cell', 'descendant_id' => 'g-cell', 'distance' => 0],
        ['ancestor_id' => 'g-local', 'descendant_id' => 'g-cell', 'distance' => 1],
        ['ancestor_id' => 'g-nat', 'descendant_id' => 'g-cell', 'distance' => 2],
    ];

    $ref = new ReflectionClass(CertificateService::class);
    $svc = $ref->newInstanceWithoutConstructor();
    foreach (['db' => $db, 'clock' => new Clock()] as $prop => $val) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($svc, $val);
    }

    echo "create + revise + retire\n";
    $c1 = $svc->createTemplate('org-1', [
        'name' => 'Local gathering', 'body_template' => '<p>{{member.preferred_name}}</p><p>{{event.title}}</p>',
        'event_type' => 'gathering', 'group_id' => 'g-local',
    ]);
    $chk('create ok', $c1->ok);
    $id1 = (string) ($c1->data['template_id'] ?? '');
    $rev = $svc->reviseTemplate('org-1', $id1, ['body_template' => '<h1>{{member.preferred_name}}</h1>', 'name' => 'Local gathering']);
    $chk('revise ok new version', $rev->ok && (int) ($rev->data['version'] ?? 0) === 2);
    $id2 = (string) ($rev->data['template_id'] ?? '');
    $chk('prior version still present', $svc->findTemplate('org-1', $id1) !== null);
    $chk('prior version still active', ($svc->findTemplate('org-1', $id1)['status'] ?? '') === 'active');
    $ret = $svc->retireTemplate('org-1', $id1);
    $chk('retire prior version', $ret->ok && ($svc->findTemplate('org-1', $id1)['status'] ?? '') === 'retired');
    $chk('latest still active', ($svc->findTemplate('org-1', $id2)['status'] ?? '') === 'active');
    $chk('revise retired 409', $svc->reviseTemplate('org-1', $id1, ['body_template' => 'x', 'name' => 'n'])->status === 409
        || ! $svc->reviseTemplate('org-1', $id1, ['body_template' => 'x', 'name' => 'n'])->ok);

    echo "preview\n";
    $p = $svc->previewHtml('org-1', $id2);
    $chk('preview ok', $p->ok);
    $html = (string) ($p->data['html'] ?? '');
    $chk('preview substitutes sample name', str_contains($html, 'Ada Mensah'));
    $chk('preview is not an issued cert', ! str_contains($html, 'verification_id'));

    echo "resolution group → ancestor → org\n";
    $orgDefault = $svc->createTemplate('org-1', [
        'name' => 'Org default', 'body_template' => '<p>ORG {{event.title}}</p>',
    ]);
    $natType = $svc->createTemplate('org-1', [
        'name' => 'Nat gathering', 'body_template' => '<p>NAT</p>',
        'event_type' => 'gathering', 'group_id' => 'g-nat',
    ]);
    $chk('org + nat created', $orgDefault->ok && $natType->ok);
    $resolve = (new ReflectionMethod(CertificateService::class, 'resolveTemplate'));
    $resolve->setAccessible(true);
    $hit = $resolve->invoke($svc, 'org-1', null, 'gathering', 'g-cell');
    $chk('cell gathering resolves to local v2', is_array($hit) && (string) ($hit['id'] ?? '') === $id2, (string) ($hit['id'] ?? ''));
    $chk('resolved version 2', is_array($hit) && (int) ($hit['version'] ?? 0) === 2);

    $db->rows['certificate_templates'] = array_values(array_filter(
        $db->rows['certificate_templates'],
        static fn ($r) => ($r['group_id'] ?? null) !== 'g-local',
    ));
    $hit2 = $resolve->invoke($svc, 'org-1', null, 'gathering', 'g-cell');
    $chk('falls back to national gathering', is_array($hit2) && (string) ($hit2['id'] ?? '') === (string) ($natType->data['template_id'] ?? ''));
    $hit3 = $resolve->invoke($svc, 'org-1', null, 'conference', 'g-cell');
    $chk('no type match → org default', is_array($hit3) && (string) ($hit3['id'] ?? '') === (string) ($orgDefault->data['template_id'] ?? ''));

    echo "source + i18n + routes\n";
    $ctrl = file_get_contents($root . '/app/Modules/Events/Controllers/CertificateController.php');
    $chk('edit/revise/retire/preview actions', str_contains($ctrl, 'function editTemplate') && str_contains($ctrl, 'function previewTemplate') && str_contains($ctrl, 'function retireTemplate'));
    $view = file_get_contents($root . '/app/Modules/Events/Views/certificate_templates.php');
    $chk('list has preview+edit+retire', str_contains($view, '/preview') && str_contains($view, '/edit') && str_contains($view, '/retire'));
    $routes = file_get_contents($root . '/app/Config/Routes.php');
    $chk('preview route', str_contains($routes, 'previewTemplate'));
    $en = require $root . '/app/Modules/Events/Language/en/Events.php';
    $chk('en has preview key', isset($en['certificate']['preview']));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
