<?php

declare(strict_types=1);

/**
 * Cause show_target: hide goal numbers on public surfaces; members still see them.
 *
 *   php app/Modules/Contributions/Services/tests/cause_show_target_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public function table(string $t): \Fake\QB { return new \Fake\QB($this, $t); }
        public function query(string $sql, $binds = null): \Fake\RS
        {
            return new \Fake\RS([['total' => 0]]);
        }
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
        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}
        public function select($f) { return $this; }
        public function where($k, $v = null, $escape = true) { $this->conds[] = [$k, $v]; return $this; }
        public function orderBy($k, $d = 'ASC') { return $this; }
        public function limit($l, $o = 0) { return $this; }
        public function get(): RS
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], function ($r) {
                foreach ($this->conds as [$k, $v]) {
                    if ((string) ($r[$k] ?? '') !== (string) $v) { return false; }
                }
                return true;
            }));
            return new RS($rows);
        }
        public function insert(array $row): bool { $this->db->rows[$this->t][] = $row; return true; }
        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                $ok = true;
                foreach ($this->conds as [$k, $v]) {
                    if ((string) ($r[$k] ?? '') !== (string) $v) { $ok = false; }
                }
                if ($ok) { $this->db->rows[$this->t][$i] = array_merge($r, $set); }
            }
            return true;
        }
        public function countAllResults($reset = true): int { return count($this->get()->getResultArray()); }
        public function like($k, $v) { return $this; }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\CauseService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Contributions/Services/CauseService.php';

    $pass = 0; $fail = 0;
    $chk = static function (string $l, bool $ok, string $d = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $l . ($ok ? '' : ' — ' . $d) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-09-25 12:00:00', new DateTimeZone('UTC')));
    $db = new BaseConnection();
    $db->rows['causes'] = [];
    $db->rows['contributions'] = [];
    $svc = new CauseService($db, new Clock());

    $c = $svc->create('org-1', [
        'name' => 'Building', 'currency' => 'GHS',
        'target_minor' => 500000, 'target_count' => 40, 'show_target' => 0,
    ]);
    $chk('create with hide', $c->ok);
    $id = (string) ($c->data['cause_id'] ?? '');
    $row = $svc->find($id);
    $chk('persisted show_target 0', (int) ($row['show_target'] ?? -1) === 0);

    $pub = $svc->progress('org-1', $id, false);
    $chk('public progress hides target', $pub->ok && array_key_exists('target_minor', $pub->data) && $pub->data['target_minor'] === null, json_encode($pub->data));
    $chk('public progress hides percent', array_key_exists('percent', $pub->data) && $pub->data['percent'] === null);
    $chk('public progress hides count target', array_key_exists('target_count', $pub->data) && $pub->data['target_count'] === null);
    $chk('raised still present', array_key_exists('raised_minor', $pub->data));

    $mem = $svc->progress('org-1', $id, true);
    $chk('member progress shows target', (int) ($mem->data['target_minor'] ?? 0) === 500000);
    $chk('member progress shows count', (int) ($mem->data['target_count'] ?? 0) === 40);

    $svc->update('org-1', $id, ['show_target' => 1]);
    $pub2 = $svc->progress('org-1', $id, false);
    $chk('unhide restores public target', (int) ($pub2->data['target_minor'] ?? 0) === 500000);

    $stripped = CauseService::hideGoalsForPublic(['target_minor' => 9, 'target_count' => 2, 'percent' => 10, 'show_target' => 0]);
    $chk('hideGoalsForPublic', $stripped['target_minor'] === null && $stripped['percent'] === null);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
