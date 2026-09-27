<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

/**
 * Constant-time HMAC-SHA256 verification of inbound payment webhooks
 * (SRS FR-VBCS-006, FR-INT-012).
 *
 * Extracted from WebhookController so the trust decision — the gate that
 * protects every payment state change from spoofed events — is unit-testable in
 * isolation. Behaviour is unchanged: verify the provider's signature over the
 * RAW request body using the provider's configured secret, comparing in
 * constant time.
 *
 * FAIL-CLOSED by construction:
 *  - an empty signature is never valid,
 *  - a missing/empty secret is never valid (an unconfigured provider cannot be
 *    trusted — we do not silently accept), and
 *  - comparison uses hash_equals to avoid timing side-channels.
 */
final class WebhookSignatureVerifier
{
    /**
     * Resolve the per-provider secret, falling back to a global one, matching
     * the historical env lookup order.
     */
    public static function secretForProvider(string $provider): string
    {
        return (string) (getenv('webhook.secret.' . $provider) ?: getenv('WEBHOOK_SECRET') ?: '');
    }

    /**
     * True only when $signature is a valid HMAC-SHA256 of $rawBody under
     * $secret. Empty signature or empty secret => false (fail closed).
     */
    public function verify(string $rawBody, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /** Convenience: verify against the provider's configured secret. */
    public function verifyForProvider(string $provider, string $rawBody, string $signature): bool
    {
        return $this->verify($rawBody, $signature, self::secretForProvider($provider));
    }
}
