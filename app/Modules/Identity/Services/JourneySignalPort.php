<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam AccountService uses to emit a journey signal when a new account is
 * created (M4 — the "one open seam"), so the Identity module depends on this one
 * method rather than the whole Journey module. The production adapter forwards to
 * `Journey\JourneySignalService::ingest()`, which evaluates the membership-facet
 * ENTRY rule that opens the journey at the first ladder stage. Tests supply a
 * trivial in-memory implementation. Mirrors the identical seams in Referrals and
 * Groups.
 *
 * @see \WBS\Journey\Services\JourneySignalService::ingest()
 */
interface JourneySignalPort
{
    /**
     * @param array<string,mixed> $data user_id, action, group_id?, actor_id?,
     *   evidence_type?, evidence_ref?, attributes?
     */
    public function ingest(string $organizationId, array $data): Result;
}
