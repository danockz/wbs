<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Authenticated envelope encryption for secrets at rest (SRS §10.1, NFR-SEC).
 *
 * Used for provider credential slots, OAuth tokens, and any other secret that
 * must be stored but never displayed again (FR-INT-005). Uses libsodium's
 * XChaCha20-Poly1305 secretbox: a random 24-byte nonce per message, an AEAD
 * tag for tamper detection, and an "aad" context string bound into the tag so
 * a ciphertext cannot be replayed into a different record/field.
 *
 * The stored blob layout is: version(1) || nonce(24) || ciphertext(+16 MAC).
 * The master key is a 32-byte key from the secret store (dev: encryption.key).
 * A key id is recorded alongside every blob so keys can be rotated without
 * losing the ability to decrypt historical ciphertext.
 */
final class SecretBox
{
    private const VERSION = 1;

    /** Single-key mode: the raw 32-byte key. Null when provider-backed. */
    private readonly ?string $key;

    /** Multi-key mode: resolves data keys per keyId. Null in single-key mode. */
    private readonly ?KeyProvider $provider;

    /** keyId tagged onto new ciphertext. */
    private readonly string $keyId;

    /**
     * Two construction modes, both backward compatible:
     *
     *  - Legacy single-key: pass a raw 32-byte string (optionally a keyId). All
     *    historical call sites keep working unchanged; decrypt uses that one key.
     *  - Provider-backed: pass a {@see KeyProvider}. New ciphertext is tagged
     *    with the provider's active keyId, and decrypt resolves each blob's own
     *    keyId through the provider — enabling rotation/provider coexistence
     *    without re-encrypting historical rows.
     *
     * @param non-empty-string|KeyProvider $key 32-byte raw key, or a KeyProvider
     */
    public function __construct(
        #[SensitiveParameter] string|KeyProvider $key,
        string $keyId = 'k1',
    ) {
        if ($key instanceof KeyProvider) {
            $this->provider = $key;
            $this->key      = null;
            $this->keyId    = $key->activeKeyId();

            return;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('SecretBox requires a 32-byte key.');
        }

        $this->key      = $key;
        $this->provider = null;
        $this->keyId    = $keyId;
    }

    /**
     * Resolve the raw key to use for a given blob keyId. In single-key mode the
     * one key is always returned (legacy behavior: the blob keyId is not
     * enforced). In provider mode the key is resolved per keyId, and an unknown
     * keyId is a hard failure.
     *
     * @return non-empty-string
     */
    private function keyForBlob(string $keyId): string
    {
        if ($this->provider !== null) {
            $key = $this->provider->keyFor($keyId);
            if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('KeyProvider returned a non-32-byte key.');
            }

            return $key;
        }

        /** @var non-empty-string */
        return $this->key;
    }

    /**
     * Encrypt plaintext. The optional $aad (additional authenticated data) is
     * NOT stored but IS bound into the tag — pass a stable context such as
     * "connection:{id}:api_key" so ciphertext cannot be moved between fields.
     *
     * @return string base64 blob safe for a VARBINARY/TEXT column
     */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $aad = ''): string
    {
        $key    = $this->keyForBlob($this->keyId);
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($this->bindAad($plaintext, $aad), $nonce, $key);
        $blob   = chr(self::VERSION) . $nonce . $cipher;

        sodium_memzero($plaintext);

        return $this->keyId . ':' . base64_encode($blob);
    }

    /**
     * Decrypt a blob produced by encrypt(). Throws on any tampering, wrong key,
     * or mismatched $aad — callers should treat a throw as a hard security
     * failure, never a soft "empty secret".
     */
    public function decrypt(string $stored, string $aad = ''): string
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Malformed secret blob.');
        }

        $blobKeyId = $parts[0];
        $blob      = base64_decode($parts[1], true);
        if ($blob === false || strlen($blob) < 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Corrupt secret blob.');
        }

        $version = ord($blob[0]);
        if ($version !== self::VERSION) {
            throw new RuntimeException('Unsupported secret blob version.');
        }

        $nonce  = substr($blob, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key   = $this->keyForBlob($blobKeyId);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('Secret decryption failed (tampered or wrong key).');
        }

        return $this->unbindAad($plain, $aad);
    }

    /**
     * A non-reversible fingerprint of a secret, safe to log/compare. Lets an
     * admin confirm "the key I re-entered matches the stored one" without ever
     * revealing or storing the plaintext.
     */
    public function fingerprint(#[SensitiveParameter] string $plaintext): string
    {
        $key = $this->keyForBlob($this->keyId);

        return substr(sodium_bin2hex(sodium_crypto_generichash($plaintext, $key, 16)), 0, 16);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    private function bindAad(string $plaintext, string $aad): string
    {
        return pack('N', strlen($aad)) . $aad . $plaintext;
    }

    private function unbindAad(string $plain, string $aad): string
    {
        if (strlen($plain) < 4) {
            throw new RuntimeException('Corrupt secret payload.');
        }
        $len    = unpack('N', substr($plain, 0, 4))[1];
        $gotAad = substr($plain, 4, $len);
        if (! hash_equals($aad, $gotAad)) {
            throw new RuntimeException('Secret context mismatch (aad).');
        }

        return substr($plain, 4 + $len);
    }

    /**
     * Derive a 32-byte key from an env value that may be raw, hex, or the
     * "hex2bin:" prefixed form used in .env. Centralized so every module reads
     * the master key the same way.
     */
    public static function keyFromEnv(string $envValue): string
    {
        if (str_starts_with($envValue, 'hex2bin:')) {
            $hex = substr($envValue, 8);
            $raw = @hex2bin($hex);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $raw;
            }
        }
        if (strlen($envValue) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $envValue;
        }

        return sodium_crypto_generichash($envValue, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
