<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

use Throwable;
use WBS\Shared\Support\Clock;

/**
 * Drives registered sweeps on a common cadence (Theme C — unified sweep runner).
 *
 * This is the single scheduler the reviews called for: instead of ~18 bespoke
 * cron entries with inconsistent retry/locking/observability, every module's
 * idempotent sweep runs through here with:
 *
 *   - a per-sweep advisory LOCK so two workers never double-process a batch
 *     (a contended sweep is SKIPPED, not failed),
 *   - per-sweep error ISOLATION — an unexpected throw becomes a failed
 *     SweepResult and the batch continues (one broken sweep can't starve the
 *     rest),
 *   - a structured HEARTBEAT line per sweep (key, org scope, swept count,
 *     details, elapsed ms) via an injected logger, so a stuck/silent runner is
 *     itself observable.
 *
 * The runner is pure/DB-free itself (it only orchestrates), so it is unit-testable
 * with in-memory fakes; the CLI command wires the real registry + MySQL lock.
 */
final class SweepRunner
{
    /** @var callable(string):void */
    private $logger;

    /**
     * @param callable(string):void|null $logger sink for heartbeat lines
     *                                           (default: error_log)
     */
    public function __construct(
        private readonly SweepRegistry $registry,
        private readonly SweepLock $lock,
        private readonly Clock $clock,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * Run one sweep by key.
     *
     * @param array<string,mixed> $options
     */
    public function runOne(string $key, ?string $organizationId = null, array $options = []): SweepResult
    {
        $sweep = $this->registry->get($key);
        if ($sweep === null) {
            $res = SweepResult::fail("unknown sweep: {$key}");
            $this->heartbeat($key, $organizationId, $res, 0.0);

            return $res;
        }

        $lockName = $key . ($organizationId !== null ? ':' . $organizationId : '');
        if (! $this->lock->acquire($lockName)) {
            $res = SweepResult::skip('lock held by another worker');
            $this->heartbeat($key, $organizationId, $res, 0.0);

            return $res;
        }

        $start = microtime(true);
        try {
            $res = $sweep->run($organizationId, $options);
        } catch (Throwable $e) {
            $res = SweepResult::fail(get_class($e) . ': ' . $e->getMessage());
        } finally {
            $this->lock->release($lockName);
        }
        $elapsed = (microtime(true) - $start) * 1000;
        $this->heartbeat($key, $organizationId, $res, $elapsed);

        return $res;
    }

    /**
     * Run every registered sweep (optionally a subset by key).
     *
     * @param list<string>|null   $only    restrict to these keys (null = all)
     * @param array<string,mixed> $options passed to each sweep
     * @return array{swept:int, ran:int, failed:int, skipped:int, results:array<string,SweepResult>}
     */
    public function runAll(?string $organizationId = null, ?array $only = null, array $options = []): array
    {
        $keys = $only ?? $this->registry->keys();
        $results = [];
        $swept = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($keys as $key) {
            $res = $this->runOne($key, $organizationId, $options);
            $results[$key] = $res;
            $swept += $res->swept;
            if (! $res->ok) {
                $failed++;
            } elseif ($res->skipped) {
                $skipped++;
            }
        }

        return [
            'swept'   => $swept,
            'ran'     => count($keys),
            'failed'  => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    private function heartbeat(string $key, ?string $organizationId, SweepResult $res, float $elapsedMs): void
    {
        $line = sprintf(
            '[sweep] %s org=%s %s (%.1fms) at %s',
            $key,
            $organizationId ?? 'ALL',
            $res->summary(),
            $elapsedMs,
            $this->clock->nowUtcString(),
        );
        ($this->logger)($line);
    }
}
