<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Resolves the AUTOMATIC sponsor (upline) for a new prospect/member.
 *
 * Every prospect/member has exactly one active sponsor. When a registration does
 * not carry an explicit sponsor (a referral link's referrer, or a `?sponsor=`),
 * the hierarchical group leader — or their delegate — becomes the sponsor. This
 * service is the single place that answer "who leads the group this person is
 * joining under?", walking UP the group hierarchy until it finds one, so a
 * registration is never left sponsor-less when any leader exists above it.
 *
 * Resolution order (first non-empty wins):
 *   1. explicit sponsor id, when supplied and valid (referral referrer / ?sponsor=);
 *   2. the leader (or delegated leader/admin/coordinator) of the target group;
 *   3. the nearest ANCESTOR group (nearest-first) that has such a leader;
 *   4. the earliest active leader/admin/coordinator anywhere in the organization
 *      (the org/national root leader) as a last resort.
 *
 * A group's "leader or delegate" reuses the EXISTING model — no new role tables:
 *   - groups.leader_user_id (the designated leader), else
 *   - the earliest active group_members row with role in (leader|admin|coordinator).
 * This mirrors GroupPublicService::resolveSponsor so self-join and registration
 * agree on who the sponsor is.
 *
 * Pure read model: it never writes. {@see SponsorshipService::assign()} persists
 * the edge (and enforces acyclicity + single-active); the caller passes the id
 * this resolver returns. A self-sponsor is impossible here because the new member
 * is not yet a leader of any group.
 */
final class SponsorResolver
{
    /** Roles that may act as a group's sponsor when no leader_user_id is set. */
    private const LEADER_ROLES = ['leader', 'admin', 'coordinator'];

    public function __construct(
        private readonly BaseConnection $db,
    ) {
    }

    /**
     * Resolve the sponsor id for a new member, or null when the organization has
     * no eligible leader at all (a brand-new org's very first account).
     *
     * @param array{explicit_sponsor_id?:?string, group_id?:?string, exclude_user_id?:?string} $ctx
     */
    public function resolve(string $organizationId, array $ctx = []): ?string
    {
        $exclude = self::str($ctx['exclude_user_id'] ?? null);

        // 1) Explicit sponsor (referral referrer / ?sponsor=) — validated to be a
        //    real, non-self user in this org.
        $explicit = self::str($ctx['explicit_sponsor_id'] ?? null);
        if ($explicit !== null && $explicit !== $exclude && $this->isOrgUser($organizationId, $explicit)) {
            return $explicit;
        }

        // 2) + 3) Target group leader, then nearest ancestor with a leader.
        $groupId = self::str($ctx['group_id'] ?? null);
        if ($groupId !== null) {
            foreach ($this->groupChain($organizationId, $groupId) as $gid) {
                $leader = $this->groupLeader($organizationId, $gid, $exclude);
                if ($leader !== null) {
                    return $leader;
                }
            }
        }

        // 4) Organization root leader — earliest active leader/admin/coordinator.
        return $this->orgRootLeader($organizationId, $exclude);
    }

    /**
     * The group's own id followed by its ancestors, nearest-first. Uses the
     * closure table when present; falls back to the materialized `path`.
     *
     * @return list<string>
     */
    private function groupChain(string $organizationId, string $groupId): array
    {
        $chain = [$groupId];

        if ($this->db->tableExists('group_closure')) {
            $rows = $this->db->table('group_closure')
                ->select('ancestor_id')
                ->where('descendant_id', $groupId)
                ->where('distance >', 0)
                ->orderBy('distance', 'ASC')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $chain[] = (string) $r['ancestor_id'];
            }

            return $chain;
        }

        // Fallback: parse the "/root/.../parent/" materialized path (root-first),
        // so we reverse it to nearest-first.
        $group = $this->db->table('groups')
            ->select('path')
            ->where('organization_id', $organizationId)
            ->where('id', $groupId)
            ->get()->getRowArray();
        $path = trim((string) ($group['path'] ?? ''), '/');
        if ($path !== '') {
            $ids = array_values(array_filter(
                explode('/', $path),
                static fn ($v) => $v !== '' && $v !== $groupId,
            ));
            foreach (array_reverse($ids) as $id) {
                $chain[] = (string) $id;
            }
        }

        return $chain;
    }

    /**
     * The leader (or delegated leader/admin/coordinator) of a single group, or
     * null. Prefers groups.leader_user_id, else the earliest active leadership
     * membership. Never returns $exclude (the new member themselves).
     */
    private function groupLeader(string $organizationId, string $groupId, ?string $exclude): ?string
    {
        $group = $this->db->table('groups')
            ->select('leader_user_id')
            ->where('organization_id', $organizationId)
            ->where('id', $groupId)
            ->get()->getRowArray();

        $leader = $group !== null ? self::str($group['leader_user_id'] ?? null) : null;
        if ($leader !== null && $leader !== $exclude && $this->isOrgUser($organizationId, $leader)) {
            return $leader;
        }

        $q = $this->db->table('group_members')
            ->select('user_id')
            ->where('organization_id', $organizationId)
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->whereIn('role', self::LEADER_ROLES);
        if ($exclude !== null) {
            $q->where('user_id !=', $exclude);
        }
        $row = $q->orderBy('joined_at', 'ASC')->get()->getRowArray();

        return $row !== null ? self::str($row['user_id']) : null;
    }

    /** Earliest active leadership membership anywhere in the org (root leader). */
    private function orgRootLeader(string $organizationId, ?string $exclude): ?string
    {
        $q = $this->db->table('group_members')
            ->select('user_id')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereIn('role', self::LEADER_ROLES);
        if ($exclude !== null) {
            $q->where('user_id !=', $exclude);
        }
        $row = $q->orderBy('joined_at', 'ASC')->get()->getRowArray();

        return $row !== null ? self::str($row['user_id']) : null;
    }

    /** True when $userId is a real user in this organization. */
    private function isOrgUser(string $organizationId, string $userId): bool
    {
        return $this->db->table('users')
            ->where('organization_id', $organizationId)
            ->where('id', $userId)
            ->countAllResults() > 0;
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
