<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use WBS\Identity\Security\LocalListBreachChecker;
use WBS\Identity\Security\NullBreachChecker;
use WBS\Identity\Security\PwnedPasswordsBreachChecker;

/**
 * Locks the breached-password checkers (SRS FR-ID-003).
 *
 * @internal
 */
final class BreachCheckerTest extends CIUnitTestCase
{
    // ---- LocalListBreachChecker (offline default) --------------------------

    public function testLocalCatchesCommonBaseWithAppendedDigits(): void
    {
        $c = new LocalListBreachChecker();
        $this->assertTrue($c->isBreached('Password1234'));
        $this->assertTrue($c->isBreached('Welcome123!'));
        $this->assertTrue($c->isBreached('Qwerty123456'));
    }

    public function testLocalCatchesLeetSubstitutions(): void
    {
        $c = new LocalListBreachChecker();
        $this->assertTrue($c->isBreached('P@ssw0rd'));
        $this->assertTrue($c->isBreached('L3tm31n99'));
    }

    public function testLocalCatchesRepeatedAndSequential(): void
    {
        $c = new LocalListBreachChecker();
        $this->assertTrue($c->isBreached('Aaaaaaaaaaaa1'));   // single repeated char
        $this->assertTrue($c->isBreached('Abcdef123456'));    // sequential runs
    }

    public function testLocalHonoursExtraBannedWords(): void
    {
        $c = new LocalListBreachChecker(['AcmeCorp']);
        $this->assertTrue($c->isBreached('AcmeCorp2024'));
        // A different org's word is not banned for this instance.
        $this->assertFalse($c->isBreached('Zenith7Falcon!'));
    }

    public function testLocalAllowsStrongPasswords(): void
    {
        $c = new LocalListBreachChecker();
        $this->assertFalse($c->isBreached('Xq7!vHm2LpZk'));
        $this->assertFalse($c->isBreached('Tr0ub4dour&3xplast'));
    }

    // ---- PwnedPasswordsBreachChecker (k-anonymity, mocked HTTP) ------------

    public function testPwnedMatchesSuffixInRangeResponse(): void
    {
        $pw     = 'Password1234';
        $suffix = substr(strtoupper(sha1($pw)), 5);
        $c      = new PwnedPasswordsBreachChecker(fn (string $url): string => $suffix . ":42\r\nDEADBEEF:0");
        $this->assertTrue($c->isBreached($pw));
    }

    public function testPwnedIgnoresPaddingRowsWithZeroCount(): void
    {
        $pw     = 'Password1234';
        $suffix = substr(strtoupper(sha1($pw)), 5);
        // Same suffix but count 0 (a padding row) must NOT count as breached.
        $c = new PwnedPasswordsBreachChecker(fn (string $url): string => $suffix . ':0');
        $this->assertFalse($c->isBreached($pw));
    }

    public function testPwnedOnlySendsHashPrefix(): void
    {
        $pw       = 'Password1234';
        $prefix   = substr(strtoupper(sha1($pw)), 0, 5);
        $captured = '';
        $c        = new PwnedPasswordsBreachChecker(function (string $url) use (&$captured): string {
            $captured = $url;

            return "DEADBEEF:1";
        });
        $c->isBreached($pw);
        // The full hash suffix must never appear in the outbound URL.
        $this->assertStringContainsString($prefix, $captured);
        $this->assertStringNotContainsString(substr(strtoupper(sha1($pw)), 5), $captured);
    }

    public function testPwnedFailsOpenToFallbackOnNetworkError(): void
    {
        // Fetch returns null (network failure) → consult the fallback.
        $c = new PwnedPasswordsBreachChecker(
            fn (string $url): ?string => null,
            new LocalListBreachChecker(),
        );
        $this->assertTrue($c->isBreached('Password1234'));   // caught by fallback
        $this->assertFalse($c->isBreached('Xq7!vHm2LpZk'));  // fallback clears it
    }

    // ---- NullBreachChecker -------------------------------------------------

    public function testNullNeverReportsBreached(): void
    {
        $c = new NullBreachChecker();
        $this->assertFalse($c->isBreached('Password1234'));
        $this->assertFalse($c->isBreached('P@ssw0rd'));
    }
}
