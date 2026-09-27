<?php

declare(strict_types=1);

/**
 * SeasonService rollover RECLAIM test (Theme C — G1).
 *
 * A `failed` rollover, or a `running` row abandoned by a crashed worker, used to
 * pin the UNIQUE transition_key for that year boundary forever — every later
 * attempt returned ROLLOVER_IN_PROGRESS and the season could never close.
 *
 * Over an in-memory DB fake that enforces the transition_key UNIQUE constraint,
 * proves:
 *   - a fresh rollover claims the transition and completes;
 *   - a second call for the same completed boundary is idempotent
 *     (already_completed, deduplicated);
 *   - a FAILED transition is reclaimed and retried to completion (attempts++);
 *   - a `running` row within its lease blocks (ROLLOVER_IN_PROGRESS);
 *   - a `running` row PAST its lease self-heals (reclaimed);
 *   - --force reclaims a fresh `running` row;
 *   - reclaimStuckRollovers() sweeps failed + stale-running rows, skipping
 *     fresh-lease running rows, and is idempotent on a re-run.
 *
 *   php app/Modules/Gamification/Services/tests/rollover_reclaim_test.php
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
        public bool $failNextTx = false;

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
            if ($this->failNextTx) {
                $this->failNextTx = false;

                return false;
            }

            return true;
        }

        // snapshotBalances uses a raw grouped query; return no rows.
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

        public function insert(array $row): bool
        {
            // Enforce the season_transitions UNIQUE(transition_key).
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
    use WBS\Gamification\Services\SeasonService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Gamification/Services/SeasonService.php';

    $pass = 0;
    $fail = 0;
    $chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $clock = new Clock();
    // Freeze time so lease arithmetic is deterministic.
    Clock::freeze(new DateTimeImmutable('2026-01-01 06:00:00', new DateTimeZone('UTC')));

    $seedActive = static function (BaseConnection $db, string $org, int $year): void {
        $db->rows['gamification_seasons'][] = [
            'id' => 'season-' . $org . '-' . $year, 'organization_id' => $org,
            'season_year' => $year, 'status' => 'active', 'active_key' => $org,
        ];
    };
    $transition = static function (BaseConnection $db, string $key): ?array {
        foreach ($db->rows['season_transitions'] ?? [] as $r) {
            if ((string) $r['transition_key'] === $key) {
                return $r;
            }
        }

        return null;
    };

    // ---- 1. fresh rollover completes ---------------------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-1', 2026);
    $svc = new SeasonService($db, $clock);

    $r1 = $svc->rollover('org-1', 'UTC');
    $chk('fresh rollover ok', $r1->ok);
    $chk('fresh rollover completes', ($r1->data['status'] ?? '') === 'completed');
    $t = $transition($db, 'org-1:2026:2027');
    $chk('transition marked completed', ($t['status'] ?? '') === 'completed');
    $chk('closing season closed', (function () use ($db) {
        foreach ($db->rows['gamification_seasons'] as $s) {
            if ($s['id'] === 'season-org-1-2026') {
                return $s['status'] === 'closed';
            }
        }

        return false;
    })());

    // ---- 2. idempotent re-run (already-completed boundary) -----------------
    // Rebuild a clean state where 2026 is active AND its transition is already
    // completed, so the claim collides and the completed-dedupe path is hit.
    $db2 = new BaseConnection();
    $db2->rows['season_transitions'] = [[
        'id' => 'done', 'organization_id' => 'org-9', 'transition_key' => 'org-9:2026:2027',
        'from_season_id' => 'season-org-9-2026', 'to_season_id' => 'season-org-9-2027',
        'status' => 'completed', 'attempts' => 1,
        'created_at' => '2026-01-01 00:00:00', 'completed_at' => '2026-01-01 00:01:00', 'updated_at' => '2026-01-01 00:01:00',
    ]];
    $db2->rows['gamification_seasons'] = [];
    $seedActive($db2, 'org-9', 2026);
    $svc2 = new SeasonService($db2, $clock);
    $r2   = $svc2->rollover('org-9', 'UTC');
    $chk('already-completed boundary is idempotent', $r2->ok && ($r2->data['status'] ?? '') === 'already_completed');

    // ---- 3. FAILED transition is reclaimed ---------------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [[
        'id' => 'tx1', 'organization_id' => 'org-2', 'transition_key' => 'org-2:2026:2027',
        'from_season_id' => 'season-org-2-2026', 'to_season_id' => null, 'status' => 'failed',
        'attempts' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-2', 2026);
    $svc = new SeasonService($db, $clock);

    $r3 = $svc->rollover('org-2', 'UTC');
    $chk('failed transition reclaimed to completion', $r3->ok && ($r3->data['status'] ?? '') === 'completed');
    $t3 = $transition($db, 'org-2:2026:2027');
    $chk('reclaim bumped attempts to 2', (int) ($t3['attempts'] ?? 0) === 2, (string) ($t3['attempts'] ?? -1));
    $chk('reclaimed transition now completed', ($t3['status'] ?? '') === 'completed');

    // ---- 3b. a failing transaction marks the row failed (retryable next) ---
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-6', 2026);
    $db->failNextTx = true; // first rollover's transaction fails
    $svc = new SeasonService($db, $clock);

    $rf = $svc->rollover('org-6', 'UTC');
    $chk('failing rollover returns ROLLOVER_FAILED', ! $rf->ok && $rf->code === 'ROLLOVER_FAILED');
    $chk('failed rollover leaves row status=failed', $transition($db, 'org-6:2026:2027')['status'] === 'failed');
    // ...and a retry now reclaims it to completion.
    $rf2 = $svc->rollover('org-6', 'UTC');
    $chk('retry after failure completes', $rf2->ok && ($rf2->data['status'] ?? '') === 'completed');

    // ---- 4. running WITHIN lease blocks ------------------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [[
        'id' => 'tx2', 'organization_id' => 'org-3', 'transition_key' => 'org-3:2026:2027',
        'from_season_id' => 'season-org-3-2026', 'to_season_id' => null, 'status' => 'running',
        'attempts' => 1, 'created_at' => '2026-01-01 05:59:00', 'updated_at' => '2026-01-01 05:59:00',
    ]];
    $db->rows['gamification_seasons'] = [];
    $seedActive($db, 'org-3', 2026);
    $svc = new SeasonService($db, $clock);

    $r4 = $svc->rollover('org-3', 'UTC');
    $chk('fresh-lease running blocks', ! $r4->ok && $r4->code === 'ROLLOVER_IN_PROGRESS');
    $chk('blocked running still running', $transition($db, 'org-3:2026:2027')['status'] === 'running');

    // ---- 5. running PAST lease self-heals ----------------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [[
        'id' => 'tx3', 'organization_id' => 'org-4', 'transition_key' => 'org-4:2026:2027',
        'from_season_id' => 'season-org-4-2026', 'to_season_id' => null, 'status' => 'running',
        'attempts' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', // 6h old
    ]];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-4', 2026);
    $svc = new SeasonService($db, $clock);

    $r5 = $svc->rollover('org-4', 'UTC');
    $chk('stale-lease running self-heals', $r5->ok && ($r5->data['status'] ?? '') === 'completed');

    // ---- 6. --force reclaims a fresh running row ---------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [[
        'id' => 'tx4', 'organization_id' => 'org-5', 'transition_key' => 'org-5:2026:2027',
        'from_season_id' => 'season-org-5-2026', 'to_season_id' => null, 'status' => 'running',
        'attempts' => 1, 'created_at' => '2026-01-01 05:59:00', 'updated_at' => '2026-01-01 05:59:00',
    ]];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-5', 2026);
    $svc = new SeasonService($db, $clock);

    $r6 = $svc->rollover('org-5', 'UTC', true);
    $chk('--force reclaims fresh running', $r6->ok && ($r6->data['status'] ?? '') === 'completed');

    // ---- 7. reclaimStuckRollovers sweep ------------------------------------
    $db = new BaseConnection();
    $db->rows['season_transitions'] = [
        // stuck failed
        ['id' => 'a', 'organization_id' => 'org-a', 'transition_key' => 'org-a:2026:2027', 'from_season_id' => 's', 'to_season_id' => null, 'status' => 'failed', 'attempts' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        // stale running
        ['id' => 'b', 'organization_id' => 'org-b', 'transition_key' => 'org-b:2026:2027', 'from_season_id' => 's', 'to_season_id' => null, 'status' => 'running', 'attempts' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        // fresh running — must be skipped
        ['id' => 'c', 'organization_id' => 'org-c', 'transition_key' => 'org-c:2026:2027', 'from_season_id' => 's', 'to_season_id' => null, 'status' => 'running', 'attempts' => 1, 'created_at' => '2026-01-01 05:59:00', 'updated_at' => '2026-01-01 05:59:00'],
    ];
    $db->rows['gamification_seasons'] = [];
    $db->rows['point_ledger'] = [];
    $db->rows['season_balance_snapshots'] = [];
    $seedActive($db, 'org-a', 2026);
    $seedActive($db, 'org-b', 2026);
    $seedActive($db, 'org-c', 2026);
    $svc = new SeasonService($db, $clock);

    $swept = $svc->reclaimStuckRollovers(null, 100);
    $chk('sweep reclaims 2 (failed + stale running)', $swept === 2, (string) $swept);
    $chk('fresh-lease running left untouched', $transition($db, 'org-c:2026:2027')['status'] === 'running');
    $chk('failed org-a now completed', $transition($db, 'org-a:2026:2027')['status'] === 'completed');
    $chk('stale org-b now completed', $transition($db, 'org-b:2026:2027')['status'] === 'completed');

    // idempotent re-run: nothing left stuck (org-c still fresh-running)
    $swept2 = $svc->reclaimStuckRollovers(null, 100);
    $chk('sweep re-run reclaims 0', $swept2 === 0, (string) $swept2);

    Clock::freeze(null);

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
