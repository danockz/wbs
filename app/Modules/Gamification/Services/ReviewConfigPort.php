<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

/**
 * Narrow config port the fraud-review aging sweep (G2) reads its per-org SLA
 * thresholds through, so FraudReviewAgingService depends on one method rather
 * than the whole ConfigService (which is final and DB-bound). The production
 * adapter wraps ConfigService::get; tests supply a trivial in-memory map.
 */
interface ReviewConfigPort
{
    /** Per-org config value, cast by the underlying store, or $default. */
    public function get(string $organizationId, string $key, mixed $default = null): mixed;
}
