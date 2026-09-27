<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Resolves a MeetingProvider adapter by machine code. Adapters are built lazily.
 */
final class MeetingProviderRegistry
{
    /** @var array<string, callable():MeetingProvider> */
    private array $factories = [];

    /** @param array<string, callable():MeetingProvider> $factories */
    public function __construct(array $factories = [])
    {
        foreach ($factories as $code => $factory) {
            $this->factories[strtolower($code)] = $factory;
        }
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

    public function get(string $code): MeetingProvider
    {
        $code = strtolower($code);
        if (! isset($this->factories[$code])) {
            throw new ProviderException("no meeting provider registered for '{$code}'", 0, $code);
        }

        return ($this->factories[$code])();
    }
}
