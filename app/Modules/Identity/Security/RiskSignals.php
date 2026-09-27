<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

/**
 * Immutable bundle of authentication-context signals fed to the RiskEngine
 * (SRS adaptive/dynamic MFA). Values are already privacy-minimized (IP/device
 * are passed as hashes) — the engine reasons over booleans and coarse facts.
 */
final class RiskSignals
{
    public function __construct(
        public readonly bool $newDevice = false,
        public readonly bool $newLocation = false,
        public readonly bool $impossibleTravel = false,
        public readonly int $recentFailedLogins = 0,
        public readonly bool $knownIpReputationBad = false,
        public readonly bool $sensitiveActionRequested = false,
        public readonly bool $credentialStuffingPattern = false,
    ) {
    }
}
