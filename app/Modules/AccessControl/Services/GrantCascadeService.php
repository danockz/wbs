<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * Grant-teardown cascade (Theme B consumer — finding AC3).
 *
 * When a person is torn down (account deactivated / suspended / anonymized /
 * merged away), their access must not keep resolving. This is the ACL half of the
 * lifecycle-signal fan-out: the Identity module emits the teardown event once
 * (ID1), and this service — invoked by the JobRouter — cascade-revokes everything
 * that grants the subject authority:
 *
 *   - active `role_assignments` for the subject,
 *   - active `delegations` the subject RECEIVED (delegate_id) AND everything
 *     sub-delegated below each (a child cannot outlive its parent),
 *   - active `delegations` the subject GRANTED (delegator_id) and their subtrees
 *     (the grantor is gone, so the loan is void),
 *   - active `break_glass_sessions` for the subject (flipped to expired, left
 *     pending their mandatory post-use review).
 *
 * This is a SYSTEM-authority teardown, not a maker-checker action: the account is
 * already gone, so there is no human approver and no scope check — exactly like
 * the `expireLapsed` housekeeping pass. All writes are audited with
 * actor_type=system. Idempotent: only `active` rows are touched, so a redelivered
 * teardown event is a no-op.
 *
 * MERGE POLICY (ID2 alignment): a merge cascade-revokes the LOSER's grants; it
 * does NOT copy them to the survivor. Authority is re-established for the survivor
 * through the authorized grant path, never inherited by a blind re-point — that
 * would launder authority onto a different identity.
 */
final class GrantCascadeService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Cascade-revoke all authority for a torn-down subject.
     *
     * @param string $reasonCode e.g. account.deactivated | account.suspended |
     *                           account.anonymized | account.merged
     * @return Result data: revoked_assignments, revoked_delegations,
     *                closed_break_glass
     */
    public function onAccountTornDown(string $organizationId, string $subjectId, string $reasonCode): Result
    {
        if ($organizationId === '' || $subjectId === '') {
            return Result::fail('CASCADE_BAD_INPUT', 'acl.cascade_bad_input', 422);
        }

        $now = $this->clock->nowUtcString();

        // 1) Role assignments held by the subject.
        $revokedAssignments = $this->revokeActive('role_assignments', 'subject_id', $subjectId, $organizationId, [
            'status'     => 'revoked',
            'revoked_at' => $now,
        ]);

        // 2) Delegations the subject received or granted — plus their subtrees.
        $delegationRoots = $this->activeDelegationIdsFor($organizationId, $subjectId);
        $delegationIds   = $this->expandDelegationSubtrees($delegationRoots);
        $revokedDelegations = 0;
        if ($delegationIds !== []) {
            $this->db->table('delegations')
                ->whereIn('id', $delegationIds)
                ->where('status', 'active')
                ->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => null]);
            $revokedDelegations = $this->db->affectedRows();
        }

        // 3) Break-glass sessions for the subject -> expired (review still pending).
        $closedBreakGlass = $this->revokeActive('break_glass_sessions', 'subject_id', $subjectId, $organizationId, [
            'status'    => 'expired',
            'closed_at' => $this->clock->nowUtcMicro(),
        ]);

        $this->audit->record($organizationId, [
            'action'      => 'acl.grant.cascade_teardown',
            'actor_id'    => null,
            'actor_type'  => 'system',
            'object_type' => 'user',
            'object_id'   => $subjectId,
            'outcome'     => 'success',
            'metadata'    => [
                'reason'               => $reasonCode,
                'auto'                 => true,
                'revoked_assignments'  => $revokedAssignments,
                'revoked_delegations'  => $revokedDelegations,
                'closed_break_glass'   => $closedBreakGlass,
            ],
        ]);

        return Result::ok([
            'revoked_assignments' => $revokedAssignments,
            'revoked_delegations' => $revokedDelegations,
            'closed_break_glass'  => $closedBreakGlass,
        ]);
    }

    /**
     * Group teardown cascade (Theme B group-half — finding AC9).
     *
     * When a group is torn down (dissolved / merged away), authority SCOPED TO
     * THAT GROUP must not keep resolving — the node is gone, so a grant that only
     * meant something inside it is void. Invoked by the JobRouter on
     * `group.dissolved` / `group.merged`. Two cuts, both SYSTEM-authority and
     * idempotent:
     *
     *   1. EXACT-SCOPE revoke — every active grant whose `scope_group_id` is the
     *      dead group:
     *        - `role_assignments`         -> revoked,
     *        - `delegations` (+ subtrees) -> revoked (a child cannot outlive the
     *          scope its parent was bound to),
     *        - `break_glass_sessions`     -> expired (review still pending),
     *        - `access_requests` that are `pending` or `approved` -> revoked
     *          (no point approving/keeping a grant into a group that no longer
     *          exists).
     *      An ANCESTOR grant scoped `self_and_descendants` is deliberately LEFT
     *      ALONE: dissolve is leaf-only and merge re-parents children under the
     *      survivor, so the ancestor's scope still covers live groups.
     *
     *   2. MULTI-GROUP SET prune — remove the dead group from every hand-picked
     *      `grant_scope_groups` set (RuBAC `scope_mode = 'groups'`). A grant that
     *      still names other live groups keeps working; a `groups`-mode
     *      `role_assignment`/`delegation` whose set becomes EMPTY is revoked
     *      (it now covers nothing).
     *
     * MERGE POLICY (mirrors AC3/ID2): a merge REVOKES the dead group's scoped
     * authority; it never copies it to the survivor. Authority over the survivor
     * is re-established through the authorized grant path, never inherited.
     *
     * @return Result data: revoked_assignments, revoked_delegations,
     *                closed_break_glass, revoked_requests, pruned_set_rows,
     *                revoked_empty_set
     */
    public function onGroupTornDown(string $organizationId, string $groupId, string $reasonCode): Result
    {
        if ($organizationId === '' || $groupId === '') {
            return Result::fail('CASCADE_BAD_INPUT', 'acl.cascade_bad_input', 422);
        }

        $now = $this->clock->nowUtcString();

        // 1) Exact-scope role assignments.
        $revokedAssignments = $this->revokeActive('role_assignments', 'scope_group_id', $groupId, $organizationId, [
            'status'     => 'revoked',
            'revoked_at' => $now,
        ]);

        // 2) Exact-scope delegations + their sub-delegation subtrees.
        $delegationRoots = $this->activeScopedDelegationIds($organizationId, $groupId);
        $delegationIds   = $this->expandDelegationSubtrees($delegationRoots);
        $revokedDelegations = 0;
        if ($delegationIds !== []) {
            $this->db->table('delegations')
                ->whereIn('id', $delegationIds)
                ->where('status', 'active')
                ->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => null]);
            $revokedDelegations = max(0, (int) $this->db->affectedRows());
        }

        // 3) Exact-scope break-glass sessions -> expired.
        $closedBreakGlass = $this->revokeActive('break_glass_sessions', 'scope_group_id', $groupId, $organizationId, [
            'status'    => 'expired',
            'closed_at' => $this->clock->nowUtcMicro(),
        ]);

        // 4) Exact-scope access requests that are still live (pending/approved).
        $this->db->table('access_requests')
            ->where('organization_id', $organizationId)
            ->where('scope_group_id', $groupId)
            ->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'revoked', 'updated_at' => $this->clock->nowUtcMicro()]);
        $revokedRequests = max(0, (int) $this->db->affectedRows());

        // 5) Multi-group set prune + empty-set revoke.
        [$prunedSetRows, $revokedEmptySet] = $this->pruneMultiGroupSets($organizationId, $groupId, $now);

        $this->audit->record($organizationId, [
            'action'      => 'acl.grant.group_cascade_teardown',
            'actor_id'    => null,
            'actor_type'  => 'system',
            'object_type' => 'group',
            'object_id'   => $groupId,
            'outcome'     => 'success',
            'metadata'    => [
                'reason'               => $reasonCode,
                'auto'                 => true,
                'revoked_assignments'  => $revokedAssignments,
                'revoked_delegations'  => $revokedDelegations,
                'closed_break_glass'   => $closedBreakGlass,
                'revoked_requests'     => $revokedRequests,
                'pruned_set_rows'      => $prunedSetRows,
                'revoked_empty_set'    => $revokedEmptySet,
            ],
        ]);

        return Result::ok([
            'revoked_assignments' => $revokedAssignments,
            'revoked_delegations' => $revokedDelegations,
            'closed_break_glass'  => $closedBreakGlass,
            'revoked_requests'    => $revokedRequests,
            'pruned_set_rows'     => $prunedSetRows,
            'revoked_empty_set'   => $revokedEmptySet,
        ]);
    }

    /**
     * Active delegation ids scoped EXACTLY to the dead group (delegation roots to
     * expand into subtrees).
     *
     * @return list<string>
     */
    private function activeScopedDelegationIds(string $organizationId, string $groupId): array
    {
        $rows = $this->db->table('delegations')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('scope_group_id', $groupId)
            ->where('status', 'active')
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['id'], $rows));
    }

    /**
     * Remove the dead group from every hand-picked multi-group set, then revoke
     * any `groups`-mode role_assignment / delegation whose set is now empty.
     *
     * @return array{0:int,1:int} [pruned set rows, revoked empty-set grants]
     */
    private function pruneMultiGroupSets(string $organizationId, string $groupId, string $now): array
    {
        if (! $this->db->tableExists('grant_scope_groups')) {
            return [0, 0];
        }

        // The grants that named this group in their set (before we prune it).
        $affected = $this->db->table('grant_scope_groups')
            ->select('grant_type, grant_id')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->get()->getResultArray();

        // Prune the dead group from every set.
        $this->db->table('grant_scope_groups')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->delete();
        $prunedSetRows = max(0, (int) $this->db->affectedRows());

        // Revoke any groups-mode role_assignment / delegation whose set is now
        // empty (it covers nothing). Other grant types (break_glass short-lived,
        // access_requests transient) are left to their own lifecycles.
        $revokedEmptySet = 0;
        $tableFor = ['role_assignment' => 'role_assignments', 'delegation' => 'delegations'];
        foreach ($affected as $a) {
            $grantType = (string) $a['grant_type'];
            $grantId   = (string) $a['grant_id'];
            $table     = $tableFor[$grantType] ?? null;
            if ($table === null) {
                continue;
            }
            $remaining = $this->db->table('grant_scope_groups')
                ->where('grant_type', $grantType)
                ->where('grant_id', $grantId)
                ->countAllResults();
            if ($remaining > 0) {
                continue; // still covers other live groups
            }
            $set = $table === 'delegations'
                ? ['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => null]
                : ['status' => 'revoked', 'revoked_at' => $now];
            $this->db->table($table)
                ->where('id', $grantId)
                ->where('scope_mode', 'groups')
                ->where('status', 'active')
                ->update($set);
            $revokedEmptySet += max(0, (int) $this->db->affectedRows());
        }

        return [$prunedSetRows, $revokedEmptySet];
    }

    /**
     * Update every active row matching one column == value; return rows affected.
     *
     * @param array<string,mixed> $set
     */
    private function revokeActive(string $table, string $column, string $value, string $organizationId, array $set): int
    {
        $this->db->table($table)
            ->where('organization_id', $organizationId)
            ->where($column, $value)
            ->where('status', 'active')
            ->update($set);

        return max(0, (int) $this->db->affectedRows());
    }

    /**
     * Active delegation ids where the subject is either the delegate (received)
     * or the delegator (granted).
     *
     * @return list<string>
     */
    private function activeDelegationIdsFor(string $organizationId, string $subjectId): array
    {
        $rows = $this->db->table('delegations')
            ->select('id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->groupStart()
                ->where('delegate_id', $subjectId)
                ->orWhere('delegator_id', $subjectId)
            ->groupEnd()
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['id'], $rows));
    }

    /**
     * Expand a set of delegation ids to include every descendant (sub-delegation)
     * so revoking a parent revokes its whole subtree. Bounded BFS over parent_id.
     *
     * @param list<string> $rootIds
     * @return list<string>
     */
    private function expandDelegationSubtrees(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }
        $all     = array_fill_keys($rootIds, true);
        $frontier = $rootIds;
        // Bound the walk so a pathological/cyclic parent chain can't spin forever.
        for ($depth = 0; $depth < 32 && $frontier !== []; $depth++) {
            $children = $this->db->table('delegations')
                ->select('id')
                ->whereIn('parent_id', $frontier)
                ->get()->getResultArray();
            $next = [];
            foreach ($children as $c) {
                $id = (string) $c['id'];
                if (! isset($all[$id])) {
                    $all[$id]  = true;
                    $next[]    = $id;
                }
            }
            $frontier = $next;
        }

        return array_keys($all);
    }
}
