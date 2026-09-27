<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Configurable group-kind taxonomy (leadership-responsibility model, option B).
 *
 * A "kind" classifies WHAT a group is — department, activity team, ministry,
 * committee — independently of WHERE it is placed in the hierarchy and WHICH
 * branches it cross-cuts. It is a pure org-configurable catalog: creating or
 * renaming a kind never changes access evaluation (scope stays uniform), it only
 * labels groups for filtering, reporting, and admin UX.
 *
 * `default_placement` (nested|crosscut|either) is advisory metadata for the admin
 * UI — e.g. a "department" kind might default to `crosscut` so the create form
 * pre-offers cross-cut links — but it does NOT constrain or alter scope.
 */
final class GroupKindService
{
    private const PLACEMENTS = ['nested', 'crosscut', 'either'];

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $data code, name, description?, default_placement?, icon?, color?, sort_order?
     */
    public function create(string $organizationId, array $data, ?string $issuerId = null): Result
    {
        $code = $this->normalizeCode($data['code'] ?? '');
        if ($code === '') {
            return Result::fail('CODE_REQUIRED', 'group.kind_code_required', 422);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return Result::fail('NAME_REQUIRED', 'group.kind_name_required', 422);
        }
        $placement = $this->normalizePlacement($data['default_placement'] ?? 'either');
        if ($placement === null) {
            return Result::fail('BAD_PLACEMENT', 'group.kind_bad_placement', 422, ['allowed' => self::PLACEMENTS]);
        }

        $dupe = $this->db->table('group_kinds')
            ->where('organization_id', $organizationId)->where('code', $code)
            ->countAllResults() > 0;
        if ($dupe) {
            return Result::fail('DUPLICATE', 'group.kind_duplicate', 409, ['code' => $code]);
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $this->db->table('group_kinds')->insert([
            'id'                => $id,
            'organization_id'   => $organizationId,
            'code'              => $code,
            'name'              => $name,
            'description'       => isset($data['description']) ? (string) $data['description'] : null,
            'default_placement' => $placement,
            'icon'              => isset($data['icon']) ? (string) $data['icon'] : null,
            'color'             => isset($data['color']) ? (string) $data['color'] : null,
            'sort_order'        => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
            'status'            => 'active',
            'created_at'        => $now,
        ]);
        $this->audit->record($organizationId, [
            'action'      => 'group.kind.create',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'group_kind',
            'object_id'   => $id,
            'outcome'     => 'success',
            'metadata'    => ['code' => $code],
        ]);

        return Result::created(['kind_id' => $id, 'code' => $code, 'status' => 'active']);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(string $organizationId, string $kindId, array $data, ?string $issuerId = null): Result
    {
        $row = $this->find($organizationId, $kindId);
        if ($row === null) {
            return Result::notFound('group.kind_not_found', 'KIND_NOT_FOUND');
        }

        $update = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return Result::fail('NAME_REQUIRED', 'group.kind_name_required', 422);
            }
            $update['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = $data['description'] !== null ? (string) $data['description'] : null;
        }
        if (array_key_exists('default_placement', $data)) {
            $placement = $this->normalizePlacement($data['default_placement']);
            if ($placement === null) {
                return Result::fail('BAD_PLACEMENT', 'group.kind_bad_placement', 422);
            }
            $update['default_placement'] = $placement;
        }
        foreach (['icon', 'color'] as $f) {
            if (array_key_exists($f, $data)) {
                $update[$f] = $data[$f] !== null ? (string) $data[$f] : null;
            }
        }
        if (array_key_exists('sort_order', $data)) {
            $update['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = ((string) $data['status']) === 'inactive' ? 'inactive' : 'active';
        }
        if ($update === []) {
            return Result::ok(['kind_id' => $kindId, 'status' => 'unchanged']);
        }

        $update['updated_at'] = $this->clock->nowUtcMicro();
        $this->db->table('group_kinds')->where('id', $kindId)->update($update);
        $this->audit->record($organizationId, [
            'action'      => 'group.kind.update',
            'actor_id'    => $issuerId,
            'actor_type'  => 'user',
            'object_type' => 'group_kind',
            'object_id'   => $kindId,
            'outcome'     => 'success',
            'metadata'    => ['fields' => array_keys($update)],
        ]);

        return Result::ok(['kind_id' => $kindId, 'status' => 'updated']);
    }

    /** @return list<array<string,mixed>> */
    public function list(string $organizationId, bool $activeOnly = false): array
    {
        $q = $this->db->table('group_kinds')->where('organization_id', $organizationId);
        if ($activeOnly) {
            $q->where('status', 'active');
        }

        return $q->orderBy('sort_order', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();
    }

    /**
     * The kinds catalogue enriched with the number of ACTIVE groups classified
     * under each kind (`group_count`), so the console can surface the
     * group↔kind relationship at a glance. One grouped COUNT query joined onto
     * the catalogue in PHP — no per-row query.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithCounts(string $organizationId, bool $activeOnly = false): array
    {
        $kinds = $this->list($organizationId, $activeOnly);
        if ($kinds === []) {
            return [];
        }

        $counts = [];
        $rows = $this->db->table('groups')
            ->select('kind_code, COUNT(*) AS c', false)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('kind_code IS NOT NULL', null, false)
            ->groupBy('kind_code')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $counts[(string) $r['kind_code']] = (int) $r['c'];
        }

        foreach ($kinds as &$k) {
            $k['group_count'] = $counts[(string) ($k['code'] ?? '')] ?? 0;
        }
        unset($k);

        return $kinds;
    }

    /**
     * The active groups classified under a given kind CODE (drill-down from the
     * catalogue). Ordered by hierarchy path so the list reads top-down.
     *
     * @return list<array<string,mixed>>
     */
    public function groupsForKind(string $organizationId, string $code, int $limit = 500): array
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return [];
        }

        return $this->db->table('groups')
            ->select('id, name, slug, type, depth, path')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('kind_code', $code)
            ->orderBy('path', 'ASC')
            ->limit(max(1, min($limit, 2000)))
            ->get()->getResultArray();
    }

    public function show(string $organizationId, string $kindId): Result
    {
        $row = $this->find($organizationId, $kindId);
        if ($row === null) {
            return Result::notFound('group.kind_not_found', 'KIND_NOT_FOUND');
        }

        // Enrich with the groups classified under this kind (drill-down) + count.
        $groups = $this->groupsForKind($organizationId, (string) ($row['code'] ?? ''));
        $row['groups']      = $groups;
        $row['group_count'] = count($groups);

        return Result::ok($row);
    }

    /** True when $code is a defined, active kind for the org (used to validate groups.kind_code). */
    public function isValidCode(string $organizationId, string $code): bool
    {
        $code = $this->normalizeCode($code);
        if ($code === '') {
            return false;
        }

        return $this->db->table('group_kinds')
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->where('status', 'active')
            ->countAllResults() > 0;
    }

    /** @return array<string,mixed>|null */
    private function find(string $organizationId, string $kindId): ?array
    {
        return $this->db->table('group_kinds')
            ->where('organization_id', $organizationId)->where('id', $kindId)
            ->get()->getRowArray() ?: null;
    }

    private function normalizeCode(mixed $code): string
    {
        return strtolower(trim((string) $code));
    }

    private function normalizePlacement(mixed $placement): ?string
    {
        $p = strtolower(trim((string) $placement));
        if ($p === '') {
            return 'either';
        }

        return in_array($p, self::PLACEMENTS, true) ? $p : null;
    }
}
