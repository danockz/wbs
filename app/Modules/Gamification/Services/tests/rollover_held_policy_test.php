<?php

declare(strict_types=1);

/**
 * SeasonService rollover — G4 open-held-entry disposition policy.
 *
 * Before G4, a `held` entry in a closing season sat in an archived, closed
 * season; approving/clearing it later mutated the FROZEN season's rollup (or the
 * points vanished from the subject's spendable balance). This proves the
 * config-driven policy at rollover:
 *
 *   - carry_forward (default): open held entries (and their open fraud reviews)
 *     are re-pointed to the NEW season and NOT archived, so a later approve/clear
 *     rolls up into the active season; final entries stay in the closed season
 *     and are archived;
 *   - reject_on_close: open held entries flip to `reversed` and their reviews are
 *     rejected (points never become spendable);
 *   - resolve_before_close: rollover REFUSES while open held entries exist
 *     (HELD_ENTRIES_OPEN), forcing a human to clear the queue first.
 *
 *   php app/Modules/Gamification/Services/tests/rollover_held_policy_test.php
 */

namespace CodeIgniter\Database {
    class DatabaseException extends \RuntimeException
    {
    }

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

        public function transStart(): void
        {
        }

        public function transComplete(): void
        {
        }

        public function transStatus(): bool
        {
            return true;
        }

        // snapshotBalances uses a raw grouped query over FINAL entries.
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            return new \Fake\RS([]);
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
        private ?string $orderBy = null;

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null, $escape = true)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function whereIn($k, array $vals)
        {
            $this->in[trim((string) $k)] = array_map('strval', $vals);

            return $this;
        }

        public function orderBy($col, $dir = 'ASC')
        {
            $this->orderBy = $col;

            return $this;
        }

        public function get($limit = null): RS
        {
            $out = array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
            if ($this->orderBy !== null) {
                usort($out, fn ($a, $b) => strcmp((string) ($a[$this->orderBy] ?? ''), (string) ($b[$this->orderBy] ?? '')));
            }
            if ($limit !== null) {
                $out = array_slice($out, 0, (int) $limit);
            }

            return new RS($out);
        }

        public function countAllResults(): int
        {
            return count(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
        }

        public function insert(array $row): bool
        {
            if ($this->t === 'season_transitions' && isset($row['transition_key'])) {
                foreach ($this->db->rows[$this->t] ?? [] as $r) {
                    if ((string) ($r['transition_key'] ?? '') === (string) $row['transition_key']) {
                        throw new \CodeIgniter\Database\DatabaseException('duplicate transition_key');
                    }
                }
            }
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

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Gamification\Services\ConfigService;
    use WBS\Gamification\Services\SeasonService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Gamification/Services/ConfigService.php';
    require_once $root . '/app/Modules/Gamification/Services/SeasonService.php';

    // Real ConfigService over the fake DB: seed a gamification_config row for the
    // policy (or none, to exercise the default).
    $configWith = static function (BaseConnection $db, ?string $policy): ConfigService {
        $db->rows['gamification_config'] = $policy === null ? [] : [[
            'organization_id' => 'org-1',
            'config_key'      => 'held_rollover_policy',
            'config_value'    => $policy,
            'config_type'     => 'string',
        ]];

        return new ConfigService($db, new Clock());
    };

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    Clock::freeze(new DateTimeImmutable('2026-01-01 06:00:00', new DateTimeZone('UTC')));

    $ORG = 'org-1';

    $seed = static function () use ($ORG): BaseConnection {
        $db = new BaseConnection();
        $db->rows['gamification_seasons'][] = [
            'id' => 'season-2025', 'organization_id' => $ORG,
            'season_year' => 2025, 'status' => 'active', 'active_key' => $ORG,
        ];
        $db->rows['point_ledger'] = [
            ['id' => 'l-final', 'organization_id' => $ORG, 'season_id' => 'season-2025', 'state' => 'final', 'points' => 10, 'archived' => 0],
            ['id' => 'l-held-1', 'organization_id' => $ORG, 'season_id' => 'season-2025', 'state' => 'held', 'points' => 5, 'archived' => 0, 'group_id' => 'g1'],
            ['id' => 'l-held-2', 'organization_id' => $ORG, 'season_id' => 'season-2025', 'state' => 'held', 'points' => 7, 'archived' => 0, 'group_id' => 'g1'],
        ];
        $db->rows['fraud_reviews'] = [
            ['id' => 'fr-1', 'organization_id' => $ORG, 'ledger_id' => 'l-held-1', 'status' => 'open'],
            ['id' => 'fr-2', 'organization_id' => $ORG, 'ledger_id' => 'l-held-2', 'status' => 'open'],
        ];
        $db->rows['season_transitions'] = [];
        $db->rows['season_balance_snapshots'] = [];

        return $db;
    };

    $ledger = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['point_ledger'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };
    $review = static function (BaseConnection $db, string $id): ?array {
        foreach ($db->rows['fraud_reviews'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    };
    $nextSeasonId = static function (BaseConnection $db): ?string {
        foreach ($db->rows['gamification_seasons'] as $s) {
            if ((int) $s['season_year'] === 2026) {
                return (string) $s['id'];
            }
        }

        return null;
    };

    // ---- carry_forward (default) ------------------------------------------
    $db  = $seed();
    $cfg = $configWith($db, null); // no value -> default carry_forward
    $svc = new SeasonService($db, new Clock(), $cfg);
    $res = $svc->rollover($ORG, 'UTC');
    $chk('carry_forward: rollover completed', $res->ok && ($res->data['status'] ?? '') === 'completed', json_encode($res->data ?? []));
    $chk('carry_forward: policy reported', ($res->data['held_policy'] ?? '') === 'carry_forward');
    $chk('carry_forward: 2 held disposed', ($res->data['held_disposed'] ?? 0) === 2);

    $next = $nextSeasonId($db);
    $chk('carry_forward: next season opened', $next !== null);
    $chk('carry_forward: held-1 moved to new season', ($ledger($db, 'l-held-1')['season_id'] ?? '') === $next);
    $chk('carry_forward: held-2 moved to new season', ($ledger($db, 'l-held-2')['season_id'] ?? '') === $next);
    $chk('carry_forward: carried held NOT archived', (int) ($ledger($db, 'l-held-1')['archived'] ?? 1) === 0);
    $chk('carry_forward: held still held', ($ledger($db, 'l-held-1')['state'] ?? '') === 'held');
    $chk('carry_forward: final entry stays in closed season', ($ledger($db, 'l-final')['season_id'] ?? '') === 'season-2025');
    $chk('carry_forward: final entry archived', (int) ($ledger($db, 'l-final')['archived'] ?? 0) === 1);
    $chk('carry_forward: reviews stay open', ($review($db, 'fr-1')['status'] ?? '') === 'open');

    // ---- reject_on_close ---------------------------------------------------
    $db  = $seed();
    $cfg = $configWith($db, 'reject_on_close');
    $svc = new SeasonService($db, new Clock(), $cfg);
    $res = $svc->rollover($ORG, 'UTC');
    $chk('reject_on_close: rollover completed', $res->ok, json_encode($res->data ?? []));
    $chk('reject_on_close: held-1 reversed', ($ledger($db, 'l-held-1')['state'] ?? '') === 'reversed');
    $chk('reject_on_close: held-2 reversed', ($ledger($db, 'l-held-2')['state'] ?? '') === 'reversed');
    $chk('reject_on_close: review-1 rejected', ($review($db, 'fr-1')['status'] ?? '') === 'rejected');
    $chk('reject_on_close: final entry untouched', ($ledger($db, 'l-final')['state'] ?? '') === 'final');

    // ---- resolve_before_close: refuse while open held exist ---------------
    $db  = $seed();
    $cfg = $configWith($db, 'resolve_before_close');
    $svc = new SeasonService($db, new Clock(), $cfg);
    $res = $svc->rollover($ORG, 'UTC');
    $chk('resolve_before_close: rollover refused', ! $res->ok && $res->code === 'HELD_ENTRIES_OPEN', (string) $res->code);
    $seasonStatus = '';
    foreach ($db->rows['gamification_seasons'] as $s) {
        if ($s['id'] === 'season-2025') {
            $seasonStatus = (string) $s['status'];
        }
    }
    $chk('resolve_before_close: season NOT closed', $seasonStatus === 'active', $seasonStatus);
    $chk('resolve_before_close: held untouched', ($ledger($db, 'l-held-1')['state'] ?? '') === 'held');

    // ---- resolve_before_close: proceeds once held cleared -----------------
    $db  = $seed();
    // clear the held entries first
    foreach ($db->rows['point_ledger'] as &$r) {
        if ($r['state'] === 'held') {
            $r['state'] = 'final';
        }
    }
    unset($r);
    $cfg = $configWith($db, 'resolve_before_close');
    $svc = new SeasonService($db, new Clock(), $cfg);
    $res = $svc->rollover($ORG, 'UTC');
    $chk('resolve_before_close: proceeds when queue empty', $res->ok && ($res->data['status'] ?? '') === 'completed', json_encode($res->data ?? []));

    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}
