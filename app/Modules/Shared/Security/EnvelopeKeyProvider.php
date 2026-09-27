<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

use RuntimeException;

/**
 * Tier 1 {@see KeyProvider} using envelope encryption
 * (docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md, Options B/C/D).
 *
 * Instead of holding raw data keys, this provider holds *wrapped* DEKs — each
 * only decryptable by a managed KEK via a {@see KekUnwrapper}. On the decrypt
 * path it unwraps the DEK for a given keyId (once, then caches it briefly in
 * memory to avoid a manager round-trip per blob); on the encrypt path it tags
 * new ciphertext with {@see activeKeyId()}.
 *
 * Why this shape:
 *  - The master key (KEK) never enters the app — only the unwrapper touches it,
 *    and behind AWS/GCP/Azure/Vault it never leaves the HSM at all.
 *  - Rotation is free: register `k2 => wrappedDekV2`, flip the active id, and new
 *    writes tag `k2:` while historical `k1:` blobs still unwrap. No bulk
 *    re-encryption — the keyId lives in every SecretBox blob.
 *  - Switching cloud = swapping the injected {@see KekUnwrapper}. This class does
 *    not change.
 *
 * Multiple wrapped DEKs are supported so historical and active epochs coexist.
 */
final class EnvelopeKeyProvider implements KeyProvider
{
    /**
     * @var array<string,string> keyId => wrapped DEK (KEK-encrypted, provider blob)
     */
    private readonly array $wrappedByKeyId;

    /**
     * @var array<string,non-empty-string> in-memory unwrapped-DEK cache (keyId => raw key)
     */
    private array $cache = [];

    /**
     * @param KekUnwrapper          $unwrapper      unwraps DEKs via the managed KEK
     * @param array<string,string>  $wrappedByKeyId keyId => wrapped DEK; MUST be non-empty
     * @param string                $activeKeyId    keyId to tag new ciphertext with
     */
    public function __construct(
        private readonly KekUnwrapper $unwrapper,
        array $wrappedByKeyId,
        private readonly string $activeKeyId,
    ) {
        if ($wrappedByKeyId === []) {
            throw new RuntimeException('EnvelopeKeyProvider requires at least one wrapped DEK.');
        }
        if (! isset($wrappedByKeyId[$activeKeyId])) {
            throw new RuntimeException(
                sprintf('EnvelopeKeyProvider active keyId "%s" has no wrapped DEK.', $activeKeyId),
            );
        }

        $this->wrappedByKeyId = $wrappedByKeyId;
    }

    public function activeKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function keyFor(string $keyId): string
    {
        if (isset($this->cache[$keyId])) {
            return $this->cache[$keyId];
        }

        if (! isset($this->wrappedByKeyId[$keyId])) {
            // Unknown keyId is a hard failure — never a soft/empty key.
            throw new RuntimeException(
                sprintf('EnvelopeKeyProvider has no wrapped DEK for keyId "%s".', $keyId),
            );
        }

        $dek = $this->unwrapper->unwrap($this->wrappedByKeyId[$keyId]);
        if (strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('Unwrapped DEK is not 32 bytes.');
        }

        return $this->cache[$keyId] = $dek;
    }

    /**
     * The KEK/manager identity behind this provider, for audit/diagnostics.
     */
    public function kekId(): string
    {
        return $this->unwrapper->kekId();
    }
}
