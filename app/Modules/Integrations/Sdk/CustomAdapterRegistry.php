<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * Resolves a registered custom-adapter INSTANCE by its FQCN (SRS FR-INT-013).
 *
 * Custom adapters are shipped, reviewed CODE — not runtime-injected. So the set
 * of instantiable adapter classes is an explicit, code-owned allowlist bound
 * here (in Config/Services), exactly like the built-in StreamProvider/
 * MeetingProvider registries. A registration whose impl_class is not in this
 * allowlist can never be contract-tested or activated: shipping the class and
 * allowlisting it is itself the reviewed act, closing the door on arbitrary
 * class instantiation from a DB string.
 */
final class CustomAdapterRegistry
{
    /** @var array<string, callable():CustomAdapter> */
    private array $factories = [];

    /** @param array<string, callable():CustomAdapter> $factories keyed by FQCN */
    public function __construct(array $factories = [])
    {
        foreach ($factories as $class => $factory) {
            $this->register($class, $factory);
        }
    }

    /** @param callable():CustomAdapter $factory */
    public function register(string $class, callable $factory): void
    {
        $this->factories[ltrim($class, '\\')] = $factory;
    }

    public function has(string $class): bool
    {
        return isset($this->factories[ltrim($class, '\\')]);
    }

    /** @return list<string> */
    public function classes(): array
    {
        return array_keys($this->factories);
    }

    public function get(string $class): CustomAdapter
    {
        $key = ltrim($class, '\\');
        if (! isset($this->factories[$key])) {
            throw new \RuntimeException("custom adapter class '{$key}' is not registered/allowlisted");
        }

        return ($this->factories[$key])();
    }
}
