<?php

declare(strict_types=1);

/**
 * CommitmentService::cancelActiveForSubject test (Theme B consumer — C8).
 *
 * A deactivated / merged member's recurring commitments must stop reminding
 * forever. Proves over an in-memory DB fake:
 *   - only the subject's ACTIVE commitments flip to cancelled (ended_at stamped);
 *   - already-cancelled / completed rows are left alone (idempotent re-run = 0);
 *   - OTHER subjects' commitments are untouched;
 *   - it is SYSTEM authority — no owner check (unlike cancel());
 *   - empty org/subject returns 0 (no writes).
 *
 *   php app/Modules/Contributions/Services/tests/commitment_teardown_test.php
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

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function orderBy($a, $b = null)
        {
            return $this;
        }

        public function get(): RS
        {
            return new RS(array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r))));
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
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }
    }
}

// Minimal stand-ins for the constructor's collaborators (unused by the method
// under test). Declared under their exact FQCNs so the real files never load.
namespace WBS\Contributions\Services {
    if (! class_exists(CauseService::class)) {
        class CauseService
        {
        }
    }
}
namespace WBS\Notifications\Services {
    if (! class_exists(NotificationService::class)) {
        class NotificationService
        {
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Contributions\Services\CauseService;
    use WBS\Contributions\Services\CommitmentService;
    use WBS\Notifications\Services\NotificationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Contributions/Services/CommitmentService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG = 'org-1';
    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['giving_commitments'] = [
            ['id' => 'gc-1', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'active'],
            ['id' => 'gc-2', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'active'],
            ['id' => 'gc-3', 'organization_id' => $ORG, 'subject_id' => 'u1', 'status' => 'completed'],
            ['id' => 'gc-4', 'organization_id' => $ORG, 'subject_id' => 'u2', 'status' => 'active'], // bystander
        ];

        return $db;
    };
    $statusOf = static function (BaseConnection $db, string $id): array {
        foreach ($db->rows['giving_commitments'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return [];
    };

    $mk = static fn (BaseConnection $db) => new CommitmentService($db, new Clock(), new CauseService(), new NotificationService());

    // ---- teardown cancels the subject's active commitments ------------------
    $db = $seed();
    $svc = $mk($db);
    $n = $svc->cancelActiveForSubject($ORG, 'u1', 'account.deactivated');
    $chk('cancels 2 active commitments', $n === 2, (string) $n);
    $chk('gc-1 cancelled', $statusOf($db, 'gc-1')['status'] === 'cancelled');
    $chk('gc-1 ended_at stamped', ! empty($statusOf($db, 'gc-1')['ended_at']));
    $chk('gc-2 cancelled', $statusOf($db, 'gc-2')['status'] === 'cancelled');
    $chk('completed gc-3 untouched', $statusOf($db, 'gc-3')['status'] === 'completed');
    $chk('bystander gc-4 untouched', $statusOf($db, 'gc-4')['status'] === 'active');

    // ---- idempotent re-run --------------------------------------------------
    $n2 = $svc->cancelActiveForSubject($ORG, 'u1', 'account.deactivated');
    $chk('re-run cancels 0 (idempotent)', $n2 === 0, (string) $n2);

    // ---- bad input ----------------------------------------------------------
    $chk('empty org -> 0', $svc->cancelActiveForSubject('', 'u1', 'x') === 0);
    $chk('empty subject -> 0', $svc->cancelActiveForSubject($ORG, '', 'x') === 0);
    $chk('bystander still active after bad calls', $statusOf($db, 'gc-4')['status'] === 'active');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
