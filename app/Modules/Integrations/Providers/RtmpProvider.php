<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;
use WBS\Shared\Support\Uuid;

/**
 * RTMP / custom ingest "provider". There is no external API: the caller supplies
 * an ingest server URL (non-secret) and the adapter mints a random stream KEY,
 * which is a SECRET and is therefore stored via CredentialVault under the
 * connection's 'rtmp_stream_key' slot — never returned in the BroadcastResult
 * and never persisted on the stream_destinations row.
 *
 * The ingest server URL comes from the destination's non-secret capabilities
 * bag (destination.capabilities.ingest_url).
 */
final class RtmpProvider implements StreamProvider
{
    public function __construct(
        private readonly CredentialVault $vault,
    ) {
    }

    public function code(): string
    {
        return 'rtmp';
    }

    public function createBroadcast(array $stream, array $destination): BroadcastResult
    {
        $caps      = $this->caps($destination);
        $ingestUrl = (string) ($caps['ingest_url'] ?? '');
        if ($ingestUrl === '' || ! preg_match('#^rtmps?://#i', $ingestUrl)) {
            throw new ProviderException('RTMP ingest_url missing or invalid', 0, $this->code());
        }

        // Mint a stream key and store it as a secret when a connection exists.
        $streamKey = bin2hex(random_bytes(24));
        $connId    = $destination['connection_id'] ?? null;
        if ($connId !== null) {
            $this->vault->put((string) $connId, 'rtmp_stream_key', $streamKey);
        }

        // The external_ref is a NON-secret local broadcast handle.
        return new BroadcastResult(
            externalRef: 'rtmp_' . Uuid::v7(),
            ingestUrl: $ingestUrl,
            watchUrl: $caps['watch_url'] ?? null,
            meta: ['key_stored' => $connId !== null],
        );
    }

    public function startBroadcast(array $destination): BroadcastResult
    {
        // RTMP is "live" as soon as the encoder pushes; nothing to call.
        return new BroadcastResult(
            externalRef: (string) ($destination['external_ref'] ?? ''),
            meta: ['note' => 'rtmp is live when the encoder connects'],
        );
    }

    public function endBroadcast(array $destination): BroadcastResult
    {
        return new BroadcastResult(
            externalRef: (string) ($destination['external_ref'] ?? ''),
            meta: ['note' => 'rtmp ends when the encoder disconnects'],
        );
    }

    public function fetchAnalytics(array $destination): array
    {
        // A bare RTMP server exposes no viewer API.
        return [
            ['metric' => 'viewers', 'value' => null, 'exactness' => 'unavailable'],
        ];
    }

    /** @return array<string,mixed> */
    private function caps(array $destination): array
    {
        $caps = $destination['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }

        return is_array($caps) ? $caps : [];
    }
}
