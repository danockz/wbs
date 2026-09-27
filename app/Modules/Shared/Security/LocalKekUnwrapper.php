<?php

declare(strict_types=1);

namespace WBS\Shared\Security;

use RuntimeException;
use SensitiveParameter;

/**
 * Offline {@see KekUnwrapper}: unwraps DEKs with a local libsodium KEK instead of
 * a cloud HSM. This is the reference implementation of the envelope path — it
 * lets {@see EnvelopeKeyProvider} be exercised end-to-end in dev/test/on-prem
 * (and in this sandbox, which has no network) while a managed AWS/GCP/Azure/Vault
 * unwrapper is a same-shaped drop-in.
 *
 * Wrapped-DEK format (matches what {@see wrap()} produces):
 *   base64( nonce(24) || crypto_secretbox(dek, nonce, kek) )
 *
 * The KEK itself is 32 raw bytes, normalized through {@see SecretBox::keyFromEnv}
 * so the same env conventions (`hex2bin:` prefix, raw-32, else hashed) apply.
 * In a real deployment the KEK never lives in the app — that is the whole point
 * of Options B/C/D; this class exists so the *mechanism* is real and tested.
 */
final class LocalKekUnwrapper implements KekUnwrapper
{
    /** @var non-empty-string */
    private readonly string $kek;

    /**
     * @param string $kekMaterial raw/hex/`hex2bin:`-prefixed 32-byte KEK
     * @param string $kekId       audit id for the KEK (default "local")
     */
    public function __construct(
        #[SensitiveParameter] string $kekMaterial,
        private readonly string $kekId = 'local',
    ) {
        $this->kek = SecretBox::keyFromEnv($kekMaterial);
    }

    /**
     * Wrap a raw 32-byte DEK under this KEK, producing the base64 blob that
     * {@see unwrap()} consumes. Used by provisioning/tests to mint wrapped DEKs;
     * a cloud KEK does this server-side via GenerateDataKey/encrypt.
     *
     * @param non-empty-string $dek 32 raw bytes
     */
    public function wrap(#[SensitiveParameter] string $dek): string
    {
        if (strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('DEK to wrap must be 32 bytes.');
        }

        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($dek, $nonce, $this->kek);

        return base64_encode($nonce . $cipher);
    }

    public function unwrap(#[SensitiveParameter] string $wrappedDek): string
    {
        $raw = base64_decode($wrappedDek, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Malformed wrapped DEK.');
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $dek = sodium_crypto_secretbox_open($cipher, $nonce, $this->kek);
        if ($dek === false || strlen($dek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Wrong KEK, tampered blob, or bad length — all hard failures.
            throw new RuntimeException('DEK unwrap failed (bad KEK or corrupt wrapped key).');
        }

        return $dek;
    }

    public function kekId(): string
    {
        return $this->kekId;
    }
}
