<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * One result row from the contract-test suite (SRS FR-INT-013).
 */
final class ContractCheck
{
    public function __construct(
        public readonly string $id,
        public readonly bool $passed,
        public readonly string $detail,
        public readonly bool $critical = true,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'passed'   => $this->passed,
            'critical' => $this->critical,
            'detail'   => $this->detail,
        ];
    }
}
