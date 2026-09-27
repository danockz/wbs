<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use WBS\Shared\Security\EnvKeyProvider;
use WBS\Shared\Security\KeyProvider;
use WBS\Shared\Security\SecretBox;

/**
 * Locks in the KeyProvider seam guarantees (docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md):
 *  - legacy raw-key construction is unchanged (backward compatibility),
 *  - a provider-backed box round-trips,
 *  - the EnvKeyProvider default is byte-for-byte compatible with the old
 *    `SecretBox::keyFromEnv(...)` wiring, and
 *  - blobs are decryptable across keyIds via a multi-key provider (rotation /
 *    provider migration) with no re-encryption.
 *
 * @internal
 */
final class SecretBoxKeyProviderTest extends CIUnitTestCase
{
    private const KEY_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // 32 bytes
    private const KEY_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB'; // 32 bytes

    public function testLegacyRawKeyStillRoundTrips(): void
    {
        $box    = new SecretBox(self::KEY_A);
        $cipher = $box->encrypt('hunter2', 'ctx:1');

        $this->assertStringStartsWith('k1:', $cipher);
        $this->assertSame('hunter2', $box->decrypt($cipher, 'ctx:1'));
    }

    public function testProviderBackedBoxRoundTrips(): void
    {
        $box    = new SecretBox(new EnvKeyProvider(self::KEY_A));
        $cipher = $box->encrypt('s3cr3t', 'ctx:2');

        $this->assertSame('s3cr3t', $box->decrypt($cipher, 'ctx:2'));
    }

    public function testEnvProviderMatchesLegacyKeyFromEnvBytes(): void
    {
        // Same env value → same underlying key → ciphertext from one decrypts on the other.
        $legacy   = new SecretBox(SecretBox::keyFromEnv(self::KEY_A));
        $provided = new SecretBox(new EnvKeyProvider(self::KEY_A));

        $cipher = $provided->encrypt('interop', 'ctx:3');
        $this->assertSame('interop', $legacy->decrypt($cipher, 'ctx:3'));
    }

    public function testCrossKeyCoexistenceForRotation(): void
    {
        // A multi-key provider: active key is B, but historical k1 blobs (key A)
        // must still decrypt — this is the no-re-encryption rotation guarantee.
        $provider = new class implements KeyProvider {
            public function activeKeyId(): string
            {
                return 'k2';
            }

            public function keyFor(string $keyId): string
            {
                return match ($keyId) {
                    'k1'    => SecretBoxKeyProviderTest::keyA(),
                    'k2'    => SecretBoxKeyProviderTest::keyB(),
                    default => throw new RuntimeException('unknown keyId ' . $keyId),
                };
            }
        };

        // Historical blob written under the old single key A, tagged k1.
        $legacyBlob = (new SecretBox(self::KEY_A, 'k1'))->encrypt('old-secret', 'ctx:4');

        $box = new SecretBox($provider);

        // New writes are tagged with the active keyId k2...
        $newBlob = $box->encrypt('new-secret', 'ctx:5');
        $this->assertStringStartsWith('k2:', $newBlob);
        $this->assertSame('new-secret', $box->decrypt($newBlob, 'ctx:5'));

        // ...and the old k1 blob still decrypts through the same box.
        $this->assertSame('old-secret', $box->decrypt($legacyBlob, 'ctx:4'));
    }

    public function testUnknownKeyIdIsHardFailure(): void
    {
        $box  = new SecretBox(new EnvKeyProvider(self::KEY_A, 'k1'));
        $blob = (new SecretBox(self::KEY_B, 'k9'))->encrypt('x', 'ctx:6');

        $this->expectException(RuntimeException::class);
        $box->decrypt($blob, 'ctx:6'); // EnvKeyProvider has no key for 'k9'
    }

    public static function keyA(): string
    {
        return self::KEY_A;
    }

    public static function keyB(): string
    {
        return self::KEY_B;
    }
}
