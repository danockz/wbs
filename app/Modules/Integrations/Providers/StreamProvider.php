<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Contract every live-streaming provider adapter implements (YouTube, Twitch,
 * Facebook, RTMP, …). Replaces the source spec's per-platform switch statements
 * inside one God-class with a polymorphic adapter set.
 *
 * Design rules:
 *  - Adapters receive a connectionId and pull tokens ONLY via CredentialVault's
 *    useSecret() closure — a plaintext credential never crosses this boundary.
 *  - Every method returns a BroadcastResult of NON-secret references, or throws
 *    ProviderException carrying the provider's own error message.
 *  - fetchAnalytics() returns provider-reported numbers tagged with an
 *    'exactness' the caller records via StreamEngagementService::recordMetric —
 *    the platform never fabricates a metric a provider does not expose.
 */
interface StreamProvider
{
    /** Machine code, e.g. 'youtube' — matches stream_destinations.provider. */
    public function code(): string;

    /**
     * Create the broadcast/stream on the provider and return its external ref.
     *
     * @param array<string,mixed> $stream      The streams row.
     * @param array<string,mixed> $destination The stream_destinations row.
     */
    public function createBroadcast(array $stream, array $destination): BroadcastResult;

    /**
     * Transition the provider broadcast to live/testing→active.
     *
     * @param array<string,mixed> $destination The stream_destinations row (with external_ref).
     */
    public function startBroadcast(array $destination): BroadcastResult;

    /**
     * End the provider broadcast.
     *
     * @param array<string,mixed> $destination The stream_destinations row.
     */
    public function endBroadcast(array $destination): BroadcastResult;

    /**
     * Fetch provider-reported analytics for a broadcast.
     *
     * @param array<string,mixed> $destination The stream_destinations row.
     * @return array{metric:string,value:?float,exactness:string}[] Zero or more samples.
     */
    public function fetchAnalytics(array $destination): array;
}
