<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam the ContactBookService uses to emit a journey signal when a
 * contact records a decision (e.g. `join_group`), so the outreach module depends
 * on this one method rather than the whole Journey module. The production adapter
 * wraps Journey\JourneySignalService::ingest() — which evaluates membership-facet
 * rules and advances (or proposes) the person's stage. Tests supply a trivial
 * in-memory implementation.
 *
 * @see \WBS\Journey\Services\JourneySignalService::ingest()
 */
interface JourneySignalPort
{
    /**
     * @param array<string,mixed> $data user_id, action, group_id?, scope_group_id?,
     *   attributes?, actor_id?
     */
    public function ingest(string $organizationId, array $data): Result;
}
