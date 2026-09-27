<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

/**
 * One credential a group may send on, and how it came to be usable.
 *
 * Deliberately carries NO secret material: the plaintext lives only inside the
 * vault callback that {@see NotificationCredentialResolver::withSecrets()} opens
 * (FR-INT-005 write-only slots). What it does carry is everything needed to
 * build a transport — the provider name the chain keys on, the non-secret
 * settings from the connection (base URL, path, country code, provider order),
 * the approved sender identity, and the provenance of the right to use it:
 *
 *  - `via = 'own'`     — the group supplied these credentials itself;
 *  - `via = 'granted'` — an ancestor (or the org) supplied them and granted this
 *                        group access, with `grantId` naming the grant and
 *                        `scopeMode` recording how wide that grant reaches.
 *
 * `specificity` is the distance from the sending group to the credential's owner
 * (0 = the group itself, 1 = parent, …); org-wide rows sort last. It exists so
 * "nearest body that provided or granted a credential wins" is a plain sort
 * rather than a rule buried in a query.
 */
final class ResolvedCredential
{
    /**
     * @param array<string,mixed> $settings non-secret connection config
     */
    public function __construct(
        public readonly string $connectionId,
        public readonly string $provider,
        public readonly string $adapterCode,
        public readonly string $channel,
        public readonly ?string $ownerGroupId,
        public readonly string $via,
        public readonly ?string $grantId,
        public readonly string $scopeMode,
        public readonly ?string $senderId,
        public readonly array $settings,
        public readonly int $specificity,
    ) {
    }

    /** A non-secret setting from the connection (`settings` JSON). */
    public function setting(string $key, mixed $default = null): mixed
    {
        $v = $this->settings[$key] ?? null;

        return $v === null || $v === '' ? $default : $v;
    }

    /** True when an ancestor/org grant (rather than the group itself) authorizes this. */
    public function isGranted(): bool
    {
        return $this->via === 'granted';
    }
}
