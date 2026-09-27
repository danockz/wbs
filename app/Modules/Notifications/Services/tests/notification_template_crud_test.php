<?php

declare(strict_types=1);

/**
 * Notification template CRUD + send-time resolution
 * (group+role → group base → org+role → org base, then locale → en).
 *
 *   php app/Modules/Notifications/Services/tests/notification_template_crud_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
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
                if ((string) $rv !== (string) $c['v']) { return false; }
            }
            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Notifications\Services\NotificationTemplateService;
    use WBS\Notifications\Services\TemplateRenderer;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Notifications/Services/TemplateRenderer.php';
    require_once $root . '/app/Modules/Notifications/Services/NotificationTemplateService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $l, bool $ok, string $d = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $l . ($ok ? '' : ' — ' . $d) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-09-24 12:00:00', new DateTimeZone('UTC')));
    $db = new BaseConnection();
    $db->rows['notification_templates'] = [];
    $db->rows['organizations'] = [['id' => 'org-1', 'default_locale' => 'en']];
    $db->rows['users'] = [['id' => 'u-1', 'locale' => 'fr', 'display_name' => 'Ada']];
    $db->rows['group_members'] = [['user_id' => 'u-1', 'group_id' => 'g-1', 'status' => 'active', 'role' => 'leader']];
    $db->rows['role_assignments'] = [];
    $db->rows['roles'] = [];

    $svc = new NotificationTemplateService($db, new Clock(), new TemplateRenderer());

    echo "crud\n";
    $c = $svc->create('org-1', [
        'key_name' => 'event_reminder', 'channel' => 'email', 'locale' => 'en',
        'body' => 'Hi :name, reminder for :title', 'subject' => 'Reminder',
        'group_id' => 'g-1',
    ]);
    $chk('create ok', $c->ok);
    $id = (string) ($c->data['template_id'] ?? '');
    $rev = $svc->revise('org-1', $id, ['body' => 'Hello :name — :title starts :starts_at']);
    $chk('revise version 2', $rev->ok && (int) ($rev->data['version'] ?? 0) === 2);
    $id2 = (string) ($rev->data['template_id'] ?? '');
    $chk('old version remains', $svc->find('org-1', $id) !== null);
    $chk('retire', $svc->retire('org-1', $id)->ok && ($svc->find('org-1', $id)['status'] ?? '') === 'retired');
    $chk('missing fields 422', $svc->create('org-1', ['key_name' => '', 'body' => ''])->ok === false);
    $chk('bad locale 422', $svc->create('org-1', ['key_name' => 'event_reminder', 'body' => 'x', 'locale' => 'xx'])->ok === false);

    echo "role variant + resolution\n";
    $svc->create('org-1', [
        'key_name' => 'event_reminder', 'channel' => 'email', 'locale' => 'en',
        'body' => 'ORG BASE :name', 'group_id' => '',
    ]);
    $svc->create('org-1', [
        'key_name' => 'event_reminder', 'channel' => 'email', 'locale' => 'en',
        'body' => 'ORG LEADER :name', 'group_id' => '',
        'audience_kind' => 'membership', 'audience_role' => 'leader',
    ]);
    $svc->create('org-1', [
        'key_name' => 'event_reminder', 'channel' => 'email', 'locale' => 'fr',
        'body' => 'GROUPE FR :name', 'group_id' => 'g-1',
    ]);
    $hit = $svc->resolveForSend('org-1', 'event_reminder', 'email', [
        'group_id' => 'g-1', 'locale' => 'fr', 'membership_role' => 'leader',
    ]);
    $chk('group+locale wins over org role', is_array($hit) && str_contains((string) ($hit['body'] ?? ''), 'GROUPE FR'), (string) ($hit['body'] ?? ''));
    $hitEn = $svc->resolveForSend('org-1', 'event_reminder', 'email', [
        'group_id' => 'g-1', 'locale' => 'en', 'membership_role' => 'leader',
    ]);
    $chk('en at group uses latest active group en (v2)', is_array($hitEn) && str_contains((string) ($hitEn['body'] ?? ''), 'Hello :name'), (string) ($hitEn['body'] ?? ''));
    $hitOrg = $svc->resolveForSend('org-1', 'event_reminder', 'email', [
        'group_id' => 'g-missing', 'locale' => 'en', 'membership_role' => 'leader',
    ]);
    $chk('unknown group → org+membership role', is_array($hitOrg) && str_contains((string) ($hitOrg['body'] ?? ''), 'ORG LEADER'), (string) ($hitOrg['body'] ?? ''));
    $hitBase = $svc->resolveForSend('org-1', 'event_reminder', 'email', [
        'group_id' => 'g-missing', 'locale' => 'en',
    ]);
    $chk('no role → org base', is_array($hitBase) && str_contains((string) ($hitBase['body'] ?? ''), 'ORG BASE'));

    echo "render\n";
    $out = $svc->renderBody('Hi :name, see {{event.title}}', ['name' => 'Ada', 'event.title' => 'Night'], true);
    $chk('named + mustache', str_contains($out, 'Ada') && str_contains($out, 'Night'));

    echo "source locks\n";
    $ctrl = file_get_contents($root . '/app/Modules/Notifications/Controllers/TemplateController.php');
    $chk('controller gated comments provider.configure', str_contains($ctrl, 'provider.configure'));
    $routes = file_get_contents($root . '/app/Config/Routes.php');
    $chk('notifications/templates routed', str_contains($routes, 'TemplateController::index'));
    $menu = file_get_contents($root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php');
    $chk('menu comms.templates', str_contains($menu, 'comms.templates'));
    $send = file_get_contents($root . '/app/Modules/Notifications/Services/NotificationService.php');
    $chk('send() resolves stored templates', str_contains($send, 'resolveForSend'));
    $fan = file_get_contents($root . '/app/Modules/Notifications/Services/CampaignService.php');
    $chk('campaign fanout passes template_key', str_contains($fan, "'template_key'"));
    $en = require $root . '/app/Modules/Notifications/Language/en/Notifications.php';
    $chk('en templates.heading', isset($en['templates']['heading']));

    echo "\n== {$pass} passed, {$fail} failed ==\n";
    exit($fail === 0 ? 0 : 1);
}
