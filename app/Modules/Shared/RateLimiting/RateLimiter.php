<?php

declare(strict_types=1);

namespace WBS\Shared\RateLimiting;

use Redis;
use Throwable;

/**
 * Reusable rate-limiting service (SRS FR-RL-001..006).
 *
 * Sliding-window and token-bucket algorithms implemented atomically in Redis
 * via Lua so counting is race-free under concurrency. Key dimensions are hashed
 * (never raw PII). When Redis is unavailable the policy's failOpen flag decides
 * the outcome — security/financial policies fail CLOSED.
 */
final class RateLimiter
{
    private const SLIDING_LUA = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local limit = tonumber(ARGV[3])
        redis.call('ZREMRANGEBYSCORE', key, 0, now - window)
        local count = redis.call('ZCARD', key)
        if count < limit then
            redis.call('ZADD', key, now, now .. '-' .. math.random())
            redis.call('EXPIRE', key, window)
            return {1, limit - count - 1}
        end
        return {0, 0}
    LUA;

    private const TOKEN_LUA = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local rate = tonumber(ARGV[2])
        local capacity = tonumber(ARGV[3])
        local tokens = tonumber(redis.call('HGET', key, 'tokens') or capacity)
        local ts = tonumber(redis.call('HGET', key, 'ts') or now)
        local elapsed = math.max(0, now - ts)
        tokens = math.min(capacity, tokens + elapsed * rate)
        local allowed = 0
        local remaining = math.floor(tokens)
        if tokens >= 1 then
            tokens = tokens - 1
            allowed = 1
            remaining = math.floor(tokens)
        end
        redis.call('HSET', key, 'tokens', tokens, 'ts', now)
        redis.call('EXPIRE', key, capacity / rate * 2)
        return {allowed, remaining}
    LUA;

    public function __construct(
        private readonly PolicyRegistry $registry,
        private readonly ?Redis $redis,
        private readonly string $keySalt = 'wbs',
    ) {
    }

    /**
     * Evaluate a policy against a set of key-dimension values.
     *
     * @param array<string,string> $dimensions e.g. ['route'=>'auth.login','ip'=>'1.2.3.4','user'=>'uuid']
     */
    public function check(string $policyName, array $dimensions): RateLimitDecision
    {
        $policy = $this->registry->get($policyName);
        if ($policy === null) {
            // Unknown policy: allow but with a sentinel version so it's visible.
            return new RateLimitDecision(true, RatePolicy::ACT_ALLOW, 0, 0, $policyName, 0);
        }

        if ($this->redis === null) {
            // Store unavailable. A fail-CLOSED policy (auth/payment) will now
            // block EVERY request — indistinguishable to the client from a real
            // limit breach (FR-RL-006). Log it server-side so operators can tell
            // "Redis is down" from "someone is hammering login"; this is the only
            // signal that separates the two identical 429s.
            if (! $policy->failOpen) {
                log_message(
                    'critical',
                    'RateLimiter: store UNAVAILABLE; policy "{policy}" failing CLOSED (blocking all traffic). Start Redis / install phpredis.',
                    ['policy' => $policy->name],
                );
            } else {
                log_message(
                    'warning',
                    'RateLimiter: store unavailable; policy "{policy}" failing open (allowing).',
                    ['policy' => $policy->name],
                );
            }

            return $policy->failOpen
                ? RateLimitDecision::allow($policy, $policy->limit)
                : RateLimitDecision::block($policy, $policy->periodSec);
        }

        $key = $this->buildKey($policy, $dimensions);

        try {
            if ($policy->algorithm === RatePolicy::ALGO_TOKEN_BUCKET) {
                [$allowed, $remaining] = $this->redis->eval(
                    self::TOKEN_LUA,
                    [$key, (string) microtime(true), (string) $policy->refillPerSecond(), (string) $policy->capacity()],
                    1,
                );
            } else {
                [$allowed, $remaining] = $this->redis->eval(
                    self::SLIDING_LUA,
                    [$key, (string) microtime(true), (string) $policy->periodSec, (string) $policy->limit],
                    1,
                );
            }
        } catch (Throwable $e) {
            // A live error talking to Redis mid-request: same fail-open/closed
            // rule, and log the cause (never surfaced to the client).
            log_message(
                $policy->failOpen ? 'warning' : 'critical',
                'RateLimiter: store ERROR on policy "{policy}" ({msg}); failing {mode}.',
                [
                    'policy' => $policy->name,
                    'msg'    => $e->getMessage(),
                    'mode'   => $policy->failOpen ? 'open' : 'closed',
                ],
            );

            return $policy->failOpen
                ? RateLimitDecision::allow($policy, $policy->limit)
                : RateLimitDecision::block($policy, $policy->periodSec);
        }

        return ((int) $allowed) === 1
            ? RateLimitDecision::allow($policy, (int) $remaining)
            : RateLimitDecision::block($policy, $policy->periodSec);
    }

    /**
     * Build a hashed cache key from the policy's declared dimensions. Raw PII
     * never appears in the key (FR-RL-002).
     *
     * @param array<string,string> $dimensions
     */
    private function buildKey(RatePolicy $policy, array $dimensions): string
    {
        $parts = [$policy->name, (string) $policy->version];
        foreach ($policy->keyBy as $dim) {
            $val    = $dimensions[$dim] ?? '';
            $parts[] = $dim . ':' . hash_hmac('sha256', $val, $this->keySalt);
        }

        return 'rl:' . implode('|', $parts);
    }
}
