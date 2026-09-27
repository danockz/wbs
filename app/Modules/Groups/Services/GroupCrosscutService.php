<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Cross-cutting group links (leadership-responsibility model).
 *
 * The group hierarchy is a strict single-parent tree. A CROSS-CUTTING group —
 * a worship team, a youth network, a choir — is an ordinary group that draws
 * members from many different branches/cells. This service records the
 * many-to-many links between such a group and the hierarchy nodes it spans, so
 * the access layer can (opt-in, down-only) extend a leader's hierarchy scope to
 * the cross-cut groups attached to the nodes they cover.
 *
 * Guards on link():
 *   - both groups exist and belong to the same organization;
 *   - a group cannot be linked to itself;
 *   - the link must be genuinely cross-cutting — the cross-cut group may not be
 *     an ancestor or descendant of the hierarchy node (that relationship is
 *     already expressed by the tree, so linking it would be redundant/confusing).
 *
 * Links are idempotent on UNIQUE(crosscut_group_id, hierarchy_group_id) and
 * every mutation is audited.
 */
final class GroupCrosscutService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly GroupScopeResolver $groupScope,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Link a cross-cut group to a hierarchy node it spans.
     */
    public function link(
        string $organizationId,
        string $crosscutGroupId,
        string $hierarchyGroupId,
        ?string $issuerId = null,
    ): Result {
        if ($crosscutGroupId === '' || $hierarchyGroupId === '') {
            return Result::fail('GROUPS_REQUIRED', 'group.crosscut_groups_required', 422);
        }
        if ($crosscutGroupId === $hierarchyGroupId) {
            return Result::fail('SELF_LINK', 'group.crosscut_self_link', 422);
        }

        $cross = $this->group($organizationId, $crosscutGroupId);
        $hier  = $this->group($organizationId, $hierarchyGroupId);
        if ($cross === null || $hier === null) {
            return Result::notFound('group.crosscut_group_not_found', 'GROUP_NOT_FOUND');
        }

        // Reject links that are already expressed by the tree (ancestor/descendant).
        $chain = array_merge(
            [$hierarchyGroupId],
            $this->groupScope->ancestors($hierarchyGroupId),
            $this->groupScope->descendants($hierarchyGroupId),
        );
        if (in_array($crosscutGroupId, $chain, true)) {
            return Result::fail('NOT_CROSSCUTTING', 'group.crosscut_not_crosscutting', 422);
        }

        // Idempotent: an existing link is a success (no duplicate row).
        $existing = $this->db->table('group_crosscut_links')
            ->where('crosscut_group_id', $crosscutGroupId)
            ->where('hierarchy_group_id', $hierarchyGroupId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return Result::ok(['link_id' => (string) $existing['id'], 'status' => 'exists']);
        }

        $id = Uuid::v7();
        $this->db->table('group_crosscut_links')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'crosscut_group_id'  => $crosscutGroupId,
            'hierarchy_group_id' => $hierarchyGroupId,
            'created_by'         => $issuerId,
            'created_at'         => $this->clock->nowUtcMicro(),
        ]);
        $this->audit->record($organizationId, [
            'action'      => 'group.crosscut.link',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'group_crosscut_link',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => ['crosscut_group_id' => $crosscutGroupId, 'hierarchy_group_id' => $hierarchyGroupId],
        ]);

        return Result::created([
            'link_id'            => $id,
            'crosscut_group_id'  => $crosscutGroupId,
            'hierarchy_group_id' => $hierarchyGroupId,
            'status'             => 'linked',
        ]);
    }

    /** Remove a cross-cut link. */
    public function unlink(
        string $organizationId,
        string $crosscutGroupId,
        string $hierarchyGroupId,
        ?string $issuerId = null,
    ): Result {
        $row = $this->db->table('group_crosscut_links')
            ->where('organization_id', $organizationId)
            ->where('crosscut_group_id', $crosscutGroupId)
            ->where('hierarchy_group_id', $hierarchyGroupId)
            ->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('group.crosscut_link_not_found', 'LINK_NOT_FOUND');
        }

        $this->db->table('group_crosscut_links')->where('id', $row['id'])->delete();
        $this->audit->record($organizationId, [
            'action'      => 'group.crosscut.unlink',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'group_crosscut_link',
            'object_id'   => (string) $row['id'],
            'outcome'     => 'success',
            'metadata'    => ['crosscut_group_id' => $crosscutGroupId, 'hierarchy_group_id' => $hierarchyGroupId],
        ]);

        return Result::ok(['status' => 'unlinked']);
    }

    /**
     * The hierarchy nodes a cross-cut group spans.
     *
     * @return list<array<string,mixed>>
     */
    public function nodesForCrosscut(string $organizationId, string $crosscutGroupId): array
    {
        return $this->db->table('group_crosscut_links l')
            ->select('l.hierarchy_group_id, g.name, g.type, g.depth, g.path')
            ->join('groups g', 'g.id = l.hierarchy_group_id')
            ->where('l.organization_id', $organizationId)
            ->where('l.crosscut_group_id', $crosscutGroupId)
            ->get()->getResultArray();
    }

    /**
     * The cross-cut groups attached to a hierarchy node.
     *
     * @return list<array<string,mixed>>
     */
    public function crosscutsForNode(string $organizationId, string $hierarchyGroupId): array
    {
        return $this->db->table('group_crosscut_links l')
            ->select('l.crosscut_group_id, g.name, g.type, g.status')
            ->join('groups g', 'g.id = l.crosscut_group_id')
            ->where('l.organization_id', $organizationId)
            ->where('l.hierarchy_group_id', $hierarchyGroupId)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function group(string $organizationId, string $groupId): ?array
    {
        return $this->db->table('groups')
            ->where('organization_id', $organizationId)->where('id', $groupId)
            ->get()->getRowArray() ?: null;
    }
}
