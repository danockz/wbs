<?php

declare(strict_types=1);

namespace WBS\Admin\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Effective group configuration resolver (SRS FR-GRP-006).
 *
 * For a given group + capability, walks the group ancestry (nearest first) and
 * returns the effective value together with its SOURCE group, version and the
 * inheritance decision that produced it. Inheritance modes:
 *
 *   - inherit_only                    : value always comes from the nearest
 *                                       ancestor that owns one; the child cannot
 *                                       override (its own row is ignored for value).
 *   - ancestor_default_child_override : child value wins if present, else nearest
 *                                       ancestor default.
 *   - child_owned                     : only the group's own value is used; no
 *                                       inheritance.
 *   - not_inheritable                 : only the group's own value; never exposed
 *                                       to descendants.
 *
 * A child cannot weaken global security/financial/abuse limits — those live in
 * platform_settings and are resolved separately; this resolver governs the
 * group-configurable capabilities only.
 */
final class EffectiveConfigResolver
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** Set/override a group's own configuration for a capability. */
    public function set(
        string $organizationId,
        string $groupId,
        string $capability,
        mixed $value,
        string $inheritanceMode = 'ancestor_default_child_override',
        ?string $actorId = null,
    ): Result {
        $modes = ['inherit_only', 'ancestor_default_child_override', 'child_owned', 'not_inheritable'];
        if (! in_array($inheritanceMode, $modes, true)) {
            return Result::fail('BAD_MODE', 'config.bad_inheritance_mode', 422);
        }

        $now      = $this->clock->nowUtcMicro();
        $existing = $this->db->table('group_configurations')
            ->where('group_id', $groupId)->where('capability', $capability)
            ->get()->getRowArray();

        if ($existing === null) {
            $this->db->table('group_configurations')->insert([
                'id'               => Uuid::v7(),
                'organization_id'  => $organizationId,
                'group_id'         => $groupId,
                'capability'       => $capability,
                'inheritance_mode' => $inheritanceMode,
                'value_json'       => json_encode($value),
                'version'          => 1,
                'updated_by'       => $actorId,
                'updated_at'       => $now,
            ]);
            $ver = 1;
        } else {
            $ver = (int) $existing['version'] + 1;
            $this->db->table('group_configurations')->where('id', $existing['id'])->update([
                'inheritance_mode' => $inheritanceMode,
                'value_json'       => json_encode($value),
                'version'          => $ver,
                'updated_by'       => $actorId,
                'updated_at'       => $now,
            ]);
        }

        return Result::ok(['group_id' => $groupId, 'capability' => $capability, 'version' => $ver]);
    }

    /**
     * Resolve the effective value for a group's capability.
     *
     * @return Result data: {value, source_group_id, version, inheritance_mode, decision}
     */
    public function resolve(string $groupId, string $capability): Result
    {
        // Ancestry nearest-first: distance 0 (self) .. N (root).
        $chain = $this->db->table('group_closure')
            ->select('ancestor_id, distance')
            ->where('descendant_id', $groupId)
            ->orderBy('distance', 'ASC')
            ->get()->getResultArray();

        if ($chain === []) {
            // No closure rows (e.g. group not projected yet): treat self as sole node.
            $chain = [['ancestor_id' => $groupId, 'distance' => 0]];
        }

        $own = $this->configFor($groupId, $capability);

        // child_owned / not_inheritable: only the group's own value matters.
        if ($own !== null && in_array($own['inheritance_mode'], ['child_owned', 'not_inheritable'], true)) {
            return $this->shape($own, 'own_' . $own['inheritance_mode']);
        }

        // ancestor_default_child_override: child value wins if present.
        if ($own !== null && $own['inheritance_mode'] === 'ancestor_default_child_override' && $own['value_json'] !== null) {
            return $this->shape($own, 'child_override');
        }

        // inherit_only OR no own value: take nearest ancestor (distance > 0) that
        // owns a value and permits inheritance.
        foreach ($chain as $node) {
            if ((int) $node['distance'] === 0) {
                continue; // skip self; handled above
            }
            $anc = $this->configFor($node['ancestor_id'], $capability);
            if ($anc === null || $anc['value_json'] === null) {
                continue;
            }
            if (in_array($anc['inheritance_mode'], ['child_owned', 'not_inheritable'], true)) {
                // Ancestor's value is private to itself; do not inherit.
                continue;
            }

            return $this->shape($anc, 'inherited_from_ancestor');
        }

        // Nothing resolved.
        return Result::ok([
            'value'            => null,
            'source_group_id'  => null,
            'version'          => null,
            'inheritance_mode' => $own['inheritance_mode'] ?? null,
            'decision'         => 'no_effective_value',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function configFor(string $groupId, string $capability): ?array
    {
        return $this->db->table('group_configurations')
            ->where('group_id', $groupId)->where('capability', $capability)
            ->get()->getRowArray() ?: null;
    }

    private function shape(array $row, string $decision): Result
    {
        return Result::ok([
            'value'            => $row['value_json'] !== null ? json_decode((string) $row['value_json'], true) : null,
            'source_group_id'  => $row['group_id'],
            'version'          => (int) $row['version'],
            'inheritance_mode' => $row['inheritance_mode'],
            'decision'         => $decision,
        ]);
    }
}
