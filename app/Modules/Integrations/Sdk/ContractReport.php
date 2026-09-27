<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * Aggregate outcome of running the contract-test suite against a custom adapter
 * (SRS FR-INT-013).
 *
 * An adapter is CERTIFIED only when every critical check passes. The report is
 * stored with the adapter registration as the reviewable conformance evidence.
 */
final class ContractReport
{
    /** @param list<ContractCheck> $checks */
    public function __construct(
        public readonly string $adapterCode,
        public readonly int $adapterVersion,
        public readonly array $checks,
    ) {
    }

    public function passed(): bool
    {
        foreach ($this->checks as $c) {
            if ($c->critical && ! $c->passed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ContractCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (ContractCheck $c): bool => ! $c->passed));
    }

    public function summary(): string
    {
        $total  = count($this->checks);
        $passed = count(array_filter($this->checks, static fn (ContractCheck $c): bool => $c->passed));

        return "{$passed}/{$total} checks passed";
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'adapter_code'    => $this->adapterCode,
            'adapter_version' => $this->adapterVersion,
            'certified'       => $this->passed(),
            'summary'         => $this->summary(),
            'checks'          => array_map(static fn (ContractCheck $c): array => $c->toArray(), $this->checks),
        ];
    }
}
