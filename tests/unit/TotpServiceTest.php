<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Identity\Security\TotpService;

/**
 * Locks the RFC 6238 TOTP implementation (SRS: TOTP preferred MFA factor).
 *
 * Correctness of the second authentication factor is security-critical: a
 * miscomputed code either locks users out or (worse) accepts wrong codes, and a
 * too-wide verification window enlarges the guess surface. These tests pin the
 * computation against the published RFC 6238 Appendix B test vectors and pin the
 * verification window behaviour.
 *
 * The RFC vectors use the ASCII seed "12345678901234567890"; codeForCounter()
 * takes a base32 secret, so we base32-encode that seed first. Pure crypto — no
 * DB, no PHP extension beyond hash/random.
 *
 * @internal
 */
final class TotpServiceTest extends CIUnitTestCase
{
    /** RFC 6238 SHA-1 seed "12345678901234567890" base32-encoded (8-digit codes in the RFC). */
    private const RFC_SECRET_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function service(): TotpService
    {
        // RFC 6238 Appendix B test vectors are for 8 digits, SHA-1, 30s period.
        return new TotpService('sha1', 8, 30);
    }

    /**
     * @return list<array{0:int,1:string}> [unix time, expected 8-digit code]
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
        ];
    }

    /**
     * @dataProvider rfcVectors
     */
    public function testMatchesRfc6238Vectors(int $time, string $expected): void
    {
        $counter = intdiv($time, 30);
        $this->assertSame($expected, $this->service()->codeForCounter(self::RFC_SECRET_B32, $counter));
    }

    public function testVerifyAcceptsTheCurrentCodeAtAFixedTime(): void
    {
        $svc  = $this->service();
        $at   = 1111111111;
        $code = $svc->codeForCounter(self::RFC_SECRET_B32, intdiv($at, 30));

        $this->assertTrue($svc->verify(self::RFC_SECRET_B32, $code, 1, $at));
    }

    public function testVerifyToleratesOneStepOfSkewButNotTwo(): void
    {
        $svc  = $this->service();
        $at   = 1111111111;
        $prev = $svc->codeForCounter(self::RFC_SECRET_B32, intdiv($at, 30) - 1);
        $far  = $svc->codeForCounter(self::RFC_SECRET_B32, intdiv($at, 30) - 2);

        $this->assertTrue($svc->verify(self::RFC_SECRET_B32, $prev, 1, $at), 'one step back within window');
        $this->assertFalse($svc->verify(self::RFC_SECRET_B32, $far, 1, $at), 'two steps back outside window');
    }

    public function testWrongCodeAndWrongLengthAreRejected(): void
    {
        $svc = $this->service();
        $at  = 1111111111;

        $this->assertFalse($svc->verify(self::RFC_SECRET_B32, '00000000', 1, $at));
        $this->assertFalse($svc->verify(self::RFC_SECRET_B32, '123', 1, $at));   // wrong length
        $this->assertFalse($svc->verify(self::RFC_SECRET_B32, '', 1, $at));
    }

    public function testGenerateSecretIsValidBase32AndRoundTripsThroughVerify(): void
    {
        $svc    = new TotpService('sha1', 6, 30);
        $secret = $svc->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $at   = 1700000000;
        $code = $svc->codeForCounter($secret, intdiv($at, 30));
        $this->assertSame(6, strlen($code));
        $this->assertTrue($svc->verify($secret, $code, 1, $at));
    }

    public function testProvisioningUriCarriesTheSecretAndIssuer(): void
    {
        $uri = (new TotpService('sha1', 6, 30))->provisioningUri('ABCD', 'user@example.org', 'WBS');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABCD', $uri);
        $this->assertStringContainsString('issuer=WBS', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
