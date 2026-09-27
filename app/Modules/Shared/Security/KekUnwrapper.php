<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

/**
 * The drop-in seam for a Tier 1 managed key manager
 * (docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md, Options B/C/D).
 *
 * Envelope encryption splits custody in two:
 *  - a **KEK** (key-encrypting key / CMK) that never leaves the manager's HSM, and
 *  - one or more **DEK**s (data-encryption keys) that {@see SecretBox} actually
 *    uses. Each DEK is stored only in *wrapped* (KEK-encrypted) form; unwrapping
 *    it requires a call to the manager.
 *
 * A KekUnwrapper is exactly that "unwrap this wrapped DEK" call, abstracted away
 * from *which* manager performs it:
 *  - AWS KMS      → `Decrypt` on the wrapped blob (CMK never exported).
 *  - GCP KMS      → `decrypt`; Azure Key Vault → `unwrapKey`.
 *  - Vault Transit→ `transit/decrypt/<key>`.
 *  - {@see LocalKekUnwrapper} → offline libsodium unwrap (dev/test/on-prem).
 *
 * Adding a cloud provider is implementing this one method and selecting it in
 * {@see \WBS\Shared\Config\Services::keyProvider()} — no call-site changes, and
 * no bulk re-encryption, because {@see EnvelopeKeyProvider} tags every blob with
 * its keyId.
 */
interface KekUnwrapper
{
    /**
     * Unwrap a KEK-encrypted DEK back to its raw 32 bytes.
     *
     * Implementations MUST throw on any failure (unknown key, denied IAM policy,
     * tampered ciphertext, wrong length) — a soft/empty return is a hard
     * security failure, never tolerated.
     *
     * @param string $wrappedDek provider-specific wrapped key material
     *                           (e.g. a KMS ciphertext blob), base64 or raw
     * @return non-empty-string 32 raw bytes
     */
    public function unwrap(#[\SensitiveParameter] string $wrappedDek): string;

    /**
     * Stable identifier of the KEK/manager backing this unwrapper, for audit and
     * diagnostics (e.g. "aws-kms:arn:...:key/abcd", "vault:transit/wbs", "local").
     * MUST NOT leak key material.
     */
    public function kekId(): string;
}
