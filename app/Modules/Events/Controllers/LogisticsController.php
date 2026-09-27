<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Logistics and operations (SRS FR-EVT-018).
 *
 * Individual accessibility/dietary needs are specially classified: the
 * `needs` read endpoint is gated behind authorize:event.logistics.manage in
 * Routes.php, and aggregate reporting never exposes individual detail.
 */
final class LogisticsController extends BaseController
{
    /**
     * GET events/{id}/logistics — the browser LOGISTICS CONSOLE: the plan header
     * plus its resources, seating, staff roster and suppliers, each section with a
     * no-JS PRG form for the matching write action (create plan / add resource /
     * add seating / assign staff / add supplier / refresh projection). API clients
     * get the same data as JSON. `renderForm` mints the `_csrf` token the
     * webcsrf-guarded POSTs need. NO specially-classified accessibility detail is
     * shown here (that stays behind the gated needs endpoint).
     */
    public function planConsole(string $eventId = '')
    {
        $overview = EventServices::logistics()->planOverview($eventId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($overview + ['event_id' => $eventId]));
        }

        return $this->renderForm('WBS\Events\Views\logistics_plan', $overview + [
            'eventId' => $eventId,
            // user_id on the assign-staff form is an entity reference → offer the
            // org roster (view excludes users already on this event's staff roster).
            'roster'  => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
        ]);
    }

    public function ensurePlan(string $eventId = '')
    {
        return $this->respondLogistics(
            EventServices::logistics()->ensurePlan($this->orgId(), $eventId),
            $eventId,
            'planReadyFlash',
        );
    }

    public function addResource(string $eventId = '')
    {
        return $this->respondLogistics(
            EventServices::logistics()->addResource($this->orgId(), $eventId, $this->input()),
            $eventId,
            'resourceAddedFlash',
        );
    }

    public function orderResource(string $resourceId = '')
    {
        return $this->respondWith(EventServices::logistics()->orderResource(
            $resourceId,
            (int) $this->field('quantity_ordered', 0),
        ));
    }

    public function refreshProjection(string $eventId = '')
    {
        // Derive the current expected-attendance point estimate, then reproject.
        $expected = EventServices::events()->expectedAttendance($eventId, (float) $this->field('show_rate', 0.6));
        if ($expected->failed()) {
            return $this->respondLogistics($expected, $eventId, 'projectionRefreshedFlash');
        }
        $point = (int) ($expected->data['expected']['point_estimate'] ?? 0);

        return $this->respondLogistics(
            EventServices::logistics()->refreshProjection($eventId, $point),
            $eventId,
            'projectionRefreshedFlash',
        );
    }

    public function addSeatingArea(string $eventId = '')
    {
        return $this->respondLogistics(
            EventServices::logistics()->addSeatingArea($this->orgId(), $eventId, $this->input()),
            $eventId,
            'seatingAddedFlash',
        );
    }

    public function assignStaff(string $eventId = '')
    {
        return $this->respondLogistics(
            EventServices::logistics()->assignStaff($this->orgId(), $eventId, $this->input()),
            $eventId,
            'staffAssignedFlash',
        );
    }

    public function addSupplier(string $eventId = '')
    {
        return $this->respondLogistics(
            EventServices::logistics()->addSupplier($this->orgId(), $eventId, $this->input()),
            $eventId,
            'supplierAddedFlash',
        );
    }

    /**
     * PRG for a browser logistics write: API clients keep the raw Result (JSON);
     * browsers redirect back to the event's logistics console with a localized
     * success flash, or the failing Result's message as an error flash.
     */
    private function respondLogistics(Result $result, string $eventId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $eventId !== '' ? '/events/' . rawurlencode($eventId) . '/logistics' : '/events';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Events.logistics.' . $okKey));
    }

    /** Record an individual's specially-classified accessibility/dietary need. */
    public function recordNeed(string $eventId = '')
    {
        $in = $this->input();

        return $this->respondWith(EventServices::logistics()->recordAccessibilityNeed(
            $this->orgId(),
            $eventId,
            (string) ($in['user_id'] ?? ''),
            $in,
            $this->actorId(),
        ));
    }

    /** Non-sensitive catering headcount rollup (safe for dashboards). */
    public function cateringAggregates(string $eventId = '')
    {
        $rows = EventServices::logistics()->cateringAggregates($eventId);

        // API clients get JSON; browsers get the bespoke catering-aggregates view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok(['catering' => $rows, 'count' => count($rows)]), 'Catering aggregates', 'event ' . $eventId);
        }

        return $this->respondWith(
            Result::ok(['catering' => $rows, 'count' => count($rows)]),
            'WBS\Events\Views\catering_aggregates',
            null,
            ['catering' => $rows],
        );
    }

    /** SPECIALLY CLASSIFIED — gated by authorize:event.logistics.manage. */
    public function needs(string $eventId = '')
    {
        $rows = EventServices::logistics()->listAccessibilityNeeds($eventId);

        // API clients get JSON; browsers get the bespoke (confidential) needs view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok(['needs' => $rows, 'count' => count($rows)]), 'Accessibility needs', 'event ' . $eventId);
        }

        return $this->respondWith(
            Result::ok(['needs' => $rows, 'count' => count($rows)]),
            'WBS\Events\Views\accessibility_needs',
            null,
            ['needs' => $rows],
        );
    }
}
