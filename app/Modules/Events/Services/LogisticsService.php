<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Logistics and operations (SRS FR-EVT-018).
 *
 * Resource plans, seating/venue-area allocation, catering headcount, staff
 * roster and supplier records. Two invariants:
 *
 *  - PLANNED vs ORDERED separation: `quantity_planned` tracks the live
 *    projection driven by expected attendance; `quantity_ordered` is the
 *    approved order and is NEVER overwritten by a projection refresh
 *    (refreshProjection touches only quantity_planned).
 *  - Individual dietary/accessibility data is SPECIALLY CLASSIFIED — stored in
 *    event_accessibility_needs and only returned by listAccessibilityNeeds(),
 *    which callers must gate behind authorized logistics personnel (ABAC).
 *    Aggregate reports use event_catering_aggregates (no personal detail).
 */
final class LogisticsService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /** Create (or fetch) the logistics plan for an event. */
    public function ensurePlan(string $organizationId, string $eventId): Result
    {
        $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();
        if ($plan !== null) {
            return Result::ok(['plan_id' => $plan['id'], 'status' => $plan['status']], 200, ['deduplicated' => true]);
        }
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        try {
            $this->db->table('event_logistics_plans')->insert([
                'id'                  => $id,
                'organization_id'     => $organizationId,
                'event_id'            => $eventId,
                'projection_expected' => 0,
                'status'              => 'draft',
                'created_at'          => $now,
            ]);
        } catch (Throwable) {
            $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();

            return Result::ok(['plan_id' => $plan['id'] ?? $id], 200, ['deduplicated' => true]);
        }

        return Result::created(['plan_id' => $id, 'status' => 'draft']);
    }

    /**
     * Read the full logistics plan for an event in one shot: the plan header plus
     * its resources, seating areas, staff roster and suppliers. Returns null for
     * the plan when none exists yet (the console then offers "create plan"). This
     * is a read helper for the browser logistics console; it exposes NO specially-
     * classified accessibility detail (that stays behind the gated needs endpoint).
     *
     * @return array{plan:array<string,mixed>|null,resources:list<array<string,mixed>>,seating:list<array<string,mixed>>,staff:list<array<string,mixed>>,suppliers:list<array<string,mixed>>}
     */
    public function planOverview(string $eventId): array
    {
        $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();

        return [
            'plan'      => $plan ?: null,
            'resources' => $this->db->table('event_resources')->where('event_id', $eventId)->orderBy('created_at', 'ASC')->get()->getResultArray(),
            'seating'   => $this->db->table('event_seating_areas')->where('event_id', $eventId)->orderBy('created_at', 'ASC')->get()->getResultArray(),
            'staff'     => $this->db->table('event_staff_roster')->where('event_id', $eventId)->orderBy('created_at', 'ASC')->get()->getResultArray(),
            'suppliers' => $this->db->table('event_suppliers')->where('event_id', $eventId)->orderBy('created_at', 'ASC')->get()->getResultArray(),
        ];
    }

    /** @param array<string,mixed> $data */
    public function addResource(string $organizationId, string $eventId, array $data): Result
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return Result::fail('NAME_REQUIRED', 'logistics.name_required', 422);
        }
        $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('event_resources')->insert([
            'id'               => $id,
            'organization_id'  => $organizationId,
            'event_id'         => $eventId,
            'plan_id'          => $plan['id'] ?? null,
            'kind'             => in_array($data['kind'] ?? 'material', ['material', 'equipment', 'security', 'catering'], true) ? $data['kind'] : 'material',
            'name'             => $data['name'],
            'unit'             => $data['unit'] ?? null,
            'quantity_planned' => (int) ($data['quantity_planned'] ?? 0),
            'quantity_ordered' => 0,
            'per_attendee'     => isset($data['per_attendee']) ? (float) $data['per_attendee'] : null,
            'supplier_id'      => $data['supplier_id'] ?? null,
            'status'           => 'planned',
            'notes'            => $data['notes'] ?? null,
            'created_at'       => $now,
        ]);

        return Result::created(['resource_id' => $id]);
    }

    /**
     * Approve an order quantity for a resource. This locks the approved amount
     * into quantity_ordered; subsequent projection refreshes will not touch it.
     */
    public function orderResource(string $resourceId, int $quantityOrdered): Result
    {
        if ($quantityOrdered < 0) {
            return Result::fail('BAD_QUANTITY', 'logistics.bad_quantity', 422);
        }
        $res = $this->db->table('event_resources')->where('id', $resourceId)->get()->getRowArray();
        if ($res === null) {
            return Result::notFound('resource.not_found', 'RESOURCE_NOT_FOUND');
        }
        $this->db->table('event_resources')->where('id', $resourceId)->update([
            'quantity_ordered' => $quantityOrdered,
            'status'           => 'ordered',
            'updated_at'       => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['resource_id' => $resourceId, 'quantity_ordered' => $quantityOrdered, 'status' => 'ordered']);
    }

    /**
     * Refresh planning projections from an expected-attendance figure. Updates
     * ONLY quantity_planned (per_attendee * expected, ceil) for resources that
     * declare a ratio. Approved orders (quantity_ordered) are never overwritten.
     */
    public function refreshProjection(string $eventId, int $expectedAttendance): Result
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('event_logistics_plans')->where('event_id', $eventId)->update([
            'projection_expected' => max(0, $expectedAttendance),
            'updated_at'          => $now,
        ]);

        $resources = $this->db->table('event_resources')
            ->where('event_id', $eventId)
            ->where('per_attendee IS NOT NULL')
            ->get()->getResultArray();

        $updated = 0;
        foreach ($resources as $r) {
            $planned = (int) ceil($expectedAttendance * (float) $r['per_attendee']);
            $this->db->table('event_resources')->where('id', $r['id'])->update([
                'quantity_planned' => $planned,   // ONLY planned; ordered untouched
                'updated_at'       => $now,
            ]);
            $updated++;
        }

        return Result::ok(['event_id' => $eventId, 'projection_expected' => $expectedAttendance, 'resources_reprojected' => $updated]);
    }

    // ---- seating -----------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function addSeatingArea(string $organizationId, string $eventId, array $data): Result
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return Result::fail('NAME_REQUIRED', 'logistics.name_required', 422);
        }
        $id  = Uuid::v7();
        $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();
        $this->db->table('event_seating_areas')->insert([
            'id'                 => $id,
            'organization_id'    => $organizationId,
            'event_id'           => $eventId,
            'plan_id'            => $plan['id'] ?? null,
            'name'               => $data['name'],
            'capacity'           => (int) ($data['capacity'] ?? 0),
            'allocated_group_id' => $data['allocated_group_id'] ?? null,
            'created_at'         => $this->clock->nowUtcString(),
        ]);

        return Result::created(['seating_area_id' => $id]);
    }

    // ---- staff roster ------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function assignStaff(string $organizationId, string $eventId, array $data): Result
    {
        $userId = (string) ($data['user_id'] ?? '');
        $role   = (string) ($data['role'] ?? '');
        if ($userId === '' || $role === '') {
            return Result::fail('MISSING_FIELDS', 'logistics.staff_missing_fields', 422);
        }
        $id  = Uuid::v7();
        $plan = $this->db->table('event_logistics_plans')->where('event_id', $eventId)->get()->getRowArray();
        try {
            $this->db->table('event_staff_roster')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'plan_id'         => $plan['id'] ?? null,
                'user_id'         => $userId,
                'role'            => $role,
                'assignment'      => $data['assignment'] ?? null,
                'status'          => 'assigned',
                'created_at'      => $this->clock->nowUtcString(),
            ]);
        } catch (Throwable) {
            return Result::fail('ALREADY_ASSIGNED', 'logistics.already_assigned', 409);
        }

        return Result::created(['roster_id' => $id]);
    }

    // ---- suppliers ---------------------------------------------------------

    /** @param array<string,mixed> $data */
    public function addSupplier(string $organizationId, string $eventId, array $data): Result
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return Result::fail('NAME_REQUIRED', 'logistics.name_required', 422);
        }
        $id = Uuid::v7();
        $this->db->table('event_suppliers')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'event_id'        => $eventId,
            'name'            => $data['name'],
            'category'        => $data['category'] ?? null,
            'contact'         => $data['contact'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'created_at'      => $this->clock->nowUtcString(),
        ]);

        return Result::created(['supplier_id' => $id]);
    }

    // ---- catering / accessibility -----------------------------------------

    /**
     * Record an individual's specially-classified dietary/accessibility need.
     * Also increments the non-sensitive aggregate headcount for the label so
     * catering can be planned without exposing personal detail.
     *
     * @param array<string,mixed> $data need_type, detail, diet_label
     */
    public function recordAccessibilityNeed(string $organizationId, string $eventId, string $userId, array $data, ?string $createdBy): Result
    {
        $needType = (string) ($data['need_type'] ?? '');
        if ($needType === '') {
            return Result::fail('TYPE_REQUIRED', 'logistics.need_type_required', 422);
        }

        $this->db->transStart();
        try {
            $this->db->table('event_accessibility_needs')->insert([
                'id'              => Uuid::v7(),
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'user_id'         => $userId,
                'need_type'       => $needType,
                'detail'          => $data['detail'] ?? null,
                'classification'  => 'restricted',
                'created_by'      => $createdBy,
                'created_at'      => $this->clock->nowUtcString(),
            ]);
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('ALREADY_RECORDED', 'logistics.need_already_recorded', 409);
        }

        // Aggregate rollup (non-sensitive) keyed by diet/label.
        $label = (string) ($data['diet_label'] ?? $needType);
        $this->bumpCateringAggregate($organizationId, $eventId, $label, 1);

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('RECORD_FAILED', 'logistics.record_failed', 500);
        }

        return Result::created(['event_id' => $eventId, 'need_type' => $needType]);
    }

    /**
     * Non-sensitive catering headcount rollup by dietary label — safe for
     * logistics dashboards. Contains NO individual detail.
     *
     * @return list<array<string,mixed>>
     */
    public function cateringAggregates(string $eventId): array
    {
        return $this->db->table('event_catering_aggregates')
            ->select('diet_label, headcount')
            ->where('event_id', $eventId)
            ->orderBy('diet_label', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Individual accessibility/dietary needs. SPECIALLY CLASSIFIED: callers MUST
     * gate this behind authorized logistics personnel (route filter
     * authorize:event.logistics.manage). Never surface in aggregate reports.
     *
     * @return list<array<string,mixed>>
     */
    public function listAccessibilityNeeds(string $eventId): array
    {
        return $this->db->table('event_accessibility_needs')
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'ASC')
            ->get()->getResultArray();
    }

    private function bumpCateringAggregate(string $organizationId, string $eventId, string $label, int $delta): void
    {
        $now = $this->clock->nowUtcString();
        $existing = $this->db->table('event_catering_aggregates')
            ->where('event_id', $eventId)->where('diet_label', $label)
            ->get()->getRowArray();
        if ($existing === null) {
            try {
                $this->db->table('event_catering_aggregates')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'event_id'        => $eventId,
                    'diet_label'      => $label,
                    'headcount'       => max(0, $delta),
                    'updated_at'      => $now,
                ]);
            } catch (Throwable) {
                $this->db->query(
                    'UPDATE event_catering_aggregates SET headcount = headcount + ?, updated_at = ? WHERE event_id = ? AND diet_label = ?',
                    [$delta, $now, $eventId, $label],
                );
            }
        } else {
            $this->db->query(
                'UPDATE event_catering_aggregates SET headcount = GREATEST(0, headcount + ?), updated_at = ? WHERE id = ?',
                [$delta, $now, $existing['id']],
            );
        }
    }
}
