<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

use CodeIgniter\Database\BaseConnection;

/**
 * Hierarchical group-scope resolution over the materialized `group_closure`
 * table (see Groups migration 000007). This is the single, shared implementation
 * of "how a group inherits from its ancestors" so every group-scoped surface
 * (rank ladders, campaigns, activity categories, follow-up types, and the PDP's
 * RBAC scope check) resolves inheritance identically.
 *
 * Two inheritance semantics exist in the platform and both are supported here:
 *
 *   1. MOST-SPECIFIC-WINS (a single record resolved by code): the nearest scope
 *      in the chain (self, then ancestors nearest-first, then org-wide NULL)
 *      that has a matching active row wins. An ANCESTOR row only participates
 *      when it is flagged `include_descendants = 1`; the group's own row and the
 *      org-wide fallback always participate. Used by resolveByCode().
 *
 *   2. UNION (a set/taxonomy listing): a group sees its own rows, plus org-wide
 *      rows, plus ancestor rows flagged `include_descendants = 1`. Callers build
 *      that OR themselves using ancestors(); this class just supplies the ids.
 *
 * `group_closure` stores (ancestor_id, descendant_id, distance) with distance 0
 * for self. Ancestor lookups filter distance > 0 and order by distance ASC so
 * the nearest parent is considered before the grandparent.
 */
final class GroupScopeResolver
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Ancestor group ids of $groupId, nearest-first (distance ASC), excluding
     * self. Empty when the group is a root or unknown.
     *
     * @return list<string>
     */
    public function ancestors(string $groupId): array
    {
        $rows = $this->db->table('group_closure')
            ->select('ancestor_id')
            ->where('descendant_id', $groupId)
            ->where('distance >', 0)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();

        $ids = array_values(array_map(static fn ($r): string => (string) $r['ancestor_id'], $rows));

        return $this->withoutDeadGroups($ids);
    }

    /**
     * Ordered most-specific-wins candidates for a group: self first, then
     * ancestors nearest-first, then null (org-wide). Each entry is a tuple
     * [group_id|null, isSelf]. `isSelf` is true only for the group's own scope;
     * ancestors and org-wide are false so callers can require include_descendants
     * on ancestor rows.
     *
     * @return list<array{0:string|null,1:bool}>
     */
    public function chain(?string $groupId): array
    {
        if ($groupId === null || $groupId === '') {
            return [[null, false]];
        }

        $chain = [[$groupId, true]];
        foreach ($this->ancestors($groupId) as $ancestorId) {
            $chain[] = [$ancestorId, false];
        }
        $chain[] = [null, false]; // org-wide fallback

        return $chain;
    }

    /**
     * Resolve the single most-specific active row for $code within a group scope.
     *
     * Self and org-wide rows always match; an ancestor row matches only when it
     * carries `include_descendants = 1`. The first match walking self -> nearest
     * ancestor -> ... -> org-wide wins. Returns null when nothing matches.
     *
     * @param array<string,mixed> $extraWhere additional equality filters (e.g.
     *                                          ['status' => 'active'])
     * @return array<string,mixed>|null
     */
    public function resolveByCode(
        string $table,
        string $organizationId,
        string $code,
        ?string $groupId,
        array $extraWhere = ['status' => 'active'],
    ): ?array {
        foreach ($this->chain($groupId) as [$candidate, $isSelf]) {
            $q = $this->db->table($table)
                ->where('organization_id', $organizationId)
                ->where('code', $code);
            $candidate === null ? $q->where('group_id', null) : $q->where('group_id', $candidate);
            foreach ($extraWhere as $col => $val) {
                $q->where($col, $val);
            }
            // Inheriting from an ancestor requires that ancestor to opt in.
            if (! $isSelf && $candidate !== null) {
                $q->where('include_descendants', 1);
            }
            $row = $q->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }

        return null;
    }

    /**
     * True when a grant scoped to $scopeGroupId (NULL = org-wide) covers a
     * request targeting $targetGroupId. Encapsulates the RBAC scope rule so the
     * PDP and any caller agree:
     *
     *   - org-wide grant (scopeGroupId === null)      -> always covers;
     *   - exact match (scope === target)              -> covers;
     *   - ancestor grant WITH includeDescendants      -> covers a descendant;
     *   - otherwise                                    -> does not cover.
     *
     * When $targetGroupId is null the request is org-wide and ONLY an org-wide
     * grant covers it (a purely group-scoped admin cannot act org-wide).
     *
     * This is the legacy boolean signature, kept so existing callers do not
     * change; it delegates to {@see grantCoversScoped()} using the two classic
     * modes (self / self_and_descendants).
     */
    public function grantCovers(?string $scopeGroupId, bool $includeDescendants, ?string $targetGroupId): bool
    {
        $mode = $includeDescendants ? ScopeMode::SELF_AND_DESCENDANTS : ScopeMode::SELF;

        return $this->grantCoversScoped($scopeGroupId, $mode, $targetGroupId);
    }

    /**
     * Mode-aware scope coverage — the single source of truth for the leadership
     * responsibility model (self / self+descendants / descendants-only / a
     * hand-picked group set).
     *
     *   - org-wide grant (scopeGroupId === null, mode SELF) -> covers everything;
     *   - mode SELF                 -> covers only an exact match;
     *   - mode SELF_AND_DESCENDANTS -> covers self and any descendant;
     *   - mode DESCENDANTS_ONLY     -> covers descendants but NOT the group node;
     *   - mode GROUPS               -> covers iff $targetGroupId is in $groupSet.
     *
     * An org-wide action ($targetGroupId === null) is covered ONLY by the
     * org-wide grant (NULL scope, SELF); a purely group-scoped grant never
     * authorizes an org-wide action.
     *
     * @param list<string> $groupSet specific groups for mode GROUPS
     */
    public function grantCoversScoped(
        ?string $scopeGroupId,
        string $mode,
        ?string $targetGroupId,
        array $groupSet = [],
        bool $includeCrosscut = false,
    ): bool {
        // GROUPS mode is a pure set-membership test, independent of scopeGroupId.
        if ($mode === ScopeMode::GROUPS) {
            if ($targetGroupId !== null && in_array($targetGroupId, $groupSet, true)) {
                return true;
            }

            return $includeCrosscut && $this->coveredViaCrosscut($scopeGroupId, $mode, $targetGroupId, $groupSet);
        }

        // Org-wide grant: NULL scope with the plain SELF mode means "everything".
        if ($scopeGroupId === null) {
            return true;
        }
        if ($targetGroupId === null) {
            return false; // org-wide action needs an org-wide grant
        }

        if ($scopeGroupId === $targetGroupId) {
            return ScopeMode::includesSelf($mode);
        }
        if (ScopeMode::includesDescendants($mode)
            && in_array($scopeGroupId, $this->ancestors($targetGroupId), true)) {
            return true;
        }

        // Cross-cut (opt-in, down-only): a target cross-cut group is covered when
        // the scope covers ANY hierarchy node the cross-cut group is linked to.
        return $includeCrosscut && $this->coveredViaCrosscut($scopeGroupId, $mode, $targetGroupId, $groupSet);
    }

    /**
     * Cross-cut coverage test (down-only): true when $targetGroupId is a
     * cross-cut group linked to at least one hierarchy node that the scope
     * already covers (by the ordinary hierarchy rule — crosscut never chains to
     * further crosscut). If $targetGroupId is not a cross-cut group this is false.
     *
     * @param list<string> $groupSet
     */
    private function coveredViaCrosscut(
        ?string $scopeGroupId,
        string $mode,
        ?string $targetGroupId,
        array $groupSet,
    ): bool {
        if ($targetGroupId === null) {
            return false;
        }
        foreach ($this->crosscutHierarchyNodes($targetGroupId) as $hierarchyNode) {
            if ($this->grantCoversScoped($scopeGroupId, $mode, $hierarchyNode, $groupSet, false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hierarchy nodes a cross-cut group is linked to (the branches/cells it
     * spans). Empty when the group is not cross-cutting.
     *
     * @return list<string>
     */
    public function crosscutHierarchyNodes(string $crosscutGroupId): array
    {
        if (! $this->db->tableExists('group_crosscut_links')) {
            return [];
        }
        $rows = $this->db->table('group_crosscut_links')
            ->select('hierarchy_group_id')
            ->where('crosscut_group_id', $crosscutGroupId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['hierarchy_group_id'], $rows));
    }

    /**
     * Cross-cut groups attached to any of the given hierarchy nodes. Used to
     * expand a scope into the cross-cut groups it reaches (diagnostics /
     * resolveScopeGroups with $includeCrosscut).
     *
     * @param list<string> $hierarchyGroupIds
     * @return list<string>
     */
    public function crosscutGroupsUnder(array $hierarchyGroupIds): array
    {
        if ($hierarchyGroupIds === [] || ! $this->db->tableExists('group_crosscut_links')) {
            return [];
        }
        $rows = $this->db->table('group_crosscut_links')
            ->select('crosscut_group_id')
            ->whereIn('hierarchy_group_id', $hierarchyGroupIds)
            ->get()->getResultArray();

        return array_values(array_unique(
            array_map(static fn ($r): string => (string) $r['crosscut_group_id'], $rows),
        ));
    }

    /**
     * Delegated-administration containment: can an issuer whose OWN management
     * grant is (issuerScope, issuerMode, issuerSet) hand out a responsibility
     * scoped to (targetScope, targetMode, targetSet)? True only when EVERY group
     * the target scope would cover is also covered by the issuer's scope — a
     * leader can never grant broader than they themselves hold.
     *
     * We test containment by checking the target's representative groups against
     * the issuer's coverage:
     *   - org-wide target requires an org-wide issuer;
     *   - GROUPS target: each group in the set must be issuer-covered;
     *   - self / *_descendants target: the target node (for self-inclusive modes)
     *     and — for descendant-inclusive modes — every descendant of the target
     *     node must be issuer-covered.
     *
     * @param list<string> $issuerSet
     * @param list<string> $targetSet
     */
    public function scopeContains(
        ?string $issuerScope,
        string $issuerMode,
        array $issuerSet,
        ?string $targetScope,
        string $targetMode,
        array $targetSet,
        bool $issuerCrosscut = false,
        bool $targetCrosscut = false,
    ): bool {
        // An org-wide issuer contains any target.
        if ($issuerScope === null && $issuerMode === ScopeMode::SELF && $issuerSet === []) {
            return true;
        }

        // Collect the groups the target scope resolves to, then require the
        // issuer to cover each. Descendant-inclusive target modes are expanded
        // over the closure so "self+descendants" can't smuggle in groups the
        // issuer doesn't hold. When the target opts into cross-cut, its reachable
        // cross-cut groups are added to the set the issuer must also cover — so a
        // leader can only delegate cross-cut reach they themselves hold.
        $targets = $this->resolveScopeGroups($targetScope, $targetMode, $targetSet, $targetCrosscut);
        if ($targets === null) {
            // target is org-wide -> only an org-wide issuer (handled above) contains it
            return false;
        }
        if ($targets === []) {
            return false;
        }

        foreach ($targets as $g) {
            if (! $this->grantCoversScoped($issuerScope, $issuerMode, $g, $issuerSet, $issuerCrosscut)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Expand a scope into the concrete set of group ids it covers. Returns null
     * for an org-wide scope (unbounded), or a list of group ids otherwise.
     *
     * @param list<string> $groupSet
     * @return list<string>|null
     */
    public function resolveScopeGroups(
        ?string $scopeGroupId,
        string $mode,
        array $groupSet = [],
        bool $includeCrosscut = false,
    ): ?array {
        if ($mode === ScopeMode::GROUPS) {
            $out = array_values(array_unique($groupSet));
            if ($includeCrosscut) {
                $out = array_values(array_unique([...$out, ...$this->crosscutGroupsUnder($out)]));
            }

            return $out;
        }
        if ($scopeGroupId === null) {
            return null; // org-wide
        }

        $out = [];
        if (ScopeMode::includesSelf($mode)) {
            $out[] = $scopeGroupId;
        }
        if (ScopeMode::includesDescendants($mode)) {
            foreach ($this->descendants($scopeGroupId) as $d) {
                $out[] = $d;
            }
        }
        $out = array_values(array_unique($out));

        // Cross-cut (opt-in, down-only): add the cross-cut groups attached to any
        // hierarchy node already in the resolved set.
        if ($includeCrosscut) {
            $out = array_values(array_unique([...$out, ...$this->crosscutGroupsUnder($out)]));
        }

        return $out;
    }

    /**
     * Descendant group ids of $groupId (distance > 0), excluding self.
     *
     * @return list<string>
     */
    public function descendants(string $groupId): array
    {
        $rows = $this->db->table('group_closure')
            ->select('descendant_id')
            ->where('ancestor_id', $groupId)
            ->where('distance >', 0)
            ->get()->getResultArray();

        $ids = array_values(array_map(static fn ($r): string => (string) $r['descendant_id'], $rows));

        return $this->withoutDeadGroups($ids);
    }

    /**
     * GR3 — drop TERMINAL (dissolved / merged) and ARCHIVED groups from a set of
     * ids so no group-scoped surface inherits from, or cascades into, a dead or
     * hidden node. GR2 already prunes terminal nodes from `group_closure` at the
     * source, so this is primarily belt-and-suspenders for closure rows written
     * before GR2 shipped AND the live enforcement of the reversible `archived`
     * state (which is deliberately kept in the closure so reactivation is cheap).
     *
     * FAIL-OPEN by construction: it only removes ids it can positively prove are
     * dead/archived from the `groups` table. When the `groups` table is empty or
     * absent (e.g. a resolver unit test that seeds only `group_closure`), the
     * lookup returns no dead ids and the input set passes through unchanged, so
     * existing callers/tests are unaffected.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function withoutDeadGroups(array $ids): array
    {
        if ($ids === []) {
            return $ids;
        }

        $dead = $this->db->table('groups')
            ->select('id')
            ->whereIn('id', $ids)
            ->whereIn('status', ['dissolved', 'merged', 'archived'])
            ->get()->getResultArray();

        if ($dead === []) {
            return array_values($ids);
        }

        $deadIds = [];
        foreach ($dead as $r) {
            $deadIds[(string) $r['id']] = true;
        }

        return array_values(array_filter($ids, static fn (string $id): bool => ! isset($deadIds[$id])));
    }

    /**
     * The member's own "primary/highest" group: an explicit primary membership if
     * one is marked, else the deepest (most specific) active membership. Null when
     * the member has no active group. Mirrors the gamification attribution
     * membership-fallback (RollupService::primaryMembershipGroup) so a member's
     * effective group is resolved identically wherever it is needed — kept here in
     * Shared so any module can use it without a cross-module dependency.
     */
    public function primaryMembershipGroup(string $organizationId, string $userId): ?string
    {
        $row = $this->db->table('group_members gm')
            ->select('gm.group_id')
            ->join('groups g', 'g.id = gm.group_id', 'left')
            ->where('gm.organization_id', $organizationId)
            ->where('gm.user_id', $userId)
            ->where('gm.status', 'active')
            ->orderBy("gm.membership_type = 'primary'", 'DESC', false)
            ->orderBy('g.depth', 'DESC')
            ->orderBy('gm.joined_at', 'ASC')
            ->get(1)
            ->getRowArray();

        return $row !== null && $row['group_id'] !== null ? (string) $row['group_id'] : null;
    }
}
