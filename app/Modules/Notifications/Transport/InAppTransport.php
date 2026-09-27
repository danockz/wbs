<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

/**
 * In-app {@see ChannelTransport}. Unlike email/SMS/push there is no external
 * provider: an in-app notification is "delivered" by the persisted delivery row
 * itself, which the client reads. This transport therefore accepts immediately.
 *
 * It exists so the dispatcher can treat EVERY channel uniformly (resolve a
 * transport, deliver, map the result) instead of special-casing in-app — and so
 * an unregistered channel remains a hard error rather than a silent "sent".
 */
final class InAppTransport implements ChannelTransport
{
    public function channel(): string
    {
        return 'inapp';
    }

    public function deliver(TransportMessage $message): TransportResult
    {
        // Nothing external to call; the row is the delivery.
        return TransportResult::accepted($message->deliveryId);
    }
}
