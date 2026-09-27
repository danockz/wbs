<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

/**
 * Immutable outcome of a {@see ChannelTransport::deliver()} call.
 *
 * A transport reports one of three things, which the dispatcher maps onto the
 * delivery lifecycle + the provider circuit breaker:
 *
 *  - accepted()  → provider took the message. Delivery → "sent", breaker success.
 *  - failed()    → provider fault (5xx, timeout, auth). The dispatcher RAISES so
 *                  the queue retries with backoff; breaker records a failure.
 *  - rejected()  → the message is undeliverable and retrying will not help
 *                  (invalid address, hard bounce at submit, permanent 4xx).
 *                  Delivery → "failed" WITHOUT re-queueing; not a provider fault
 *                  so the breaker is left alone.
 *
 * The distinction between failed() and rejected() is what keeps a bad recipient
 * from tripping the circuit breaker or looping the queue forever.
 */
final class TransportResult
{
    public const ACCEPTED = 'accepted';
    public const FAILED   = 'failed';
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** Provider accepted the message; $providerRequestId is its handle if any. */
    public static function accepted(?string $providerRequestId = null): self
    {
        return new self(self::ACCEPTED, $providerRequestId, null);
    }

    /** Transient provider fault — the caller should retry (and trip the breaker). */
    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, $reason);
    }

    /** Permanent per-message rejection — do not retry, do not blame the provider. */
    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }
}
