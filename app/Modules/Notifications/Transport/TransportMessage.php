<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

/**
 * Immutable, channel-agnostic message handed to a {@see ChannelTransport}.
 *
 * It carries only what a transport needs to perform real delivery:
 *  - the delivery id (for correlation / provider idempotency keys),
 *  - the resolved recipient endpoint (email address, phone, device token, …),
 *  - the already-rendered body (rendering happened at send() time),
 *  - the group whose credentials and sender identity this message sends on, and
 *  - a subject + metadata bag for channels that use them.
 *
 * `groupId` is what makes per-body credentials possible: each hierarchical group
 * supplies its own provider account (or is granted one by an ancestor), so a
 * transport must know WHICH body it is sending for. It is nullable — a delivery
 * with no group has no credentials behind it, and a credential-aware transport
 * refuses it rather than borrowing somebody else's account.
 *
 * PII note: this object exists only for the duration of a dispatch job and is
 * never persisted; the durable record is the notification_deliveries row.
 */
final class TransportMessage
{
    /**
     * @param array<string,mixed> $meta non-secret extras (e.g. reply_to, tags)
     */
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $organizationId,
        public readonly string $channel,
        public readonly string $category,
        public readonly string $recipient,
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly array $meta = [],
        /** The body whose provider credentials/sender identity this sends on. */
        public readonly ?string $groupId = null,
    ) {
    }
}
