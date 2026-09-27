<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Group membership lifecycle (SRS FR-GRP-003).
 *
 * A membership records person/group, membership type, role, joining/leaving/
 * effective dates, status, source, permission scope, and approval evidence. One
 * person may belong to many groups/activity groups/departments/teams — but never
 * two ACTIVE memberships of the same type in the same group (enforced by an
 * app-maintained active_key + UNIQUE index), and never a pair barred by the
 * configurable conflict rules.
 *
 * Every mutation appends an event to `group_membership_events` and writes a
 * hash-chain audit entry, so approval evidence and lifecycle are fully traceable.
 */
final class GroupMembershipService
{
    /** Membership types (FR-GRP-002/003). Extended via data, validated loosely. */
    public const TYPES = ['member', 'leader', 'activity', 'department', 'team', 'guest'];

    /** Sources of a membership record. */
    private const SOURCES = ['manual', 'self_join', 'import', 'referral', 'event', 'system'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly ?JourneySignalPort $journeySignals = null,
    ) {
    }

    /**
     * Add (or request) a membership. When the group requires approval, the
     * record starts `approval_state = pending` and `status = pending` until an
     * authorized reviewer approves it; otherwise it is active immediately.
     *
     * @param array<string,mixed> $data user_id, membership_type, role,
     *   source, permission_scope[], effective_from, effective_to,
     *   requires_approval(bool), approval_evidence[], added_by, actor_id
     */
    public function add(string $organizationId, string $groupId, array $data): Result
    {
        $userId = (string) ($data['user_id'] ?? '');
        if ($userId === '') {
            return Result::fail('USER_REQUIRED', 'group.member_user_required', 422);
        }

        $group = $this->db->table('groups')
            ->where('id', $groupId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }
        if (($group['status'] ?? '') !== 'active') {
            return Result::fail('GROUP_INACTIVE', 'group.inactive', 409, ['status' => $group['status'] ?? null]);
        }

        $type = in_array($data['membership_type'] ?? '', self::TYPES, true)
            ? (string) $data['membership_type']
            : 'member';
        $role   = trim((string) ($data['role'] ?? 'member')) ?: 'member';
        $source = in_array($data['source'] ?? '', self::SOURCES, true) ? (string) $data['source'] : 'manual';

        // Already an active membership of this type in this group? Idempotent.
        $existing = $this->activeMembership($groupId, $userId, $type);
        if ($existing !== null) {
            return Result::ok([
                'membership_id' => $existing['id'],
                'group_id'      => $groupId,
                'user_id'       => $userId,
            ], 200, ['already_member' => true]);
        }

        // Conflict rules: does the person already hold a conflicting active
        // membership type (globally or in this same group per rule scope)?
        $conflict = $this->detectConflict($organizationId, $groupId, $userId, $type);
        if ($conflict !== null) {
            return Result::fail('MEMBERSHIP_CONFLICT', 'group.membership_conflict', 409, $conflict);
        }

        $requiresApproval = ! empty($data['requires_approval']);
        $approvalState    = $requiresApproval ? 'pending' : 'approved';
        $status           = $requiresApproval ? 'pending' : 'active';

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $effFrom = ! empty($data['effective_from']) ? (string) $data['effective_from'] : $now;

        // active_key is set ONLY for active rows so the UNIQUE index enforces
        // one-active-per (user, group, type); pending/historical rows are NULL.
        $activeKey = $status === 'active' ? $this->activeKey($userId, $groupId, $type) : null;

        try {
            $this->db->table('group_members')->insert([
                'id'                => $id,
                'organization_id'   => $organizationId,
                'group_id'          => $groupId,
                'user_id'           => $userId,
                'role'              => $role,
                'membership_type'   => $type,
                'status'            => $status,
                'source'            => $source,
                'permission_scope'  => isset($data['permission_scope']) ? json_encode($data['permission_scope']) : null,
                'effective_from'    => $effFrom,
                'effective_to'      => ! empty($data['effective_to']) ? (string) $data['effective_to'] : null,
                'approval_state'    => $approvalState,
                'approved_by'       => $requiresApproval ? null : ($data['added_by'] ?? null),
                'approved_at'       => $requiresApproval ? null : $now,
                'approval_ref'      => $data['approval_ref'] ?? null,
                'approval_evidence' => isset($data['approval_evidence']) ? json_encode($data['approval_evidence']) : null,
                'added_by'          => $data['added_by'] ?? null,
                'active_key'        => $activeKey,
                'joined_at'         => $now,
                'updated_at'        => $now,
            ]);
        } catch (Throwable) {
            // Unique clash on active_key = a concurrent active membership won.
            $existing = $this->activeMembership($groupId, $userId, $type);

            return Result::ok([
                'membership_id' => $existing['id'] ?? $id,
                'group_id'      => $groupId,
                'user_id'       => $userId,
            ], 200, ['already_member' => true]);
        }

        $this->event($organizationId, $id, $requiresApproval ? 'requested' : 'joined', $data['actor_id'] ?? ($data['added_by'] ?? null), [
            'membership_type' => $type,
            'role'            => $role,
            'source'          => $source,
        ]);
        $this->audit->record($organizationId, [
            'actor_id'    => $data['actor_id'] ?? ($data['added_by'] ?? null),
            'action'      => $requiresApproval ? 'group.membership.requested' : 'group.membership.joined',
            'object_type' => 'group_membership',
            'object_id'   => $id,
            'metadata'    => ['group_id' => $groupId, 'user_id' => $userId, 'membership_type' => $type],
        ]);

        // M7: a membership that becomes ACTIVE immediately signals the journey.
        // A pending request signals later, on approve() — a not-yet-approved
        // belonging should not advance a stage.
        if ($status === 'active') {
            $this->emitJourneySignal($organizationId, 'journey.signal.group.joined', $userId, $groupId, $id, $data['actor_id'] ?? ($data['added_by'] ?? null), [
                'membership_type' => $type,
                'source'          => $source,
            ]);
        }

        return Result::created([
            'membership_id'  => $id,
            'group_id'       => $groupId,
            'user_id'        => $userId,
            'membership_type' => $type,
            'status'         => $status,
            'approval_state' => $approvalState,
        ]);
    }

    /** Approve a pending membership request (authorized reviewer). */
    public function approve(string $organizationId, string $membershipId, string $actorId, array $evidence = []): Result
    {
        $m = $this->findInOrg($organizationId, $membershipId);
        if ($m === null) {
            return Result::notFound('group.membership_not_found', 'MEMBERSHIP_NOT_FOUND');
        }
        if ($m['approval_state'] !== 'pending') {
            return Result::fail('BAD_STATE', 'group.membership_bad_state', 409, ['approval_state' => $m['approval_state']]);
        }

        // Re-check conflicts at approval time (state may have changed since request).
        $conflict = $this->detectConflict($organizationId, (string) $m['group_id'], (string) $m['user_id'], (string) $m['membership_type']);
        if ($conflict !== null) {
            return Result::fail('MEMBERSHIP_CONFLICT', 'group.membership_conflict', 409, $conflict);
        }

        $now       = $this->clock->nowUtcString();
        $activeKey = $this->activeKey((string) $m['user_id'], (string) $m['group_id'], (string) $m['membership_type']);
        try {
            $this->db->table('group_members')->where('id', $membershipId)->update([
                'status'            => 'active',
                'approval_state'    => 'approved',
                'approved_by'       => $actorId,
                'approved_at'       => $now,
                'approval_evidence' => $evidence !== [] ? json_encode($evidence) : $m['approval_evidence'],
                'active_key'        => $activeKey,
                'updated_at'        => $now,
            ]);
        } catch (Throwable) {
            return Result::fail('MEMBERSHIP_CONFLICT', 'group.membership_conflict', 409, ['reason' => 'concurrent_active']);
        }

        $this->event($organizationId, $membershipId, 'approved', $actorId, ['evidence' => $evidence]);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'group.membership.approved',
            'object_type' => 'group_membership',
            'object_id'   => $membershipId,
            'metadata'    => ['group_id' => $m['group_id'], 'user_id' => $m['user_id']],
        ]);

        // M7: approval is when a pending request becomes a real belonging, so the
        // journey signal fires here (not at request time).
        $this->emitJourneySignal($organizationId, 'journey.signal.group.joined', (string) $m['user_id'], (string) $m['group_id'], $membershipId, $actorId, [
            'membership_type' => (string) $m['membership_type'],
            'source'          => (string) ($m['source'] ?? ''),
            'approved'        => true,
        ]);

        return Result::ok(['membership_id' => $membershipId, 'status' => 'active']);
    }

    /** Reject a pending membership request. */
    public function reject(string $organizationId, string $membershipId, string $actorId, string $reason = ''): Result
    {
        $m = $this->findInOrg($organizationId, $membershipId);
        if ($m === null) {
            return Result::notFound('group.membership_not_found', 'MEMBERSHIP_NOT_FOUND');
        }
        if ($m['approval_state'] !== 'pending') {
            return Result::fail('BAD_STATE', 'group.membership_bad_state', 409, ['approval_state' => $m['approval_state']]);
        }
        $now = $this->clock->nowUtcString();
        $this->db->table('group_members')->where('id', $membershipId)->update([
            'status'         => 'rejected',
            'approval_state' => 'rejected',
            'approved_by'    => $actorId,
            'approved_at'    => $now,
            'leave_reason'   => $reason !== '' ? $reason : null,
            'active_key'     => null,
            'updated_at'     => $now,
        ]);
        $this->event($organizationId, $membershipId, 'rejected', $actorId, ['reason' => $reason]);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'group.membership.rejected',
            'object_type' => 'group_membership',
            'object_id'   => $membershipId,
            'metadata'    => ['reason' => $reason],
        ]);

        return Result::ok(['membership_id' => $membershipId, 'status' => 'rejected']);
    }

    /** End an active membership (effective-dated leave; row kept as history). */
    public function leave(string $organizationId, string $membershipId, string $actorId, string $reason = ''): Result
    {
        $m = $this->findInOrg($organizationId, $membershipId);
        if ($m === null) {
            return Result::notFound('group.membership_not_found', 'MEMBERSHIP_NOT_FOUND');
        }
        if ($m['status'] !== 'active') {
            return Result::fail('BAD_STATE', 'group.membership_bad_state', 409, ['status' => $m['status']]);
        }
        $now = $this->clock->nowUtcString();
        $this->db->table('group_members')->where('id', $membershipId)->update([
            'status'       => 'ended',
            'left_at'      => $now,
            'leave_reason' => $reason !== '' ? $reason : null,
            'effective_to' => $m['effective_to'] ?? $now,
            'active_key'   => null, // frees the one-active slot; row remains historical
            'updated_at'   => $now,
        ]);
        $this->event($organizationId, $membershipId, 'left', $actorId, ['reason' => $reason]);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'group.membership.left',
            'object_type' => 'group_membership',
            'object_id'   => $membershipId,
            'metadata'    => ['group_id' => $m['group_id'], 'user_id' => $m['user_id'], 'reason' => $reason],
        ]);

        // M7 (regress counterpart): leaving a group emits a `left` signal so a
        // rule can react (e.g. a leader-review proposal), consistent with the
        // join emitter. The account-teardown path (endActiveForSubject) does NOT
        // emit — that is a bulk system action, not a member-initiated leave.
        $this->emitJourneySignal($organizationId, 'journey.signal.group.left', (string) $m['user_id'], (string) $m['group_id'], $membershipId, $actorId, [
            'membership_type' => (string) $m['membership_type'],
            'reason'          => $reason,
        ]);

        return Result::ok(['membership_id' => $membershipId, 'status' => 'ended']);
    }

    /**
     * Change the role and/or permission scope of an active membership.
     *
     * @param array<string,mixed> $data role, permission_scope[]
     */
    public function changeRole(string $organizationId, string $membershipId, string $actorId, array $data): Result
    {
        $m = $this->findInOrg($organizationId, $membershipId);
        if ($m === null) {
            return Result::notFound('group.membership_not_found', 'MEMBERSHIP_NOT_FOUND');
        }
        if ($m['status'] !== 'active') {
            return Result::fail('BAD_STATE', 'group.membership_bad_state', 409, ['status' => $m['status']]);
        }
        $fields = ['updated_at' => $this->clock->nowUtcString()];
        $action = 'role_changed';
        if (isset($data['role']) && trim((string) $data['role']) !== '') {
            $fields['role'] = trim((string) $data['role']);
        }
        if (array_key_exists('permission_scope', $data)) {
            $fields['permission_scope'] = $data['permission_scope'] !== null ? json_encode($data['permission_scope']) : null;
            $action = isset($fields['role']) ? 'role_changed' : 'scope_changed';
        }
        $this->db->table('group_members')->where('id', $membershipId)->update($fields);

        $this->event($organizationId, $membershipId, $action, $actorId, [
            'role'             => $fields['role'] ?? $m['role'],
            'permission_scope' => $data['permission_scope'] ?? null,
        ]);
        $this->audit->record($organizationId, [
            'actor_id'    => $actorId,
            'action'      => 'group.membership.' . $action,
            'object_type' => 'group_membership',
            'object_id'   => $membershipId,
            'metadata'    => ['group_id' => $m['group_id'], 'user_id' => $m['user_id']],
        ]);

        return Result::ok(['membership_id' => $membershipId] + $fields);
    }

    /**
     * List memberships for a group (optionally filtered by status/type).
     *
     * @return list<array<string,mixed>>
     */
    public function listForGroup(string $organizationId, string $groupId, ?string $status = 'active', ?string $type = null): array
    {
        $q = $this->db->table('group_members gm')
            ->select('gm.*, u.display_name', false)
            ->join('users u', 'u.id = gm.user_id', 'left')
            ->where('gm.organization_id', $organizationId)
            ->where('gm.group_id', $groupId);
        if ($status !== null && $status !== '') {
            $q->where('gm.status', $status);
        }
        if ($type !== null && $type !== '') {
            $q->where('gm.membership_type', $type);
        }

        return $q->orderBy('gm.joined_at', 'DESC')->get()->getResultArray();
    }

    /**
     * Distinct members across a SET of groups, in one query (resource-light — no
     * N+1 across the scope). Returns one row per user (deduped) with their
     * display_name, ordered by name. Used to build scope-bounded person pickers
     * (e.g. the follow-up subject picker over a follower's own group + descendants).
     *
     * @param list<string> $groupIds
     * @return list<array<string,mixed>>
     */
    public function listDistinctForGroups(string $organizationId, array $groupIds, ?string $status = 'active'): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('strval', $groupIds), static fn ($g): bool => $g !== '')));
        if ($groupIds === []) {
            return [];
        }

        $q = $this->db->table('group_members gm')
            ->select('MIN(u.display_name) AS display_name, gm.user_id', false)
            ->join('users u', 'u.id = gm.user_id', 'left')
            ->where('gm.organization_id', $organizationId)
            ->whereIn('gm.group_id', $groupIds);
        if ($status !== null && $status !== '') {
            $q->where('gm.status', $status);
        }

        $rows = $q->groupBy('gm.user_id')->orderBy('display_name', 'ASC')->get()->getResultArray();

        // Normalise to {id, display_name} so it drops straight into person pickers.
        return array_values(array_map(static fn (array $r): array => [
            'id'           => (string) ($r['user_id'] ?? ''),
            'display_name' => (string) ($r['display_name'] ?? ''),
        ], $rows));
    }

    /**
     * All memberships a person holds (across groups) — demonstrates FR-GRP-003
     * multi-group membership.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $organizationId, string $userId, ?string $status = 'active'): array
    {
        $q = $this->db->table('group_members gm')
            ->select('gm.*, g.name AS group_name, g.type AS group_type', false)
            ->join('groups g', 'g.id = gm.group_id', 'left')
            ->where('gm.organization_id', $organizationId)
            ->where('gm.user_id', $userId);
        if ($status !== null && $status !== '') {
            $q->where('gm.status', $status);
        }

        return $q->orderBy('gm.joined_at', 'DESC')->get()->getResultArray();
    }

    /**
     * Guarantee the "every system user belongs to a group" invariant.
     *
     * No-op when the user already holds an active membership ANYWHERE (they are
     * not moved — belonging is additive, and a leader with several memberships
     * keeps them all). Otherwise the user is attached to $groupId as an ordinary
     * member.
     *
     * Callers supply the group because the choice of WHICH group is a placement
     * decision that lives elsewhere (a mentor's home group for a prospect, the
     * registering group at sign-up); this method only makes the belonging real
     * and idempotent. With no group to attach to and no existing membership it
     * fails loudly rather than inventing one — an unplaced user is a data gap to
     * surface (see `geo:backfill-location`-style sweeps), not to paper over.
     *
     * @param array<string,mixed> $opts source, added_by, actor_id, requires_approval
     *
     * @return Result data = {belonged:bool, created:bool, membership_id:?string, group_id:?string}
     */
    public function ensureBelonging(string $organizationId, string $userId, ?string $groupId, array $opts = []): Result
    {
        $userId = trim($userId);
        if ($userId === '') {
            return Result::fail('USER_REQUIRED', 'group.member_user_required', 422);
        }

        $existing = $this->listForUser($organizationId, $userId, 'active');
        if ($existing !== []) {
            return Result::ok([
                'belonged'      => true,
                'created'       => false,
                'membership_id' => isset($existing[0]['id']) ? (string) $existing[0]['id'] : null,
                'group_id'      => isset($existing[0]['group_id']) ? (string) $existing[0]['group_id'] : null,
            ]);
        }

        $groupId = is_string($groupId) ? trim($groupId) : '';
        if ($groupId === '') {
            return Result::fail('NO_GROUP_FOR_BELONGING', 'group.no_group_for_belonging', 422);
        }

        $res = $this->add($organizationId, $groupId, [
            'user_id'           => $userId,
            'membership_type'   => 'member',
            'role'              => 'member',
            'source'            => (string) ($opts['source'] ?? 'system'),
            // Belonging is not an application: nobody has to approve being placed
            // in the group their own mentor already belongs to.
            'requires_approval' => (bool) ($opts['requires_approval'] ?? false),
            'added_by'          => $opts['added_by'] ?? null,
            'actor_id'          => $opts['actor_id'] ?? ($opts['added_by'] ?? null),
        ]);
        if (! $res->ok) {
            return $res;
        }

        $data = is_array($res->data) ? $res->data : [];

        return Result::ok([
            'belonged'      => false,
            'created'       => true,
            'membership_id' => isset($data['membership_id']) ? (string) $data['membership_id'] : null,
            'group_id'      => $groupId,
            'status'        => $data['status'] ?? null,
        ], $res->status);
    }

    /** @return array<string,mixed>|null a membership with its event trail. */
    public function find(string $organizationId, string $membershipId): ?array
    {
        $m = $this->findInOrg($organizationId, $membershipId);
        if ($m === null) {
            return null;
        }
        $m['events'] = $this->db->table('group_membership_events')
            ->where('membership_id', $membershipId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();

        return $m;
    }

    /** Pending membership requests for a group (reviewer queue). @return list<array<string,mixed>> */
    public function pendingForGroup(string $organizationId, string $groupId): array
    {
        return $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('approval_state', 'pending')
            ->orderBy('joined_at', 'ASC')
            ->get()->getResultArray();
    }

    // ------------------------------------------------------- conflict rules

    /**
     * Define a conflicting membership-type pair (configurable, idempotent).
     *
     * @param array<string,mixed> $data type_a, type_b, scope, reason
     */
    public function defineConflict(string $organizationId, array $data): Result
    {
        $a = trim((string) ($data['type_a'] ?? ''));
        $b = trim((string) ($data['type_b'] ?? ''));
        if ($a === '' || $b === '' || $a === $b) {
            return Result::fail('BAD_PAIR', 'group.conflict_bad_pair', 422);
        }
        // Normalize order so (a,b) and (b,a) are the same rule.
        if (strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }
        $scope = in_array($data['scope'] ?? '', ['global', 'same_group', 'same_branch'], true)
            ? (string) $data['scope']
            : 'global';
        $now = $this->clock->nowUtcString();

        $existing = $this->db->table('group_membership_conflicts')
            ->where('organization_id', $organizationId)
            ->where('type_a', $a)->where('type_b', $b)->where('scope', $scope)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['conflict_id' => $existing['id']], 200, ['exists' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('group_membership_conflicts')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'type_a'          => $a,
            'type_b'          => $b,
            'scope'           => $scope,
            'reason'          => isset($data['reason']) ? (string) $data['reason'] : null,
            'created_at'      => $now,
        ]);

        return Result::created(['conflict_id' => $id, 'type_a' => $a, 'type_b' => $b, 'scope' => $scope]);
    }

    /** @return list<array<string,mixed>> */
    public function listConflicts(string $organizationId): array
    {
        return $this->db->table('group_membership_conflicts')
            ->where('organization_id', $organizationId)
            ->orderBy('type_a', 'ASC')
            ->get()->getResultArray();
    }

    // ---- Lifecycle-signal consumers (Theme B — membership half of M1/M2) ----

    /**
     * END every ACTIVE membership a person holds across all groups (Theme B,
     * membership teardown — finding M1 belonging half).
     *
     * Invoked by the JobRouter on account deactivate / suspend / anonymize: a
     * gone/frozen person's `group_members` rows must stop counting as `active` so
     * they drop out of rosters (`listForGroup(..., 'active')`), scope resolution
     * (`GroupScopeResolver` reads `status='active'`) and group-size metrics. This
     * mirrors the E-B2 registration release / CO6 enrollment withdraw consumers:
     * SYSTEM authority (no scope check — the account is gone), fault-isolated by
     * the router, and idempotent (only `active` rows flip, and the row already
     * carries the teardown reason on re-delivery), so a redelivered teardown is a
     * no-op. Historical (`ended`) rows are left intact — membership history is
     * never destroyed. The one-active slot is freed (`active_key = NULL`) so the
     * person can cleanly re-join if the account is later reactivated (M10).
     *
     * @return int number of memberships ended
     */
    public function endActiveForSubject(string $organizationId, string $userId, string $reasonCode): int
    {
        if ($organizationId === '' || $userId === '') {
            return 0;
        }

        $now  = $this->clock->nowUtcString();
        $rows = $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get()->getResultArray();

        $ended = 0;
        foreach ($rows as $m) {
            $membershipId = (string) $m['id'];
            $this->db->table('group_members')->where('id', $membershipId)->update([
                'status'       => 'ended',
                'left_at'      => $now,
                'leave_reason' => substr('teardown: ' . $reasonCode, 0, 255),
                'effective_to' => $m['effective_to'] ?? $now,
                'active_key'   => null, // frees the one-active slot; row remains historical
                'updated_at'   => $now,
            ]);
            $this->event($organizationId, $membershipId, 'left', null, [
                'reason'      => $reasonCode,
                'system'      => true,
                'teardown'    => true,
            ]);
            $this->audit->record($organizationId, [
                'actor_id'    => null,
                'action'      => 'group.membership.left',
                'object_type' => 'group_membership',
                'object_id'   => $membershipId,
                'metadata'    => ['group_id' => $m['group_id'], 'user_id' => $userId, 'reason' => $reasonCode, 'teardown' => true],
            ]);
            $ended++;
        }

        return $ended;
    }

    /**
     * RESTORE the memberships a teardown ended for a person (Theme B — M10, the
     * inverse of {@see endActiveForSubject}). Invoked by the JobRouter on
     * `account.reactivated`: when a suspended/deactivated account returns, the
     * belongings the teardown ended should come back so the person re-appears on
     * rosters, in scope resolution and group-size metrics.
     *
     * Only rows the teardown itself ended are candidates — identified by the
     * `teardown:` marker `endActiveForSubject` stamped into `leave_reason`. A
     * membership the person left for their own reasons is NEVER resurrected.
     * A candidate is restored to `active` (leave_reason/left_at cleared,
     * active_key recomputed) UNLESS the person already holds an active membership
     * of that same (group, type) — e.g. they re-joined while suspended — in which
     * case the one-active guard wins and the candidate is left ended (no
     * duplicate active slot). SYSTEM authority + idempotent (a re-run finds the
     * rows already active-or-superseded and restores 0).
     *
     * @return int number of memberships restored
     */
    public function restoreForSubject(string $organizationId, string $userId): int
    {
        if ($organizationId === '' || $userId === '') {
            return 0;
        }

        $now  = $this->clock->nowUtcString();
        $rows = $this->db->table('group_members')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'ended')
            ->like('leave_reason', 'teardown:', 'after')
            ->get()->getResultArray();

        // Slots the person currently holds active — cannot be double-filled.
        $activeSlots = [];
        foreach (
            $this->db->table('group_members')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->get()->getResultArray() as $a
        ) {
            $activeSlots[(string) $a['group_id'] . ':' . (string) $a['membership_type']] = true;
        }

        $restored = 0;
        foreach ($rows as $m) {
            $membershipId = (string) $m['id'];
            $groupId      = (string) $m['group_id'];
            $type         = (string) $m['membership_type'];
            $slot         = $groupId . ':' . $type;

            // The person re-joined this exact slot while away — keep their newer
            // active membership; leave this teardown row ended (deduped).
            if (isset($activeSlots[$slot])) {
                continue;
            }

            try {
                $this->db->table('group_members')->where('id', $membershipId)->update([
                    'status'       => 'active',
                    'left_at'      => null,
                    'leave_reason' => null,
                    'effective_to' => null,
                    'active_key'   => $this->activeKey($userId, $groupId, $type),
                    'updated_at'   => $now,
                ]);
            } catch (Throwable) {
                // A concurrent active membership won the unique slot — skip.
                continue;
            }
            $activeSlots[$slot] = true; // guard intra-batch dupes
            $this->event($organizationId, $membershipId, 'joined', null, [
                'reason'   => 'account.reactivated',
                'system'   => true,
                'restored' => true,
            ]);
            $this->audit->record($organizationId, [
                'actor_id'    => null,
                'action'      => 'group.membership.joined',
                'object_type' => 'group_membership',
                'object_id'   => $membershipId,
                'metadata'    => ['group_id' => $groupId, 'user_id' => $userId, 'restored' => true],
            ]);
            $restored++;
        }

        return $restored;
    }

    /**
     * RE-POINT a merged person's group memberships to the survivor (Theme B,
     * membership merge half — finding M2). Mirrors the Groups-merge cascade that
     * re-homes memberships to a survivor group, and the E-B2 / CO6 / J4 account
     * merge re-points: the loser's belongings move to the survivor rather than
     * being stranded on a dead `merged` user id (which loses continuity and can
     * double-count the same human).
     *
     * For each ACTIVE loser membership: if the survivor already holds an active
     * membership of the SAME (group, membership_type) — the one-active guard
     * (`gm_active_uq` on active_key) would collide — the loser row is instead
     * ENDED as a superseded duplicate (the survivor's own membership wins; no
     * silent overwrite). Otherwise the row is re-pointed to the survivor
     * (user_id + recomputed active_key). Historical (`ended`) loser rows are
     * re-pointed for continuity but keep `active_key = NULL` (no slot contention).
     * Never a blind cross-identity rewrite — only rows keyed to the loser move,
     * and the active-slot invariant is preserved. Idempotent.
     *
     * @return array{repointed:int, superseded:int, history_repointed:int}
     */
    public function reassignForMerge(string $organizationId, string $loserUserId, string $survivorUserId): array
    {
        $zero = ['repointed' => 0, 'superseded' => 0, 'history_repointed' => 0];
        if ($organizationId === '' || $loserUserId === '' || $survivorUserId === '' || $loserUserId === $survivorUserId) {
            return $zero;
        }

        $now = $this->clock->nowUtcString();
        $out = $zero;

        // Survivor's existing ACTIVE (group, type) slots — cannot be double-filled.
        $survActive = [];
        foreach (
            $this->db->table('group_members')
                ->where('organization_id', $organizationId)
                ->where('user_id', $survivorUserId)
                ->where('status', 'active')
                ->get()->getResultArray() as $s
        ) {
            $survActive[(string) $s['group_id'] . ':' . (string) $s['membership_type']] = true;
        }

        foreach (
            $this->db->table('group_members')
                ->where('organization_id', $organizationId)
                ->where('user_id', $loserUserId)
                ->get()->getResultArray() as $m
        ) {
            $membershipId = (string) $m['id'];
            $groupId      = (string) $m['group_id'];
            $type         = (string) $m['membership_type'];
            $slot         = $groupId . ':' . $type;

            // Historical loser rows: re-point owner for continuity, no active slot.
            if ((string) $m['status'] !== 'active') {
                $this->db->table('group_members')->where('id', $membershipId)
                    ->update(['user_id' => $survivorUserId, 'updated_at' => $now]);
                $out['history_repointed']++;

                continue;
            }

            // Active loser row where the survivor already holds that slot → end it
            // as a superseded duplicate (survivor's membership wins).
            if (isset($survActive[$slot])) {
                $this->db->table('group_members')->where('id', $membershipId)->update([
                    'status'       => 'ended',
                    'left_at'      => $now,
                    'leave_reason' => 'merge: superseded by survivor membership',
                    'effective_to' => $m['effective_to'] ?? $now,
                    'active_key'   => null,
                    'updated_at'   => $now,
                ]);
                $this->event($organizationId, $membershipId, 'left', null, ['reason' => 'account.merged', 'system' => true, 'superseded' => true]);
                $out['superseded']++;

                continue;
            }

            // Otherwise re-point to the survivor and recompute the active_key so
            // the one-active guard now protects the survivor's slot.
            $this->db->table('group_members')->where('id', $membershipId)->update([
                'user_id'    => $survivorUserId,
                'active_key' => $this->activeKey($survivorUserId, $groupId, $type),
                'updated_at' => $now,
            ]);
            $survActive[$slot] = true; // guard intra-loser dupes
            $this->event($organizationId, $membershipId, 'role_changed', null, ['reason' => 'account.merged', 'system' => true, 'repointed_from' => $loserUserId]);
            $out['repointed']++;
        }

        return $out;
    }

    // ------------------------------------------------------------- internals

    /**
     * Would granting $type to $userId violate a configured conflict rule?
     *
     * @return array<string,mixed>|null conflict detail, or null when clear
     */
    private function detectConflict(string $organizationId, string $groupId, string $userId, string $type): ?array
    {
        $rules = $this->db->table('group_membership_conflicts')
            ->where('organization_id', $organizationId)
            ->groupStart()->where('type_a', $type)->orWhere('type_b', $type)->groupEnd()
            ->get()->getResultArray();
        if ($rules === []) {
            return null;
        }

        foreach ($rules as $rule) {
            $other = $rule['type_a'] === $type ? $rule['type_b'] : $rule['type_a'];

            $q = $this->db->table('group_members')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('membership_type', $other)
                ->where('status', 'active');
            if ($rule['scope'] === 'same_group') {
                $q->where('group_id', $groupId);
            }
            // 'same_branch' would additionally constrain to shared ancestry; the
            // global/same_group scopes cover current policy. Branch scope is a
            // documented future refinement (needs the closure join).
            $held = $q->countAllResults() > 0;
            if ($held) {
                return [
                    'conflicting_type' => $other,
                    'requested_type'   => $type,
                    'scope'            => $rule['scope'],
                    'reason'           => $rule['reason'],
                ];
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function activeMembership(string $groupId, string $userId, string $type): ?array
    {
        return $this->db->table('group_members')
            ->where('group_id', $groupId)->where('user_id', $userId)
            ->where('membership_type', $type)->where('status', 'active')
            ->get()->getRowArray() ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findInOrg(string $organizationId, string $membershipId): ?array
    {
        return $this->db->table('group_members')
            ->where('id', $membershipId)->where('organization_id', $organizationId)
            ->get()->getRowArray() ?: null;
    }

    private function activeKey(string $userId, string $groupId, string $type): string
    {
        return hash('sha256', $userId . ':' . $groupId . ':' . $type);
    }

    /**
     * Emit a belonging-change journey signal (M7). Standing definition:
     * "integration" = group membership + foundations/membership courses. Courses
     * already emit `journey.signal.course.completed`; this is the membership half,
     * so joining/leaving a group can advance (or regress) the person's journey
     * through the SAME rule engine — no bespoke path. Journey context = null (the
     * person's ORG-WIDE primary journey) while the originating group scopes which
     * leaders' rules may fire. Best-effort and fully fault-isolated: a signal
     * failure never affects the belonging write. No-op when no port is wired
     * (e.g. unit tests that don't exercise the journey seam).
     *
     * @param array<string,mixed> $attributes
     */
    private function emitJourneySignal(string $organizationId, string $action, string $userId, string $groupId, string $membershipId, ?string $actorId, array $attributes): void
    {
        if ($this->journeySignals === null || $userId === '') {
            return;
        }
        try {
            $this->journeySignals->ingest($organizationId, [
                'user_id'        => $userId,
                'action'         => $action,
                'group_id'       => null,       // advance the org-wide journey
                'scope_group_id' => $groupId,   // group scopes which leader rules fire
                'actor_id'       => $actorId,
                'evidence_type'  => 'group_membership',
                'evidence_ref'   => 'membership:' . $membershipId,
                'attributes'     => ['group_id' => $groupId] + $attributes,
            ]);
        } catch (Throwable) {
            // Journey automation is best-effort.
        }
    }

    /** @param array<string,mixed> $detail */
    private function event(string $organizationId, string $membershipId, string $action, ?string $actorId, array $detail): void
    {
        $this->db->table('group_membership_events')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'membership_id'   => $membershipId,
            'action'          => $action,
            'actor_id'        => $actorId,
            'detail'          => $detail !== [] ? json_encode($detail) : null,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);
    }
}
