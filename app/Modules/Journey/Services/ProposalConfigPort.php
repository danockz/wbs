<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * Narrow config port the journey proposal-aging sweep (J6) reads its per-org SLA
 * thresholds through, so JourneyProposalAgingService depends on one method rather
 * than the whole Admin SettingsService (which is final and DB-bound). The
 * production adapter wraps SettingsService::get; tests supply a trivial in-memory
 * map.
 */
interface ProposalConfigPort
{
    /** Per-org config value (already decoded by the store), or $default. */
    public function get(string $organizationId, string $key, mixed $default = null): mixed;
}
