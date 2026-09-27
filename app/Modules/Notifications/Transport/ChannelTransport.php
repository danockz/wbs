<?php

declare(strict_types=1);

namespace WBS\Notifications\Transport;

/**
 * A concrete outbound channel (email, SMS, push, …) that performs REAL external
 * I/O — the piece that was previously stubbed (FR-NOT-*, FR-INT-012).
 *
 * The notification pipeline is channel-agnostic up to this seam:
 * NotificationService::send() records the delivery and stages a
 * `notification.dispatch` job; the dispatcher resolves the transport for the
 * job's channel and calls {@see deliver()}. Adding a channel = implementing this
 * interface and registering it in {@see TransportRegistry} — no dispatcher or
 * service changes.
 *
 * Implementations MUST NOT throw for ordinary provider errors; they return a
 * {@see TransportResult} so the dispatcher can distinguish retryable faults from
 * permanent per-message rejections. Only truly unexpected states should throw.
 */
interface ChannelTransport
{
    /** Channel code this transport handles, e.g. "email". */
    public function channel(): string;

    /**
     * Attempt real delivery of an already-rendered message.
     *
     * Never throws for expected provider conditions — signal them via
     * {@see TransportResult::failed()} (retryable) or
     * {@see TransportResult::rejected()} (permanent).
     */
    public function deliver(TransportMessage $message): TransportResult;
}
