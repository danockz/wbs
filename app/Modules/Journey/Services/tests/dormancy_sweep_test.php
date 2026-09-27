<?php

declare(strict_types=1);

/**
 * InvolvementService::processDormancy test (Theme C — M5).
 *
 * There was no dormancy model and no runner: a member who stopped participating
 * stayed `active` and the involvement `cold` band was a read-only signal. Over an
 * in-memory DB fake + config stub, proves:
 *   - DEFAULT OFF: with the gate unset, nothing is marked (skipped_gated);
 *   - when enabled: a cold + stale member is marked `dormant` (dormant_since set);
 *   - a cold member with RECENT activity is not marked;
 *   - a warm/hot member is not marked (and re-engages if previously dormant);
 *   - a previously-dormant member who is now warm flips back to active
 *     (dormant_since cleared);
 *   - idempotent: a second pass marks 0 / re-engages 0;
 *   - the gate is resolved per context (org-root fallback).
 *
 *   php app/Modules/Journey/Services/tests/dormancy_sweep_test.php
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
        private array $ne = [];
        private array $isNull = [];
        private ?string $selectCols = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function select($c)
        {
            $this->selectCols = (string) $c;

            return $this;
        }

        public function where($k, $v = null, $escape = true)
        {
            $k = trim((string) $k);
            if (str_ends_with($k, '!=')) {
                $this->ne[trim(substr($k, 0, -2))] = $v;

                return $this;
            }
            if ($v === null && ! str_contains($k, ' ')) {
                // where('group_id', null) => IS NULL match
                $this->isNull[] = $k;

                return $this;
            }
            $this->eq[$k] = $v;

            return $this;
        }

        public function orderBy($c, $d = 'ASC')
        {
            return $this;
        }

        public function limit($n)
        {
            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
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
            foreach ($this->ne as $k => $v) {
                if ((string) ($r[$k] ?? '') === (string) $v) {
                    return false;
                }
            }
            foreach ($this->isNull as $k) {
                if (($r[$k] ?? null) !== null) {
                    return false;
                }
            }

            return true;
        }
    }
}

namespace WBS\Journey\Services {
    require_once dirname(__DIR__, 5) . '/app/Modules/Journey/Services/ConfigResolverPort.php';

    // Config stub: a map of "group|capability" => value.
    class MapConfig implements ConfigResolverPort
    {
        /** @var array<string,mixed> */
        public array $map = [];

        public function value(string $groupId, string $capability): mixed
        {
            return $this->map[$groupId . '|' . $capability] ?? null;
        }
    }
}

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Journey\Services\InvolvementService;
    use WBS\Journey\Services\MapConfig;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/GroupScopeResolver.php';
    require_once $root . '/app/Modules/Journey/Services/ConfigResolverPort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementSourcePort.php';
    require_once $root . '/app/Modules/Journey/Services/ConfigWriterPort.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyTransitionListener.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementTriagePort.php';
    require_once $root . '/app/Modules/Journey/Services/InvolvementService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    $recent = '2026-05-20 12:00:00'; // 12 days ago
    $stale  = '2026-01-01 12:00:00'; // ~150 days ago

    $ORG  = 'org-1';
    $ROOT = 'g-root';

    $seed = static function () use ($ORG, $ROOT, $recent, $stale): BaseConnection {
        $db = new BaseConnection();
        $db->rows['groups'] = [['id' => $ROOT, 'organization_id' => $ORG, 'status' => 'active', 'depth' => 1, 'created_at' => '2026-01-01 00:00:00']];
        $db->rows['member_involvement_snapshots'] = [
            // cold + stale -> dormant
            ['organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => null, 'band' => 'cold', 'last_activity_at' => $stale],
            // cold + recent -> not dormant
            ['organization_id' => $ORG, 'user_id' => 'u2', 'group_id' => null, 'band' => 'cold', 'last_activity_at' => $recent],
            // warm -> not dormant
            ['organization_id' => $ORG, 'user_id' => 'u3', 'group_id' => null, 'band' => 'warm', 'last_activity_at' => $recent],
            // hot, previously dormant -> re-engage
            ['organization_id' => $ORG, 'user_id' => 'u4', 'group_id' => null, 'band' => 'hot', 'last_activity_at' => $recent],
        ];
        $db->rows['member_journeys'] = [
            ['organization_id' => $ORG, 'user_id' => 'u1', 'group_id' => null, 'dormancy_state' => 'active', 'dormant_since' => null],
            ['organization_id' => $ORG, 'user_id' => 'u2', 'group_id' => null, 'dormancy_state' => 'active', 'dormant_since' => null],
            ['organization_id' => $ORG, 'user_id' => 'u3', 'group_id' => null, 'dormancy_state' => 'active', 'dormant_since' => null],
            ['organization_id' => $ORG, 'user_id' => 'u4', 'group_id' => null, 'dormancy_state' => 'dormant', 'dormant_since' => '2026-03-01 00:00:00'],
        ];

        return $db;
    };

    $stateOf = static function (BaseConnection $db, string $uid): array {
        foreach ($db->rows['member_journeys'] as $r) {
            if ($r['user_id'] === $uid) {
                return $r;
            }
        }

        return [];
    };

    // ---- DEFAULT OFF --------------------------------------------------------
    $db  = $seed();
    $cfg = new MapConfig(); // nothing enabled
    $svc = new InvolvementService($db, new Clock(), null, $cfg);
    $r   = $svc->processDormancy($ORG, null, 5000);
    $chk('default off marks nothing', $r['marked'] === 0 && $r['reengaged'] === 0, json_encode($r));
    $chk('default off skips all as gated', $r['skipped_gated'] === 4, (string) $r['skipped_gated']);
    $chk('u1 untouched when off', $stateOf($db, 'u1')['dormancy_state'] === 'active');

    // ---- ENABLED ------------------------------------------------------------
    $db  = $seed();
    $cfg = new MapConfig();
    $cfg->map[$ROOT . '|' . InvolvementService::CAP_DORMANCY_ENABLED] = true;
    // window defaults to 90 -> dormancy default 180, but stale is 150d < 180.
    // Set an explicit 90-day threshold so u1 (150d) qualifies.
    $cfg->map[$ROOT . '|' . InvolvementService::CAP_DORMANCY_DAYS] = 90;
    $svc = new InvolvementService($db, new Clock(), null, $cfg);
    $r   = $svc->processDormancy($ORG, null, 5000);

    $chk('u1 cold+stale marked dormant', $stateOf($db, 'u1')['dormancy_state'] === 'dormant');
    $chk('u1 dormant_since stamped', ! empty($stateOf($db, 'u1')['dormant_since']));
    $chk('u2 cold+recent not marked', $stateOf($db, 'u2')['dormancy_state'] === 'active');
    $chk('u3 warm not marked', $stateOf($db, 'u3')['dormancy_state'] === 'active');
    $chk('u4 hot re-engaged (dormant->active)', $stateOf($db, 'u4')['dormancy_state'] === 'active');
    $chk('u4 dormant_since cleared', $stateOf($db, 'u4')['dormant_since'] === null);
    $chk('marked = 1', $r['marked'] === 1, (string) $r['marked']);
    $chk('reengaged = 1', $r['reengaged'] === 1, (string) $r['reengaged']);
    $chk('none skipped when enabled', $r['skipped_gated'] === 0, (string) $r['skipped_gated']);

    // ---- idempotent second pass --------------------------------------------
    $r2 = $svc->processDormancy($ORG, null, 5000);
    $chk('second pass marks 0', $r2['marked'] === 0, (string) $r2['marked']);
    $chk('second pass re-engages 0', $r2['reengaged'] === 0, (string) $r2['reengaged']);

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
