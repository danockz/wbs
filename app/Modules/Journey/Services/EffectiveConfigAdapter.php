<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;

/**
 * Production ConfigResolverPort: resolves involvement config through the existing
 * hierarchical EffectiveConfigResolver (nearest-group-wins, walking ancestry to
 * the org root, with the module's own inheritance rules). Reuses the platform's
 * one config system per the standing constraint — no parallel config store.
 *
 * Returns the resolved `value` (scalar or decoded array) or null when unset, so
 * the InvolvementService falls back to its built-in defaults (default-safe).
 */
final class EffectiveConfigAdapter implements ConfigResolverPort
{
    public function __construct(private readonly EffectiveConfigResolver $resolver)
    {
    }

    public function value(string $groupId, string $capability): mixed
    {
        try {
            $res = $this->resolver->resolve($groupId, $capability);
        } catch (Throwable) {
            return null;
        }
        if (! $res->ok) {
            return null;
        }

        return $res->data['value'] ?? null;
    }
}
