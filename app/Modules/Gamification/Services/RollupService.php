<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;

/**
 * Ancestor roll-up + group attribution for ranking (design doc Part B.3–B.4).
 *
 * Two responsibilities, both pure derivations of existing tables:
 *
 *  1. resolveGroupId() — decide which group a ledger entry credits, following the
 *     settled resolution order: explicit receiving group → causes/events group
 *     (passed by the caller) → MEMBERSHIP FALLBACK (the member's own group;
 *     primary, else highest/deepest) → NULL (pure org-level).
 *
 *  2. applyDelta() / rebuild() — maintain group_point_rollup, a rebuildable cache
 *     where a contribution tagged group L contributes to L AND EVERY ANCESTOR of
 *     L (via group_closure). For each such group we update the fully-specific
 *     cell (category, project, phase) and the combined-total cells where each of
 *     those dimensions is rolled to the '*' sentinel, so every "all" board stays
 *     consistent. Reversals apply the compensating (negative) delta.
 *
 * The rollup is never a source of truth — balance()/history still read the
 * ledger. It exists only to make group/ancestor/cross-cut ranking O(read).
 */
final class RollupService
{
    /** Sentinel used for the "all combined" cell of a nullable dimension. */
    public const ALL = '*';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Resolve the credited group_id for an activity, honouring the settled
     * attribution order. Returns null for a genuinely org-level activity.
     *
     * @param array<string,mixed> $opts receiving_group_id (explicit, from
     *        events.group_id / causes.group_id / attribution rows) and, for the
     *        fallback, subject_id + subject_type + organization_id.
     */
    public function resolveGroupId(string $organizationId, string $subjectId, array $opts): ?string
    {
        // 1–3. Explicit receiving group (caller already resolved events.group_id /
        //      causes.group_id / an event_attendance_group_attribution row).
        $receiving = $opts['receiving_group_id'] ?? $opts['group_id'] ?? null;
        if ($receiving !== null && $receiving !== '') {
            return (string) $receiving;
        }

        // A group/team subject is its own group — no fallback needed.
        $subjectType = (string) ($opts['subject_type'] ?? 'user');
        if ($subjectType === 'group' || $subjectType === 'team') {
            return $subjectId;
        }

        // 4. Membership fallback — the member's own group. Prefer an explicit
        //    primary membership; otherwise the deepest (most specific) group the
        //    member actively belongs to. Roll-up handles its ancestors.
        $primary = $this->primaryMembershipGroup($organizationId, $subjectId);
        if ($primary !== null) {
            return $primary;
        }

        // 5. No group at all → pure org-level (counts to the individual only).
        return null;
    }

    /**
     * The member's own attribution group: an explicit primary membership if the
     * schema/records mark one, else the deepest active membership (greatest
     * groups.depth = most specific). Null when the member has no active group.
     */
    public function primaryMembershipGroup(string $organizationId, string $subjectId): ?string
    {
        $row = $this->db->table('group_members gm')
            ->select('gm.group_id')
            ->join('groups g', 'g.id = gm.group_id', 'left')
            ->where('gm.organization_id', $organizationId)
            ->where('gm.user_id', $subjectId)
            ->where('gm.status', 'active')
            // Prefer a leader/owner style membership, then the deepest node.
            ->orderBy("gm.membership_type = 'primary'", 'DESC', false)
            ->orderBy('g.depth', 'DESC')
            ->orderBy('gm.joined_at', 'ASC')
            ->get(1)
            ->getRowArray();

        return $row !== null && $row['group_id'] !== null ? (string) $row['group_id'] : null;
    }

    /**
     * Apply a signed delta to the rollup for a ledger entry's credited group and
     * every ancestor-or-self of it. Deltas may be negative (reversals). A null
     * $groupId means org-level only — nothing to roll up (individual standing is
     * still the ledger SUM).
     *
     * @param int $points        signed points delta
     * @param int $amountMinor   signed volume delta (minor units)
     * @param int $contributions signed contribution-count delta
     */
    public function applyDelta(
        string $organizationId,
        string $seasonId,
        ?string $groupId,
        ?string $categoryCode,
        ?string $projectCode,
        ?string $phase,
        int $points,
        int $amountMinor,
        int $contributions,
    ): void {
        if ($groupId === null || $groupId === '') {
            return;
        }
        if ($points === 0 && $amountMinor === 0 && $contributions === 0) {
            return;
        }

        $cat   = $categoryCode !== null && $categoryCode !== '' ? $categoryCode : self::ALL;
        $proj  = $projectCode !== null && $projectCode !== '' ? $projectCode : self::ALL;
        $phv   = $phase !== null && $phase !== '' ? $phase : self::ALL;

        // Ancestor-or-self set (distance >= 0) from the closure table.
        $ancestors = $this->ancestorsOrSelf($groupId);

        // For each ancestor group, update the fully-specific cell and every
        // combination that rolls one or more of the 3 dims to '*' (2^3 = 8 cells)
        // so all "combined" boards stay consistent in one pass.
        $cells = self::dimensionCells($cat, $proj, $phv);

        foreach ($ancestors as $gid) {
            foreach ($cells as [$c, $p, $ph]) {
                $this->upsertCell($organizationId, $seasonId, $gid, $c, $p, $ph, $points, $amountMinor, $contributions);
            }
        }
    }

    /**
     * The rollup cells a single (cat, proj, phase) tuple contributes to: each of
     * the three dimensions is independently kept specific or rolled to the '*'
     * sentinel. Up to 2^3 = 8 distinct cells, DE-DUPED when a dimension already
     * equals '*'. Pure; exposed static for unit testing the fan-out math.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public static function dimensionCells(string $cat, string $proj, string $phase): array
    {
        $cells = [];
        foreach ([$cat, self::ALL] as $c) {
            foreach ([$proj, self::ALL] as $p) {
                foreach ([$phase, self::ALL] as $ph) {
                    $cells[$c . "\x00" . $p . "\x00" . $ph] = [$c, $p, $ph];
                }
            }
        }

        return array_values($cells); // de-duped when a dim already equals '*'
    }

    /** Ancestor-or-self group ids for $groupId (self included, distance 0). */
    private function ancestorsOrSelf(string $groupId): array
    {
        $rows = $this->db->table('group_closure')
            ->select('ancestor_id')
            ->where('descendant_id', $groupId)
            ->get()->getResultArray();

        $ids = array_map(static fn ($r): string => (string) $r['ancestor_id'], $rows);

        // Defensive: closure should always include the self row (distance 0).
        if (! in_array($groupId, $ids, true)) {
            $ids[] = $groupId;
        }

        return array_values(array_unique($ids));
    }

    /** Atomic upsert of one rollup cell with the signed measure deltas. */
    private function upsertCell(
        string $organizationId,
        string $seasonId,
        string $groupId,
        string $categoryCode,
        string $projectCode,
        string $phase,
        int $points,
        int $amountMinor,
        int $contributions,
    ): void {
        $sql = 'INSERT INTO group_point_rollup
                    (organization_id, season_id, group_id, category_code, project_code, phase,
                     points, volume_minor, contributions, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    points        = points        + VALUES(points),
                    volume_minor  = volume_minor  + VALUES(volume_minor),
                    contributions = contributions + VALUES(contributions),
                    updated_at    = VALUES(updated_at)';

        $this->db->query($sql, [
            $organizationId,
            $seasonId,
            $groupId,
            $categoryCode,
            $projectCode,
            $phase,
            $points,
            $amountMinor,
            $contributions,
            $this->clock->nowUtcMicro(),
        ]);
    }

    /**
     * BACKFILL attribution columns on historical ledger rows that predate
     * migration 000045 (design doc Part B.7 step 2). Best-effort and idempotent:
     * only rows whose source event still carries the data are updated, and only
     * where the target column is still NULL/0 so re-running never overwrites a
     * value the live engine has since written.
     *
     * Currently covers CONTRIBUTION-derived rows (source_ref = 'contribution:{id}'):
     *   - group_id      ← the cause's owning group (causes.group_id)
     *   - project_code  ← the cause id (a cause is a "project" axis)
     *   - amount_minor  ← the contribution amount (for the volume measure)
     * Rows with no receiving group stay NULL (membership fallback is applied by
     * the live engine on new awards, not retroactively guessed here). Extend with
     * further source families (events, follow-ups) as those carry a group.
     *
     * @return array{contribution_group:int,contribution_amount:int}
     */
    public function backfillLedgerAttribution(string $organizationId): array
    {
        // group_id + project_code from the cause, for contribution ledger rows
        // that have no group attributed yet. Signed reversal rows share the same
        // source_ref, so they inherit the same group/project — consistent with
        // how the live engine copies attribution onto reversals.
        $groupSql = 'UPDATE point_ledger pl
             JOIN contributions c
               ON c.organization_id = pl.organization_id
              AND pl.source_ref = CONCAT("contribution:", c.id)
             JOIN causes cz
               ON cz.id = c.cause_id AND cz.organization_id = pl.organization_id
             SET pl.group_id     = cz.group_id,
                 pl.project_code = c.cause_id
             WHERE pl.organization_id = ?
               AND pl.group_id IS NULL
               AND cz.group_id IS NOT NULL';
        $this->db->query($groupSql, [$organizationId]);
        $groupRows = $this->db->affectedRows();

        // amount_minor (volume measure) from the contribution, where still zero.
        // Reversal rows keep amount_minor at 0 here (they are negative points but
        // their volume compensation is only meaningful when the original had a
        // stored amount — historical originals get it, and a subsequent rebuild
        // reflects the corrected volumes).
        $amountSql = 'UPDATE point_ledger pl
             JOIN contributions c
               ON c.organization_id = pl.organization_id
              AND pl.source_ref = CONCAT("contribution:", c.id)
             SET pl.amount_minor = c.amount_minor
             WHERE pl.organization_id = ?
               AND pl.entry_type = "award"
               AND pl.amount_minor = 0';
        $this->db->query($amountSql, [$organizationId]);
        $amountRows = $this->db->affectedRows();

        return ['contribution_group' => $groupRows, 'contribution_amount' => $amountRows];
    }

    /**
     * Recompute group_point_rollup from the ledger (+ group_closure) for one
     * season, or all seasons in the org when $seasonId is null. Truncates the
     * affected rows then re-applies every FINAL ledger entry. Used by the
     * `gamification:rebuild-rollup` command and by backfill after this migration.
     */
    public function rebuild(string $organizationId, ?string $seasonId = null): int
    {
        $del = $this->db->table('group_point_rollup')->where('organization_id', $organizationId);
        if ($seasonId !== null) {
            $del->where('season_id', $seasonId);
        }
        $del->delete();

        $q = $this->db->table('point_ledger')
            ->where('organization_id', $organizationId)
            ->where('state', 'final')
            ->where('group_id IS NOT NULL', null, false);
        if ($seasonId !== null) {
            $q->where('season_id', $seasonId);
        }

        $applied = 0;
        foreach ($q->get()->getResultArray() as $e) {
            $this->applyDelta(
                $organizationId,
                (string) $e['season_id'],
                (string) $e['group_id'],
                $e['category_code'] ?? null,
                $e['project_code'] ?? null,
                $e['phase'] ?? null,
                (int) $e['points'],
                (int) ($e['amount_minor'] ?? 0),
                1,
            );
            $applied++;
        }

        return $applied;
    }
}
