<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Immutable result of creating a provider meeting/webinar.
 *
 * Holds only NON-secret references: the provider meeting id and a join URL.
 * A meeting PASSWORD, when a provider issues one, is a secret — it is stored via
 * CredentialVault by the adapter and is NOT a field here.
 */
final class MeetingResult
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $externalRef,
        public readonly ?string $joinUrl = null,
        public readonly array $meta = [],
    ) {
    }
}
