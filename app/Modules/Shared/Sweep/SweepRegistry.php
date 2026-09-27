<?php

declare(strict_types=1);

namespace WBS\Shared\Sweep;

use InvalidArgumentException;

/**
 * The catalogue of registered sweeps (Theme C).
 *
 * Each module contributes its SweepContract instances here (via the Shared
 * Services factory), giving the runner and the `sweep:run` command one place to
 * discover every scheduled job. Keys are unique; registering a duplicate key is a
 * programming error and throws at wire time (fail fast, not at 3am).
 */
final class SweepRegistry
{
    /** @var array<string,SweepContract> */
    private array $sweeps = [];

    /** @param iterable<SweepContract> $sweeps */
    public function __construct(iterable $sweeps = [])
    {
        foreach ($sweeps as $s) {
            $this->register($s);
        }
    }

    public function register(SweepContract $sweep): void
    {
        $key = $sweep->key();
        if ($key === '') {
            throw new InvalidArgumentException('Sweep key must not be empty.');
        }
        if (isset($this->sweeps[$key])) {
            throw new InvalidArgumentException("Duplicate sweep key: {$key}");
        }
        $this->sweeps[$key] = $sweep;
    }

    public function has(string $key): bool
    {
        return isset($this->sweeps[$key]);
    }

    public function get(string $key): ?SweepContract
    {
        return $this->sweeps[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = array_keys($this->sweeps);
        sort($keys);

        return $keys;
    }

    /** @return array<string,SweepContract> keyed by sweep key, sorted */
    public function all(): array
    {
        ksort($this->sweeps);

        return $this->sweeps;
    }
}
