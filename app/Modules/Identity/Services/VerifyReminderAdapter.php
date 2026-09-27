<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use WBS\Notifications\Services\NotificationService;

/**
 * Production VerifyReminderPort (M6): send the verify nudge through the platform's
 * gated NotificationService (preference/opt-out gating, quiet-hours deferral,
 * dedupe on the per-round key), so a stale pending account gets at most one
 * reminder per round, never a storm.
 */
final class VerifyReminderAdapter implements VerifyReminderPort
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function remindVerify(string $organizationId, string $userId, string $dedupeKey, array $context = []): void
    {
        $this->notifications->send(
            $organizationId,
            $userId,
            'email',
            'account_verify_reminder',
            [
                'priority'   => 'normal',
                'dedupe_key' => $dedupeKey,
                'context'    => $context,
            ],
        );
    }
}
