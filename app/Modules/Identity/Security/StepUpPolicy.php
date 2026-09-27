<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

/**
 * Maps a risk score to the MFA assurance the session must satisfy (SRS
 * adaptive/dynamic MFA). Higher risk demands a stronger factor; passkeys/TOTP
 * are "high" assurance, SMS/email are "low" and only acceptable at lower risk.
 */
final class StepUpPolicy
{
    public const REQUIRE_NONE = 'none';      // password alone acceptable
    public const REQUIRE_LOW  = 'low';       // any second factor (incl. SMS/email)
    public const REQUIRE_HIGH = 'high';      // passkey/TOTP required

    public function __construct(
        private readonly int $lowThreshold = 25,
        private readonly int $highThreshold = 55,
    ) {
    }

    public function requiredAssurance(int $riskScore): string
    {
        if ($riskScore >= $this->highThreshold) {
            return self::REQUIRE_HIGH;
        }
        if ($riskScore >= $this->lowThreshold) {
            return self::REQUIRE_LOW;
        }

        return self::REQUIRE_NONE;
    }

    /**
     * Whether a session that currently holds $currentAssurance ("none"|"low"|
     * "high") satisfies the requirement for the given risk score.
     */
    public function isSatisfied(string $currentAssurance, int $riskScore): bool
    {
        $rank     = ['none' => 0, 'low' => 1, 'high' => 2];
        $required = $this->requiredAssurance($riskScore);

        return ($rank[$currentAssurance] ?? 0) >= ($rank[$required] ?? 0);
    }
}
