<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Events\Services\RegistrationService;
use WBS\Shared\Support\Result;

/**
 * Production EventRegistrarPort: forwards a follow-up event registration to the
 * platform's own Events\RegistrationService::register(), so the pre-event gates
 * (published-only, atomic capacity + live holds, ordered waitlist, RSVP change)
 * apply uniformly whether the person self-registers or a follower registers them.
 * Reuses the Events module wholesale — no forked registration path.
 */
final class EventRegistrarAdapter implements EventRegistrarPort
{
    public function __construct(private readonly RegistrationService $registrations)
    {
    }

    public function register(string $organizationId, string $eventId, string $userId, array $opts = []): Result
    {
        return $this->registrations->register($organizationId, $eventId, $userId, $opts);
    }
}
