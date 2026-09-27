<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

use CodeIgniter\Database\BaseConnection;

/**
 * DB-backed bridge that feeds the (pure) navigation layer from real ACL data.
 *
 * Responsibilities (all read-only):
 *   - Build the tenant RoleWordTable from role_permissions ⋈ permissions (the
 *     "authority" half -- tiny, cacheable, versioned).
 *   - Read a subject's role codes (optionally scope-filtered) from role_assignments.
 *   - Produce a STATELESS grant version for a subject: a short hash of their
 *     assignment set. It changes exactly when their assignments change, so it needs
 *     no counter table and no write-path INCR -- fully in the derive-don't-store
 *     spirit. (An explicit counter is a valid alternative; this avoids new state.)
 *
 * The heavy multi-table PDP is NOT used here: menu visibility is coarse capability,
 * so role->permission bits are sufficient and cheap. Per-instance authorization
 * still lives on the route (authorize: filter). See docs/DYNAMIC-MENU-FLOOR.md.
 *
 * The pure helpers (buildTableFromRows, versionFromAssignmentRows, roleCodesFromRows)
 * are separated from the query wrappers so they can be unit-tested without a DB.
 */
final class MenuWordProvider
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    // -- Public API ----------------------------------------------------------

    /**
     * The tenant role->word table. Reads every role and its permission codes for
     * the org and folds each into a capability word. Small result; cache upstream.
     */
    public function roleWordTable(string $organizationId): RoleWordTable
    {
        $rows = $this->db->table('roles r')
            ->select('r.code AS role_code, p.code AS perm_code')
            ->join('role_permissions rp', 'rp.role_id = r.id', 'left')
            ->join('permissions p', 'p.id = rp.permission_id', 'left')
            ->where('r.organization_id', $organizationId)
            ->get()->getResultArray();

        return self::buildTableFromRows($rows);
    }

    /**
     * A subject's role codes. When $scopeGroupId is given, include org-wide grants
     * (scope_group_id IS NULL) plus grants at that group. Active grants only when
     * the status column exists (later migrations); tolerant if it does not.
     *
     * @return list<string>
     */
    public function roleCodesForSubject(string $organizationId, string $subjectId, ?string $scopeGroupId = null): array
    {
        $b = $this->db->table('role_assignments ra')
            ->select('r.code AS role_code, ra.scope_group_id, ra.role_id')
            ->join('roles r', 'r.id = ra.role_id')
            ->where('ra.organization_id', $organizationId)
            ->where('ra.subject_id', $subjectId);

        $rows = $b->get()->getResultArray();

        return self::roleCodesFromRows($rows, $scopeGroupId);
    }

    /**
     * Stateless grant version for a subject: short hash of their (role, scope) set.
     * Changes iff their assignments change. Used as the ETag/cache-key version.
     */
    public function grantVersion(string $organizationId, string $subjectId): string
    {
        $rows = $this->db->table('role_assignments')
            ->select('role_id, scope_group_id')
            ->where('organization_id', $organizationId)
            ->where('subject_id', $subjectId)
            ->get()->getResultArray();

        return self::versionFromAssignmentRows($rows);
    }

    // -- Pure helpers (DB-free, unit-testable) -------------------------------

    /**
     * @param list<array<string,mixed>> $rows each: role_code, perm_code (perm_code
     *                                        may be null for a role with no perms)
     */
    public static function buildTableFromRows(array $rows): RoleWordTable
    {
        $byRole = [];
        foreach ($rows as $r) {
            $role = (string) ($r['role_code'] ?? '');
            if ($role === '') {
                continue;
            }
            $byRole[$role] ??= [];
            $perm = $r['perm_code'] ?? null;
            if ($perm !== null && $perm !== '') {
                $byRole[$role][] = (string) $perm;
            }
        }

        return RoleWordTable::fromRolePermissions($byRole);
    }

    /**
     * @param list<array<string,mixed>> $rows each: role_code, scope_group_id
     * @return list<string> distinct role codes in scope (org-wide + this group)
     */
    public static function roleCodesFromRows(array $rows, ?string $scopeGroupId): array
    {
        $codes = [];
        foreach ($rows as $r) {
            $scope = $r['scope_group_id'] ?? null;
            $scope = $scope === '' ? null : $scope;

            // Org-wide grants always apply; group-scoped grants apply only when the
            // active scope matches (null active scope = org view => org-wide only).
            $applies = $scope === null
                || ($scopeGroupId !== null && (string) $scope === $scopeGroupId);

            if ($applies) {
                $codes[(string) $r['role_code']] = true;
            }
        }

        return array_keys($codes);
    }

    /** @param list<array<string,mixed>> $rows each: role_id, scope_group_id */
    public static function versionFromAssignmentRows(array $rows): string
    {
        $tuples = [];
        foreach ($rows as $r) {
            $tuples[] = (string) ($r['role_id'] ?? '') . ':' . (string) ($r['scope_group_id'] ?? '');
        }
        sort($tuples);

        return substr(hash('xxh128', implode('|', $tuples)), 0, 12);
    }
}
