<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Resolves a StreamProvider adapter by its machine code. Adapters are supplied
 * lazily (as factories) so an adapter that needs env credentials isn't built
 * until it is actually requested.
 */
final class ProviderRegistry
{
    /** @var array<string, callable():StreamProvider> */
    private array $factories = [];

    /** @param array<string, callable():StreamProvider> $factories */
    public function __construct(array $factories = [])
    {
        foreach ($factories as $code => $factory) {
            $this->register($code, $factory);
        }
    }

    /** @param callable():StreamProvider $factory */
    public function register(string $code, callable $factory): void
    {
        $this->factories[strtolower($code)] = $factory;
    }

    public function has(string $code): bool
    {
        return isset($this->factories[strtolower($code)]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->factories);
    }

    public function get(string $code): StreamProvider
    {
        $code = strtolower($code);
        if (! isset($this->factories[$code])) {
            throw new ProviderException("no stream provider registered for '{$code}'", 0, $code);
        }

        return ($this->factories[$code])();
    }
}
