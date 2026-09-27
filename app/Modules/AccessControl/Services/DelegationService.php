<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Delegation of authority (SRS FR-ACL-005).
 *
 * "Leaders can delegate only permissions they possess and only to an
 * equal/narrower scope, no longer than their own authorization, with an explicit
 * delegate, purpose, maximum duration, and revocation. Delegation chains have a
 * configurable maximum depth and are fully traceable."
 *
 * Every invariant is enforced at creation time against the delegator's OWN
 * authority for that permission, where "authority" is any currently-effective
 * grant that confers the permission:
 *   1. a role assignment (role_permissions → permissions),
 *   2. an approved direct permission access-request, or
 *   3. an active delegation TO the delegator (this is what makes a *chain*).
 *
 * For each candidate authority we check, using the SAME hierarchy rule as the
 * PDP ({@see GroupScopeResolver::scopeContains()}):
 *   - SCOPE — the delegation's scope must be equal to or narrower than the
 *     authority's scope. This uses full-model containment (scope_mode +
 *     hand-picked group set + opt-in cross-cut), so a delegate can never receive
 *     a broader mode, an extra group, or cross-cut reach the delegator lacks.
 *   - DURATION — the delegation's end must not exceed the authority's own end
 *     (an unbounded role grant imposes no cap beyond the mandatory max duration).
 *   - DEPTH — delegating from a role/direct grant produces depth 1; delegating
 *     from an existing delegation produces parent.depth + 1, capped at the
 *     configurable maximum (env `acl.maxDelegationDepth`, default 3).
 *
 * A delegation is always time-bounded and revocable, and its parent_id + depth
 * make the chain fully traceable.
 */
final class DelegationService
{
    /** Hard ceiling on how long any single delegation may last (days). */
    private const MAX_DURATION_DAYS = 365;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupScopeResolver $groupScope,
        private readonly AuditLogger $audit,
        private readonly GrantScopeWriter $scope,
    ) {
    }

    /** Configurable maximum delegation-chain depth (FR-ACL-005). */
    public function maxDepth(): int
    {
        return max(1, (int) (getenv('acl.maxDelegationDepth') ?: 3));
    }

    /**
     * Create a delegation from $delegatorId to a named delegate.
     *
     * @param array<string,mixed> $data delegate_id, permission_code,
     *        scope_group_id?, scope_mode?, include_descendants?, scope_groups?,
     *        include_crosscut?, purpose, duration_days
     */
    public function delegate(string $organizationId, string $delegatorId, array $data): Result
    {
        $delegateId = (string) ($data['delegate_id'] ?? '');
        $permission = (string) ($data['permission_code'] ?? '');
        $purpose    = trim((string) ($data['purpose'] ?? ''));

        if ($delegateId === '') {
            return Result::fail('DELEGATE_REQUIRED', 'acl.delegate_required', 422);
        }
        if ($permission === '') {
            return Result::fail('PERMISSION_REQUIRED', 'acl.permission_required', 422);
        }
        if ($purpose === '') {
            return Result::fail('PURPOSE_REQUIRED', 'acl.purpose_required', 422);
        }
        if ($delegateId === $delegatorId) {
            return Result::fail('SELF_DELEGATION', 'acl.self_delegation', 422);
        }

        // Full scope model (mode + hand-picked set + opt-in cross-cut).
        $want = $this->scope->parse($data);
        if (! $want['ok']) {
            return Result::fail('BAD_SCOPE', $want['message'], 422, $want['extra'] ?? []);
        }
        $wantScope = $want['group_id'];

        // Mandatory, bounded duration.
        $days = isset($data['duration_days']) ? (int) $data['duration_days'] : 0;
        if ($days < 1) {
            return Result::fail('DURATION_REQUIRED', 'acl.duration_required', 422);
        }
        if ($days > self::MAX_DURATION_DAYS) {
            return Result::fail('DURATION_TOO_LONG', 'acl.duration_too_long', 422, ['max_days' => self::MAX_DURATION_DAYS]);
        }
        $now   = $this->clock->nowUtcString();
        $wantTo = $this->clock->now()->modify("+{$days} days");

        // The delegator's own authority for this permission.
        $authorities = $this->authoritiesFor($organizationId, $delegatorId, $permission, $now);
        if ($authorities === []) {
            // Can't delegate what you don't possess.
            return Result::denied('acl.not_authorized_to_delegate', 'NOT_AUTHORIZED_TO_DELEGATE');
        }

        // Find an authority that covers the requested scope, duration, and depth.
        $chosen = null;
        foreach ($authorities as $a) {
            // SCOPE: equal/narrower than this authority. Uses full-model
            // containment so the delegated mode/hand-picked set/cross-cut reach
            // can never exceed what the authority itself holds.
            if (! $this->groupScope->scopeContains(
                $a['scope_group_id'], $a['mode'], $a['groups'],
                $want['group_id'], $want['mode'], $want['groups'],
                $a['include_crosscut'], $want['include_crosscut'],
            )) {
                continue;
            }
            // DURATION: no longer than this authority's own end (null = unbounded).
            if ($a['effective_to'] !== null && $wantTo > new \DateTimeImmutable($a['effective_to'])) {
                continue;
            }
            // DEPTH: role/direct authority → depth 1; sub-delegation → parent+1.
            $depth = $a['next_depth'];
            if ($depth > $this->maxDepth()) {
                continue;
            }
            // Prefer the shallowest valid chain.
            if ($chosen === null || $depth < $chosen['depth']) {
                $chosen = [
                    'depth'             => $depth,
                    'parent_id'         => $a['delegation_id'],
                    'source_grant_type' => $a['source_grant_type'] ?? null,
                    'source_grant_id'   => $a['source_grant_id'] ?? null,
                ];
            }
        }

        if ($chosen === null) {
            // The delegator holds the permission, but not at a scope/duration/depth
            // that permits THIS delegation — report the binding constraint.
            return Result::denied('acl.delegation_exceeds_authority', 'DELEGATION_EXCEEDS_AUTHORITY');
        }

        $id = Uuid::v7();
        $this->db->table('delegations')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'delegator_id'        => $delegatorId,
            'delegate_id'         => $delegateId,
            'permission_code'     => $permission,
            'purpose'             => $purpose,
            'parent_id'           => $chosen['parent_id'],
            'source_grant_type'   => $chosen['source_grant_type'],
            'source_grant_id'     => $chosen['source_grant_id'],
            'depth'               => $chosen['depth'],
            'status'              => 'active',
            'effective_from'      => $now,
            'effective_to'        => $wantTo->format('Y-m-d H:i:s'),
            'created_at'          => $now,
        ] + $this->scope->columns($want));
        $this->scope->syncGroupSet($organizationId, 'delegation', $id, $want);

        $this->audit->record($organizationId, [
            'action'      => 'acl.delegation.grant',
            'actor_id'    => $delegatorId,
            'actor_type'  => 'user',
            'object_type' => 'delegation',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => [
                'delegate_id'     => $delegateId,
                'permission_code' => $permission,
                'scope_group_id'  => $wantScope,
                'depth'           => $chosen['depth'],
                'parent_id'       => $chosen['parent_id'],
                'effective_to'    => $wantTo->format('Y-m-d H:i:s'),
            ],
        ]);

        return Result::created([
            'delegation_id'   => $id,
            'delegate_id'     => $delegateId,
            'permission_code' => $permission,
            'scope_group_id'  => $wantScope,
            'depth'           => $chosen['depth'],
            'parent_id'       => $chosen['parent_id'],
            'effective_to'    => $wantTo->format('Y-m-d H:i:s'),
            'status'          => 'active',
        ]);
    }

    /**
     * Revoke a delegation. Cascades to everything sub-delegated from it, because
     * a child delegation cannot outlive the authority it was derived from.
     */
    public function revoke(string $organizationId, string $actorId, string $delegationId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'acl.reason_required', 422);
        }

        $row = $this->db->table('delegations')
            ->where('id', $delegationId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('acl.delegation_not_found', 'DELEGATION_NOT_FOUND');
        }

        // The delegator (or an ancestor delegator up the chain) may revoke.
        if (! $this->canRevoke($organizationId, $actorId, $row)) {
            return Result::denied('acl.cannot_revoke_delegation', 'CANNOT_REVOKE_DELEGATION');
        }

        $now  = $this->clock->nowUtcString();
        $ids  = $this->collectSubtree($delegationId); // includes itself
        $this->db->table('delegations')
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => $actorId]);

        $this->audit->record($organizationId, [
            'action'      => 'acl.delegation.revoke',
            'actor_id'    => $actorId,
            'actor_type'  => 'user',
            'object_type' => 'delegation',
            'object_id'   => $delegationId,
            'outcome'     => 'success',
            'metadata'    => ['reason' => $reason, 'cascaded' => count($ids)],
        ]);

        return Result::ok(['delegation_id' => $delegationId, 'status' => 'revoked', 'revoked_count' => count($ids)]);
    }

    /**
     * Cascade-revoke every delegation derived from a source grant (gap AC2).
     *
     * Called by RoleAssignmentService::revoke / AccessRequestService::revoke and
     * both expire-lapsed paths so a delegation can never outlive the role
     * assignment / access request it was carved from. Because the root
     * source_grant_id is propagated to the entire sub-delegation chain, one flat
     * update reaches all descendants. Idempotent (only flips `active` rows).
     *
     * @param string $sourceGrantType role_assignment | access_request
     */
    public function revokeBySourceGrant(string $organizationId, string $sourceGrantType, string $sourceGrantId, ?string $actorId, string $reason): int
    {
        $active = $this->db->table('delegations')
            ->where('organization_id', $organizationId)
            ->where('source_grant_type', $sourceGrantType)
            ->where('source_grant_id', $sourceGrantId)
            ->where('status', 'active')
            ->get()->getResultArray();
        if ($active === []) {
            return 0;
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('delegations')
            ->where('organization_id', $organizationId)
            ->where('source_grant_type', $sourceGrantType)
            ->where('source_grant_id', $sourceGrantId)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => $now, 'revoked_by' => $actorId]);

        $this->audit->record($organizationId, [
            'action'      => 'acl.delegation.cascade_revoke',
            'actor_id'    => $actorId,
            'actor_type'  => 'system',
            'object_type' => $sourceGrantType,
            'object_id'   => $sourceGrantId,
            'outcome'     => 'success',
            'metadata'    => ['reason' => $reason, 'cascaded' => count($active)],
        ]);

        return count($active);
    }

    /**
     * Expire active delegations whose ROOT source grant is no longer live (gap
     * AC2, sweep path): a role_assignment that isn't `active` or an access_request
     * that isn't `approved`. Called from the expiry sweep so a lapsed (not just
     * explicitly revoked) grant still tears down its derived delegations.
     * Idempotent. Returns the number expired.
     */
    public function expireOrphanedBySource(?string $organizationId, string $now): int
    {
        $q = $this->db->table('delegations')
            ->where('status', 'active')
            ->where('source_grant_id IS NOT NULL');
        if ($organizationId !== null) {
            $q->where('organization_id', $organizationId);
        }
        $active = $q->get()->getResultArray();
        if ($active === []) {
            return 0;
        }

        $expired = 0;
        foreach ($active as $d) {
            $type = (string) ($d['source_grant_type'] ?? '');
            $id   = (string) ($d['source_grant_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $live = false;
            if ($type === 'role_assignment') {
                $src  = $this->db->table('role_assignments')->where('id', $id)->get()->getRowArray();
                $live = $src !== null && (string) $src['status'] === 'active';
            } elseif ($type === 'access_request') {
                $src  = $this->db->table('access_requests')->where('id', $id)->get()->getRowArray();
                $live = $src !== null && (string) $src['status'] === 'approved';
            } else {
                // Unknown/legacy provenance — leave it to its own window.
                continue;
            }
            if (! $live) {
                $this->db->table('delegations')->where('id', (string) $d['id'])->where('status', 'active')->update([
                    'status'     => 'revoked',
                    'revoked_at' => $now,
                    'revoked_by' => null,
                ]);
                $expired++;
            }
        }

        if ($expired > 0) {
            $this->audit->record((string) ($organizationId ?? ''), [
                'action'      => 'acl.delegation.expire_orphaned',
                'actor_id'    => null,
                'actor_type'  => 'system',
                'object_type' => 'delegation',
                'object_id'   => 'sweep',
                'outcome'     => 'success',
                'metadata'    => ['expired' => $expired, 'as_of' => $now],
            ]);
        }

        return $expired;
    }

    /**
     * The delegation chain rooted at a delegation, for traceability.
     *
     * @return list<array<string,mixed>>
     */
    public function chain(string $organizationId, string $delegationId): array
    {
        $ids = $this->collectSubtree($delegationId);
        if ($ids === []) {
            return [];
        }

        return $this->db->table('delegations')
            ->whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->orderBy('depth', 'ASC')->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Delegations currently held BY a subject (what they received).
     *
     * @return list<array<string,mixed>>
     */
    public function forDelegate(string $organizationId, string $delegateId, bool $activeOnly = true): array
    {
        $q = $this->db->table('delegations')
            ->where('organization_id', $organizationId)
            ->where('delegate_id', $delegateId);
        if ($activeOnly) {
            $q->where('status', 'active')->where('effective_to >', $this->clock->nowUtcString());
        }

        return $q->orderBy('created_at', 'DESC')->get()->getResultArray();
    }

    /**
     * Resolve the delegator's currently-effective authorities for a permission.
     * Each entry carries the scope, whether it includes descendants, its own
     * expiry (null = unbounded), and the depth a NEW delegation would take if
     * derived from it (`next_depth`) plus the source delegation id for chaining.
     *
     * @return list<array{scope_group_id:?string,mode:string,groups:list<string>,include_crosscut:bool,effective_to:?string,next_depth:int,delegation_id:?string}>
     */
    private function authoritiesFor(string $organizationId, string $subjectId, string $permission, string $now): array
    {
        $out = [];

        // 1) Role assignments conferring the permission.
        $roleRows = $this->db->table('role_assignments ra')
            ->select('ra.id, ra.scope_group_id, ra.scope_mode, ra.include_descendants, ra.include_crosscut, ra.effective_to')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $subjectId)
            ->where('ra.organization_id', $organizationId)
            ->where('p.code', $permission)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();
        foreach ($roleRows as $r) {
            // Root provenance: this delegation descends from THIS role assignment.
            $out[] = $this->authorityFromRow($r, 'role_assignment', 1, null, 'role_assignment', (string) $r['id']);
        }

        // 2) Approved direct permission grants (access requests).
        $directRows = $this->db->table('access_requests')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut, effective_to')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->where('grant_type', 'permission')
            ->where('permission_code', $permission)
            ->where('status', 'approved')
            ->groupStart()
                ->where('effective_to IS NULL')->orWhere('effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();
        foreach ($directRows as $r) {
            // Root provenance: this delegation descends from THIS access request.
            $out[] = $this->authorityFromRow($r, 'access_request', 1, null, 'access_request', (string) $r['id']);
        }

        // 3) Active delegations TO the subject — enables sub-delegation chains.
        $delegRows = $this->db->table('delegations')
            ->select('id, scope_group_id, scope_mode, include_descendants, include_crosscut, effective_to, depth, source_grant_type, source_grant_id')
            ->where('organization_id', $organizationId)
            ->where('delegate_id', $subjectId)
            ->where('permission_code', $permission)
            ->where('status', 'active')
            ->where('effective_from <=', $now)
            ->where('effective_to >', $now)
            ->get()->getResultArray();
        foreach ($delegRows as $r) {
            // A sub-delegation inherits its parent's ROOT provenance so the whole
            // chain shares one source_grant_id — a flat cascade catches all of it
            // (AC2). Fall back to the parent delegation itself for legacy rows
            // that predate the provenance columns.
            $rootType = $r['source_grant_type'] ?? 'delegation';
            $rootId   = $r['source_grant_id'] ?? (string) $r['id'];
            $out[] = $this->authorityFromRow($r, 'delegation', (int) $r['depth'] + 1, (string) $r['id'], (string) $rootType, (string) $rootId);
        }

        return $out;
    }

    /**
     * Normalize a grant row into a full-model authority descriptor, loading the
     * hand-picked group set when the row is in GROUPS mode.
     *
     * @param array<string,mixed> $r
     * @return array{scope_group_id:?string,mode:string,groups:list<string>,include_crosscut:bool,effective_to:?string,next_depth:int,delegation_id:?string}
     */
    private function authorityFromRow(array $r, string $grantType, int $nextDepth, ?string $delegationId, ?string $rootGrantType = null, ?string $rootGrantId = null): array
    {
        $mode = ScopeMode::normalize($r['scope_mode'] ?? null, $r['include_descendants'] ?? null);

        return [
            'scope_group_id'   => $this->normalizeGroup($r['scope_group_id'] ?? null),
            'mode'             => $mode,
            'groups'           => $mode === ScopeMode::GROUPS ? $this->scope->groupSet($grantType, (string) $r['id']) : [],
            'include_crosscut' => ! empty($r['include_crosscut']),
            'effective_to'     => $r['effective_to'] ?? null,
            'next_depth'       => $nextDepth,
            'delegation_id'    => $delegationId,
            // Root provenance (AC2) — the source grant the whole chain descends
            // from, so revoking that grant can cascade to this delegation.
            'source_grant_type' => $rootGrantType,
            'source_grant_id'   => $rootGrantId,
        ];
    }

    /**
     * True when the actor may revoke this delegation: the immediate delegator,
     * or any delegator up the parent chain (a leader can always revoke what was
     * sub-delegated from their own grant).
     *
     * @param array<string,mixed> $row
     */
    private function canRevoke(string $organizationId, string $actorId, array $row): bool
    {
        if ((string) $row['delegator_id'] === $actorId) {
            return true;
        }
        $parentId = $row['parent_id'] ?? null;
        while ($parentId !== null && $parentId !== '') {
            $parent = $this->db->table('delegations')
                ->select('delegator_id, parent_id')
                ->where('id', $parentId)->where('organization_id', $organizationId)
                ->get()->getRowArray();
            if ($parent === null) {
                break;
            }
            if ((string) $parent['delegator_id'] === $actorId) {
                return true;
            }
            $parentId = $parent['parent_id'] ?? null;
        }

        return false;
    }

    /**
     * Collect a delegation id plus every delegation sub-delegated beneath it.
     *
     * @return list<string>
     */
    private function collectSubtree(string $rootId): array
    {
        $all      = [$rootId];
        $frontier = [$rootId];
        // Bounded by max depth, so this terminates quickly.
        while ($frontier !== []) {
            $children = $this->db->table('delegations')
                ->select('id')
                ->whereIn('parent_id', $frontier)
                ->get()->getResultArray();
            $next = [];
            foreach ($children as $c) {
                $cid = (string) $c['id'];
                if (! in_array($cid, $all, true)) {
                    $all[]  = $cid;
                    $next[] = $cid;
                }
            }
            $frontier = $next;
        }

        return $all;
    }

    private function normalizeGroup(mixed $g): ?string
    {
        return $g !== null && $g !== '' ? (string) $g : null;
    }
}
