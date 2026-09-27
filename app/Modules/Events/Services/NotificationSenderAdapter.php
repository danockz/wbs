<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Notifications\Services\NotificationService;

/**
 * Production NotificationSenderPort: forwards to the idempotent
 * NotificationService::send (dedupe on opts['dedupe_key'], RetentionPolicyGate,
 * outbox staging of `notification.dispatch` for queued sends). Reuses the
 * Notifications module wholesale — no forked transport.
 */
final class NotificationSenderAdapter implements NotificationSenderPort
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = []): void
    {
        $this->notifications->send($organizationId, $userId, $channel, $category, $opts);
    }
}
