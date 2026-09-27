<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

/**
 * The contract every scheduled sweep implements (Theme C — unified sweep runner).
 *
 * The reviews found ~18 places that park work in a table expecting a periodic job
 * that was never written (deferred notifications, failed season rollover, stale
 * access requests, journey involvement staleness, …). Rather than 18 inconsistent
 * cron entries with 18 retry/locking/observability stories, each module
 * contributes ONE `SweepContract` and the shared `SweepRunner` drives them all on
 * a common cadence with a common shape:
 *
 *   - bounded batch (never unbounded table scans),
 *   - idempotent (safe to re-run; a second pass in the same window is a no-op),
 *   - per-org scoping (run for one org or all),
 *   - structured "swept N" result for heartbeat logging.
 *
 * Implementations MUST NOT throw for ordinary "nothing to do"; they return a
 * SweepResult. The runner wraps each call so an unexpected throw is caught and
 * turned into a failed SweepResult without aborting the rest of the batch.
 */
interface SweepContract
{
    /**
     * Stable machine key, e.g. `acl.expire`, `events.close-due`,
     * `journey.involvement-recompute`. Used to select (`--only=`) and to label
     * heartbeat metrics. MUST be unique across the whole platform.
     */
    public function key(): string;

    /** One-line human description for the runner's listing/logs. */
    public function description(): string;

    /**
     * Run one bounded, idempotent pass.
     *
     * @param string|null          $organizationId null = every organization
     * @param array<string,mixed>  $options        e.g. ['limit' => 500, 'grace' => 6]
     */
    public function run(?string $organizationId, array $options = []): SweepResult;
}
