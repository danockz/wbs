<?php

declare(strict_types=1);

namespace WBS\Shared\RateLimiting;

/**
 * Outcome of a rate-limit check. Deliberately does NOT expose which key
 * dimension caused a block (SRS FR-RL-006) — only the policy action and
 * retry hint are surfaced to callers.
 */
final class RateLimitDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $action,
        public readonly int $remaining,
        public readonly int $retryAfter,
        public readonly string $policy,
        public readonly int $policyVersion,
    ) {
    }

    public static function allow(RatePolicy $p, int $remaining): self
    {
        return new self(true, RatePolicy::ACT_ALLOW, $remaining, 0, $p->name, $p->version);
    }

    public static function block(RatePolicy $p, int $retryAfter): self
    {
        return new self(false, $p->onExceed, 0, $retryAfter, $p->name, $p->version);
    }
}
