<?php

declare(strict_types=1);

namespace WBS\AccessControl\Policy;

/**
 * Immutable authorization query handed to the PDP (SRS: MAC+RBAC+ABAC).
 *
 * Carries the subject, the action code, the optional target object, and a free
 * attribute bag used by ABAC conditions (e.g. submitted_by, amount, group_id).
 * Nothing here is trusted for identity — the caller populates subjectId from the
 * authenticated session, never from client input.
 */
final class AccessRequest
{
    /**
     * @param array<string,mixed> $attributes contextual attributes for ABAC
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $subjectId,
        public readonly string $action,
        public readonly ?string $objectType = null,
        public readonly ?string $objectId = null,
        public readonly array $attributes = [],
    ) {
    }

    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
