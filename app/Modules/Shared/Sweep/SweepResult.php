<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

/**
 * Outcome of a single sweep pass (Theme C — unified sweep runner).
 *
 * Every registered sweep returns one of these so the runner can log a uniform
 * "swept N" heartbeat and aggregate a batch without knowing each sweep's internal
 * shape. `swept` is the headline count of rows the pass acted on; `details`
 * carries the sweep-specific breakdown (e.g. expired_assignments, refreshed) for
 * structured logging; `ok=false` + `error` marks a pass that failed so the runner
 * can continue with the others rather than aborting the whole batch.
 */
final class SweepResult
{
    /**
     * @param array<string,int|string|bool> $details
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $swept,
        public readonly array $details = [],
        public readonly ?string $error = null,
        public readonly bool $skipped = false,
    ) {
    }

    /** @param array<string,int|string|bool> $details */
    public static function ok(int $swept, array $details = []): self
    {
        return new self(true, max(0, $swept), $details);
    }

    /** A pass that ran but had nothing to do (still a success). */
    public static function nothing(array $details = []): self
    {
        return new self(true, 0, $details);
    }

    /** A pass deliberately not run (e.g. lock held by another worker). */
    public static function skip(string $why): self
    {
        return new self(true, 0, ['reason' => $why], null, true);
    }

    public static function fail(string $error, array $details = []): self
    {
        return new self(false, 0, $details, $error);
    }

    /** One-line, log-friendly summary. */
    public function summary(): string
    {
        if (! $this->ok) {
            return 'FAILED: ' . (string) $this->error;
        }
        if ($this->skipped) {
            return 'skipped (' . (string) ($this->details['reason'] ?? 'n/a') . ')';
        }
        $parts = [];
        foreach ($this->details as $k => $v) {
            $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
        }

        return 'swept ' . $this->swept . ($parts === [] ? '' : ' [' . implode(', ', $parts) . ']');
    }
}
