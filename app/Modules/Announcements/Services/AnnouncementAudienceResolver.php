<?php

declare(strict_types=1);

namespace WBS\Announcements\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Announcements\Support\AnnouncementScope;

/**
 * AND-across-dimensions audience for an announcement.
 *
 *   - Multiple rows of the SAME kind OR (any of these groups / any of these roles).
 *   - Different kinds AND (must sit in the group-scope AND have the role AND …).
 *   - kind=user rows are DIRECT MESSAGING only: they are the whole audience when
 *     no group/kind/role filter is set. They are NEVER extra-included into a
 *     filtered announcement.
 *   - Empty dimension = no constraint on it.
 *   - Completely unconstrained (no group, kind, role, or user) → empty (fail-closed).
 *
 * @phpstan-type Target array{kind:string,ref:string,scope_mode?:string}
 */
final class AnnouncementAudienceResolver
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<Target> $targets
     * @return list<string> distinct user ids
     */
    public function resolve(string $organizationId, array $targets): array
    {
        $by = ['group' => [], 'group_kind' => [], 'platform_role' => [], 'membership_role' => [], 'user' => []];
        foreach ($targets as $t) {
            $kind = (string) ($t['kind'] ?? '');
            $ref  = trim((string) ($t['ref'] ?? ''));
            if ($ref === '' || ! isset($by[$kind])) {
                continue;
            }
            $by[$kind][] = $t;
        }

        $named = [];
        foreach ($by['user'] as $t) {
            $id = trim((string) $t['ref']);
            if ($id !== '') {
                $named[$id] = true;
            }
        }

        $hasFilter = $by['group'] !== [] || $by['group_kind'] !== []
            || $by['platform_role'] !== [] || $by['membership_role'] !== [];
        // Named users are reserved for direct messaging — not a filter extra.
        if (! $hasFilter) {
            return array_keys($named);
        }

        $covered = $this->coveredGroupIds($organizationId, $by['group'], $by['group_kind']);
        if ($covered !== null && $covered === []) {
            return [];
        }

        $candidates = $this->memberUserIds($organizationId, $covered, $by['membership_role']);
        if ($by['platform_role'] !== []) {
            $allowed = $this->usersWithPlatformRoles($organizationId, $by['platform_role']);
            $candidates = array_values(array_filter($candidates, static fn ($id) => isset($allowed[$id])));
        }

        return $candidates;
    }

    /**
     * @param list<Target> $groupTargets
     * @param list<Target> $kindTargets
     * @return list<string>|null null = no group constraint
     */
    private function coveredGroupIds(string $organizationId, array $groupTargets, array $kindTargets): ?array
    {
        if ($groupTargets === [] && $kindTargets === []) {
            return null;
        }
        $ids = [];
        foreach ($groupTargets as $t) {
            foreach ($this->expand((string) $t['ref'], AnnouncementScope::normalize($t['scope_mode'] ?? '')) as $id) {
                $ids[$id] = true;
            }
        }
        if ($groupTargets === [] && $kindTargets !== []) {
            $codes = [];
            foreach ($kindTargets as $t) {
                $codes[strtolower(trim((string) $t['ref']))] = true;
            }
            foreach ($this->groupsWithKinds($organizationId, array_keys($codes)) as $id) {
                $ids[$id] = true;
            }

            return array_keys($ids);
        }
        if ($kindTargets !== []) {
            $codes = [];
            foreach ($kindTargets as $t) {
                $codes[strtolower(trim((string) $t['ref']))] = true;
            }
            $keep = [];
            foreach (array_keys($ids) as $id) {
                $g = $this->db->table('groups')->select('kind_code')->where('id', $id)->get()->getRowArray();
                $code = strtolower(trim((string) ($g['kind_code'] ?? '')));
                if (isset($codes[$code])) {
                    $keep[$id] = true;
                }
            }
            $ids = $keep;
        }

        return array_keys($ids);
    }

    /**
     * @param list<string>|null $groupIds
     * @param list<Target> $roleTargets
     * @return list<string>
     */
    private function memberUserIds(string $organizationId, ?array $groupIds, array $roleTargets): array
    {
        $roles = [];
        foreach ($roleTargets as $t) {
            $roles[strtolower(trim((string) $t['ref']))] = true;
        }

        if ($groupIds === null) {
            // No group scope: membership-role (if any) is org-wide; otherwise
            // platform-role-only queries start from every active member.
            $q = $this->db->table('group_members')
                ->select('user_id, role')
                ->where('organization_id', $organizationId)
                ->where('status', 'active');
            $rows = $q->get()->getResultArray();
        } else {
            if ($groupIds === []) {
                return [];
            }
            $rows = [];
            foreach ($groupIds as $gid) {
                foreach ($this->db->table('group_members')
                    ->select('user_id, role')
                    ->where('organization_id', $organizationId)
                    ->where('group_id', $gid)
                    ->where('status', 'active')
                    ->get()->getResultArray() as $r) {
                    $rows[] = $r;
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $uid = (string) ($r['user_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            if ($roles !== [] && ! isset($roles[strtolower(trim((string) ($r['role'] ?? '')))])) {
                continue;
            }
            $out[$uid] = true;
        }

        return array_keys($out);
    }

    /**
     * @param list<Target> $roleTargets
     * @return array<string,true>
     */
    private function usersWithPlatformRoles(string $organizationId, array $roleTargets): array
    {
        $codes = [];
        foreach ($roleTargets as $t) {
            $codes[strtolower(trim((string) $t['ref']))] = true;
        }
        $roleIds = [];
        foreach (array_keys($codes) as $code) {
            $row = $this->db->table('roles')->select('id')->where('code', $code)->get()->getRowArray();
            if ($row !== null) {
                $roleIds[(string) $row['id']] = true;
            }
        }
        $out = [];
        foreach (array_keys($roleIds) as $rid) {
            foreach ($this->db->table('role_assignments')
                ->select('user_id')
                ->where('role_id', $rid)
                ->get()->getResultArray() as $a) {
                $uid = (string) ($a['user_id'] ?? '');
                if ($uid !== '') {
                    $out[$uid] = true;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function expand(string $groupId, string $mode): array
    {
        if ($groupId === '') {
            return [];
        }
        if ($mode === AnnouncementScope::SELF) {
            return [$groupId];
        }
        if ($mode === AnnouncementScope::ANCESTORS) {
            $ids = [$groupId => true];
            foreach ($this->db->table('group_closure')
                ->select('ancestor_id, distance')
                ->where('descendant_id', $groupId)
                ->where('distance >', 0)
                ->get()->getResultArray() as $r) {
                $id = (string) ($r['ancestor_id'] ?? '');
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }

            return array_keys($ids);
        }
        $rows = $this->db->table('group_closure')
            ->select('descendant_id, distance')
            ->where('ancestor_id', $groupId)
            ->get()->getResultArray();
        $ids = [];
        foreach ($rows as $r) {
            $dist = (int) ($r['distance'] ?? 0);
            if ($mode === AnnouncementScope::DESCENDANTS_ONLY && $dist === 0) {
                continue;
            }
            $id = (string) ($r['descendant_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        if ($ids === [] && $mode === AnnouncementScope::SELF_AND_DESCENDANTS) {
            return [$groupId];
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private function groupsWithKinds(string $organizationId, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            foreach ($this->db->table('groups')
                ->select('id')
                ->where('organization_id', $organizationId)
                ->where('kind_code', $code)
                ->get()->getResultArray() as $g) {
                $id = (string) ($g['id'] ?? '');
                if ($id !== '') {
                    $out[] = $id;
                }
            }
        }

        return $out;
    }
}
