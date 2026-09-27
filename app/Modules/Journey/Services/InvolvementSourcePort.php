<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * Narrow port the InvolvementService uses to gather a member's involvement
 * metrics from the source modules (Events/Courses activity, Referrals
 * sponsorships, Contributions giving, gamification points, and the rolled-up
 * downline quantum). Keeping this behind one method means the engine — and its
 * band rules — stay unit-testable without booting four modules, and the
 * expensive cross-module reads live in ONE adapter that runs only on the
 * snapshot WRITE path (transition / batch recompute), never the render hot path.
 */
interface InvolvementSourcePort
{
    /**
     * Aggregate one member's involvement metrics for a context since $sinceUtc.
     *
     * @return array{
     *   last_activity_at: ?string,       // Y-m-d H:i:s of most recent activity (any window)
     *   activity_count: int,             // participations within the window
     *   sponsorship_count: int,          // active direct recruits (Referrals)
     *   giving_minor: int,               // verified giving in minor units (window or lifetime per adapter)
     *   points: int,                     // gamification balance (point_ledger)
     *   downline_own_quantum: int        // SUM of disciples' OWN quantum (pre-rollup-share)
     * }
     */
    public function metricsFor(string $organizationId, string $userId, ?string $groupId, string $sinceUtc): array;
}
