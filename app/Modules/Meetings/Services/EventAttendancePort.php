<?php

declare(strict_types=1);

namespace WBS\Meetings\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam the Meetings MT2 reconciler uses to turn a meeting's provider
 * attendance EVIDENCE into platform ATTENDANCE, so Meetings depends on this one
 * method rather than the whole Events module. The production adapter wraps
 * Events\CheckinService::checkInFromMeetingEvidence() — which records a
 * `streaming` attendance through the same path as a QR/manual check-in (awards,
 * journey signals, group attribution, one-active-attendance UNIQUE key), so a
 * reconciled attendance is indistinguishable from a live one and a duplicate
 * evidence row dedupes idempotently. Tests supply a trivial in-memory double.
 *
 * @see \WBS\Events\Services\CheckinService::checkInFromMeetingEvidence()
 */
interface EventAttendancePort
{
    /**
     * @param list<string> $groupAttribution
     */
    public function recordFromMeetingEvidence(
        string $organizationId,
        string $eventId,
        string $userId,
        array $groupAttribution = [],
    ): Result;
}
