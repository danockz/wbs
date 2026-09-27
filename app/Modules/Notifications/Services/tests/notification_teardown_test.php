<?php

declare(strict_types=1);

/**
 * NotificationService::onAccountTornDown test (Theme B consumer — N7).
 *
 * Over an in-memory DB fake, proves:
 *   - inflight deliveries (queued / deferred) for the subject -> cancelled, with
 *     an account_teardown suppression_reason;
 *   - already-sent / delivered / failed deliveries are NOT touched;
 *   - OTHER users' inflight deliveries are untouched;
 *   - a hard do_not_contact suppression at scope `all` is inserted once;
 *   - idempotent re-run cancels 0 and inserts NO second suppression;
 *   - empty org/subject is a no-op.
 *
 *   php app/Modules/Notifications/Services/tests/notification_teardown_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows)
        {
        }

        public function getRowArray()
        {
            return $this->rows[0] ?? null;
        }

        public function getResultArray()
        {
            return $this->rows;
        }
    }

    class QB
    {
        private array $eq = [];
        private array $in = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function countAllResults(): int
        {
            return count(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;
            $this->db->affected = 1;

            return true;
        }

        public function update(array $set): bool
        {
            $n = 0;
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                    $n++;
                }
            }
            $this->db->affected = $n;

            return true;
        }

        private function matches(array $r): bool
        {
            foreach ($this->eq as $k => $v) {
                if ((string) ($r[$k] ?? '') !== (string) $v) {
                    return false;
                }
            }
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Constructor collaborators (unused by onAccountTornDown) stubbed at their FQCNs.
namespace WBS\Notifications\Services {
    if (! class_exists(RetentionPolicyGate::class)) {
        class RetentionPolicyGate
        {
        }
    }
    if (! class_exists(TemplateRenderer::class)) {
        class TemplateRenderer
        {
        }
    }
}
namespace WBS\Shared\Messaging {
    if (! class_exists(OutboxService::class)) {
        class OutboxService
        {
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Notifications\Services\NotificationService;
    use WBS\Notifications\Services\RetentionPolicyGate;
    use WBS\Notifications\Services\TemplateRenderer;
    use WBS\Shared\Messaging\OutboxService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Notifications/Services/NotificationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $db = new BaseConnection();
    $db->rows['notification_deliveries'] = [
        ['id' => 'd1', 'organization_id' => $ORG, 'user_id' => 'u1', 'status' => 'queued'],
        ['id' => 'd2', 'organization_id' => $ORG, 'user_id' => 'u1', 'status' => 'deferred'],
        ['id' => 'd3', 'organization_id' => $ORG, 'user_id' => 'u1', 'status' => 'sent'],
        ['id' => 'd4', 'organization_id' => $ORG, 'user_id' => 'u2', 'status' => 'queued'], // bystander
    ];
    $db->rows['notification_suppressions'] = [];

    $svc = new NotificationService($db, new Clock(), new RetentionPolicyGate(), new TemplateRenderer(), new OutboxService());

    $statusOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['notification_deliveries'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    $res = $svc->onAccountTornDown($ORG, 'u1', 'account.deactivated');
    $chk('cancels 2 inflight deliveries', $res['cancelled'] === 2, (string) $res['cancelled']);
    $chk('queued -> cancelled', $statusOf($db, 'd1')['status'] === 'cancelled');
    $chk('deferred -> cancelled', $statusOf($db, 'd2')['status'] === 'cancelled');
    $chk('cancel reason recorded', str_contains((string) $statusOf($db, 'd1')['suppression_reason'], 'account_teardown'));
    $chk('sent delivery untouched', $statusOf($db, 'd3')['status'] === 'sent');
    $chk('bystander inflight untouched', $statusOf($db, 'd4')['status'] === 'queued');

    $chk('suppression inserted', $res['suppressed'] === true);
    $chk('one all-scope do_not_contact suppression', count($db->rows['notification_suppressions']) === 1);
    $sup = $db->rows['notification_suppressions'][0];
    $chk('suppression scope = all', ($sup['scope'] ?? '') === 'all');
    $chk('suppression reason = do_not_contact', ($sup['reason'] ?? '') === 'do_not_contact');
    $chk('suppression targets the subject', ($sup['user_id'] ?? '') === 'u1');

    // ---- idempotent re-run --------------------------------------------------
    $res2 = $svc->onAccountTornDown($ORG, 'u1', 'account.deactivated');
    $chk('re-run cancels 0', $res2['cancelled'] === 0);
    $chk('re-run inserts NO second suppression', $res2['suppressed'] === false
        && count($db->rows['notification_suppressions']) === 1);

    // ---- guards -------------------------------------------------------------
    $chk('empty org no-op', $svc->onAccountTornDown('', 'u1', 'x') === ['cancelled' => 0, 'suppressed' => false]);
    $chk('empty subject no-op', $svc->onAccountTornDown($ORG, '', 'x') === ['cancelled' => 0, 'suppressed' => false]);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
