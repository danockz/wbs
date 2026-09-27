<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * Immutable input handed to a custom adapter for a single canonical operation
 * (SRS FR-INT-013).
 *
 * A custom adapter NEVER receives raw credentials or a database handle. It gets:
 *  - the canonical operation being invoked,
 *  - a non-secret parameter bag (already validated by the caller),
 *  - the non-secret connection config (from the manifest's config_schema), and
 *  - a `secret(slot)` resolver closure that yields a credential ONLY for the
 *    duration of the call (backed by CredentialVault::useSecret at runtime, or
 *    by fixtures under the contract-test harness). This keeps the adapter honest
 *    and testable without ever exposing plaintext to application code.
 */
final class OperationRequest
{
    /**
     * @param array<string,mixed>     $params  non-secret operation parameters
     * @param array<string,mixed>     $config  non-secret connection config
     * @param callable(string):?string $secretResolver returns a credential for a slot, or null
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $params = [],
        public readonly array $config = [],
        private $secretResolver = null,
    ) {
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Resolve a credential slot for the duration of this call. Returns null when
     * the slot is unavailable; the adapter must fail safely in that case.
     */
    public function secret(string $slot): ?string
    {
        if ($this->secretResolver === null) {
            return null;
        }

        return ($this->secretResolver)($slot);
    }
}
