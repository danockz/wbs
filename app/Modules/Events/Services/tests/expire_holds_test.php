<?php

declare(strict_types=1);

/**
 * RegistrationService::processExpiredHolds test (Theme C — E-C1).
 *
 * The checkout/hold paths only expire stale holds LAZILY (per-event, when a new
 * hold is attempted), so a quiet event leaves `held` rows past `expires_at`
 * forever — the seat never re-enters capacity maths and the
 * `th_active_uq (event_id, user_id, status)` UNIQUE key wedges the buyer out of
 * ever holding again. This background sweep releases them. Over an in-memory DB
 * fake (with `<=` operator + `get($limit)` support), proves:
 *   - only holds whose expires_at has passed AND are still `held` flip to
 *     `expired`; a live (future-expiry) hold is untouched;
 *   - already-consumed / already-expired / released rows are ignored;
 *   - org scope filters; null org sweeps all orgs;
 *   - the row-limit bounds a pass;
 *   - counts {scanned,expired} are accurate;
 *   - idempotent: a second pass releases 0.
 *
 *   php app/Modules/Events/Services/tests/expire_holds_test.php
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
        /** @var array<int,array{k:string,op:string,v:mixed}> */
        private array $conds = [];
        /** @var array<string,list<string>> */
        private array $in = [];
        private ?string $orderKey = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

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

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

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
                usort($rows, fn ($a, $b) => (string) ($a[$this->orderKey] ?? '') <=> (string) ($b[$this->orderKey] ?? ''));
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
            foreach ($this->in as $k => $vals) {
                if (! in_array((string) ($r[$k] ?? ''), $vals, true)) {
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
    $NOW  = '2026-06-01 12:00:00';
    $PAST = '2026-06-01 11:00:00';
    $FUT  = '2026-06-01 13:00:00';
    $ORG  = 'org-1';

    $statusOf = static function (BaseConnection $db, string $id): string {
        foreach ($db->rows['ticket_holds'] as $h) {
            if ($h['id'] === $id) {
                return (string) $h['status'];
            }
        }

        return '';
    };

    $seed = static function () use ($ORG, $PAST, $FUT): BaseConnection {
        $db = new BaseConnection();
        $db->rows['ticket_holds'] = [
            ['id' => 'h-stale',    'organization_id' => $ORG,   'event_id' => 'ev1', 'user_id' => 'u1', 'status' => 'held',     'expires_at' => $PAST],
            ['id' => 'h-live',     'organization_id' => $ORG,   'event_id' => 'ev1', 'user_id' => 'u2', 'status' => 'held',     'expires_at' => $FUT],
            ['id' => 'h-consumed', 'organization_id' => $ORG,   'event_id' => 'ev1', 'user_id' => 'u3', 'status' => 'consumed', 'expires_at' => $PAST],
            ['id' => 'h-already',  'organization_id' => $ORG,   'event_id' => 'ev1', 'user_id' => 'u4', 'status' => 'expired',  'expires_at' => $PAST],
            ['id' => 'h-released', 'organization_id' => $ORG,   'event_id' => 'ev1', 'user_id' => 'u5', 'status' => 'released', 'expires_at' => $PAST],
            ['id' => 'h-org2',     'organization_id' => 'org-2', 'event_id' => 'ev9', 'user_id' => 'u6', 'status' => 'held',     'expires_at' => $PAST],
        ];

        return $db;
    };

    // ---- org-scoped pass ----------------------------------------------------
    $db  = $seed();
    $svc = new RegistrationService($db, new Clock());
    $r   = $svc->processExpiredHolds($ORG, 500);

    $chk('scanned = 1 (only the stale held in org)', $r['scanned'] === 1, (string) $r['scanned']);
    $chk('expired = 1', $r['expired'] === 1, (string) $r['expired']);
    $chk('h-stale -> expired', $statusOf($db, 'h-stale') === 'expired');
    $chk('h-live untouched (future expiry)', $statusOf($db, 'h-live') === 'held');
    $chk('h-consumed untouched', $statusOf($db, 'h-consumed') === 'consumed');
    $chk('h-already untouched', $statusOf($db, 'h-already') === 'expired');
    $chk('h-released untouched', $statusOf($db, 'h-released') === 'released');
    $chk('other org untouched under org scope', $statusOf($db, 'h-org2') === 'held');

    // ---- idempotent second pass --------------------------------------------
    $r2 = $svc->processExpiredHolds($ORG, 500);
    $chk('second pass scans 0', $r2['scanned'] === 0, (string) $r2['scanned']);
    $chk('second pass expires 0', $r2['expired'] === 0, (string) $r2['expired']);

    // ---- all-orgs pass ------------------------------------------------------
    $db  = $seed();
    $svc = new RegistrationService($db, new Clock());
    $r3  = $svc->processExpiredHolds(null, 500);
    $chk('all-orgs scans both stale holds', $r3['scanned'] === 2, (string) $r3['scanned']);
    $chk('all-orgs expires both', $r3['expired'] === 2, (string) $r3['expired']);
    $chk('org2 hold now expired', $statusOf($db, 'h-org2') === 'expired');

    // ---- row limit bounds a pass -------------------------------------------
    $db  = $seed();
    $svc = new RegistrationService($db, new Clock());
    $r4  = $svc->processExpiredHolds(null, 1);
    $chk('limit=1 caps scanned at 1', $r4['scanned'] === 1, (string) $r4['scanned']);
    $chk('limit=1 caps expired at 1', $r4['expired'] === 1, (string) $r4['expired']);

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
