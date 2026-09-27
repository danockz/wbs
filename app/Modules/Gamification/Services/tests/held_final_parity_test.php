<?php

declare(strict_types=1);

/**
 * Held → final PARITY test (Phase 0: gap G3).
 *
 * There are two doors that promote a `held` point-ledger entry to `final`:
 *   - approveAward()  (approval workflow)
 *   - clearHeld()     (fraud-clear workflow)
 *
 * They used to be asymmetric: approveAward ran achievement evaluation and
 * recorded the approver, while clearHeld did neither — so an identical held
 * award released via the fraud-clear path could be DENIED an achievement the
 * approval path would have unlocked, and left a weaker audit trail.
 *
 * This drives the REAL PointsEngine + AchievementService (over an in-memory DB
 * fake) and asserts both doors:
 *   - unlock the SAME `count`-type achievement once the award becomes final, and
 *   - stamp `resolved_by` on the fraud_review row.
 *
 *   php app/Modules/Gamification/Services/tests/held_final_parity_test.php
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

        // AchievementService::unlock wraps inserts in a transaction; the fake
        // applies writes immediately, so these are no-ops that always "succeed".
        public function transStart(): bool { return true; }

        public function transComplete(): bool { return true; }

        public function transStatus(): bool { return true; }

        // Raw query support (PointsEngine::balance). Not needed by the count-type
        // achievement path, but present so the engine never fatals.
        public function query(string $sql, array $binds = []): \Fake\RS
        {
            return new \Fake\RS([['total' => 0]]);
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
        private array $select = [];

        public function __construct(private \CodeIgniter\Database\BaseConnection $db, private string $t)
        {
        }

        public function where($k, $v = null)
        {
            $this->eq[trim((string) $k)] = $v;

            return $this;
        }

        public function select($cols)
        {
            $this->select = array_map('trim', explode(',', (string) $cols));

            return $this;
        }

        public function get($limit = null): RS
        {
            $rows = $this->matching();
            if ($this->select !== []) {
                $rows = array_map(function ($r) {
                    $o = [];
                    foreach ($this->select as $c) {
                        $o[$c] = $r[$c] ?? null;
                    }

                    return $o;
                }, $rows);
            }

            return new RS(array_values($rows));
        }

        public function countAllResults(): int
        {
            return count($this->matching());
        }

        public function insert(array $row): bool
        {
            $this->db->rows[$this->t][] = $row;

            return true;
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

        public function delete(): bool
        {
            $this->db->rows[$this->t] = array_values(array_filter(
                $this->db->rows[$this->t] ?? [],
                fn ($r) => ! $this->matches($r),
            ));

            return true;
        }

        private function matching(): array
        {
            return array_values(array_filter($this->db->rows[$this->t] ?? [], fn ($r) => $this->matches($r)));
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

namespace {
    use CodeIgniter\Database\BaseConnection;
    use WBS\Gamification\Services\AchievementService;
    use WBS\Gamification\Services\PointsEngine;
    use WBS\Gamification\Services\RankService;
    use WBS\Gamification\Services\RollupService;
    use WBS\Gamification\Services\SeasonService;
    use WBS\Gamification\Services\StreakService;
    use WBS\Shared\Support\Clock;

    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Shared/Support/Clock.php';
    require_once $root . '/app/Modules/Shared/Support/Uuid.php';
    require_once $root . '/app/Modules/Shared/Support/Result.php';
    require_once $root . '/app/Modules/Gamification/Support/FormulaEvaluator.php';
    require_once $root . '/app/Modules/Gamification/Services/SeasonService.php';
    require_once $root . '/app/Modules/Gamification/Services/StreakService.php';
    require_once $root . '/app/Modules/Gamification/Services/RankService.php';
    require_once $root . '/app/Modules/Gamification/Services/RollupService.php';
    require_once $root . '/app/Modules/Gamification/Services/PointsEngine.php';
    require_once $root . '/app/Modules/Gamification/Services/AchievementService.php';

    $pass = 0;
    $fail = 0;
    $chk  = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
        $ok ? $pass++ : $fail++;
    };

    $ORG    = 'org-1';
    $SEASON = 'season-1';

    // Build a fresh world: one active season, one org-wide `count` achievement
    // (threshold 1, no activity filter → any single FINAL award unlocks it), and
    // one HELD award entry for the given subject.
    $build = static function (string $subjectId): BaseConnection {
        $db = new BaseConnection();
        $db->rows['gamification_seasons'] = [[
            'id' => 'season-1', 'organization_id' => 'org-1', 'status' => 'active',
        ]];
        $db->rows['achievement_definitions'] = [[
            'id'             => 'ach-1',
            'organization_id' => 'org-1',
            'code'           => 'first_award',
            'status'         => 'active',
            'group_id'       => null,
            'trigger_type'   => 'count',
            'trigger_config' => json_encode(['count' => 1]),
            'xp'             => 10,
            'bonus_points'   => 0,
            'bonus_rule_code' => '',
            'phase'          => 'general',
        ]];
        $db->rows['point_ledger'] = [[
            'id'              => 'led-' . $subjectId,
            'organization_id' => 'org-1',
            'season_id'       => 'season-1',
            'subject_id'      => $subjectId,
            'rule_id'         => 'rule-1',
            'entry_type'      => 'award',
            'points'          => 50,
            'source_ref'      => 'evt:' . $subjectId,
            'state'           => 'held',
            'group_id'        => null,
            'category_code'   => null,
            'project_code'    => null,
            'phase'           => null,
            'amount_minor'    => 0,
        ]];
        $db->rows['fraud_reviews'] = [[
            'id'              => 'fr-' . $subjectId,
            'organization_id' => 'org-1',
            'subject_id'      => $subjectId,
            'source_ref'      => 'evt:' . $subjectId,
            'reason'          => 'requires_review',
            'ledger_id'       => 'led-' . $subjectId,
            'status'          => 'open',
            'resolved_at'     => null,
            'resolved_by'     => null,
        ]];
        $db->rows['user_achievements']         = [];
        $db->rows['user_achievement_progress'] = [];

        return $db;
    };

    $clock = new Clock();

    $wire = static function (BaseConnection $db) use ($clock): array {
        $seasons = new SeasonService($db, $clock);
        $streaks = new StreakService($db, $clock);
        $ranks   = new RankService($db, $clock);
        $rollup  = new RollupService($db, $clock);
        // Achievements is wired into the engine so BOTH doors evaluate them.
        $engine  = new PointsEngine($db, $clock, $seasons, null, null, $rollup);
        $ach     = new AchievementService($db, $clock, $engine, $seasons, $streaks, $ranks);
        // Late-bind the achievements collaborator via a fresh engine that shares
        // the same DB (PointsEngine takes achievements in its ctor).
        $engineWithAch = new PointsEngine($db, $clock, $seasons, $ach, null, $rollup);

        return [$engineWithAch, $ach];
    };

    $isUnlocked = static function (BaseConnection $db, string $subjectId): bool {
        foreach ($db->rows['user_achievements'] ?? [] as $r) {
            if (($r['subject_id'] ?? '') === $subjectId && ($r['achievement_code'] ?? '') === 'first_award') {
                return true;
            }
        }

        return false;
    };
    $review = static function (BaseConnection $db, string $subjectId): array {
        foreach ($db->rows['fraud_reviews'] ?? [] as $r) {
            if (($r['subject_id'] ?? '') === $subjectId) {
                return $r;
            }
        }

        return [];
    };

    // ── Door A: clearHeld ─────────────────────────────────────────────────────
    echo "clearHeld promotes the entry, unlocks the achievement, and records the actor\n";
    {
        $db = $build('subjA');
        [$engine] = $wire($db);
        $chk('achievement not yet unlocked (held)', ! $isUnlocked($db, 'subjA'));
        $res = $engine->clearHeld('led-subjA', 'fraud-officer-1');
        $chk('clearHeld ok', $res->ok, $res->message ?? '');
        $chk('entry is now final', ($db->rows['point_ledger'][0]['state'] ?? '') === 'final');
        $chk('clearHeld unlocked the achievement', $isUnlocked($db, 'subjA'));
        $rv = $review($db, 'subjA');
        $chk('review cleared', ($rv['status'] ?? '') === 'cleared');
        $chk('review records resolved_by (G3 audit fix)', ($rv['resolved_by'] ?? null) === 'fraud-officer-1');
        $chk('result surfaces resolved_by', ($res->data['resolved_by'] ?? '') === 'fraud-officer-1');
    }

    // ── Door B: approveAward ──────────────────────────────────────────────────
    echo "approveAward promotes the entry, unlocks the SAME achievement, records the actor\n";
    {
        $db = $build('subjB');
        [$engine] = $wire($db);
        $chk('achievement not yet unlocked (held)', ! $isUnlocked($db, 'subjB'));
        $res = $engine->approveAward('led-subjB', 'approver-1', 'looks good');
        $chk('approveAward ok', $res->ok, $res->message ?? '');
        $chk('entry is now final', ($db->rows['point_ledger'][0]['state'] ?? '') === 'final');
        $chk('approveAward unlocked the achievement', $isUnlocked($db, 'subjB'));
        $rv = $review($db, 'subjB');
        $chk('review cleared', ($rv['status'] ?? '') === 'cleared');
        $chk('review records resolved_by', ($rv['resolved_by'] ?? null) === 'approver-1');
    }

    // ── Parity: both doors reach the SAME downstream state ────────────────────
    echo "both doors reach identical downstream state (the G3 invariant)\n";
    {
        $dbA = $build('subjX');
        [$engA] = $wire($dbA);
        $engA->clearHeld('led-subjX', 'officer');

        $dbB = $build('subjX');
        [$engB] = $wire($dbB);
        $engB->approveAward('led-subjX', 'approver');

        $chk('both leave the entry final', ($dbA->rows['point_ledger'][0]['state'] ?? '') === 'final'
            && ($dbB->rows['point_ledger'][0]['state'] ?? '') === 'final');
        $chk('both unlock the achievement', $isUnlocked($dbA, 'subjX') && $isUnlocked($dbB, 'subjX'));
        $chk('both stamp a resolver', ($review($dbA, 'subjX')['resolved_by'] ?? null) !== null
            && ($review($dbB, 'subjX')['resolved_by'] ?? null) !== null);
    }

    // ── Idempotency: a non-held entry stays put on both doors ──────────────────
    echo "a non-held entry is a no-op on both doors\n";
    {
        $db = $build('subjC');
        $db->rows['point_ledger'][0]['state'] = 'final'; // already final
        [$engine, $ach] = $wire($db);
        $res = $engine->approveAward('led-subjC', 'approver-1');
        $chk('approveAward reports already_resolved', $res->ok && ($res->meta['already_resolved'] ?? false) === true);
    }

    echo "\n";
    if ($fail === 0) {
        echo "OK  {$pass} passed, 0 failed\n";
        exit(0);
    }
    echo "FAIL  {$pass} passed, {$fail} failed\n";
    exit(1);
}
