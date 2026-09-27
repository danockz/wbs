<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Point-threshold ranks/tiers (adaptation of awardlib.md rank_definitions +
 * determineRank/getNextRank).
 *
 * Ranks are data-configured per org (code, name, min_points). Resolution is a
 * pure read over active tiers ordered by min_points; nothing here mutates the
 * ledger. Feeds the `rank_reached` achievement trigger.
 */
final class RankService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Read one rank tier by code. Within a group scope, resolution is
     * most-specific-wins over the ancestor chain (self → ancestor-with-
     * descendants → org-wide); without a group it reads the org-wide row.
     */
    public function show(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;

        if ($gid !== null && $this->groupScope !== null) {
            $row = $this->groupScope->resolveByCode('rank_definitions', $organizationId, $code, $gid, []);
        } else {
            $q = $this->db->table('rank_definitions')
                ->where('organization_id', $organizationId)->where('code', $code);
            $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
            $row = $q->get()->getRowArray();
        }

        if ($row === null) {
            return Result::notFound('gamification.rank_not_found', 'RANK_NOT_FOUND');
        }
        $row['include_descendants'] = (bool) ($row['include_descendants'] ?? 0);

        return Result::ok($row);
    }

    /**
     * List rank tiers as configured (org-wide, or a group's visible resolved
     * ladder when $groupId is given). Distinct from tiers(), which returns only
     * the single winning scope's active set for rank computation.
     *
     * @return list<array<string,mixed>>
     */
    public function listDefinitions(string $organizationId, ?string $groupId = null): array
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        if ($gid !== null) {
            return $this->tiers($organizationId, $gid);
        }

        return $this->db->table('rank_definitions')
            ->where('organization_id', $organizationId)
            ->orderBy('group_id', 'ASC')->orderBy('min_points', 'ASC')->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Define (or update) a rank tier. Optionally scoped to a hierarchical group
     * ($data['group_id']); NULL keeps the tier org-wide (backward compatible).
     * A group tier overrides an org-wide tier of the same code for that group
     * and (when include_descendants) its subgroups.
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = (string) ($data['code'] ?? '');
        $name = (string) ($data['name'] ?? '');
        if ($code === '' || $name === '') {
            return Result::fail('BAD_RANK', 'gamification.bad_rank', 422);
        }

        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $q = $this->db->table('rank_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        $payload = [
            'name'                => mb_substr($name, 0, 150),
            'min_points'          => (int) ($data['min_points'] ?? 0),
            'sort_order'          => (int) ($data['sort_order'] ?? 0),
            'icon'                => $data['icon'] ?? null,
            'color'               => $data['color'] ?? null,
            'include_descendants' => ! empty($data['include_descendants']) ? 1 : 0,
            'status'              => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? $data['status'] : 'active',
        ];

        if ($existing !== null) {
            $this->db->table('rank_definitions')->where('id', $existing['id'])->update($payload);

            return Result::ok(['rank_id' => $existing['id'], 'code' => $code, 'updated' => true]);
        }

        $id = Uuid::v7();
        $this->db->table('rank_definitions')->insert($payload + [
            'id'              => $id,
            'organization_id' => $organizationId,
            'group_id'        => $groupId,
            'code'            => $code,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['rank_id' => $id, 'code' => $code, 'group_id' => $groupId]);
    }

    /**
     * All active tiers for a scope, lowest threshold first.
     *
     * When $groupId is given, resolution is MOST-SPECIFIC-WINS: the nearest
     * group in the ancestor chain (self first) that has any active tiers is
     * used; if none, the org-wide (group_id IS NULL) set applies. This lets a
     * subgroup inherit its parent's ladder unless it defines its own.
     *
     * @return list<array<string,mixed>>
     */
    public function tiers(string $organizationId, ?string $groupId = null): array
    {
        foreach ($this->groupChain($groupId) as $candidate) {
            $rows = $this->activeTiersFor($organizationId, $candidate);
            if ($rows !== []) {
                return $rows;
            }
        }

        return $this->activeTiersFor($organizationId, null);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeTiersFor(string $organizationId, ?string $groupId): array
    {
        $q = $this->db->table('rank_definitions')
            ->where('organization_id', $organizationId)->where('status', 'active');
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);

        return $q->orderBy('min_points', 'ASC')->orderBy('sort_order', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Ordered scope candidates for a group: self, then ancestors nearest-first
     * (only those flagged to include descendants), then org-wide (null).
     *
     * @return list<string|null>
     */
    private function groupChain(?string $groupId): array
    {
        if ($groupId === null) {
            return [null];
        }

        $chain = [$groupId];
        $rows  = $this->db->table('group_closure')
            ->select('ancestor_id, distance')
            ->where('descendant_id', $groupId)
            ->where('distance >', 0)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $chain[] = (string) $r['ancestor_id'];
        }
        $chain[] = null; // org-wide fallback

        return $chain;
    }

    /** Deactivate a rank tier (org-wide by default, or a specific group's). */
    /**
     * PARTIAL update of an existing rank tier. Merges $changes over the stored
     * row (omitted fields unchanged); `code`/`group_id` immutable; NOT_FOUND
     * when absent. Delegates to define() for validation + persistence.
     *
     * @param array<string,mixed> $changes any subset of the define() fields
     */
    public function update(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('rank_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.rank_not_found', 'RANK_NOT_FOUND');
        }

        $merged             = array_merge($existing, $changes);
        $merged['code']     = $code;
        $merged['group_id'] = $gid;

        return $this->define($organizationId, $merged);
    }

    public function disable(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('rank_definitions')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $tier = $q->get()->getRowArray();
        if ($tier === null) {
            return Result::notFound('gamification.rank_not_found', 'RANK_NOT_FOUND');
        }
        $this->db->table('rank_definitions')->where('id', $tier['id'])->update(['status' => 'inactive']);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    /** Highest tier whose min_points <= points, or null when below the lowest. */
    public function determineRank(string $organizationId, int $points, ?string $groupId = null): ?array
    {
        $current = null;
        foreach ($this->tiers($organizationId, $groupId) as $tier) {
            if ($points >= (int) $tier['min_points']) {
                $current = $tier;
            } else {
                break;
            }
        }

        return $current;
    }

    /** The next tier above the current points, or null when already at top. */
    public function nextRank(string $organizationId, int $points, ?string $groupId = null): ?array
    {
        foreach ($this->tiers($organizationId, $groupId) as $tier) {
            if ((int) $tier['min_points'] > $points) {
                return $tier;
            }
        }

        return null;
    }
}
