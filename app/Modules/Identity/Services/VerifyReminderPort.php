<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

/**
 * Narrow seam the M6 verify-expiry sweep uses to nudge a `pending_verification`
 * account to finish verifying, without Identity depending on the Notifications
 * service surface directly. The production adapter forwards to
 * NotificationService::send() (preference-gated + deduped); tests supply a spy.
 */
interface VerifyReminderPort
{
    /**
     * Remind $userId to verify their account. MUST be idempotent per reminder
     * round via $dedupeKey so a re-run doesn't double-notify.
     *
     * @param array<string,mixed> $context
     */
    public function remindVerify(
        string $organizationId,
        string $userId,
        string $dedupeKey,
        array $context = [],
    ): void;
}
