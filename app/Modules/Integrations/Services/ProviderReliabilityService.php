<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Integrations\Providers\ProviderException;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Provider circuit-breaking + quota accounting (SRS FR-INT-012).
 *
 * Wraps any provider call so that:
 *   - a run of consecutive failures OPENS the circuit for a cooldown, short-
 *     circuiting further calls (fail fast → the caller engages the documented
 *     fallback instead of hammering a broken provider);
 *   - after the cooldown the circuit goes HALF_OPEN and admits a single probe;
 *     a probe success CLOSES it, a probe failure re-opens it;
 *   - each attempt is metered against a rolling per-window quota, so an
 *     adapter's approved API budget is respected.
 *
 * State is per (organization, scope_key) where scope_key is the provider/adapter
 * code. No secrets ever touch this layer — only the provider's own error text.
 */
final class ProviderReliabilityService
{
    /** Consecutive failures that trip the breaker. */
    private const FAILURE_THRESHOLD = 5;

    /** Cooldown before a half-open probe is allowed. */
    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Execute a provider call through the breaker + quota gate.
     *
     * @template T
     * @param callable():T $call the actual provider invocation
     * @param array{quota_limit?:int, quota_window?:string} $opts
     *
     * @return Result Result::ok(['result' => T]) on success; a fail Result with
     *   a `fallback` hint on short-circuit / quota / provider error. The caller
     *   consults FallbackMatrix for the concrete degraded behaviour.
     */
    public function call(string $organizationId, string $scopeKey, callable $call, array $opts = []): Result
    {
        $gate = $this->admit($organizationId, $scopeKey, $opts);
        if (! $gate->ok) {
            return $gate;
        }

        try {
            $value = $call();
            $this->recordSuccess($organizationId, $scopeKey);

            return Result::ok(['result' => $value]);
        } catch (ProviderException $e) {
            $this->recordFailure($organizationId, $scopeKey, $e->getMessage());

            return Result::fail('PROVIDER_ERROR', $e->getMessage(), 502, [], [
                'scope'         => $scopeKey,
                'provider'      => $e->provider !== '' ? $e->provider : $scopeKey,
                'http_status'   => $e->httpStatus,
                'circuit_state' => $this->stateOf($organizationId, $scopeKey),
            ]);
        } catch (Throwable $e) {
            $this->recordFailure($organizationId, $scopeKey, $e->getMessage());

            return Result::fail('PROVIDER_ERROR', $e->getMessage(), 502, [], [
                'scope'         => $scopeKey,
                'circuit_state' => $this->stateOf($organizationId, $scopeKey),
            ]);
        }
    }

    /**
     * Decide whether a call may proceed NOW (breaker closed/half-open probe and
     * quota available). Increments quota usage when it admits the call.
     *
     * @param array{quota_limit?:int, quota_window?:string} $opts
     */
    public function admit(string $organizationId, string $scopeKey, array $opts = []): Result
    {
        $row  = $this->loadOrInit($organizationId, $scopeKey);
        $now  = $this->clock->now();

        // Circuit gate.
        if ($row['state'] === 'open') {
            $nextProbe = $row['next_probe_at'] !== null ? strtotime((string) $row['next_probe_at']) : 0;
            if ($now->getTimestamp() < $nextProbe) {
                return Result::fail('CIRCUIT_OPEN', 'integration.circuit_open', 503, [], [
                    'scope'         => $scopeKey,
                    'circuit_state' => 'open',
                    'retry_at'      => $row['next_probe_at'],
                    'reason'        => 'short_circuit',
                ]);
            }
            // Cooldown elapsed → allow a single half-open probe.
            $this->transition($organizationId, $scopeKey, 'half_open');
        }

        // Quota gate (only when a limit is supplied).
        $limit  = isset($opts['quota_limit']) ? (int) $opts['quota_limit'] : 0;
        $window = in_array($opts['quota_window'] ?? '', ['minute', 'hour', 'day'], true)
            ? (string) $opts['quota_window'] : 'minute';
        if ($limit > 0) {
            $used = $this->currentUsage($organizationId, $scopeKey, $window);
            if ($used >= $limit) {
                return Result::fail('QUOTA_EXCEEDED', 'integration.quota_exceeded', 429, [], [
                    'scope'      => $scopeKey,
                    'window'     => $window,
                    'used'       => $used,
                    'limit'      => $limit,
                    'reason'     => 'quota',
                ]);
            }
            $this->incrementUsage($organizationId, $scopeKey, $window, $limit);
        }

        return Result::ok(['scope' => $scopeKey, 'circuit_state' => $this->stateOf($organizationId, $scopeKey)]);
    }

    /** Record a successful call: close the circuit, reset the failure run. */
    public function recordSuccess(string $organizationId, string $scopeKey): void
    {
        $now = $this->clock->nowUtcMicro();
        $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->set('success_count', 'success_count + 1', false)
            ->update([
                'state'                => 'closed',
                'consecutive_failures' => 0,
                'last_outcome'         => 'success',
                'last_error'           => null,
                'opened_at'            => null,
                'next_probe_at'        => null,
                'last_transition_at'   => $now,
                'updated_at'           => $now,
            ]);
    }

    /** Record a failed call: bump the run and open the breaker at threshold. */
    public function recordFailure(string $organizationId, string $scopeKey, string $error): void
    {
        $row  = $this->loadOrInit($organizationId, $scopeKey);
        $runs = (int) $row['consecutive_failures'] + 1;
        $now  = $this->clock->nowUtcMicro();

        $update = [
            'last_outcome' => 'failure',
            'last_error'   => mb_substr($error, 0, 255),
            'updated_at'   => $now,
        ];

        // In half_open a single failure re-opens immediately; otherwise open at
        // the consecutive-failure threshold.
        if ($row['state'] === 'half_open' || $runs >= self::FAILURE_THRESHOLD) {
            $update['state']              = 'open';
            $update['consecutive_failures'] = $runs;
            $update['opened_at']          = $now;
            $update['next_probe_at']      = $this->clock->now()
                ->modify('+' . self::COOLDOWN_SECONDS . ' seconds')->format('Y-m-d H:i:s.u');
            $update['last_transition_at'] = $now;
        } else {
            $update['consecutive_failures'] = $runs;
        }

        $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->set('failure_count', 'failure_count + 1', false)
            ->update($update);
    }

    /** Operator override: force the circuit closed (e.g. after a fix). */
    public function reset(string $organizationId, string $scopeKey): Result
    {
        $this->loadOrInit($organizationId, $scopeKey);
        $now = $this->clock->nowUtcMicro();
        $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->update([
                'state'                => 'closed',
                'consecutive_failures' => 0,
                'last_outcome'         => null,
                'opened_at'            => null,
                'next_probe_at'        => null,
                'last_transition_at'   => $now,
                'updated_at'           => $now,
            ]);

        return Result::ok(['scope' => $scopeKey, 'circuit_state' => 'closed']);
    }

    /** Health snapshot of all breakers for an org (console/monitoring). */
    public function health(string $organizationId): Result
    {
        $rows = $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)
            ->orderBy('scope_key')->get()->getResultArray();

        return Result::ok([
            'circuits' => array_map(static fn ($r) => [
                'scope'                => $r['scope_key'],
                'state'                => $r['state'],
                'consecutive_failures' => (int) $r['consecutive_failures'],
                'failure_count'        => (int) $r['failure_count'],
                'success_count'        => (int) $r['success_count'],
                'last_outcome'         => $r['last_outcome'],
                'last_error'           => $r['last_error'],
                'opened_at'            => $r['opened_at'],
                'next_probe_at'        => $r['next_probe_at'],
            ], $rows),
            'count' => count($rows),
        ]);
    }

    // --------------------------------------------------------------- internals

    private function stateOf(string $organizationId, string $scopeKey): string
    {
        $row = $this->db->table('provider_circuit_state')
            ->select('state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->get()->getRowArray();

        return (string) ($row['state'] ?? 'closed');
    }

    /** @return array<string,mixed> */
    private function loadOrInit(string $organizationId, string $scopeKey): array
    {
        $row = $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->get()->getRowArray();
        if ($row !== null) {
            return $row;
        }

        $now = $this->clock->nowUtcMicro();
        $seed = [
            'id'                   => Uuid::v7(),
            'organization_id'      => $organizationId,
            'scope_key'            => $scopeKey,
            'state'                => 'closed',
            'consecutive_failures' => 0,
            'failure_count'        => 0,
            'success_count'        => 0,
            'created_at'           => $now,
        ];
        try {
            $this->db->table('provider_circuit_state')->insert($seed);
        } catch (Throwable) {
            // Concurrent insert — re-read the winning row.
            $row = $this->db->table('provider_circuit_state')
                ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
                ->get()->getRowArray();
            if ($row !== null) {
                return $row;
            }
        }

        return $seed;
    }

    private function transition(string $organizationId, string $scopeKey, string $state): void
    {
        $now = $this->clock->nowUtcMicro();
        $this->db->table('provider_circuit_state')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->update(['state' => $state, 'last_transition_at' => $now, 'updated_at' => $now]);
    }

    private function windowStart(string $window): string
    {
        $now = $this->clock->now();

        return match ($window) {
            'hour' => $now->format('Y-m-d H:00:00'),
            'day'  => $now->format('Y-m-d 00:00:00'),
            default => $now->format('Y-m-d H:i:00'),
        };
    }

    private function currentUsage(string $organizationId, string $scopeKey, string $window): int
    {
        $row = $this->db->table('provider_quota_usage')
            ->select('used')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->where('window_unit', $window)->where('window_start', $this->windowStart($window))
            ->get()->getRowArray();

        return (int) ($row['used'] ?? 0);
    }

    private function incrementUsage(string $organizationId, string $scopeKey, string $window, int $limit): void
    {
        $start = $this->windowStart($window);
        $now   = $this->clock->nowUtcMicro();
        $existing = $this->db->table('provider_quota_usage')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->where('window_unit', $window)->where('window_start', $start)
            ->get()->getRowArray();

        if ($existing === null) {
            try {
                $this->db->table('provider_quota_usage')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'scope_key'       => $scopeKey,
                    'window_unit'     => $window,
                    'window_start'    => $start,
                    'used'            => 1,
                    'limit_hint'      => $limit,
                    'created_at'      => $now,
                ]);

                return;
            } catch (Throwable) {
                // Fall through to atomic increment on the winning row.
            }
        }

        $this->db->table('provider_quota_usage')
            ->where('organization_id', $organizationId)->where('scope_key', $scopeKey)
            ->where('window_unit', $window)->where('window_start', $start)
            ->set('used', 'used + 1', false)
            ->set('limit_hint', $limit)
            ->set('updated_at', $now)
            ->update();
    }
}
