<?php

declare(strict_types=1);

namespace WBS\Integrations\Sdk;

/**
 * Immutable, NON-secret result of a canonical operation performed by a custom
 * adapter (SRS FR-INT-013).
 *
 * Adapters return references and provider-reported data only — never secret
 * material and never a fabricated value. Metric-style results carry an
 * `exactness` tag ('exact' | 'estimated' | 'unavailable') so the platform can
 * record honest figures, mirroring the StreamProvider analytics contract.
 */
final class OperationResult
{
    /**
     * @param array<string,mixed> $data     non-secret result payload
     * @param string              $exactness exact|estimated|unavailable (for metric ops)
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly string $exactness = 'exact',
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function ok(array $data = [], string $exactness = 'exact'): self
    {
        return new self(true, $data, null, null, $exactness);
    }

    public static function fail(string $code, string $message): self
    {
        return new self(false, [], $code, $message, 'unavailable');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok'        => $this->ok,
            'data'      => $this->data,
            'error'     => $this->ok ? null : ['code' => $this->errorCode, 'message' => $this->errorMessage],
            'exactness' => $this->exactness,
        ];
    }
}
