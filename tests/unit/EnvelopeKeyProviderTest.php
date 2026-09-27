<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use WBS\Shared\Security\EnvelopeKeyProvider;
use WBS\Shared\Security\LocalKekUnwrapper;
use WBS\Shared\Security\SecretBox;

/**
 * Locks in the Tier 1 envelope-encryption seam
 * (docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md, Options B/C/D):
 *  - a SecretBox backed by an EnvelopeKeyProvider round-trips,
 *  - DEKs are only ever held wrapped and are unwrapped through a KekUnwrapper,
 *  - multiple key epochs coexist so rotation needs no re-encryption, and
 *  - unknown keyIds / wrong KEKs are hard failures, never soft empty keys.
 *
 * The LocalKekUnwrapper stands in for AWS/GCP/Azure/Vault so the envelope
 * mechanism is exercised offline; a cloud unwrapper is a same-shaped drop-in.
 *
 * @internal
 */
final class EnvelopeKeyProviderTest extends CIUnitTestCase
{
    private const KEK   = 'KEKKEKKEKKEKKEKKEKKEKKEKKEKKEKKEK'; // 32 bytes
    private const DEK_1 = 'DEK1DEK1DEK1DEK1DEK1DEK1DEK1DEK1';   // 32 bytes
    private const DEK_2 = 'DEK2DEK2DEK2DEK2DEK2DEK2DEK2DEK2';   // 32 bytes

    public function testEnvelopeBackedBoxRoundTrips(): void
    {
        $kek     = new LocalKekUnwrapper(self::KEK);
        $wrapped = ['k1' => $kek->wrap(self::DEK_1)];

        $box    = new SecretBox(new EnvelopeKeyProvider($kek, $wrapped, 'k1'));
        $cipher = $box->encrypt('envelope-secret', 'ctx:1');

        $this->assertStringStartsWith('k1:', $cipher);
        $this->assertSame('envelope-secret', $box->decrypt($cipher, 'ctx:1'));
    }

    public function testRotationAcrossEpochsWithoutReencryption(): void
    {
        $kek = new LocalKekUnwrapper(self::KEK);

        // Historical blob written when k1 was active.
        $v1   = new SecretBox(new EnvelopeKeyProvider($kek, ['k1' => $kek->wrap(self::DEK_1)], 'k1'));
        $old  = $v1->encrypt('legacy', 'ctx:2');

        // A new epoch k2 is added; both DEKs are registered, k2 is active.
        $rotated = new SecretBox(new EnvelopeKeyProvider($kek, [
            'k1' => $kek->wrap(self::DEK_1),
            'k2' => $kek->wrap(self::DEK_2),
        ], 'k2'));

        $new = $rotated->encrypt('current', 'ctx:3');
        $this->assertStringStartsWith('k2:', $new);

        // New writes use k2; historical k1 blobs still decrypt — no re-encryption.
        $this->assertSame('current', $rotated->decrypt($new, 'ctx:3'));
        $this->assertSame('legacy', $rotated->decrypt($old, 'ctx:2'));
    }

    public function testUnknownKeyIdIsHardFailure(): void
    {
        $kek      = new LocalKekUnwrapper(self::KEK);
        $provider = new EnvelopeKeyProvider($kek, ['k1' => $kek->wrap(self::DEK_1)], 'k1');

        $this->expectException(RuntimeException::class);
        $provider->keyFor('k9');
    }

    public function testWrongKekFailsToUnwrap(): void
    {
        $realKek  = new LocalKekUnwrapper(self::KEK);
        $wrong    = new LocalKekUnwrapper('WRONGWRONGWRONGWRONGWRONGWRONGWR'); // 32 bytes
        $provider = new EnvelopeKeyProvider($wrong, ['k1' => $realKek->wrap(self::DEK_1)], 'k1');

        $this->expectException(RuntimeException::class);
        $provider->keyFor('k1');
    }

    public function testActiveKeyIdMustHaveAWrappedDek(): void
    {
        $kek = new LocalKekUnwrapper(self::KEK);

        $this->expectException(RuntimeException::class);
        new EnvelopeKeyProvider($kek, ['k1' => $kek->wrap(self::DEK_1)], 'k2');
    }

    public function testKekIdIsSurfacedForAudit(): void
    {
        $kek      = new LocalKekUnwrapper(self::KEK, 'local-test');
        $provider = new EnvelopeKeyProvider($kek, ['k1' => $kek->wrap(self::DEK_1)], 'k1');

        $this->assertSame('local-test', $provider->kekId());
    }
}
