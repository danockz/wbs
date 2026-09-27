<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

/**
 * Narrow seam the R4 follow-up sweep uses to remind a contact's OWNER that a
 * follow-up is due, without the Referrals module depending on the Notifications
 * service surface directly. The production adapter forwards to
 * NotificationService::send() (preference-gated + deduped); tests supply a spy.
 */
interface FollowUpNotifierPort
{
    /**
     * Remind $ownerUserId that $contactId is due for follow-up. MUST be
     * idempotent per due date via $dedupeKey (a re-run on the same day must not
     * double-notify), mirroring the commitment-due reminder pattern.
     *
     * @param array<string,mixed> $context
     */
    public function remindFollowUpDue(
        string $organizationId,
        string $ownerUserId,
        string $contactId,
        string $dedupeKey,
        array $context = [],
    ): void;
}
