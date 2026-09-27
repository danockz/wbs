<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

/**
 * Adaptive MFA risk scoring (SRS: dynamic MFA). Produces a bounded 0..100 score
 * from weighted signals. The StepUpPolicy maps the score to a required
 * assurance level. Deterministic and side-effect free so it is easily tested.
 */
final class RiskEngine
{
    /** @var array<string,int> */
    private array $weights;

    /** @param array<string,int> $weightOverrides */
    public function __construct(array $weightOverrides = [])
    {
        $this->weights = $weightOverrides + [
            'newDevice'                 => 20,
            'newLocation'               => 15,
            'impossibleTravel'          => 45,
            'failedLoginUnit'           => 8,   // per recent failed login
            'knownIpReputationBad'      => 30,
            'sensitiveActionRequested'  => 20,
            'credentialStuffingPattern' => 40,
        ];
    }

    /** @return int 0..100 */
    public function score(RiskSignals $s): int
    {
        $score = 0;
        $score += $s->newDevice ? $this->weights['newDevice'] : 0;
        $score += $s->newLocation ? $this->weights['newLocation'] : 0;
        $score += $s->impossibleTravel ? $this->weights['impossibleTravel'] : 0;
        $score += min(3, max(0, $s->recentFailedLogins)) * $this->weights['failedLoginUnit'];
        $score += $s->knownIpReputationBad ? $this->weights['knownIpReputationBad'] : 0;
        $score += $s->sensitiveActionRequested ? $this->weights['sensitiveActionRequested'] : 0;
        $score += $s->credentialStuffingPattern ? $this->weights['credentialStuffingPattern'] : 0;

        return max(0, min(100, $score));
    }
}
