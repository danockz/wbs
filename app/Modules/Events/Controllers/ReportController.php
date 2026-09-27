<?php

declare(strict_types=1);

namespace WBS\Events\Controllers;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Http\BaseController;

/**
 * Mobilization report (SRS FR-EVT-014). Aggregate-only; permission-gated.
 */
final class ReportController extends BaseController
{
    /** Live mobilization report for an event. */
    public function build(string $eventId = '')
    {
        $result = EventServices::reports()->build($eventId);

        // API clients get JSON; browsers get the bespoke aggregate-report view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Event report', 'event ' . $eventId);
        }

        return $this->respondWith(
            $result,
            'WBS\Events\Views\report',
            null,
            ['report' => $result->data],
        );
    }

    /** Freeze an immutable snapshot for roll-up. */
    public function snapshot(string $eventId = '')
    {
        return $this->respondWith(EventServices::reports()->snapshot($eventId, $this->actorId()));
    }

    /**
     * Roll aggregate figures up across a group's events (from latest snapshots).
     *
     * `?scope=subtree` (the default, gap L5) sums the WHOLE subtree — self +
     * descendant groups — consistent with the platform's credit-every-ancestor
     * model; `?scope=self` keeps the single-group view. Invalid values fall back
     * to 'subtree' here and are re-validated in the service.
     */
    public function groupRollup(string $groupId = '')
    {
        $scope = (string) $this->field('scope', 'subtree');
        if (! in_array($scope, ['self', 'subtree'], true)) {
            $scope = 'subtree';
        }

        $result = EventServices::reports()->groupRollup($groupId, $scope);

        // API clients get JSON; browsers get the bespoke roll-up view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Event group roll-up', 'group ' . $groupId);
        }

        $data = is_array($result->data) ? $result->data : [];

        return $this->respondWith(
            $result,
            'WBS\Events\Views\report_rollup',
            null,
            [
                'rollup'        => $data['rollup'] ?? [],
                'groupId'       => $groupId,
                'scope'         => $data['scope'] ?? $scope,
                'groupsCounted' => $data['groups_counted'] ?? 1,
            ],
        );
    }
}
