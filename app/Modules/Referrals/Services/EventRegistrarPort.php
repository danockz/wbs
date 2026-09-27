<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam the ContactBookService uses to register a contact for an EVENT as
 * part of a follow-up, so the outreach module depends on this one method rather
 * than the whole Events module. The production adapter wraps
 * Events\RegistrationService::register() — which enforces the pre-event gates
 * (published-only, ATOMIC capacity + live holds, ordered waitlist) — so a
 * follow-up registration can never oversell or land on a draft/closed event.
 * Tests supply a trivial in-memory implementation.
 *
 * @see \WBS\Events\Services\RegistrationService::register()
 */
interface EventRegistrarPort
{
    /**
     * @param array<string,mixed> $opts group_attribution, source_ref, rsvp_state
     */
    public function register(string $organizationId, string $eventId, string $userId, array $opts = []): Result;
}
