<?php

declare(strict_types=1);

namespace WBS\Gamification\Sweep;

use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, G2): age the OPEN fraud-review queue.
 *
 * A `requires_review` award holds points and opens a `fraud_reviews` row, but
 * nothing ever aged it — a review a human never noticed stranded the points
 * forever. Wraps the bounded, idempotent
 * FraudReviewAgingService::sweepOpenReviews(), which runs a uniform
 * remind → escalate → (optional) timeout lifecycle over open reviews, every step
 * watermark-guarded so a re-run in the same window is a no-op.
 *
 * Thresholds are per-org `gamification_config` (`held_review_remind_hours`,
 * `held_review_escalate_hours`, `held_review_timeout_days`); auto-reject is OFF
 * by default (timeout_days=0).
 */
final class HeldReviewAgingSweep implements SweepContract
{
    public function key(): string
    {
        return 'gamification.held-review-aging';
    }

    public function description(): string
    {
        return 'Remind, escalate and (optionally) time-out open fraud reviews so held points are never stranded.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 200;

        $r = GamificationServices::fraudReviewAging()->sweepOpenReviews($organizationId, $limit);

        // "Swept" = reviews that actually advanced this pass.
        $advanced = (int) $r['reminded'] + (int) $r['escalated'] + (int) $r['timed_out'];

        return SweepResult::ok($advanced, [
            'scanned'   => (int) $r['scanned'],
            'reminded'  => (int) $r['reminded'],
            'escalated' => (int) $r['escalated'],
            'timed_out' => (int) $r['timed_out'],
        ]);
    }
}
