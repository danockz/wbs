<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Immutable result of a provider broadcast operation.
 *
 * Holds only NON-secret references: the provider's broadcast id (safe to store
 * in stream_destinations.external_ref), an optional ingest/watch URL, and an
 * arbitrary metadata bag. The RTMP stream KEY is a secret and is deliberately
 * NOT a field here — when a provider mints one, the adapter stores it via
 * CredentialVault, never in this object.
 */
final class BroadcastResult
{
    /**
     * @param array<string,mixed> $meta Non-secret provider metadata.
     */
    public function __construct(
        public readonly string $externalRef,
        public readonly ?string $ingestUrl = null,
        public readonly ?string $watchUrl = null,
        public readonly array $meta = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'external_ref' => $this->externalRef,
            'ingest_url'   => $this->ingestUrl,
            'watch_url'    => $this->watchUrl,
            'meta'         => $this->meta,
        ];
    }
}
