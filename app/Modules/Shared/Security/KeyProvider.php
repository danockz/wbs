<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

/**
 * Source of 32-byte data keys for {@see SecretBox}, decoupled from *who* guards
 * the key material (SRS §10.1, NFR-SEC).
 *
 * This is the single seam for Tier 1 key-management providers
 * (see docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md). The default implementation
 * ({@see EnvKeyProvider}) reads the key from the platform-injected env value and
 * is behavior-identical to the historical `SecretBox::keyFromEnv(...)` path.
 * A future AWS/GCP/Azure KMS or HashiCorp Vault provider is a drop-in: implement
 * this interface and rebind `WBS\Shared\Config\Services::keyProvider()`.
 *
 * Coexistence & rotation rely on the `keyId` recorded in every SecretBox blob
 * (`{keyId}:{base64(...)}`): new ciphertext is tagged with {@see activeKeyId()},
 * while historical rows are decrypted by resolving their own keyId via
 * {@see keyFor()}. No bulk re-encryption is needed to rotate or switch provider.
 */
interface KeyProvider
{
    /**
     * The keyId that newly-written ciphertext should be tagged with. For a
     * managed-KMS provider this identifies the active data-key epoch.
     */
    public function activeKeyId(): string;

    /**
     * Resolve the raw 32-byte data key for a given keyId, used on the decrypt
     * path. Implementations MUST throw if the keyId is unknown — a missing key
     * is a hard security failure, never a soft "empty key".
     *
     * @return non-empty-string 32 raw bytes
     */
    public function keyFor(string $keyId): string;
}
