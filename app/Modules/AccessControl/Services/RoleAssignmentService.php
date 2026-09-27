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
 * Direct management of role assignments (SRS FR-ACL-003).
 *
 * The access-request workflow (FR-ACL-004) is the routine path; this service is
 * the administrative one — an authorized issuer directly grants or revokes a
 * role, at a group/resource scope, with an effective window, optional ABAC
 * conditions, and full audit. It is deliberately group-hierarchy-aware:
 *
 *  - An issuer may only assign or revoke WITHIN a branch their OWN grant of the
 *    management permission covers (delegated administration). The authoritative
 *    hierarchy check reuses {@see GroupScopeResolver::grantCovers()}, exactly as
 *    the PDP does, so "covers" means the same everywhere.
 *  - Assignments are expiring by default and every mutation appends a
 *    hash-chained audit entry (FR-ACL-003: "documented, and audited").
 *
 * Roles are templates and permissions are centrally cataloged (FR-ACL-002/003),
 * so this service never mints ad-hoc permissions — it only binds an existing
 * role to a subject within a scope.
 */
final class RoleAssignmentService
{
    /** Permission that authorizes managing role assignments. */
    public const MANAGE_PERMISSION = 'access.assignment.manage';

    /** Default lifetime when a caller does not specify one (days). */
    private const DEFAULT_DURATION_DAYS = 365;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupScopeResolver $groupScope,
        private readonly AuditLogger $audit,
        private readonly ?DelegationService $delegations = null,
    ) {
    }

    /**
     * Grant (or refresh) a role assignment for a subject within a scope.
     *
     * @param array<string,mixed> $data role_id, scope_group_id?, include_descendants?,
     *                                   duration_days?, conditions?
     */
    public function assign(string $organizationId, string $issuerId, array $data): Result
    {
        $roleId = (string) ($data['role_id'] ?? '');
        if ($roleId === '') {
            return Result::fail('ROLE_REQUIRED', 'acl.role_required', 422);
        }
        $subjectId = (string) ($data['subject_id'] ?? '');
        if ($subjectId === '') {
            return Result::fail('SUBJECT_REQUIRED', 'acl.subject_required', 422);
        }

        // Role must exist and belong to this org (roles are org-scoped templates).
        $role = $this->db->table('roles')
            ->where('id', $roleId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($role === null) {
            return Result::notFound('acl.role_not_found', 'ROLE_NOT_FOUND');
        }

        $scopeGroupId = $this->normalizeGroup($data['scope_group_id'] ?? null);
        $scopeMode    = ScopeMode::normalize($data['scope_mode'] ?? null, $data['include_descendants'] ?? null);
        $scopeGroups  = [];
        if ($scopeMode === ScopeMode::GROUPS) {
            $raw = $data['scope_groups'] ?? [];
            if (! is_array($raw) || $raw === []) {
                return Result::fail('GROUPS_REQUIRED', 'acl.assignment_groups_required', 422);
            }
            $scopeGroups = array_values(array_unique(array_map('strval', $raw)));
        } elseif ($scopeGroupId === null && $scopeMode !== ScopeMode::SELF) {
            return Result::fail('SCOPE_GROUP_REQUIRED', 'acl.assignment_scope_group_required', 422, ['mode' => $scopeMode]);
        }
        // Kept for back-compat storage; derived from the mode.
        $includeDesc     = ScopeMode::includesDescendants($scopeMode);
        $includeCrosscut = ! empty($data['include_crosscut']);
        $targetScope = [
            'group_id' => $scopeGroupId,
            'mode'     => $scopeMode,
            'groups'   => $scopeGroups,
            'crosscut' => $includeCrosscut,
        ];

        // Authorization to sub-assign. TWO paths, either sufficient:
        //   (a) classic delegated-admin — the issuer holds access.assignment.manage
        //       covering the target scope; OR
        //   (b) leadership containment — the issuer holds, over a scope that
        //       CONTAINS the target scope, every permission the assigned role
        //       grants. This lets ANY leader hand out responsibilities within
        //       their own scope, bounded by what they themselves hold there,
        //       without a separate org-admin step.
        if (! $this->issuerCanAssign($organizationId, $issuerId, $roleId, $targetScope)) {
            return Result::denied('acl.assignment_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        $conditions = $this->encodeConditions($data['conditions'] ?? null);
        if ($conditions === false) {
            return Result::fail('BAD_CONDITIONS', 'acl.bad_conditions', 422);
        }

        $now  = $this->clock->nowUtcString();
        $from = $now;
        $days = isset($data['duration_days']) ? max(1, (int) $data['duration_days']) : self::DEFAULT_DURATION_DAYS;
        $to   = $this->clock->now()->modify("+{$days} days")->format('Y-m-d H:i:s');

        // Idempotent on the UNIQUE (subject_id, role_id, scope_group_id) triple:
        // refresh an existing row rather than duplicate it.
        $existing = $this->db->table('role_assignments')
            ->where('subject_id', $subjectId)
            ->where('role_id', $roleId)
            ->where('scope_group_id', $scopeGroupId)
            ->get()->getRowArray();

        if ($existing !== null) {
            $this->db->table('role_assignments')->where('id', $existing['id'])->update([
                'status'              => 'active',
                'scope_mode'          => $scopeMode,
                'include_crosscut'    => (int) $includeCrosscut,
                'include_descendants' => (int) $includeDesc,
                'conditions'          => $conditions,
                'source'              => 'admin',
                'issued_by'           => $issuerId,
                'effective_from'      => $from,
                'effective_to'        => $to,
                'revoked_at'          => null,
            ]);
            $id = (string) $existing['id'];
        } else {
            $id = Uuid::v7();
            $this->db->table('role_assignments')->insert([
                'id'                  => $id,
                'organization_id'     => $organizationId,
                'subject_id'          => $subjectId,
                'role_id'             => $roleId,
                'scope_group_id'      => $scopeGroupId,
                'scope_mode'          => $scopeMode,
                'include_crosscut'    => (int) $includeCrosscut,
                'status'              => 'active',
                'include_descendants' => (int) $includeDesc,
                'conditions'          => $conditions,
                'source'              => 'admin',
                'issued_by'           => $issuerId,
                'effective_from'      => $from,
                'effective_to'        => $to,
                'created_at'          => $now,
            ]);
        }
        $this->syncGroupSet($organizationId, $id, $scopeMode, $scopeGroups);

        $this->audit->record($organizationId, [
            'action'      => 'acl.assignment.grant',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'role_assignment',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => [
                'subject_id'          => $subjectId,
                'role_id'             => $roleId,
                'scope_group_id'      => $scopeGroupId,
                'include_descendants' => $includeDesc,
                'effective_to'        => $to,
            ],
        ]);

        return Result::created([
            'assignment_id'  => $id,
            'subject_id'     => $subjectId,
            'role_id'        => $roleId,
            'scope_group_id' => $scopeGroupId,
            'effective_to'   => $to,
            'status'         => 'active',
        ]);
    }

    /**
     * Revoke an assignment. Soft (status=revoked + revoked_at) so history and
     * the audit chain remain intact.
     */
    public function revoke(string $organizationId, string $issuerId, string $assignmentId, string $reason): Result
    {
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'acl.reason_required', 422);
        }

        $row = $this->db->table('role_assignments')
            ->where('id', $assignmentId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('acl.assignment_not_found', 'ASSIGNMENT_NOT_FOUND');
        }

        $targetScope = [
            'group_id' => $this->normalizeGroup($row['scope_group_id'] ?? null),
            'mode'     => ScopeMode::normalize($row['scope_mode'] ?? null, $row['include_descendants'] ?? null),
            'groups'   => $this->grantGroupSet('role_assignment', (string) $row['id']),
            'crosscut' => ! empty($row['include_crosscut']),
        ];
        // Same two-path authority as assign: delegated-admin OR leadership
        // containment over the assigned role at the assignment's scope.
        if (! $this->issuerCanAssign($organizationId, $issuerId, (string) $row['role_id'], $targetScope)) {
            return Result::denied('acl.assignment_out_of_scope', 'ACCESS_OUT_OF_SCOPE');
        }

        $this->db->table('role_assignments')->where('id', $assignmentId)->update([
            'status'     => 'revoked',
            'revoked_at' => $this->clock->nowUtcString(),
        ]);

        // Cascade to delegations carved from this assignment (gap AC2): a
        // delegate cannot keep lent authority once the role that authorized the
        // loan is revoked.
        $cascaded = 0;
        if ($this->delegations !== null) {
            $cascaded = $this->delegations->revokeBySourceGrant(
                $organizationId,
                'role_assignment',
                $assignmentId,
                $issuerId,
                'source role assignment revoked',
            );
        }

        $this->audit->record($organizationId, [
            'action'      => 'acl.assignment.revoke',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'role_assignment',
            'object_id'   => $assignmentId,
            'outcome'     => 'success',
            'metadata'    => ['subject_id' => $row['subject_id'], 'reason' => $reason, 'delegations_cascaded' => $cascaded],
        ]);

        return Result::ok(['assignment_id' => $assignmentId, 'status' => 'revoked', 'delegations_cascaded' => $cascaded]);
    }

    /**
     * List a subject's assignments (management view). Includes lifecycle fields
     * so an admin can see scope, window, and status.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSubject(string $organizationId, string $subjectId): array
    {
        return $this->db->table('role_assignments ra')
            ->select('ra.id, ra.role_id, r.code AS role_code, r.name AS role_name, '
                . 'ra.scope_group_id, ra.include_descendants, ra.status, '
                . 'ra.effective_from, ra.effective_to, ra.issued_by, ra.source')
            ->join('roles r', 'r.id = ra.role_id', 'left')
            ->where('ra.organization_id', $organizationId)
            ->where('ra.subject_id', $subjectId)
            ->orderBy('ra.created_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Read one assignment (management view).
     */
    public function show(string $organizationId, string $assignmentId): Result
    {
        $row = $this->db->table('role_assignments ra')
            ->select('ra.*, r.code AS role_code, r.name AS role_name')
            ->join('roles r', 'r.id = ra.role_id', 'left')
            ->where('ra.id', $assignmentId)->where('ra.organization_id', $organizationId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('acl.assignment_not_found', 'ASSIGNMENT_NOT_FOUND');
        }

        return Result::ok($row);
    }

    /**
     * True when the issuer holds the assignment-management permission covering
     * the target scope. Mirrors the PDP's grant-coverage rule so delegated
     * administration is hierarchy-correct: an org-wide grant covers everything;
     * a group-scoped grant covers its own group (and descendants when the grant
     * says so); an org-wide target needs an org-wide grant.
     */
    /**
     * Whether the issuer may assign/revoke $roleId at the target scope. True if
     * EITHER path holds:
     *
     *   (a) classic delegated administration — the issuer holds
     *       access.assignment.manage over a scope that CONTAINS the target scope;
     *   (b) leadership containment — the issuer personally holds, over a scope
     *       that contains the target scope, EVERY permission the assigned role
     *       grants (you can only hand out what you yourself hold there).
     *
     * @param array{group_id:?string,mode:string,groups:list<string>,crosscut?:bool} $targetScope
     */
    private function issuerCanAssign(string $organizationId, string $issuerId, string $roleId, array $targetScope): bool
    {
        if ($this->issuerCanManage($organizationId, $issuerId, $targetScope)) {
            return true;
        }

        return $this->issuerContainsRole($organizationId, $issuerId, $roleId, $targetScope);
    }

    /**
     * Delegated-admin path: the issuer holds the management permission over a
     * scope that contains the target scope.
     *
     * @param array{group_id:?string,mode:string,groups:list<string>,crosscut?:bool} $targetScope
     */
    private function issuerCanManage(string $organizationId, string $issuerId, array $targetScope): bool
    {
        foreach ($this->issuerGrantsFor($organizationId, $issuerId, self::MANAGE_PERMISSION) as $g) {
            if ($this->groupScope->scopeContains(
                $g['scope'], $g['mode'], $g['groups'],
                $targetScope['group_id'], $targetScope['mode'], $targetScope['groups'],
                $g['crosscut'], ! empty($targetScope['crosscut']),
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leadership-containment path: for EVERY permission the assigned role grants,
     * the issuer must hold that permission over a scope that contains the target
     * scope. A leader thus can only hand out responsibilities they themselves
     * carry within (or above) that scope.
     *
     * @param array{group_id:?string,mode:string,groups:list<string>,crosscut?:bool} $targetScope
     */
    private function issuerContainsRole(string $organizationId, string $issuerId, string $roleId, array $targetScope): bool
    {
        $permRows = $this->db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()->getResultArray();
        $perms = array_values(array_unique(array_map(static fn ($r): string => (string) $r['code'], $permRows)));
        if ($perms === []) {
            return false; // a role granting nothing is not "held" by anyone meaningfully
        }

        foreach ($perms as $code) {
            $covered = false;
            foreach ($this->issuerGrantsFor($organizationId, $issuerId, $code) as $g) {
                if ($this->groupScope->scopeContains(
                    $g['scope'], $g['mode'], $g['groups'],
                    $targetScope['group_id'], $targetScope['mode'], $targetScope['groups'],
                    $g['crosscut'], ! empty($targetScope['crosscut']),
                )) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    /**
     * Active assignments held by the issuer that grant permission $code, returned
     * as normalized scope descriptors.
     *
     * @return list<array{scope:?string,mode:string,groups:list<string>,crosscut:bool}>
     */
    private function issuerGrantsFor(string $organizationId, string $issuerId, string $code): array
    {
        $now = $this->clock->nowUtcString();
        $grants = $this->db->table('role_assignments ra')
            ->select('ra.id, ra.scope_group_id, ra.scope_mode, ra.include_descendants, ra.include_crosscut')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $issuerId)
            ->where('ra.organization_id', $organizationId)
            ->where('p.code', $code)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        $out = [];
        foreach ($grants as $g) {
            $mode = ScopeMode::normalize($g['scope_mode'] ?? null, $g['include_descendants'] ?? null);
            $out[] = [
                'scope'    => isset($g['scope_group_id']) && $g['scope_group_id'] !== '' ? (string) $g['scope_group_id'] : null,
                'mode'     => $mode,
                'groups'   => $mode === ScopeMode::GROUPS ? $this->grantGroupSet('role_assignment', (string) $g['id']) : [],
                'crosscut' => ! empty($g['include_crosscut']),
            ];
        }

        return $out;
    }

    /** @param list<string> $groups */
    private function syncGroupSet(string $organizationId, string $assignmentId, string $mode, array $groups): void
    {
        $this->db->table('grant_scope_groups')
            ->where('grant_type', 'role_assignment')->where('grant_id', $assignmentId)->delete();
        if ($mode !== ScopeMode::GROUPS) {
            return;
        }
        $now = $this->clock->nowUtcMicro();
        foreach ($groups as $gid) {
            $this->db->table('grant_scope_groups')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'grant_type'      => 'role_assignment',
                'grant_id'        => $assignmentId,
                'group_id'        => (string) $gid,
                'created_at'      => $now,
            ]);
        }
    }

    /** @return list<string> */
    private function grantGroupSet(string $grantType, string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', $grantType)->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    private function normalizeGroup(mixed $g): ?string
    {
        return $g !== null && $g !== '' ? (string) $g : null;
    }

    /**
     * Encode optional ABAC conditions to JSON, or null when none. Returns false
     * on an invalid (non-array) conditions payload.
     *
     * @return string|null|false
     */
    private function encodeConditions(mixed $conditions): string|null|false
    {
        if ($conditions === null || $conditions === '') {
            return null;
        }
        if (! is_array($conditions)) {
            return false;
        }

        return json_encode($conditions, JSON_UNESCAPED_UNICODE);
    }
}
