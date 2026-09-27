<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\GroupScopeResolver;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Admin management of the ACTIVITY CATALOG — the Win/Build/Send grouping that
 * organises earning activities (gamification_rules) for an admin console and a
 * member "ways to earn" screen.
 *
 * Categories are data-configured per org and optionally per hierarchical group
 * (NULL group_id = org-wide default), most-specific-wins like the other
 * configurable catalogs. Each category carries a `phase` (win|build|send|
 * general) plus display metadata.
 *
 * catalog() assembles the whole earning surface (phase → category → activities)
 * from active rules for a read-only overview.
 */
final class ActivityCatalogService
{
    private const PHASES = ['win', 'build', 'send', 'general'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?GroupScopeResolver $groupScope = null,
    ) {
    }

    /**
     * Define (or update) an activity category. group_id NULL keeps it org-wide.
     *
     * @param array<string,mixed> $data code, name, phase, description, icon,
     *          color, sort_order, group_id, include_descendants, status
     */
    public function define(string $organizationId, array $data): Result
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return Result::fail('BAD_CATEGORY', 'gamification.bad_category', 422);
        }
        $phase = $data['phase'] ?? 'general';
        if (! in_array($phase, self::PHASES, true)) {
            return Result::fail('BAD_PHASE', 'gamification.bad_phase', 422, ['allowed' => self::PHASES]);
        }

        $groupId = isset($data['group_id']) && $data['group_id'] !== '' ? (string) $data['group_id'] : null;

        $q = $this->db->table('activity_categories')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $existing = $q->get()->getRowArray();

        $payload = [
            'name'                => mb_substr($name, 0, 100),
            'phase'               => (string) $phase,
            // Optional journey stage link (Option D). Advisory metadata only —
            // never gates awarding; NULL = not stage-specific.
            'stage_code'          => isset($data['stage_code']) && $data['stage_code'] !== '' ? mb_substr((string) $data['stage_code'], 0, 60) : null,
            'description'         => isset($data['description']) ? mb_substr((string) $data['description'], 0, 255) : null,
            'icon'                => isset($data['icon']) ? mb_substr((string) $data['icon'], 0, 120) : null,
            'color'               => isset($data['color']) ? mb_substr((string) $data['color'], 0, 20) : null,
            'sort_order'          => (int) ($data['sort_order'] ?? 0),
            'include_descendants' => ! empty($data['include_descendants']) ? 1 : 0,
            'status'              => in_array($data['status'] ?? 'active', ['active', 'inactive'], true) ? (string) ($data['status'] ?? 'active') : 'active',
            'updated_at'          => $this->clock->nowUtcString(),
        ];

        try {
            if ($existing !== null) {
                $this->db->table('activity_categories')->where('id', $existing['id'])->update($payload);

                return Result::ok(['id' => $existing['id'], 'code' => $code, 'updated' => true]);
            }

            $id = Uuid::v7();
            $this->db->table('activity_categories')->insert($payload + [
                'id'              => $id,
                'organization_id' => $organizationId,
                'group_id'        => $groupId,
                'code'            => $code,
                'created_at'      => $this->clock->nowUtcString(),
            ]);

            return Result::created(['id' => $id, 'code' => $code]);
        } catch (Throwable) {
            return Result::fail('CATEGORY_SAVE_FAILED', 'gamification.category_save_failed', 500);
        }
    }

    /** Deactivate a category (activities keep working; it just hides in the UI). */
    /**
     * PARTIAL update of an existing activity category. Merges $changes over the
     * stored row (omitted fields unchanged); `code`/`group_id` immutable;
     * NOT_FOUND when absent. Delegates to define() for validation + persistence.
     *
     * @param array<string,mixed> $changes any subset of the define() fields
     */
    public function update(string $organizationId, string $code, array $changes, ?string $groupId = null): Result
    {
        $gid = $groupId !== null && $groupId !== '' ? (string) $groupId : null;
        $q   = $this->db->table('activity_categories')
            ->where('organization_id', $organizationId)->where('code', $code);
        $gid === null ? $q->where('group_id', null) : $q->where('group_id', $gid);
        $existing = $q->get()->getRowArray();
        if ($existing === null) {
            return Result::notFound('gamification.category_not_found', 'CATEGORY_NOT_FOUND');
        }

        $merged             = array_merge($existing, $changes);
        $merged['code']     = $code;
        $merged['group_id'] = $gid;

        return $this->define($organizationId, $merged);
    }

    public function disable(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('activity_categories')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $row = $q->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.category_not_found', 'CATEGORY_NOT_FOUND');
        }
        $this->db->table('activity_categories')->where('id', $row['id'])
            ->update(['status' => 'inactive', 'updated_at' => $this->clock->nowUtcString()]);

        return Result::ok(['code' => $code, 'status' => 'inactive']);
    }

    /**
     * Read a single category by code (org-wide row by default, or a specific
     * group's override when $groupId is given), with the count of active
     * activities that reference it. 404 when unknown.
     */
    public function showCategory(string $organizationId, string $code, ?string $groupId = null): Result
    {
        $q = $this->db->table('activity_categories')
            ->where('organization_id', $organizationId)->where('code', $code);
        $groupId === null ? $q->where('group_id', null) : $q->where('group_id', $groupId);
        $row = $q->get()->getRowArray();
        if ($row === null) {
            return Result::notFound('gamification.category_not_found', 'CATEGORY_NOT_FOUND');
        }

        $row['activity_count'] = $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)
            ->where('category_id', $row['id'])
            ->where('status', 'active')
            ->countAllResults();

        return Result::ok($row);
    }

    /**
     * List categories (optionally filtered by phase / group).
     *
     * @return list<array<string,mixed>>
     */
    /**
     * List categories visible to a group scope, or org-wide when $groupId is
     * null.
     *
     * When a group is given, the visible set is the UNION of: the group's own
     * categories, org-wide (NULL) categories, and ancestor categories flagged
     * include_descendants=1 — resolved MOST-SPECIFIC-WINS per code (a subgroup
     * override hides the inherited row of the same code). Without a group the
     * behaviour is unchanged: every category defined for the org.
     */
    public function listCategories(string $organizationId, ?string $phase = null, ?string $groupId = null): array
    {
        $q = $this->db->table('activity_categories')->where('organization_id', $organizationId);
        if ($phase !== null && $phase !== '') {
            $q->where('phase', $phase);
        }

        if ($groupId !== null && $groupId !== '' && $this->groupScope !== null) {
            $ancestors = $this->groupScope->ancestors($groupId);
            // self OR org-wide(NULL) OR (ancestor AND include_descendants)
            $q->groupStart()
                ->where('group_id', $groupId)
                ->orWhere('group_id', null);
            if ($ancestors !== []) {
                $q->orGroupStart()
                    ->where('include_descendants', 1)
                    ->whereIn('group_id', $ancestors)
                  ->groupEnd();
            }
            $q->groupEnd();
        }

        $rows = $q->orderBy('phase', 'ASC')->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')
            ->get()->getResultArray();

        return $groupId !== null && $groupId !== ''
            ? $this->mostSpecificPerCode($rows, $groupId, $this->groupScope?->ancestors($groupId) ?? [])
            : $rows;
    }

    /**
     * Collapse a union of scoped rows to one row per code using most-specific-
     * wins: the group's own row beats a nearer ancestor, which beats a farther
     * ancestor, which beats org-wide (NULL). $ancestors is nearest-first.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string>              $ancestors nearest-first
     * @return list<array<string,mixed>>
     */
    private function mostSpecificPerCode(array $rows, string $groupId, array $ancestors): array
    {
        // Lower rank = more specific. self=0, ancestors=1..n, org-wide=PHP_INT_MAX.
        $rank = [$groupId => 0];
        foreach ($ancestors as $i => $aid) {
            $rank[$aid] = $i + 1;
        }

        $best = [];
        foreach ($rows as $r) {
            $code = (string) $r['code'];
            $g    = $r['group_id'] !== null && $r['group_id'] !== '' ? (string) $r['group_id'] : null;
            $rk   = $g === null ? PHP_INT_MAX : ($rank[$g] ?? PHP_INT_MAX - 1);
            if (! isset($best[$code]) || $rk < $best[$code]['_rank']) {
                $r['_rank']   = $rk;
                $best[$code]  = $r;
            }
        }

        $out = array_values(array_map(static function (array $r): array {
            unset($r['_rank']);

            return $r;
        }, $best));

        // Preserve the phase/sort_order/name ordering after dedupe.
        usort($out, static function (array $a, array $b): int {
            return [$a['phase'], (int) $a['sort_order'], $a['name']]
               <=> [$b['phase'], (int) $b['sort_order'], $b['name']];
        });

        return $out;
    }

    /**
     * The full earning surface for an org: phases → categories → activities
     * (active rules). Read-only; drives the admin console and a member "ways to
     * earn" view. Activities not tied to a category land under an "(uncategorised)"
     * bucket within their phase.
     *
     * @return array<string,mixed>
     */
    public function catalog(string $organizationId): array
    {
        $categories = $this->listCategories($organizationId);
        $byId       = [];
        foreach ($categories as $c) {
            $byId[$c['id']] = $c;
        }

        $rules = $this->db->table('gamification_rules')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('phase', 'ASC')->orderBy('sort_order', 'ASC')->orderBy('code', 'ASC')
            ->get()->getResultArray();

        // Group rules by phase then category.
        $phases = [];
        foreach (self::PHASES as $p) {
            $phases[$p] = ['phase' => $p, 'categories' => []];
        }

        foreach ($rules as $r) {
            $phase = in_array($r['phase'] ?? 'general', self::PHASES, true) ? $r['phase'] : 'general';
            $catId = $r['category_id'] ?? null;
            $catKey = $catId !== null && isset($byId[$catId]) ? $catId : '_uncategorised';
            if (! isset($phases[$phase]['categories'][$catKey])) {
                $phases[$phase]['categories'][$catKey] = [
                    'category'   => $catId !== null && isset($byId[$catId]) ? $byId[$catId] : ['code' => null, 'name' => '(uncategorised)'],
                    'activities' => [],
                ];
            }
            $phases[$phase]['categories'][$catKey]['activities'][] = [
                'code'          => $r['code'],
                'name'          => $r['activity_name'] ?? $r['code'],
                'points'        => (int) $r['points'],
                'point_mode'    => $r['point_mode'] ?? 'fixed',
                'point_formula' => $r['point_formula'] ?? null,
                'min_points'    => $r['min_points'] !== null ? (int) $r['min_points'] : null,
                'max_points'    => $r['max_points'] !== null ? (int) $r['max_points'] : null,
                'event_type'    => $r['event_type'],
                'icon'          => $r['icon'] ?? null,
                'color'         => $r['color'] ?? null,
                'requires_review' => (bool) $r['requires_review'],
            ];
        }

        // Re-index categories to lists for a clean JSON shape.
        $out = [];
        foreach ($phases as $p => $bucket) {
            $bucket['categories'] = array_values($bucket['categories']);
            $out[] = $bucket;
        }

        return ['organization_id' => $organizationId, 'phases' => $out];
    }
}
