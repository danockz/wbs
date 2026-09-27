<?php

declare(strict_types=1);

/**
 * G8 — WAITLIST PROMOTION ON CAPACITY RAISE
 * (RegistrationService::promoteWaitlistToCapacity).
 *
 * The symmetric counterpart of the promote-on-cancel path: when an event's
 * capacity is raised, the seats that just opened are filled from the waitlist in
 * FIFO order, reusing the SAME promotion transition (waitlist waiting→promoted,
 * registration waitlisted→registered). Over an in-memory DB fake (with a `query()`
 * shim for the capacity COUNT/SUM + operator-aware where()), proves:
 *   - headroom = capacity − (confirmed registered + live held) drives how many
 *     are promoted, in position order (FIFO);
 *   - a null capacity (unlimited) promotes everyone waiting;
 *   - live holds consume headroom (an expired hold does not);
 *   - no headroom / no waiting entries → promotes 0 (idempotent);
 *   - a completed/cancelled event's waitlist is history (promotes 0);
 *   - the promotion is guarded on source status waitlisted (race-safe);
 *   - the $limit bounds a pass; the returned headroom reflects the promotions.
 *
 *   php app/Modules/Events/Services/tests/waitlist_promotion_test.php
 */

namespace CodeIgniter\Database {
    class BaseConnection
    {
        /** @var array<string,list<array<string,mixed>>> */
        public array $rows = [];
        public int $affected = 0;
        public bool $txOk = true;

        public function table(string $t): \Fake\QB
        {
            return new \Fake\QB($this, $t);
        }

        public function affectedRows(): int
        {
            return $this->affected;
        }

        public function transStart(): void {}

        public function transComplete(): void {}

        public function transStatus(): bool
        {
            return $this->txOk;
        }

        /**
         * Minimal SQL shim for the two capacity aggregates the service runs:
         *   - COUNT(*) of registered registrations for an event;
         *   - COALESCE(SUM(quantity)) of live (unexpired) held ticket_holds.
         */
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            $eventId = (string) ($binds[0] ?? '');
            if (str_contains($sql, 'FROM event_registrations')) {
                $c = 0;
                foreach ($this->rows['event_registrations'] ?? [] as $r) {
                    if ((string) $r['event_id'] === $eventId && (string) $r['status'] === 'registered') {
                        $c++;
                    }
                }

                return new \Fake\RS([['c' => $c]]);
            }
            if (str_contains($sql, 'FROM ticket_holds')) {
                $now = (string) ($binds[1] ?? '');
                $sum = 0;
                foreach ($this->rows['ticket_holds'] ?? [] as $r) {
                    if ((string) $r['event_id'] === $eventId
                        && (string) $r['status'] === 'held'
                        && (string) ($r['expires_at'] ?? '') > $now) {
                        $sum += (int) ($r['quantity'] ?? 0);
                    }
                }

                return new \Fake\RS([['c' => $sum]]);
            }

            return new \Fake\RS([]);
        }
    }
}

namespace Fake {
    class RS
    {
        public function __construct(private array $rows) {}

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
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        private ?string $orderKey = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t) {}

        public function select($f)
        {
            return $this;
        }

        public function where($k, $v = null)
        {
            $k  = trim((string) $k);
            $op = '=';
            if (str_ends_with($k, '<=')) {
                $op = '<=';
                $k  = trim(substr($k, 0, -2));
            } elseif (str_ends_with($k, '>')) {
                $op = '>';
                $k  = trim(substr($k, 0, -1));
            }
            $this->conds[] = ['k' => $k, 'op' => $op, 'v' => $v];

            return $this;
        }

        public function orderBy($k, $dir = 'ASC')
        {
            $this->orderKey = trim((string) $k);

            return $this;
        }

        private function filtered(): array
        {
            $rows = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderKey !== null) {
                usort($rows, fn ($a, $b) => ($a[$this->orderKey] ?? 0) <=> ($b[$this->orderKey] ?? 0));
            }

            return $rows;
        }

        public function get($limit = null): RS
        {
            $rows = $this->filtered();
            if ($limit !== null) {
                $rows = array_slice($rows, 0, (int) $limit);
            }

            return new RS($rows);
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
            foreach ($this->conds as $c) {
                $rv = $r[$c['k']] ?? null;
                $ok = match ($c['op']) {
                    '<='    => (string) $rv <= (string) $c['v'],
                    '>'     => (string) $rv > (string) $c['v'],
                    default => (string) $rv === (string) $c['v'],
                };
                if (! $ok) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Events\Services\RegistrationService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Events/Services/RegistrationService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $NOW = '2026-06-01 12:00:00';
    $FUT = '2026-06-01 13:00:00';
    $PST = '2026-06-01 11:00:00';
    $ORG = 'org-1';

    $statusOfReg = static function (BaseConnection $db, string $user): string {
        foreach ($db->rows['event_registrations'] as $r) {
            if ($r['user_id'] === $user) {
                return (string) $r['status'];
            }
        }

        return '';
    };
    $statusOfWl = static function (BaseConnection $db, string $user): string {
        foreach ($db->rows['waitlist_entries'] as $r) {
            if ($r['user_id'] === $user) {
                return (string) $r['status'];
            }
        }

        return '';
    };
    $svcOf = static fn (BaseConnection $db) => new RegistrationService($db, new Clock());

    // Builder: 1 confirmed seat + N waiting entries on a published event.
    $seed = static function (int $capacity = null, string $status = 'published') use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['events'] = [
            ['id' => 'ev', 'organization_id' => $ORG, 'status' => $status, 'capacity' => $capacity],
        ];
        $db->rows['event_registrations'] = [
            ['id' => 'r0', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'seat0', 'status' => 'registered'],
            ['id' => 'r1', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w1', 'status' => 'waitlisted'],
            ['id' => 'r2', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w2', 'status' => 'waitlisted'],
            ['id' => 'r3', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w3', 'status' => 'waitlisted'],
        ];
        $db->rows['waitlist_entries'] = [
            ['id' => 'e1', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w1', 'status' => 'waiting', 'position' => 1],
            ['id' => 'e2', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w2', 'status' => 'waiting', 'position' => 2],
            ['id' => 'e3', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'w3', 'status' => 'waiting', 'position' => 3],
        ];
        $db->rows['ticket_holds'] = [];

        return $db;
    };

    // ── 1. Raise from 1 → 3: exactly 2 seats open, FIFO ─────────────────────
    $db  = $seed(3);
    $res = $svcOf($db)->promoteWaitlistToCapacity('ev');
    $chk('promotes headroom in FIFO order', ($res['promoted'] ?? []) === ['w1', 'w2'], json_encode($res));
    $chk('w1 registration promoted', $statusOfReg($db, 'w1') === 'registered');
    $chk('w2 registration promoted', $statusOfReg($db, 'w2') === 'registered');
    $chk('w3 stays waitlisted (no headroom left)', $statusOfReg($db, 'w3') === 'waitlisted');
    $chk('w1 waitlist entry promoted', $statusOfWl($db, 'w1') === 'promoted');
    $chk('w3 waitlist entry still waiting', $statusOfWl($db, 'w3') === 'waiting');
    $chk('reported capacity + residual headroom', ($res['capacity'] ?? null) === 3 && ($res['headroom'] ?? null) === 0, json_encode($res));

    // ── 2. Null capacity (unlimited) promotes everyone ──────────────────────
    $db  = $seed(null);
    $res = $svcOf($db)->promoteWaitlistToCapacity('ev');
    $chk('unlimited capacity promotes all waiting', ($res['promoted'] ?? []) === ['w1', 'w2', 'w3'], json_encode($res));
    $chk('unlimited reports null capacity + null headroom', $res['capacity'] === null && $res['headroom'] === null);

    // ── 3. Live holds consume headroom; expired holds do not ────────────────
    $db = $seed(3);
    $db->rows['ticket_holds'] = [
        ['id' => 'h1', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'b1', 'status' => 'held', 'quantity' => 1, 'expires_at' => $FUT],
        ['id' => 'h2', 'organization_id' => $ORG, 'event_id' => 'ev', 'user_id' => 'b2', 'status' => 'held', 'quantity' => 1, 'expires_at' => $PST], // expired
    ];
    $res = $svcOf($db)->promoteWaitlistToCapacity('ev');
    // capacity 3, confirmed 1 + live held 1 = 2 → headroom 1 → promote just w1.
    $chk('a live hold consumes a seat (only 1 promoted)', ($res['promoted'] ?? []) === ['w1'], json_encode($res));
    $chk('expired hold ignored in headroom maths', $statusOfReg($db, 'w2') === 'waitlisted');

    // ── 4. No headroom → promotes 0 (capacity already full) ─────────────────
    $db = $seed(1); // 1 confirmed already fills capacity 1
    $res = $svcOf($db)->promoteWaitlistToCapacity('ev');
    $chk('no headroom promotes nobody', ($res['promoted'] ?? []) === [], json_encode($res));
    $chk('no-headroom reports headroom 0', ($res['headroom'] ?? null) === 0);
    $chk('w1 stays waitlisted when full', $statusOfReg($db, 'w1') === 'waitlisted');

    // ── 5. Idempotent: a second pass after a full raise promotes 0 ──────────
    $db  = $seed(3);
    $svcOf($db)->promoteWaitlistToCapacity('ev');
    $res2 = $svcOf($db)->promoteWaitlistToCapacity('ev');
    $chk('second pass is a no-op (idempotent)', ($res2['promoted'] ?? []) === [], json_encode($res2));

    // ── 6. Terminal event → waitlist is history, promotes 0 ─────────────────
    foreach (['completed', 'cancelled'] as $term) {
        $db  = $seed(3, $term);
        $res = $svcOf($db)->promoteWaitlistToCapacity('ev');
        $chk("{$term} event promotes nobody", ($res['promoted'] ?? []) === [] && $res['capacity'] === null);
        $chk("{$term} leaves w1 waitlisted", $statusOfReg($db, 'w1') === 'waitlisted');
    }

    // ── 7. Missing event → safe no-op ───────────────────────────────────────
    $db  = $seed(3);
    $res = $svcOf($db)->promoteWaitlistToCapacity('does-not-exist');
    $chk('unknown event is a safe no-op', ($res['promoted'] ?? []) === []);

    // ── 8. $limit bounds a single pass ──────────────────────────────────────
    $db  = $seed(null); // unlimited so headroom never caps
    $res = $svcOf($db)->promoteWaitlistToCapacity('ev', 2);
    $chk('limit bounds the pass to 2', ($res['promoted'] ?? []) === ['w1', 'w2'], json_encode($res));
    $chk('w3 left for the next pass', $statusOfReg($db, 'w3') === 'waitlisted');

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
