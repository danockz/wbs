<?php

declare(strict_types=1);

/**
 * FraudReviewAgingService::sweepOpenReviews() — G2 held/fraud-review aging.
 *
 * An open fraud review (holding points) used to age forever. This proves the
 * uniform remind → escalate → timeout lifecycle:
 *   - a review younger than the remind SLA is untouched;
 *   - past the remind SLA, approver-role holders are reminded once and the
 *     watermark/counter advance; a second pass inside the interval does NOT
 *     re-remind (idempotent), but a pass after the interval does;
 *   - past the escalate SLA, the review escalates exactly once (watermark);
 *   - with timeout_days=0 a very old review is NEVER auto-rejected; with
 *     timeout_days>0 it auto-rejects via PointsEngine::rejectAward (points never
 *     go spendable) and leaves the open queue;
 *   - a resolved (non-open) review is never scanned;
 *   - notifications go to the role holders (via role_assignments → roles) and are
 *     best-effort (no holders / no service = no crash);
 *   - per-org thresholds + org scoping.
 *
 *   php app/Modules/Gamification/Services/tests/held_review_aging_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
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
        private array $conds = [];
        private ?array $in = null;
        private ?string $orderCol = null;
        private ?int $limit = null;
        /** @var array{table:string,on:string}|null */
        private ?array $join = null;
        private string $alias = '';

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
            // Support "table alias" form.
            $parts = preg_split('/\s+/', trim($t));
            $this->t = $parts[0];
            $this->alias = $parts[1] ?? '';
        }

        public function select($s, $escape = true)
        {
            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            [$key, $op] = $this->splitOp((string) $k);
            $this->conds[] = ['k' => $key, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in = ['k' => trim((string) $k), 'vals' => array_map('strval', $vals)];

            return $this;
        }

        public function join($table, $on, $type = 'inner')
        {
            $parts = preg_split('/\s+/', trim((string) $table));
            $this->join = ['table' => $parts[0], 'alias' => $parts[1] ?? '', 'on' => (string) $on];

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderCol = (string) $col;

            return $this;
        }

        public function limit($n, $offset = 0)
        {
            $this->limit = (int) $n;

            return $this;
        }

        public function get($limit = null): RS
        {
            $rows = $this->matchingRows();
            if ($this->orderCol !== null) {
                $col = $this->stripAlias($this->orderCol);
                usort($rows, fn ($a, $b) => strcmp((string) ($a[$col] ?? ''), (string) ($b[$col] ?? '')));
            }
            $lim = $limit ?? $this->limit;
            if ($lim !== null) {
                $rows = array_slice($rows, 0, (int) $lim);
            }

            return new RS($rows);
        }

        public function countAllResults(): int
        {
            return count($this->matchingRows());
        }

        public function update(array $set): bool
        {
            foreach (($this->db->rows[$this->t] ?? []) as $i => $r) {
                if ($this->matches($r)) {
                    $this->db->rows[$this->t][$i] = array_merge($r, $set);
                }
            }

            return true;
        }

        private function splitOp(string $k): array
        {
            $k = trim($k);
            foreach (['>=', '<=', '!=', '>', '<'] as $op) {
                if (str_ends_with($k, ' ' . $op)) {
                    return [trim(substr($k, 0, -strlen($op))), $op];
                }
            }

            return [$k, '='];
        }

        private function stripAlias(string $k): string
        {
            $pos = strpos($k, '.');

            return $pos === false ? $k : substr($k, $pos + 1);
        }

        private function matchingRows(): array
        {
            $base = array_values($this->db->rows[$this->t] ?? []);

            // Simple inner join for role_assignments ra JOIN roles r ON r.id = ra.role_id
            if ($this->join !== null) {
                $joinRows = $this->db->rows[$this->join['table']] ?? [];
                $merged = [];
                foreach ($base as $b) {
                    foreach ($joinRows as $j) {
                        // join on role_id == id (the only join used here)
                        if (($b['role_id'] ?? null) === ($j['id'] ?? '~')) {
                            // prefix nothing; expose both id spaces via known cols
                            $merged[] = $b + ['code' => $j['code'] ?? null, 'r_id' => $j['id'] ?? null];
                        }
                    }
                }
                $base = $merged;
            }

            return array_values(array_filter($base, fn ($r) => $this->matches($r)));
        }

        private function matches(array $r): bool
        {
            foreach ($this->conds as $c) {
                $key = $this->stripAlias($c['k']);
                $left  = (string) ($r[$key] ?? '');
                $right = (string) $c['v'];
                $ok = match ($c['op']) {
                    '='     => $left === $right,
                    '!='    => $left !== $right,
                    '>='    => $left >= $right,
                    '<='    => $left <= $right,
                    '>'     => $left > $right,
                    '<'     => $left < $right,
                    default => false,
                };
                if (! $ok) {
                    return false;
                }
            }
            if ($this->in !== null && ! in_array((string) ($r[$this->stripAlias($this->in['k'])] ?? ''), $this->in['vals'], true)) {
                return false;
            }

            return true;
        }
    }
}

namespace WBS\Notifications\Services {
    // Recording fake.
    class NotificationService
    {
        /** @var list<array{org:string,user:string,category:string,dedupe:string}> */
        public array $sent = [];

        public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = [])
        {
            $this->sent[] = [
                'org'      => $organizationId,
                'user'     => $userId,
                'category' => $category,
                'dedupe'   => (string) ($opts['dedupe_key'] ?? ''),
            ];

            return \WBS\Shared\Support\Result::ok(['sent' => true]);
        }
    }
}

namespace WBS\Gamification\Services {
    require_once dirname(__DIR__, 5) . '/app/Modules/Gamification/Services/ReviewConfigPort.php';
    require_once dirname(__DIR__, 5) . '/app/Modules/Gamification/Services/ReviewRejectionPort.php';

    // Config fake (ReviewConfigPort): per-org key/value.
    class ConfigStub implements ReviewConfigPort
    {
        /** @var array<string,array<string,mixed>> */
        public array $values = [];

        public function get(string $organizationId, string $key, mixed $default = null): mixed
        {
            return $this->values[$organizationId][$key] ?? $default;
        }
    }

    // Rejection fake (ReviewRejectionPort) recording rejectAward calls.
    class PointsStub implements ReviewRejectionPort
    {
        /** @var list<array{ledger:string,actor:string,reason:string}> */
        public array $rejected = [];

        public function rejectAward(string $ledgerId, string $approverId, string $reason): \WBS\Shared\Support\Result
        {
            $this->rejected[] = ['ledger' => $ledgerId, 'actor' => $approverId, 'reason' => $reason];

            return \WBS\Shared\Support\Result::ok(['ledger_id' => $ledgerId, 'state' => 'reversed']);
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Gamification\Services\ConfigStub;
    use WBS\Gamification\Services\FraudReviewAgingService;
    use WBS\Gamification\Services\PointsStub;
    use WBS\Notifications\Services\NotificationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Gamification/Services/ReviewConfigPort.php';
    require_once $root . '/app/Modules/Gamification/Services/ReviewRejectionPort.php';
    require_once $root . '/app/Modules/Gamification/Services/FraudReviewAgingService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG  = 'org-1';
    $NOW  = '2026-09-16 12:00:00.000000';
    Clock::freeze(new \DateTimeImmutable($NOW, new \DateTimeZone('UTC')));

    // helper to make an "N hours ago" created_at
    $ago = static function (int $hours): string {
        return (new \DateTimeImmutable('2026-09-16 12:00:00', new \DateTimeZone('UTC')))
            ->modify("-{$hours} hours")->format('Y-m-d H:i:s');
    };

    $seed = static function () use ($ORG, $ago): BaseConnection {
        $db = new BaseConnection();
        $db->rows['fraud_reviews'] = [
            // fresh (2h) -> untouched
            ['id' => 'fr-fresh', 'organization_id' => $ORG, 'status' => 'open', 'assigned_role' => 'approver', 'ledger_id' => 'l-fresh', 'reason' => 'r', 'created_at' => $ago(2), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
            // 30h old -> remind (past 24h, under 72h)
            ['id' => 'fr-remind', 'organization_id' => $ORG, 'status' => 'open', 'assigned_role' => 'approver', 'ledger_id' => 'l-remind', 'reason' => 'r', 'created_at' => $ago(30), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
            // 80h old -> escalate (past 72h), not yet escalated
            ['id' => 'fr-escalate', 'organization_id' => $ORG, 'status' => 'open', 'assigned_role' => 'approver', 'ledger_id' => 'l-esc', 'reason' => 'r', 'created_at' => $ago(80), 'reminded_at' => $ago(30), 'reminder_count' => 1, 'escalated_at' => null],
            // already resolved -> never scanned
            ['id' => 'fr-done', 'organization_id' => $ORG, 'status' => 'cleared', 'assigned_role' => 'approver', 'ledger_id' => 'l-done', 'reason' => 'r', 'created_at' => $ago(200), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null],
        ];
        // role holders: 2 approvers
        $db->rows['roles'] = [
            ['id' => 'role-appr', 'organization_id' => $ORG, 'code' => 'approver'],
        ];
        $db->rows['role_assignments'] = [
            ['id' => 'ra1', 'organization_id' => $ORG, 'subject_id' => 'u-appr-1', 'role_id' => 'role-appr'],
            ['id' => 'ra2', 'organization_id' => $ORG, 'subject_id' => 'u-appr-2', 'role_id' => 'role-appr'],
        ];

        return $db;
    };

    $byId = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['fraud_reviews'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };

    // ---- default thresholds (remind 24h, escalate 72h, timeout 0) ---------
    $db  = $seed();
    $cfg = new ConfigStub();
    $notif = new NotificationService();
    $svc = new FraudReviewAgingService($db, new Clock(), $cfg, new PointsStub(), $notif);
    $r = $svc->sweepOpenReviews($ORG, 100);

    $chk('scanned 3 open reviews (resolved excluded)', $r['scanned'] === 3, json_encode($r));
    $chk('reminded 1', $r['reminded'] === 1, json_encode($r));
    $chk('escalated 1', $r['escalated'] === 1, json_encode($r));
    $chk('timed_out 0 (auto-reject off by default)', $r['timed_out'] === 0);

    $chk('fr-fresh untouched', ($byId($db, 'fr-fresh')['reminded_at'] ?? null) === null);
    $chk('fr-remind watermarked', ! empty($byId($db, 'fr-remind')['reminded_at']));
    $chk('fr-remind counter=1', (int) $byId($db, 'fr-remind')['reminder_count'] === 1);
    $chk('fr-escalate escalated_at stamped', ! empty($byId($db, 'fr-escalate')['escalated_at']));
    $chk('fr-done never touched (resolved)', ($byId($db, 'fr-done')['reminded_at'] ?? null) === null);

    // notifications: remind -> 2 holders, escalate -> 2 holders = 4 in_app sends
    $chk('notified role holders (4 sends: 2 remind + 2 escalate)', count($notif->sent) === 4, (string) count($notif->sent));
    $reminderSends = array_filter($notif->sent, fn ($s) => $s['category'] === 'gamification_review_reminder');
    $escSends = array_filter($notif->sent, fn ($s) => $s['category'] === 'gamification_review_escalation');
    $chk('2 reminder notifications', count($reminderSends) === 2);
    $chk('2 escalation notifications', count($escSends) === 2);

    // ---- idempotent: immediate second pass does NOT re-fire same-window work -
    // fr-remind was just reminded (<24h ago) so it is not due again; fr-escalate
    // is already escalated so it never re-escalates. (An escalated-but-still-open
    // review DOES keep getting follow-up reminders once its reminder interval
    // elapses — verified in the re-remind case below — but here fr-escalate's last
    // reminder was 30h ago in the seed, so this pass gives it exactly one.)
    $notif2 = new NotificationService();
    $svc2 = new FraudReviewAgingService($db, new Clock(), $cfg, new PointsStub(), $notif2);
    $r2 = $svc2->sweepOpenReviews($ORG, 100);
    $chk('second pass escalated 0 (already escalated, watermark holds)', $r2['escalated'] === 0, json_encode($r2));
    $chk('second pass does not re-remind the just-reminded fr-remind',
        (int) $byId($db, 'fr-remind')['reminder_count'] === 1, (string) $byId($db, 'fr-remind')['reminder_count']);
    $chk('escalated-but-open fr-escalate still gets follow-up reminders',
        $r2['reminded'] === 1 && (int) $byId($db, 'fr-escalate')['reminder_count'] === 2, json_encode($r2));

    // ---- re-remind after the interval elapses ------------------------------
    // Advance the clock 25h; fr-remind should be due again (but it also crosses
    // 72h now -> escalate takes precedence). Use a fresh review to isolate remind.
    $db3 = $seed();
    // make fr-remind's last reminder 25h ago and keep it under escalate age
    foreach ($db3->rows['fraud_reviews'] as &$row) {
        if ($row['id'] === 'fr-remind') {
            $row['reminded_at']    = $ago(25);
            $row['reminder_count'] = 1;
            $row['created_at']     = $ago(40); // under 72h
        }
    }
    unset($row);
    $notif3 = new NotificationService();
    $svc3 = new FraudReviewAgingService($db3, new Clock(), $cfg, new PointsStub(), $notif3);
    $r3 = $svc3->sweepOpenReviews($ORG, 100);
    $chk('re-remind after interval: fr-remind reminded again', (int) $byId($db3, 'fr-remind')['reminder_count'] === 2, json_encode($r3));

    // ---- timeout enabled: very old review auto-rejects ---------------------
    $db4 = $seed();
    $cfg4 = new ConfigStub();
    $cfg4->values[$ORG] = ['held_review_timeout_days' => 3]; // 72h
    $points4 = new PointsStub();
    $svc4 = new FraudReviewAgingService($db4, new Clock(), $cfg4, $points4, new NotificationService());
    $r4 = $svc4->sweepOpenReviews($ORG, 100);
    // fr-escalate (80h) and none else exceed 72h except... fr-escalate only.
    $chk('timeout auto-rejected the >3d review', $r4['timed_out'] === 1, json_encode($r4));
    $chk('rejectAward called via points engine', count($points4->rejected) === 1
        && $points4->rejected[0]['ledger'] === 'l-esc'
        && $points4->rejected[0]['actor'] === 'system');

    // ---- best-effort: no notification service / no holders = no crash -----
    $db5 = $seed();
    $db5->rows['role_assignments'] = []; // no holders
    $svc5 = new FraudReviewAgingService($db5, new Clock(), new ConfigStub(), new PointsStub(), null);
    $r5 = $svc5->sweepOpenReviews($ORG, 100);
    $chk('no notifier/holders: still ages watermarks without error', $r5['reminded'] === 1 && $r5['escalated'] === 1, json_encode($r5));

    // ---- org scoping -------------------------------------------------------
    $db6 = $seed();
    $db6->rows['fraud_reviews'][] = ['id' => 'fr-other', 'organization_id' => 'org-2', 'status' => 'open', 'assigned_role' => 'approver', 'ledger_id' => 'l-o', 'reason' => 'r', 'created_at' => $ago(100), 'reminded_at' => null, 'reminder_count' => 0, 'escalated_at' => null];
    $svc6 = new FraudReviewAgingService($db6, new Clock(), new ConfigStub(), new PointsStub(), new NotificationService());
    $r6 = $svc6->sweepOpenReviews($ORG, 100);
    $chk('org filter: other-org review not scanned', $r6['scanned'] === 3, json_encode($r6));
    $chk('org filter: fr-other untouched', ($byId($db6, 'fr-other')['escalated_at'] ?? null) === null);

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
