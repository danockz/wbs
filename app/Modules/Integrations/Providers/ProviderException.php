<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use RuntimeException;

/**
 * Raised when a provider API call fails. Carries the provider's OWN error
 * message (not just an HTTP status) so operators can see what to fix — mirrors
 * the honest-error principle from the source streamingservice spec.
 *
 * Never carries secret material (tokens are used inside CredentialVault closures
 * and never reach this boundary).
 */
final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string $provider = '',
    ) {
        parent::__construct($message);
    }
}
