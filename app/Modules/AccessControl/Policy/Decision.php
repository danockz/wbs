<?php

declare(strict_types=1);

namespace WBS\AccessControl\Policy;

/**
 * PDP decision (SRS: default-deny). Carries the effect plus a machine reason
 * code and the deciding stage (mac|rbac|abac|sod|default) for audit. The
 * reason is safe to log; it never leaks which other subject/attribute values
 * were involved beyond the deciding rule code.
 */
final class Decision
{
    public const PERMIT = 'permit';
    public const DENY   = 'deny';

    private function __construct(
        public readonly string $effect,
        public readonly string $reason,
        public readonly string $stage,
    ) {
    }

    public static function permit(string $reason = 'permitted', string $stage = 'rbac'): self
    {
        return new self(self::PERMIT, $reason, $stage);
    }

    public static function deny(string $reason = 'default_deny', string $stage = 'default'): self
    {
        return new self(self::DENY, $reason, $stage);
    }

    public function isPermitted(): bool
    {
        return $this->effect === self::PERMIT;
    }
}
