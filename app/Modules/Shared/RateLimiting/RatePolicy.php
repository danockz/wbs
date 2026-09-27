<?php

declare(strict_types=1);

namespace WBS\Shared\RateLimiting;

/**
 * Versioned rate-limit policy (SRS FR-RL-002).
 *
 * A policy defines scope, algorithm, period/burst, key dimensions, threshold,
 * response and escalation. It never uses raw personal data as a cache key
 * (keys are hashed) and never exposes which dimension caused a block
 * (FR-RL-002 / FR-RL-006).
 */
final class RatePolicy
{
    public const ALGO_TOKEN_BUCKET   = 'token_bucket';
    public const ALGO_SLIDING_WINDOW = 'sliding_window';

    public const ACT_ALLOW           = 'allow';
    public const ACT_SOFT_DELAY      = 'soft_delay';
    public const ACT_REQUIRE_STEP_UP = 'require_step_up';
    public const ACT_CHALLENGE       = 'challenge';
    public const ACT_DENY            = 'deny';
    public const ACT_QUEUE           = 'queue';

    /**
     * @param string       $name      Canonical policy name, e.g. "auth.login".
     * @param int          $version   Policy version, recorded on every decision.
     * @param string       $algorithm One of the ALGO_* constants.
     * @param int          $limit     Max requests per period (sliding) / bucket capacity (token).
     * @param int          $periodSec Window length in seconds.
     * @param int          $burst     Extra burst tokens for token-bucket (>=0).
     * @param list<string> $keyBy     Ordered key dimensions, e.g. ["route","ip","user"].
     * @param string       $onExceed  Action when exceeded (ACT_* constant).
     * @param bool         $failOpen  Allow when the store is unavailable. MUST be
     *                                false for security/financial actions (FR-RL-005).
     */
    public function __construct(
        public readonly string $name,
        public readonly int $version,
        public readonly string $algorithm,
        public readonly int $limit,
        public readonly int $periodSec,
        public readonly int $burst = 0,
        public readonly array $keyBy = ['route', 'ip'],
        public readonly string $onExceed = self::ACT_DENY,
        public readonly bool $failOpen = false,
    ) {
    }

    public function refillPerSecond(): float
    {
        return $this->periodSec > 0 ? $this->limit / $this->periodSec : (float) $this->limit;
    }

    public function capacity(): int
    {
        return $this->limit + $this->burst;
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            name: (string) $a['name'],
            version: (int) ($a['version'] ?? 1),
            algorithm: (string) ($a['algorithm'] ?? self::ALGO_SLIDING_WINDOW),
            limit: (int) $a['limit'],
            periodSec: (int) ($a['periodSec'] ?? 60),
            burst: (int) ($a['burst'] ?? 0),
            keyBy: array_values((array) ($a['keyBy'] ?? ['route', 'ip'])),
            onExceed: (string) ($a['onExceed'] ?? self::ACT_DENY),
            failOpen: (bool) ($a['failOpen'] ?? false),
        );
    }
}
