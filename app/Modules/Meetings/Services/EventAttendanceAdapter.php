<?php

declare(strict_types=1);

namespace WBS\Meetings\Services;

use WBS\Events\Services\CheckinService;
use WBS\Shared\Support\Result;

/**
 * Production EventAttendancePort: forwards a reconciled meeting-evidence
 * attendance to the platform's own Events\CheckinService, so the streaming
 * attendance lands through the SAME path as a QR/manual check-in (points awards,
 * journey signals, group attribution, one-active-attendance UNIQUE key). No
 * forked attendance path — the Events module stays the single writer of
 * `event_attendance`.
 */
final class EventAttendanceAdapter implements EventAttendancePort
{
    public function __construct(private readonly CheckinService $checkin)
    {
    }

    public function recordFromMeetingEvidence(
        string $organizationId,
        string $eventId,
        string $userId,
        array $groupAttribution = [],
    ): Result {
        return $this->checkin->checkInFromMeetingEvidence($organizationId, $eventId, $userId, $groupAttribution);
    }
}
