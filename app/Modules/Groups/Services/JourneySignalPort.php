<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam GroupMembershipService uses to emit a journey signal when a
 * belonging changes (a membership becomes active, or a member leaves), so the
 * Groups module depends on this one method rather than the whole Journey module.
 *
 * The direction matters: the Journey module already depends on Groups (its
 * controller resolves group scope), so a *direct* Groups → Journey dependency
 * would be a cycle. This port keeps Groups decoupled — the production adapter
 * (wired in the Groups composition root) forwards to
 * `Journey\JourneySignalService::ingest()`, which evaluates the membership-facet
 * rules and advances (or proposes) the person's stage. Tests supply a trivial
 * in-memory implementation. Mirrors the identical seam Referrals already uses.
 *
 * @see \WBS\Journey\Services\JourneySignalService::ingest()
 */
interface JourneySignalPort
{
    /**
     * @param array<string,mixed> $data user_id, action, group_id?, scope_group_id?,
     *   attributes?, actor_id?, evidence_type?, evidence_ref?
     */
    public function ingest(string $organizationId, array $data): Result;
}
