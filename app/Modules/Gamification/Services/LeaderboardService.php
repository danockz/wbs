<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * Leaderboards over the immutable ledger and season snapshots (adaptation of
 * awardlib.md getLeaderboard).
 *
 * Privacy-safe by construction: rows expose subject_id + display_name + points
 * (+ rank + achievement count) ONLY — never email (the source spec's raw SQL
 * leaked full_name AND email; that is rejected here). Display name is resolved
 * via a LEFT JOIN to users and degrades to the id when absent.
 *
 * Current-season standings come from point_ledger (final entries); past seasons
 * read the frozen season_balance_snapshots.
 */
final class LeaderboardService
{
    /** Sentinel for the "all-combined" cell of a rollup dimension. */
    private const ALL = '*';

    /** Ranking measures → the rollup column / ledger expression backing them. */
    private const MEASURES = ['points', 'volume', 'contributions'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly SeasonService $seasons,
        private readonly RankService $ranks,
    ) {
    }

    /**
     * Top subjects for a season (default: current), highest points first.
     *
     * @param array{subject_type?:string,season_id?:string} $filters
     */
    public function top(string $organizationId, int $limit = 10, array $filters = []): Result
    {
        $limit       = max(1, min($limit, 100));
        $subjectType = (string) ($filters['subject_type'] ?? 'user');

        $seasonId = $filters['season_id'] ?? ($this->seasons->activeSeason($organizationId)['id'] ?? null);
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'entries' => []]);
        }

        // Aggregate final points per subject for the season.
        $rows = $this->db->query(
            'SELECT subject_id, SUM(points) AS total_points, COUNT(*) AS entries
             FROM point_ledger
             WHERE organization_id = ? AND season_id = ? AND subject_type = ? AND state = "final"
             GROUP BY subject_id
             HAVING total_points > 0
             ORDER BY total_points DESC
             LIMIT ' . $limit,
            [$organizationId, $seasonId, $subjectType],
        )->getResultArray();

        $entries = $this->decorate($organizationId, $rows, $subjectType);

        return Result::ok([
            'season_id'    => $seasonId,
            'subject_type' => $subjectType,
            'entries'      => $entries,
        ]);
    }

    /**
     * Subject's own standing (points + position) in the current/given season.
     */
    public function standing(string $organizationId, string $subjectId, ?string $seasonId = null, string $subjectType = 'user'): Result
    {
        $seasonId ??= $this->seasons->activeSeason($organizationId)['id'] ?? null;
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'points' => 0, 'position' => null]);
        }

        $points = (int) ($this->db->query(
            'SELECT COALESCE(SUM(points),0) AS p FROM point_ledger
             WHERE organization_id = ? AND season_id = ? AND subject_id = ? AND state = "final"',
            [$organizationId, $seasonId, $subjectId],
        )->getRowArray()['p'] ?? 0);

        // Position = 1 + number of subjects strictly ahead.
        $ahead = (int) ($this->db->query(
            'SELECT COUNT(*) AS n FROM (
                SELECT subject_id, SUM(points) AS tp FROM point_ledger
                WHERE organization_id = ? AND season_id = ? AND subject_type = ? AND state = "final"
                GROUP BY subject_id HAVING tp > ?
             ) AS ahead',
            [$organizationId, $seasonId, $subjectType, $points],
        )->getRowArray()['n'] ?? 0);

        return Result::ok([
            'season_id' => $seasonId,
            'points'    => $points,
            'position'  => $points > 0 ? $ahead + 1 : null,
            'rank'      => $this->ranks->determineRank($organizationId, $points)['code'] ?? null,
        ]);
    }

    /**
     * GROUP board (design doc Part B.5). Ranks groups by their rolled-up subtree
     * total in the chosen measure, read from group_point_rollup. Two modes:
     *   - $parentGroupId given → ranks that node's DIRECT CHILDREN against each
     *     other (each child's number is its own subtree sum) — "ranked at the
     *     parent's level".
     *   - $parentGroupId null → ranks every group in the org.
     * The measure axis is configurable; the cross-cut axes are held at '*'
     * (all categories/projects/phases) unless a specific value is requested.
     *
     * @param array{season_id?:string,measure?:string,category?:string,project?:string,phase?:string} $filters
     */
    public function groups(string $organizationId, int $limit = 10, ?string $parentGroupId = null, array $filters = []): Result
    {
        $limit    = max(1, min($limit, 100));
        $seasonId = $filters['season_id'] ?? ($this->seasons->activeSeason($organizationId)['id'] ?? null);
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'entries' => []]);
        }

        $measure = $this->measureColumn($filters['measure'] ?? 'points');
        [$cat, $proj, $phase] = $this->dimensionFilters($filters);

        // Direct children only (closure distance 1) when a parent is given; the
        // JOIN's bound param is emitted BEFORE the WHERE params, so build the
        // parameter list in statement order.
        $hasParent = $parentGroupId !== null && $parentGroupId !== '';
        $childJoin = $hasParent
            ? 'JOIN group_closure gc ON gc.descendant_id = r.group_id AND gc.ancestor_id = ? AND gc.distance = 1'
            : '';
        $params = $hasParent
            ? [$parentGroupId, $organizationId, $seasonId, $cat, $proj, $phase]
            : [$organizationId, $seasonId, $cat, $proj, $phase];

        $rows = $this->db->query(
            'SELECT r.group_id, r.' . $measure . ' AS measure_value
             FROM group_point_rollup r ' . $childJoin . '
             WHERE r.organization_id = ? AND r.season_id = ?
               AND r.category_code = ? AND r.project_code = ? AND r.phase = ?
               AND r.' . $measure . ' > 0
             ORDER BY r.' . $measure . ' DESC
             LIMIT ' . $limit,
            $params,
        )->getResultArray();

        return Result::ok([
            'season_id'  => $seasonId,
            'dimension'  => 'groups',
            'parent'     => $parentGroupId,
            'measure'    => $filters['measure'] ?? 'points',
            'category'   => $cat === self::ALL ? null : $cat,
            'project'    => $proj === self::ALL ? null : $proj,
            'phase'      => $phase === self::ALL ? null : $phase,
            'entries'    => $this->decorateGroups($organizationId, $rows),
        ]);
    }

    /**
     * A single group's own standing (subtree total + position among siblings) in
     * the chosen measure. Position is 1 + siblings strictly ahead under the same
     * parent; when the group is a root (no parent), position is org-wide.
     *
     * @param array{season_id?:string,measure?:string,category?:string,project?:string,phase?:string} $filters
     */
    public function groupStanding(string $organizationId, string $groupId, array $filters = []): Result
    {
        $seasonId = $filters['season_id'] ?? ($this->seasons->activeSeason($organizationId)['id'] ?? null);
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'measure_value' => 0, 'position' => null]);
        }
        $measure = $this->measureColumn($filters['measure'] ?? 'points');
        [$cat, $proj, $phase] = $this->dimensionFilters($filters);

        $value = (int) ($this->db->query(
            'SELECT COALESCE(' . $measure . ',0) AS v FROM group_point_rollup
             WHERE organization_id = ? AND season_id = ? AND group_id = ?
               AND category_code = ? AND project_code = ? AND phase = ?',
            [$organizationId, $seasonId, $groupId, $cat, $proj, $phase],
        )->getRowArray()['v'] ?? 0);

        // Siblings = groups sharing this group's parent (closure distance 1).
        $ahead = (int) ($this->db->query(
            'SELECT COUNT(*) AS n FROM group_point_rollup r
             JOIN group_closure me   ON me.descendant_id = ?   AND me.distance = 1
             JOIN group_closure sib  ON sib.ancestor_id  = me.ancestor_id AND sib.distance = 1 AND sib.descendant_id = r.group_id
             WHERE r.organization_id = ? AND r.season_id = ?
               AND r.category_code = ? AND r.project_code = ? AND r.phase = ?
               AND r.' . $measure . ' > ?',
            [$groupId, $organizationId, $seasonId, $cat, $proj, $phase, $value],
        )->getRowArray()['n'] ?? 0);

        return Result::ok([
            'season_id'     => $seasonId,
            'group_id'      => $groupId,
            'measure'       => $filters['measure'] ?? 'points',
            'measure_value' => $value,
            'position'      => $value > 0 ? $ahead + 1 : null,
        ]);
    }

    /**
     * INDIVIDUAL board, optionally scoped to one group. When $withinGroup is set,
     * only ledger entries credited to that group (or, with $includeSubtree, its
     * whole subtree) count — this is "individual-within-group" ranking. Without
     * it, behaves like top() (org-wide). Measure is configurable.
     *
     * @param array{season_id?:string,measure?:string,category?:string,project?:string,phase?:string,include_subtree?:bool} $filters
     */
    public function individuals(string $organizationId, int $limit = 10, ?string $withinGroup = null, array $filters = []): Result
    {
        $limit    = max(1, min($limit, 100));
        $seasonId = $filters['season_id'] ?? ($this->seasons->activeSeason($organizationId)['id'] ?? null);
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'entries' => []]);
        }

        $agg = $this->ledgerMeasureExpr($filters['measure'] ?? 'points');
        $where  = 'organization_id = ? AND season_id = ? AND subject_type = "user" AND state = "final"';
        $params = [$organizationId, $seasonId];

        $where  = $this->applyLedgerDimensionFilters($where, $params, $filters);
        $where  = $this->applyLedgerGroupScope($where, $params, $withinGroup, ! empty($filters['include_subtree']));

        $rows = $this->db->query(
            'SELECT subject_id, ' . $agg . ' AS total_points, COUNT(*) AS entries
             FROM point_ledger
             WHERE ' . $where . '
             GROUP BY subject_id
             HAVING total_points > 0
             ORDER BY total_points DESC
             LIMIT ' . $limit,
            $params,
        )->getResultArray();

        return Result::ok([
            'season_id'    => $seasonId,
            'subject_type' => 'user',
            'within_group' => $withinGroup,
            'measure'      => $filters['measure'] ?? 'points',
            'entries'      => $this->decorate($organizationId, $rows, 'user'),
        ]);
    }

    /**
     * Cross-cutting board over ONE axis value — activity category, project, or
     * phase — ranking GROUPS by the chosen measure (the other two axes are held
     * at '*'). $axis is one of 'category'|'project'|'phase'.
     *
     * @param array{season_id?:string,measure?:string} $filters
     */
    public function byDimension(string $organizationId, string $axis, string $value, int $limit = 10, array $filters = []): Result
    {
        $map = ['category' => 'category', 'project' => 'project', 'phase' => 'phase'];
        if (! isset($map[$axis]) || $value === '') {
            return Result::fail('BAD_DIMENSION', 'gamification.bad_dimension', 422, [
                'allowed' => array_keys($map),
            ]);
        }
        // Reuse the group board with the single axis pinned to $value (the other
        // two axes stay at '*'). The returned payload already echoes the axis
        // values under category/project/phase.
        return $this->groups($organizationId, $limit, null, $filters + [$axis => $value]);
    }

    /**
     * DEPARTMENT / TEAM board (design doc Part B.3.3 / B.5). Departments and ad
     * hoc teams are MEMBERSHIPS, not tree nodes — so this is a set aggregation,
     * not an ancestor roll-up: rank each group that has active members of the
     * given membership `$type` by the SUM of those members' final ledger totals
     * in the chosen measure (points | volume | contributions) for the season.
     *
     * A member counted here contributes their WHOLE individual total (org-wide),
     * which is the natural reading of "how is this department doing" — it is
     * deliberately distinct from group_point_rollup (which credits by where the
     * contribution went). $type is validated against the platform's membership
     * type vocabulary.
     *
     * @param array{season_id?:string,measure?:string} $filters
     */
    public function membershipBoard(string $organizationId, string $type, int $limit = 10, array $filters = []): Result
    {
        $allowed = ['department', 'team', 'activity', 'leader', 'member', 'guest'];
        if (! in_array($type, $allowed, true)) {
            return Result::fail('BAD_MEMBERSHIP_TYPE', 'gamification.bad_membership_type', 422, ['allowed' => $allowed]);
        }

        $limit    = max(1, min($limit, 100));
        $seasonId = $filters['season_id'] ?? ($this->seasons->activeSeason($organizationId)['id'] ?? null);
        if ($seasonId === null) {
            return Result::ok(['season_id' => null, 'entries' => []]);
        }
        $agg = $this->ledgerMeasureExpr($filters['measure'] ?? 'points');

        // Members of each group (of this membership type) joined to their own
        // FINAL ledger totals for the season. One member's total counts once per
        // group they hold this membership type in.
        $rows = $this->db->query(
            'SELECT gm.group_id,
                    ' . $agg . ' AS measure_value,
                    COUNT(DISTINCT gm.user_id) AS members
             FROM group_members gm
             JOIN point_ledger pl
               ON pl.organization_id = gm.organization_id
              AND pl.subject_id      = gm.user_id
              AND pl.subject_type    = "user"
              AND pl.state           = "final"
              AND pl.season_id       = ?
             WHERE gm.organization_id = ? AND gm.membership_type = ? AND gm.status = "active"
             GROUP BY gm.group_id
             HAVING measure_value > 0
             ORDER BY measure_value DESC
             LIMIT ' . $limit,
            [$seasonId, $organizationId, $type],
        )->getResultArray();

        return Result::ok([
            'season_id'       => $seasonId,
            'dimension'       => 'membership',
            'membership_type' => $type,
            'measure'         => $filters['measure'] ?? 'points',
            'entries'         => $this->decorateGroups($organizationId, $rows),
        ]);
    }

    // ---- Measure / dimension helpers ---------------------------------------

    /** Map a public measure name to its group_point_rollup column. */
    private function measureColumn(string $measure): string
    {
        return match ($measure) {
            'volume', 'amount' => 'volume_minor',
            'contributions', 'count' => 'contributions',
            default => 'points',
        };
    }

    /** Map a public measure name to a SUM() expression over point_ledger. */
    private function ledgerMeasureExpr(string $measure): string
    {
        return match ($measure) {
            'volume', 'amount' => 'SUM(amount_minor)',
            'contributions', 'count' => 'COUNT(*)',
            default => 'SUM(points)',
        };
    }

    /**
     * Normalise the three cross-cut axes to their rollup values, defaulting each
     * to the '*' (all-combined) sentinel.
     *
     * @param array{category?:string,project?:string,phase?:string} $filters
     * @return array{0:string,1:string,2:string}
     */
    private function dimensionFilters(array $filters): array
    {
        $norm = static fn (?string $v): string => $v !== null && $v !== '' ? $v : self::ALL;

        return [$norm($filters['category'] ?? null), $norm($filters['project'] ?? null), $norm($filters['phase'] ?? null)];
    }

    /**
     * Append category/project/phase filters to a point_ledger WHERE (individuals
     * board). A null/absent axis means "all" (no filter). Mutates $params.
     *
     * @param array{category?:string,project?:string,phase?:string} $filters
     */
    private function applyLedgerDimensionFilters(string $where, array &$params, array $filters): string
    {
        foreach (['category' => 'category_code', 'project' => 'project_code', 'phase' => 'phase'] as $key => $col) {
            $v = $filters[$key] ?? null;
            if ($v !== null && $v !== '') {
                $where   .= ' AND ' . $col . ' = ?';
                $params[] = $v;
            }
        }

        return $where;
    }

    /**
     * Restrict a point_ledger WHERE to a group (exact) or its subtree (via
     * group_closure). Mutates $params. No-op when $groupId is null.
     */
    private function applyLedgerGroupScope(string $where, array &$params, ?string $groupId, bool $includeSubtree): string
    {
        if ($groupId === null || $groupId === '') {
            return $where;
        }
        if ($includeSubtree) {
            $where   .= ' AND group_id IN (SELECT descendant_id FROM group_closure WHERE ancestor_id = ?)';
            $params[] = $groupId;
        } else {
            $where   .= ' AND group_id = ?';
            $params[] = $groupId;
        }

        return $where;
    }

    /**
     * Decorate group rollup rows with name + position (no PII; groups have no
     * email). Names via a single IN() lookup, degrading to the id.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decorateGroups(string $organizationId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids   = array_column($rows, 'group_id');
        $names = [];
        $nameRows = $this->db->table('groups')
            ->select('id, name, depth, parent_id')
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)->get()->getResultArray();
        foreach ($nameRows as $g) {
            $names[(string) $g['id']] = $g;
        }

        $out      = [];
        $position = 0;
        foreach ($rows as $r) {
            $position++;
            $gid   = (string) $r['group_id'];
            $meta  = $names[$gid] ?? [];
            $out[] = [
                'position'      => $position,
                'group_id'      => $gid,
                'name'          => $meta['name'] ?? null,
                'depth'         => isset($meta['depth']) ? (int) $meta['depth'] : null,
                'parent_id'     => $meta['parent_id'] ?? null,
                'measure_value' => (int) $r['measure_value'],
            ];
        }

        return $out;
    }

    /**
     * Decorate aggregate rows with display name, position, rank, achievements.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decorate(string $organizationId, array $rows, string $subjectType): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'subject_id');

        // Names ONLY (never email). Users only; groups fall back to id.
        $names = [];
        if ($subjectType === 'user') {
            $nameRows = $this->db->table('users')
                ->select('id, display_name')
                ->whereIn('id', $ids)->get()->getResultArray();
            foreach ($nameRows as $n) {
                $names[(string) $n['id']] = $n['display_name'] ?? null;
            }
        }

        // Achievement counts in one query.
        $achCounts = [];
        $achRows   = $this->db->query(
            'SELECT subject_id, COUNT(*) AS n FROM user_achievements
             WHERE organization_id = ? AND subject_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             GROUP BY subject_id',
            array_merge([$organizationId], $ids),
        )->getResultArray();
        foreach ($achRows as $a) {
            $achCounts[(string) $a['subject_id']] = (int) $a['n'];
        }

        $out      = [];
        $position = 0;
        foreach ($rows as $r) {
            $position++;
            $sid    = (string) $r['subject_id'];
            $points = (int) $r['total_points'];
            $out[]  = [
                'position'          => $position,
                'subject_id'        => $sid,
                'display_name'      => $names[$sid] ?? null, // null -> client shows id/anonymous
                'points'            => $points,
                'rank'              => $this->ranks->determineRank($organizationId, $points)['code'] ?? null,
                'achievement_count' => $achCounts[$sid] ?? 0,
            ];
        }

        return $out;
    }
}
