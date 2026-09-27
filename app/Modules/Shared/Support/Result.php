<?php

declare(strict_types=1);

namespace WBS\Shared\Support;

/**
 * Uniform service-layer result envelope used across every module. Controllers
 * translate it to the negotiated representation (HTML/JSON) in BaseController.
 * It never carries secrets; `errors` holds field-level validation detail and
 * `meta` holds non-secret signals (pagination, idempotency, award hints).
 */
final class Result
{
    /**
     * @param array<string,mixed> $errors Field-level validation errors / detail.
     * @param array<string,mixed> $meta   Non-secret metadata (pagination, etc.).
     */
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $data = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $errors = [],
        public readonly int $status = 200,
        public readonly array $meta = [],
    ) {
    }

    /** @param array<string,mixed> $meta */
    public static function ok(mixed $data = null, int $status = 200, array $meta = []): self
    {
        return new self(true, $data, null, null, [], $status, $meta);
    }

    /** @param array<string,mixed> $meta */
    public static function created(mixed $data = null, array $meta = []): self
    {
        return new self(true, $data, null, null, [], 201, $meta);
    }

    /**
     * @param array<string,mixed> $errors
     * @param array<string,mixed> $meta
     */
    public static function fail(
        string $code,
        string $message,
        int $status = 422,
        array $errors = [],
        array $meta = [],
    ): self {
        return new self(false, null, $code, $message, $errors, $status, $meta);
    }

    public static function denied(string $message = 'access.denied', string $code = 'ACCESS_DENIED'): self
    {
        return new self(false, null, $code, $message, [], 403);
    }

    public static function notFound(string $message = 'resource.not_found', string $code = 'NOT_FOUND'): self
    {
        return new self(false, null, $code, $message, [], 404);
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        if ($this->ok) {
            $out = ['data' => $this->data];
            if ($this->meta !== []) {
                $out['meta'] = $this->meta;
            }

            return $out;
        }

        $out = [
            'type'   => 'about:blank',
            'title'  => $this->code,
            'status' => $this->status,
            'detail' => $this->message,
        ];
        if ($this->errors !== []) {
            $out['errors'] = $this->errors;
        }
        if ($this->meta !== []) {
            $out['meta'] = $this->meta;
        }

        return $out;
    }
}
