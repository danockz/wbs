<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use SensitiveParameter;

/**
 * A {@see BreachChecker} that never reports a breach. Explicit opt-out for
 * deployments that deliberately disable breach-list enforcement (e.g. an air-
 * gapped environment that wants only the length/complexity policy). Bound when
 * `identity.breachCheck=off`.
 */
final class NullBreachChecker implements BreachChecker
{
    public function isBreached(#[SensitiveParameter] string $plain): bool
    {
        return false;
    }
}
