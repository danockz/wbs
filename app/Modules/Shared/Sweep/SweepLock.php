<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

/**
 * Mutual-exclusion primitive for the sweep runner (Theme C).
 *
 * A sweep pass must not overlap with another worker running the same sweep (two
 * runners double-processing the same batch is the classic cron footgun). The
 * runner acquires a named lock per sweep key; if it can't, it SKIPS that sweep
 * (reported as a skip, not a failure) so the other worker owns the pass.
 *
 * The default production implementation uses MySQL advisory locks
 * (GET_LOCK/RELEASE_LOCK), which are connection-scoped and auto-release if the
 * worker dies — exactly the semantics wanted. Tests inject a trivial in-memory
 * lock (or a no-op) so the runner is exercisable without a database.
 */
interface SweepLock
{
    /**
     * Try to acquire the named lock without blocking (timeout 0). Returns true if
     * acquired, false if another holder has it.
     */
    public function acquire(string $name): bool;

    /** Release a previously-acquired lock. Safe to call if not held. */
    public function release(string $name): void;
}
