<?php

declare(strict_types=1);

namespace WBS\Shared\RateLimiting;

use WBS\Shared\Config\RateLimitPolicies;

/**
 * Indexed lookup over the configured rate policies. Built once from the
 * RateLimitPolicies config and shared. Unknown policy names resolve to null so
 * callers can fail safe.
 */
final class PolicyRegistry
{
    /** @var array<string,RatePolicy> */
    private array $byName = [];

    public function __construct(RateLimitPolicies $config)
    {
        foreach ($config->all() as $policy) {
            $this->byName[$policy->name] = $policy;
        }
    }

    public function get(string $name): ?RatePolicy
    {
        return $this->byName[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->byName);
    }
}
