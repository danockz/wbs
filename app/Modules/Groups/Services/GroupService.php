<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Group hierarchy management (SRS FR-GRP-001..007).
 *
 * Enforces the configurable 1–9 depth limit, keeps the tree ACYCLIC, and
 * maintains the closure-table projection on every create/move so ancestor and
 * descendant queries are recursion-free. A move that would exceed max depth,
 * create a cycle, or re-parent under its own descendant is rejected.
 */
final class GroupService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupKindService $kinds,
    ) {
    }

    /**
     * Set or clear a group's classification (kind). Passing null clears it. A
     * non-null code must reference a defined, active kind. Placement/scope is
     * unaffected — this only labels the group.
     */
    public function setKind(string $organizationId, string $groupId, ?string $kindCode): Result
    {
        $group = $this->db->table('groups')
            ->where('organization_id', $organizationId)->where('id', $groupId)
            ->get()->getRowArray();
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $code = $kindCode !== null && $kindCode !== '' ? strtolower(trim($kindCode)) : null;
        if ($code !== null && ! $this->kinds->isValidCode($organizationId, $code)) {
            return Result::fail('BAD_KIND', 'group.bad_kind', 422, ['kind_code' => $code]);
        }

        $this->db->table('groups')->where('id', $groupId)->update([
            'kind_code'  => $code,
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $groupId, 'kind_code' => $code]);
    }

    /**
     * Create a group under an optional parent. Depth = parent.depth + 1 and must
     * not exceed the organization's max_group_depth.
     *
     * @param array<string,mixed> $data name, type, kind_code, leader_user_id
     */
    public function create(string $organizationId, ?string $parentId, array $data): Result
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'group.name_required', 422);
        }

        // Optional classification (kind). When supplied it must reference a
        // defined, active kind for the org; scope evaluation is unaffected either
        // way (placement vs kind are orthogonal by design).
        $kindCode = isset($data['kind_code']) && $data['kind_code'] !== ''
            ? strtolower(trim((string) $data['kind_code'])) : null;
        if ($kindCode !== null && ! $this->kinds->isValidCode($organizationId, $kindCode)) {
            return Result::fail('BAD_KIND', 'group.bad_kind', 422, ['kind_code' => $kindCode]);
        }

        $maxDepth = $this->maxDepth($organizationId);

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->db->table('groups')->where('id', $parentId)->get()->getRowArray();
            if ($parent === null) {
                return Result::notFound('group.parent_not_found', 'PARENT_NOT_FOUND');
            }
        }

        $depth = $parent !== null ? (int) $parent['depth'] + 1 : 1;
        if ($depth > $maxDepth) {
            return Result::fail('MAX_DEPTH', 'group.max_depth', 422, ['max_depth' => $maxDepth]);
        }

        $slug = $this->uniqueSlug($organizationId, $name);
        $id   = Uuid::v7();
        $now  = $this->clock->nowUtcString();
        $path = ($parent !== null ? $parent['path'] : '/') . $id . '/';

        $this->db->transStart();

        $this->db->table('groups')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'parent_id'       => $parentId,
            'name'            => $name,
            'slug'            => $slug,
            'type'            => $data['type'] ?? null,
            'kind_code'       => $kindCode,
            'depth'           => $depth,
            'path'            => $path,
            'leader_user_id'  => $data['leader_user_id'] ?? null,
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // Closure: self row (distance 0) + one row per ancestor (distance+1).
        $this->db->table('group_closure')->insert([
            'ancestor_id' => $id, 'descendant_id' => $id, 'distance' => 0,
        ]);
        if ($parentId !== null) {
            $ancestors = $this->db->table('group_closure')
                ->where('descendant_id', $parentId)
                ->get()->getResultArray();
            foreach ($ancestors as $a) {
                $this->db->table('group_closure')->insert([
                    'ancestor_id'   => $a['ancestor_id'],
                    'descendant_id' => $id,
                    'distance'      => (int) $a['distance'] + 1,
                ]);
            }
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('CREATE_FAILED', 'group.create_failed', 500);
        }

        return Result::created(['id' => $id, 'slug' => $slug, 'depth' => $depth]);
    }

    /**
     * Move a group (and its subtree) under a new parent. Rejects cycles, self-
     * parenting, and any move that pushes the subtree past max depth.
     */
    public function move(string $groupId, ?string $newParentId): Result
    {
        $group = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }
        if ($newParentId === $groupId) {
            return Result::fail('CYCLE', 'group.cycle', 422);
        }

        $newParent = null;
        if ($newParentId !== null) {
            $newParent = $this->db->table('groups')->where('id', $newParentId)->get()->getRowArray();
            if ($newParent === null) {
                return Result::notFound('group.parent_not_found', 'PARENT_NOT_FOUND');
            }
            // A descendant cannot become the new parent (would create a cycle).
            $isDescendant = $this->db->table('group_closure')
                ->where('ancestor_id', $groupId)
                ->where('descendant_id', $newParentId)
                ->countAllResults() > 0;
            if ($isDescendant) {
                return Result::fail('CYCLE', 'group.cycle', 422);
            }
        }

        $maxDepth      = $this->maxDepth((string) $group['organization_id']);
        $subtreeHeight = $this->subtreeHeight($groupId);
        $newDepth      = $newParent !== null ? (int) $newParent['depth'] + 1 : 1;
        if ($newDepth + $subtreeHeight - 1 > $maxDepth) {
            return Result::fail('MAX_DEPTH', 'group.max_depth', 422, ['max_depth' => $maxDepth]);
        }

        $this->db->transStart();

        // Rebuild closure for the moved subtree.
        $subtree = $this->db->table('group_closure')
            ->where('ancestor_id', $groupId)
            ->get()->getResultArray();
        $subtreeIds = array_map(static fn ($r) => $r['descendant_id'], $subtree);

        // Remove edges pointing INTO the subtree from outside it.
        $this->db->table('group_closure')
            ->whereIn('descendant_id', $subtreeIds)
            ->whereNotIn('ancestor_id', $subtreeIds)
            ->delete();

        // Re-link every new ancestor to every subtree node.
        if ($newParentId !== null) {
            $newAncestors = $this->db->table('group_closure')
                ->where('descendant_id', $newParentId)
                ->get()->getResultArray();
            foreach ($newAncestors as $a) {
                foreach ($subtree as $d) {
                    $this->db->table('group_closure')->insert([
                        'ancestor_id'   => $a['ancestor_id'],
                        'descendant_id' => $d['descendant_id'],
                        'distance'      => (int) $a['distance'] + 1 + (int) $d['distance'],
                    ]);
                }
            }
        }

        // Update denormalized parent/depth/path across the subtree.
        $this->db->table('groups')->where('id', $groupId)->update([
            'parent_id'  => $newParentId,
            'updated_at' => $this->clock->nowUtcString(),
        ]);
        $this->recomputePathsAndDepth($groupId);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('MOVE_FAILED', 'group.move_failed', 500);
        }

        return Result::ok(['id' => $groupId, 'parent_id' => $newParentId]);
    }

    /** @return list<array<string,mixed>> ancestors nearest-first (excluding self) */
    public function ancestors(string $groupId): array
    {
        return $this->db->table('group_closure gc')
            ->select('g.*, gc.distance')
            ->join('groups g', 'g.id = gc.ancestor_id')
            ->where('gc.descendant_id', $groupId)
            ->where('gc.distance >', 0)
            ->orderBy('gc.distance', 'ASC')
            ->get()->getResultArray();
    }

    /** @return list<array<string,mixed>> the whole subtree (including self) */
    public function descendants(string $groupId): array
    {
        return $this->db->table('group_closure gc')
            ->select('g.*, gc.distance')
            ->join('groups g', 'g.id = gc.descendant_id')
            ->where('gc.ancestor_id', $groupId)
            ->orderBy('gc.distance', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Flat, hierarchy-ordered roster of an org's active groups for a PICKER
     * (the group-move landing page needs both the subject group and a candidate
     * new-parent list). Ordered by materialized `path` so the result reads as a
     * pre-order tree walk; `depth` lets the caller indent. Read-only, no closure
     * joins — cheap enough for a form dropdown.
     *
     * @return list<array<string,mixed>> id, name, slug, type, parent_id, depth, path, status
     */
    public function listForOrg(string $organizationId, int $limit = 500): array
    {
        // LEFT-join the kind taxonomy so callers can show a group's classification
        // (badge/label) without a second query. kind_code stays on the row for
        // back-compat; kind_name is the resolved display label (NULL if unset or
        // the kind was removed). Additive only — existing picker callers ignore it.
        return $this->db->table('groups g')
            ->select('g.id, g.name, g.slug, g.type, g.parent_id, g.depth, g.path, g.status, '
                . 'g.kind_code, gk.name AS kind_name', false)
            ->join('group_kinds gk', 'gk.organization_id = g.organization_id AND gk.code = g.kind_code', 'left')
            ->where('g.organization_id', $organizationId)
            ->where('g.status', 'active')
            ->orderBy('g.path', 'ASC')
            ->limit(max(1, min($limit, 2000)))
            ->get()->getResultArray();
    }

    /**
     * Active groups with their resolved Geo reference chain (country/state/city
     * names + ids) for the admin hierarchy browser's "by location" view. Mirrors
     * GroupPublicService::directory()'s LEFT-join chain but returns the admin
     * field set (id/name/slug/type/kind). Feed the rows to
     * GroupPublicService::nestByGeo() to build the Country → State → City tree.
     *
     * Geo is the VENUE's, not the group's: a group stores no place ids or
     * coordinates, it is placed by `primary_venue_id` and reads the venue's
     * stamped hierarchy (LocationSyncService is that stamp's only writer). One
     * LEFT hop, so a group with no venue returns with NULL levels and nests into
     * "Unlocated" rather than disappearing.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrgWithGeo(string $organizationId, int $limit = 2000): array
    {
        return $this->db->table('groups g')
            ->select(
                'g.id, g.name, g.slug, g.type, g.depth, g.kind_code, gk.name AS kind_name, '
                . 'g.primary_venue_id, '
                . 'v.country_id, v.state_id, v.city_id, '
                . 'v.country_label AS country_name, v.state_label AS state_name, v.city_label AS city_name',
                false,
            )
            ->join('group_kinds gk', 'gk.organization_id = g.organization_id AND gk.code = g.kind_code', 'left')
            ->join('venues v', 'v.id = g.primary_venue_id AND v.deleted_at IS NULL', 'left')
            ->where('g.organization_id', $organizationId)
            ->where('g.status', 'active')
            ->orderBy('g.name', 'ASC')
            ->limit(max(1, min($limit, 2000)))
            ->get()->getResultArray();
    }

    /**
     * Backward-compatible shim: a plain "member" add. FR-GRP-003 membership with
     * type/scope/approval evidence lives in GroupMembershipService::add(); this
     * writes a complete, active membership row so legacy callers keep working.
     */
    public function addMember(string $groupId, string $userId, string $role = 'member'): Result
    {
        $group = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }
        $exists = $this->db->table('group_members')
            ->where('group_id', $groupId)->where('user_id', $userId)
            ->where('membership_type', 'member')->where('status', 'active')
            ->countAllResults() > 0;
        if ($exists) {
            return Result::ok(['group_id' => $groupId, 'user_id' => $userId], 200, ['already_member' => true]);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('group_members')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $group['organization_id'],
            'group_id'        => $groupId,
            'user_id'         => $userId,
            'role'            => $role,
            'membership_type' => 'member',
            'status'          => 'active',
            'source'          => 'manual',
            'effective_from'  => $now,
            'approval_state'  => 'approved',
            'approved_at'     => $now,
            'active_key'      => hash('sha256', $userId . ':' . $groupId . ':member'),
            'joined_at'       => $now,
            'updated_at'      => $now,
        ]);

        return Result::created(['group_id' => $groupId, 'user_id' => $userId]);
    }

    /** Public landing-page theme options (mirror of GroupPublicService::THEMES). */
    public const PUBLIC_THEMES = ['aurora', 'sunrise', 'forest', 'slate'];

    /** Allowed public self-join policies. */
    private const JOIN_POLICIES = ['open', 'approval'];

    /**
     * Update a group's PUBLIC landing-page profile (theme, presentational and
     * contact fields, join settings). Only whitelisted keys are writable; the
     * theme and join policy are validated against their allowed sets.
     *
     * @param array<string,mixed> $data
     */
    public function updatePublicProfile(string $organizationId, string $groupId, array $data): Result
    {
        $group = $this->db->table('groups')
            ->where('id', $groupId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $update = [];

        if (array_key_exists('hero_theme', $data)) {
            $theme = (string) $data['hero_theme'];
            if (! in_array($theme, self::PUBLIC_THEMES, true)) {
                return Result::fail('INVALID_THEME', 'group.invalid_theme', 422, ['allowed' => self::PUBLIC_THEMES]);
            }
            $update['hero_theme'] = $theme;
        }

        if (array_key_exists('join_policy', $data)) {
            $policy = (string) $data['join_policy'];
            if (! in_array($policy, self::JOIN_POLICIES, true)) {
                return Result::fail('INVALID_JOIN_POLICY', 'group.invalid_join_policy', 422, ['allowed' => self::JOIN_POLICIES]);
            }
            $update['join_policy'] = $policy;
        }

        // Free-text / URL / contact fields (trimmed; empty string -> NULL).
        foreach (['tagline', 'description', 'location_text', 'contact_email',
                  'contact_phone', 'website_url', 'announcement', 'cover_image_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $val = trim((string) $data[$key]);
                $update[$key] = $val === '' ? null : $val;
            }
        }

        if (array_key_exists('contact_email', $update) && $update['contact_email'] !== null
            && ! filter_var($update['contact_email'], FILTER_VALIDATE_EMAIL)) {
            return Result::fail('INVALID_EMAIL', 'group.invalid_contact_email', 422);
        }

        if (array_key_exists('public_join', $data)) {
            $update['public_join'] = ! empty($data['public_join']) ? 1 : 0;
        }

        if ($update === []) {
            return Result::fail('NOTHING_TO_UPDATE', 'group.nothing_to_update', 422);
        }

        $update['updated_at'] = $this->clock->nowUtcString();
        $this->db->table('groups')->where('id', $groupId)->update($update);

        return Result::ok(['id' => $groupId, 'updated' => array_keys($update)]);
    }

    /**
     * Fetch a single group row scoped to the org (null if absent/other-org).
     *
     * @return array<string,mixed>|null
     */
    public function get(string $organizationId, string $groupId): ?array
    {
        return $this->db->table('groups')
            ->where('id', $groupId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
    }

    /**
     * Update a group's CORE fields — name, slug, type, kind_code. This is the
     * hierarchy-management edit (distinct from updatePublicProfile, which owns
     * the presentational/landing-page fields). Placement/depth are NOT changed
     * here — re-parenting is move()'s job — so the closure table is untouched.
     *
     * Only supplied keys are written. A blank name is rejected; a supplied slug
     * is normalized and checked for uniqueness within the org (excluding self);
     * a supplied kind must reference a defined active kind. Scope is unaffected
     * by name/slug/type/kind by design.
     *
     * @param array<string,mixed> $data name, slug, type, kind_code
     */
    public function update(string $organizationId, string $groupId, array $data): Result
    {
        $group = $this->get($organizationId, $groupId);
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $update = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return Result::fail('NAME_REQUIRED', 'group.name_required', 422);
            }
            $update['name'] = $name;
        }

        if (array_key_exists('slug', $data) && trim((string) $data['slug']) !== '') {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $data['slug'])), '-');
            if ($slug === '') {
                return Result::fail('INVALID_SLUG', 'group.invalid_slug', 422);
            }
            $clash = $this->db->table('groups')
                ->where('organization_id', $organizationId)
                ->where('slug', $slug)
                ->where('id !=', $groupId)
                ->countAllResults() > 0;
            if ($clash) {
                return Result::fail('SLUG_TAKEN', 'group.slug_taken', 409, ['slug' => $slug]);
            }
            $update['slug'] = $slug;
        }

        if (array_key_exists('type', $data)) {
            $type = trim((string) $data['type']);
            $update['type'] = $type === '' ? null : $type;
        }

        if (array_key_exists('kind_code', $data)) {
            $kindCode = trim((string) $data['kind_code']);
            if ($kindCode === '') {
                $update['kind_code'] = null;
            } else {
                $kindCode = strtolower($kindCode);
                if (! $this->kinds->isValidCode($organizationId, $kindCode)) {
                    return Result::fail('BAD_KIND', 'group.bad_kind', 422, ['kind_code' => $kindCode]);
                }
                $update['kind_code'] = $kindCode;
            }
        }

        if ($update === []) {
            return Result::fail('NOTHING_TO_UPDATE', 'group.nothing_to_update', 422);
        }

        $update['updated_at'] = $this->clock->nowUtcString();
        $this->db->table('groups')->where('id', $groupId)->update($update);

        return Result::ok(['id' => $groupId, 'updated' => array_keys($update)]);
    }

    /**
     * Hard-delete a group — ONLY permitted for an empty leaf: no child groups,
     * no members, and no cross-cut links. Populated or parent nodes must go
     * through the lifecycle (archive/dissolve/merge) so history and references
     * are preserved. Removes the group row and its closure edges in one tx.
     *
     * This is deliberately conservative: it protects referential integrity that
     * a blanket DELETE would violate (contributions, attendance, grants and the
     * closure table all reference group ids).
     */
    public function deleteHard(string $organizationId, string $groupId): Result
    {
        $group = $this->get($organizationId, $groupId);
        if ($group === null) {
            return Result::notFound('group.not_found', 'GROUP_NOT_FOUND');
        }

        $childCount = $this->db->table('groups')
            ->where('parent_id', $groupId)->countAllResults();
        if ($childCount > 0) {
            return Result::fail('HAS_CHILDREN', 'group.delete_has_children', 409, ['children' => $childCount]);
        }

        $memberCount = $this->db->table('group_members')
            ->where('group_id', $groupId)->countAllResults();
        if ($memberCount > 0) {
            return Result::fail('HAS_MEMBERS', 'group.delete_has_members', 409, ['members' => $memberCount]);
        }

        if ($this->db->tableExists('group_crosscut_links')) {
            $linkCount = $this->db->table('group_crosscut_links')
                ->groupStart()
                    ->where('hierarchy_group_id', $groupId)
                    ->orWhere('crosscut_group_id', $groupId)
                ->groupEnd()
                ->countAllResults();
            if ($linkCount > 0) {
                return Result::fail('HAS_CROSSCUTS', 'group.delete_has_crosscuts', 409, ['links' => $linkCount]);
            }
        }

        $this->db->transStart();
        // Closure: for a leaf the only edges are those ending at it (self + each
        // ancestor -> this node). Remove every edge that references the group.
        $this->db->table('group_closure')->where('descendant_id', $groupId)->delete();
        $this->db->table('group_closure')->where('ancestor_id', $groupId)->delete();
        $this->db->table('groups')->where('id', $groupId)->delete();
        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('DELETE_FAILED', 'group.delete_failed', 500);
        }

        return Result::ok(['id' => $groupId, 'deleted' => true]);
    }

    private function maxDepth(string $organizationId): int
    {
        $org = $this->db->table('organizations')->select('max_group_depth')
            ->where('id', $organizationId)->get()->getRowArray();
        $max = $org !== null ? (int) $org['max_group_depth'] : 9;

        return max(1, min(9, $max));
    }

    private function subtreeHeight(string $groupId): int
    {
        $row = $this->db->table('group_closure')
            ->selectMax('distance', 'h')
            ->where('ancestor_id', $groupId)
            ->get()->getRowArray();

        return ($row !== null ? (int) $row['h'] : 0) + 1;
    }

    private function recomputePathsAndDepth(string $groupId): void
    {
        $group  = $this->db->table('groups')->where('id', $groupId)->get()->getRowArray();
        if ($group === null) {
            return;
        }
        $parentPath  = '/';
        $parentDepth = 0;
        if ($group['parent_id'] !== null) {
            $parent      = $this->db->table('groups')->where('id', $group['parent_id'])->get()->getRowArray();
            $parentPath  = $parent !== null ? (string) $parent['path'] : '/';
            $parentDepth = $parent !== null ? (int) $parent['depth'] : 0;
        }
        $path  = $parentPath . $groupId . '/';
        $depth = $parentDepth + 1;
        $this->db->table('groups')->where('id', $groupId)->update([
            'path' => $path, 'depth' => $depth,
        ]);

        // Recurse into direct children.
        $children = $this->db->table('groups')->where('parent_id', $groupId)->get()->getResultArray();
        foreach ($children as $child) {
            $this->recomputePathsAndDepth((string) $child['id']);
        }
    }

    private function uniqueSlug(string $organizationId, string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug === '') {
            $slug = 'group';
        }
        if ($this->db->table('groups')->where('organization_id', $organizationId)->where('slug', $slug)->countAllResults() > 0) {
            $slug .= '-' . substr(Uuid::v7(), -6);
        }

        return $slug;
    }
}
