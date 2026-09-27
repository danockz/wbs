<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Default {@see KeyProvider}: a single data key injected via the environment
 * (Option A in docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md).
 *
 * Behavior-identical to the historical `new SecretBox(SecretBox::keyFromEnv(...))`
 * wiring: the same env value, the same `keyFromEnv` normalization, and the same
 * default keyId ("k1"). Introducing this class changes NO runtime behavior — it
 * only puts the provider seam physically in place so a managed-KMS/Vault provider
 * can be dropped in later without touching call sites.
 *
 * NOTE: this class deliberately preserves the current permissive `keyFromEnv`
 * behavior (weak/short values are hashed up to 32 bytes). Hardening that to a
 * fail-fast, 32-byte-only check in production is Tier 0 and is intentionally
 * NOT done here.
 */
final class EnvKeyProvider implements KeyProvider
{
    /** @var non-empty-string */
    private readonly string $key;

    /**
     * @param string $envValue raw/hex/`hex2bin:`-prefixed key material
     * @param string $keyId    id tagged onto new ciphertext (default "k1")
     */
    public function __construct(
        #[SensitiveParameter] string $envValue,
        private readonly string $keyId = 'k1',
    ) {
        $this->key = SecretBox::keyFromEnv($envValue);
    }

    /**
     * Build from the standard env keys, matching the legacy lookup order
     * (`encryption.key` then `ENCRYPTION_KEY`).
     */
    public static function fromEnv(string $keyId = 'k1'): self
    {
        $value = (string) (getenv('encryption.key') ?: getenv('ENCRYPTION_KEY') ?: '');

        return new self($value, $keyId);
    }

    public function activeKeyId(): string
    {
        return $this->keyId;
    }

    public function keyFor(string $keyId): string
    {
        if ($keyId !== $this->keyId) {
            throw new RuntimeException(
                sprintf('EnvKeyProvider has no key for id "%s" (active "%s").', $keyId, $this->keyId),
            );
        }

        return $this->key;
    }
}
