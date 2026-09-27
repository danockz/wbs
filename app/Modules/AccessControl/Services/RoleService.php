<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Administrative CRUD for the RBAC role catalogue and its permission grants
 * (SRS FR-ACL-002/003).
 *
 * Roles are ORGANIZATION-scoped templates (not group-scoped): a role binds a set
 * of catalogued permissions, and {@see RoleAssignmentService} later binds a role
 * to a subject WITHIN a group subtree. Because a role definition is org-wide in
 * effect, managing the catalogue requires an ORG-WIDE grant of the management
 * permission — the same coverage rule the PDP uses, expressed via
 * {@see GroupScopeResolver::grantCovers()} against a null target (only a null,
 * i.e. org-wide, scope covers an org-wide action).
 *
 * Two safety rails enforce FR-ACL-002 ("wildcards / broad grants need extra
 * controls", least privilege):
 *
 *   1. SYSTEM roles (the seeded org_admin, finance, … marked is_system) cannot
 *      be re-coded, have their permission set rewritten, or be deleted.
 *   2. PRIVILEGE-ESCALATION guard: an issuer may only grant a role permissions
 *      the issuer THEMSELVES currently holds — unless they hold admin.manage.
 *      This stops a delegated role-admin from minting a super-role.
 *
 * Permissions are centrally catalogued; this service never mints ad-hoc
 * permission codes, it only binds EXISTING ones to a role. Every mutation
 * appends a hash-chained audit entry.
 */
final class RoleService
{
    /** Permission that authorizes managing the role catalogue. */
    public const MANAGE_PERMISSION = 'access.role.manage';

    /** Holding this permission bypasses the privilege-escalation guard. */
    private const SUPER_PERMISSION = 'admin.manage';

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupScopeResolver $groupScope,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Create a new (non-system) role.
     *
     * @param array<string,mixed> $data code, name, description?
     */
    public function create(string $organizationId, string $issuerId, array $data): Result
    {
        $code = $this->normalizeCode($data['code'] ?? '');
        if ($code === '') {
            return Result::fail('CODE_REQUIRED', 'acl.role_code_required', 422);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'acl.role_name_required', 422);
        }

        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.role_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $dupe = $this->db->table('roles')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->get()->getRowArray();
        if ($dupe !== null) {
            return Result::fail('ROLE_EXISTS', 'acl.role_exists', 409);
        }

        $now = $this->clock->nowUtcString();
        $id  = Uuid::v7();
        $this->db->table('roles')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'code'            => $code,
            'name'            => $name,
            'description'     => $this->normalizeDescription($data['description'] ?? null),
            'is_system'       => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->recordAudit($organizationId, $issuerId, 'acl.role.create', $id, ['code' => $code, 'name' => $name]);

        return Result::created(['role_id' => $id, 'code' => $code, 'name' => $name]);
    }

    /**
     * Update a role's name/description (never its code once created).
     *
     * @param array<string,mixed> $data name?, description?
     */
    public function update(string $organizationId, string $issuerId, string $roleId, array $data): Result
    {
        $role = $this->findRole($organizationId, $roleId);
        if ($role === null) {
            return Result::notFound('acl.role_not_found', 'ROLE_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.role_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }

        $update = ['updated_at' => $this->clock->nowUtcString()];
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return Result::fail('NAME_REQUIRED', 'acl.role_name_required', 422);
            }
            $update['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = $this->normalizeDescription($data['description']);
        }

        $this->db->table('roles')->where('id', $roleId)->update($update);
        $this->recordAudit($organizationId, $issuerId, 'acl.role.update', $roleId, array_diff_key($update, ['updated_at' => true]));

        return Result::ok(['role_id' => $roleId, 'status' => 'updated']);
    }

    /**
     * Delete a role. Refused for system roles and for roles still referenced by
     * any assignment (history/audit integrity) — revoke assignments first.
     */
    public function delete(string $organizationId, string $issuerId, string $roleId): Result
    {
        $role = $this->findRole($organizationId, $roleId);
        if ($role === null) {
            return Result::notFound('acl.role_not_found', 'ROLE_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.role_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }
        if (! empty($role['is_system'])) {
            return Result::fail('ROLE_IS_SYSTEM', 'acl.role_is_system', 409);
        }

        $inUse = (int) ($this->db->table('role_assignments')
            ->where('role_id', $roleId)->countAllResults());
        if ($inUse > 0) {
            return Result::fail('ROLE_IN_USE', 'acl.role_in_use', 409, ['assignments' => $inUse]);
        }

        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        $this->db->table('roles')->where('id', $roleId)->delete();

        $this->recordAudit($organizationId, $issuerId, 'acl.role.delete', $roleId, ['code' => $role['code']]);

        return Result::ok(['role_id' => $roleId, 'status' => 'deleted']);
    }

    /**
     * Replace the permission set bound to a role (role_permissions CRUD).
     *
     * @param list<string> $permissionCodes
     */
    public function setPermissions(string $organizationId, string $issuerId, string $roleId, array $permissionCodes): Result
    {
        $role = $this->findRole($organizationId, $roleId);
        if ($role === null) {
            return Result::notFound('acl.role_not_found', 'ROLE_NOT_FOUND');
        }
        if (! $this->issuerCanManage($organizationId, $issuerId)) {
            return Result::denied('acl.role_manage_denied', 'ACCESS_OUT_OF_SCOPE');
        }
        if (! empty($role['is_system'])) {
            return Result::fail('ROLE_IS_SYSTEM', 'acl.role_is_system', 409);
        }

        // De-dupe and validate the codes exist in the central catalogue.
        $codes = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $permissionCodes,
        ), static fn ($c) => $c !== '')));

        $catalog = [];
        if ($codes !== []) {
            $rows = $this->db->table('permissions')->whereIn('code', $codes)->get()->getResultArray();
            foreach ($rows as $r) {
                $catalog[(string) $r['code']] = (string) $r['id'];
            }
        }
        $unknown = array_values(array_diff($codes, array_keys($catalog)));
        if ($unknown !== []) {
            return Result::fail('UNKNOWN_PERMISSION', 'acl.unknown_permission', 422, ['unknown' => $unknown]);
        }

        // Privilege-escalation guard: the issuer must hold every permission they
        // grant, unless they hold the super permission.
        $held = $this->issuerPermissionCodes($organizationId, $issuerId);
        if (! in_array(self::SUPER_PERMISSION, $held, true)) {
            $escalation = array_values(array_diff($codes, $held));
            if ($escalation !== []) {
                return Result::denied('acl.role_privilege_escalation', 'PRIVILEGE_ESCALATION');
            }
        }

        // Replace the grant set atomically.
        $this->db->transStart();
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();
        if ($codes !== []) {
            $batch = [];
            foreach ($codes as $code) {
                $batch[] = ['role_id' => $roleId, 'permission_id' => $catalog[$code]];
            }
            $this->db->table('role_permissions')->insertBatch($batch);
        }
        $this->db->table('roles')->where('id', $roleId)->update(['updated_at' => $this->clock->nowUtcString()]);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('ROLE_PERMISSIONS_FAILED', 'acl.role_permissions_failed', 500);
        }

        $this->recordAudit($organizationId, $issuerId, 'acl.role.permissions.set', $roleId, ['permissions' => $codes]);

        return Result::ok(['role_id' => $roleId, 'permissions' => $codes]);
    }

    /**
     * List roles with their permission codes (management view).
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $organizationId): array
    {
        $roles = $this->db->table('roles')
            ->where('organization_id', $organizationId)
            ->orderBy('code', 'ASC')
            ->get()->getResultArray();

        foreach ($roles as &$role) {
            $role['permissions'] = $this->permissionCodesFor((string) $role['id']);
        }

        return $roles;
    }

    /**
     * The full central permission catalogue (code + description), for building a
     * permission picker on the role form. Read-only; a single ordered query over
     * the small, org-independent `permissions` table (resource-light — no per-row
     * lookups). The role form uses it to render the checkbox set.
     *
     * @return list<array{code:string,description:string}>
     */
    public function listPermissionCatalog(): array
    {
        $rows = $this->db->table('permissions')
            ->select('code, description')
            ->orderBy('code', 'ASC')
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r) => [
            'code'        => (string) ($r['code'] ?? ''),
            'description' => (string) ($r['description'] ?? ''),
        ], $rows));
    }

    /** Read one role with its permission codes. */
    public function show(string $organizationId, string $roleId): Result
    {
        $role = $this->findRole($organizationId, $roleId);
        if ($role === null) {
            return Result::notFound('acl.role_not_found', 'ROLE_NOT_FOUND');
        }
        $role['permissions'] = $this->permissionCodesFor($roleId);

        return Result::ok($role);
    }

    // ---------------------------------------------------------------------

    /**
     * True when the issuer holds the role-management permission at ORG-WIDE
     * scope. Reuses the PDP coverage rule: only a null (org-wide) grant scope
     * covers an org-wide target, so a merely group-scoped manager cannot rewrite
     * the shared catalogue.
     */
    private function issuerCanManage(string $organizationId, string $issuerId): bool
    {
        $now = $this->clock->nowUtcString();

        $grants = $this->db->table('role_assignments ra')
            ->select('ra.scope_group_id, ra.include_descendants')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $issuerId)
            ->where('ra.organization_id', $organizationId)
            ->where('p.code', self::MANAGE_PERMISSION)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        foreach ($grants as $g) {
            $scope = isset($g['scope_group_id']) && $g['scope_group_id'] !== '' ? (string) $g['scope_group_id'] : null;
            $incl  = ! empty($g['include_descendants']);
            if ($this->groupScope->grantCovers($scope, $incl, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All permission codes the issuer currently holds via active role grants.
     *
     * @return list<string>
     */
    private function issuerPermissionCodes(string $organizationId, string $issuerId): array
    {
        $now = $this->clock->nowUtcString();

        $rows = $this->db->table('role_assignments ra')
            ->distinct()
            ->select('p.code')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('ra.subject_id', $issuerId)
            ->where('ra.organization_id', $organizationId)
            ->where('ra.status', 'active')
            ->groupStart()
                ->where('ra.effective_to IS NULL')
                ->orWhere('ra.effective_to >', $now)
            ->groupEnd()
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['code'], $rows));
    }

    /** @return list<string> */
    private function permissionCodesFor(string $roleId): array
    {
        $rows = $this->db->table('role_permissions rp')
            ->select('p.code')
            ->join('permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->orderBy('p.code', 'ASC')
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (string) $r['code'], $rows));
    }

    /** @return array<string,mixed>|null */
    private function findRole(string $organizationId, string $roleId): ?array
    {
        return $this->db->table('roles')
            ->where('id', $roleId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
    }

    private function normalizeCode(mixed $code): string
    {
        return strtolower(trim((string) $code));
    }

    private function normalizeDescription(mixed $d): ?string
    {
        $d = $d === null ? '' : trim((string) $d);

        return $d === '' ? null : $d;
    }

    /** @param array<string,mixed> $metadata */
    private function recordAudit(string $organizationId, string $issuerId, string $action, string $objectId, array $metadata): void
    {
        $this->audit->record($organizationId, [
            'action'      => $action,
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'role',
            'object_id'   => $objectId,
            'outcome'     => 'success',
            'metadata'    => $metadata,
        ]);
    }
}
