<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\ScopeMode;
use WBS\Shared\Support\Uuid;

/**
 * Shared parsing + persistence of a grant's group scope (leadership-
 * responsibility model). One implementation used by every grant writer —
 * role assignments, rules, delegations, access requests, break-glass — so the
 * full scope model (scope_mode + hand-picked multi-group set + include_crosscut)
 * is expressed identically everywhere and matches what the PDP reads back.
 *
 * A grant's scope is:
 *   - scope_group_id  — the anchor group (NULL = org-wide);
 *   - scope_mode      — self | self_and_descendants | descendants_only | groups;
 *   - include_crosscut— opt-in, down-only cross-cut coverage;
 *   - a hand-picked set of groups (mode = groups) stored in grant_scope_groups
 *     keyed by (grant_type, grant_id).
 *
 * `include_descendants` is still written for backward compatibility, derived
 * from the mode, so pre-existing readers keep working.
 */
final class GrantScopeWriter
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Parse raw request data into a normalized scope descriptor.
     *
     * @param array<string,mixed> $data scope_group_id?, scope_mode?,
     *        include_descendants?, scope_groups?, include_crosscut?
     * @return array{ok:bool,message?:string,extra?:array<string,mixed>,
     *   group_id:?string,mode:string,groups:list<string>,
     *   include_descendants:bool,include_crosscut:bool}
     */
    public function parse(array $data): array
    {
        $mode    = ScopeMode::normalize($data['scope_mode'] ?? null, $data['include_descendants'] ?? null);
        $groupId = isset($data['scope_group_id']) && $data['scope_group_id'] !== ''
            ? (string) $data['scope_group_id'] : null;
        $groups  = [];

        if ($mode === ScopeMode::GROUPS) {
            $raw = $data['scope_groups'] ?? [];
            if (! is_array($raw) || $raw === []) {
                return $this->fail('acl.scope_groups_required', [], $groupId, $mode);
            }
            $groups = array_values(array_unique(array_map('strval', $raw)));
        } elseif ($groupId === null && $mode !== ScopeMode::SELF) {
            // descendants_only / self_and_descendants need an anchor group.
            return $this->fail('acl.scope_group_required', ['mode' => $mode], $groupId, $mode);
        }

        return [
            'ok'                  => true,
            'group_id'            => $groupId,
            'mode'                => $mode,
            'groups'              => $groups,
            'include_descendants' => ScopeMode::includesDescendants($mode),
            'include_crosscut'    => ! empty($data['include_crosscut']),
        ];
    }

    /**
     * The columns a grant row should persist for this scope (excluding the
     * hand-picked set, which lives in grant_scope_groups).
     *
     * @param array{group_id:?string,mode:string,include_descendants:bool,include_crosscut:bool} $scope
     * @return array{scope_group_id:?string,scope_mode:string,include_descendants:int,include_crosscut:int}
     */
    public function columns(array $scope): array
    {
        return [
            'scope_group_id'      => $scope['group_id'],
            'scope_mode'          => $scope['mode'],
            'include_descendants' => (int) $scope['include_descendants'],
            'include_crosscut'    => (int) $scope['include_crosscut'],
        ];
    }

    /**
     * Replace the hand-picked group set for a grant. No-op (after clearing) for
     * any mode other than GROUPS.
     *
     * @param array{mode:string,groups:list<string>} $scope
     */
    public function syncGroupSet(string $organizationId, string $grantType, string $grantId, array $scope): void
    {
        $this->db->table('grant_scope_groups')
            ->where('grant_type', $grantType)->where('grant_id', $grantId)->delete();
        if (($scope['mode'] ?? null) !== ScopeMode::GROUPS) {
            return;
        }
        $now = $this->clock->nowUtcMicro();
        foreach ($scope['groups'] ?? [] as $gid) {
            $this->db->table('grant_scope_groups')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'grant_type'      => $grantType,
                'grant_id'        => $grantId,
                'group_id'        => (string) $gid,
                'created_at'      => $now,
            ]);
        }
    }

    /** @return list<string> */
    public function groupSet(string $grantType, string $grantId): array
    {
        $rows = $this->db->table('grant_scope_groups')
            ->select('group_id')
            ->where('grant_type', $grantType)->where('grant_id', $grantId)
            ->get()->getResultArray();

        return array_values(array_map(static fn ($r): string => (string) $r['group_id'], $rows));
    }

    /**
     * @return array{ok:bool,message:string,extra:array<string,mixed>,group_id:?string,mode:string,groups:list<string>,include_descendants:bool,include_crosscut:bool}
     */
    private function fail(string $message, array $extra, ?string $groupId, string $mode): array
    {
        return [
            'ok'                  => false,
            'message'             => $message,
            'extra'               => $extra,
            'group_id'            => $groupId,
            'mode'                => $mode,
            'groups'              => [],
            'include_descendants' => ScopeMode::includesDescendants($mode),
            'include_crosscut'    => false,
        ];
    }
}
