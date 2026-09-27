<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

/**
 * Immutable outcome of a fraud assessment on a single click.
 *
 * Carries only a boolean flag, a numeric risk score, and a short operator-facing
 * reason string — never any raw PII (no IP, UA, or fingerprint components). The
 * reason is composed from signal names, safe to persist in
 * referral_clicks.suspicious_reason and to surface in review UIs.
 */
final class FraudVerdict
{
    /**
     * @param list<string> $signals Names of the heuristics that fired.
     */
    public function __construct(
        public readonly bool $suspicious,
        public readonly int $score,
        public readonly array $signals = [],
    ) {
    }

    /** Short, PII-free reason string for storage / display. */
    public function reason(): string
    {
        if ($this->signals === []) {
            return '';
        }

        return substr(implode(',', $this->signals), 0, 255);
    }
}
