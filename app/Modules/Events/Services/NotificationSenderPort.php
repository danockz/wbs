<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * Narrow send seam wrapping NotificationService::send(), so the EventNotifier can
 * be unit-tested with a spy and does not itself depend on the whole Notifications
 * module. The production adapter forwards to the idempotent
 * NotificationService::send (dedupe on opts['dedupe_key'], RetentionPolicyGate,
 * outbox staging of `notification.dispatch`) — reusing that infra, not forking it.
 */
interface NotificationSenderPort
{
    /**
     * @param array<string,mixed> $opts NotificationService::send opts
     *                                   (dedupe_key, category context, priority, …)
     */
    public function send(string $organizationId, string $userId, string $channel, string $category, array $opts = []): void;
}
