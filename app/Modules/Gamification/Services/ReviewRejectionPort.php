<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow port for the ONE points-engine action the fraud-review aging sweep (G2)
 * needs when a review times out: post a compensating reversal so the held points
 * never become spendable. FraudReviewAgingService depends on this rather than the
 * whole (final) PointsEngine; the production adapter delegates to
 * PointsEngine::rejectAward, tests record the call.
 */
interface ReviewRejectionPort
{
    public function rejectAward(string $ledgerId, string $approverId, string $reason): Result;
}
