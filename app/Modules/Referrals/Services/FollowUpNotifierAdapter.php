<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Notifications\Services\NotificationService;

/**
 * Production FollowUpNotifierPort (R4): send the owner a follow-up-due reminder
 * through the platform's NotificationService, which applies preference/opt-out
 * gating, quiet-hours deferral (later released by the N1 sweep) and dedupe on the
 * stable per-due-date key — so a contact going overdue produces exactly one
 * reminder per due date, never a daily storm.
 */
final class FollowUpNotifierAdapter implements FollowUpNotifierPort
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function remindFollowUpDue(
        string $organizationId,
        string $ownerUserId,
        string $contactId,
        string $dedupeKey,
        array $context = [],
    ): void {
        $this->notifications->send(
            $organizationId,
            $ownerUserId,
            'email',
            'outreach_follow_up_due',
            [
                'priority'   => 'normal',
                'dedupe_key' => $dedupeKey,
                'context'    => $context,
            ],
        );
    }
}
