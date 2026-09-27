<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;

/**
 * Production EventConfigPort: resolves event-notification config through the
 * existing hierarchical EffectiveConfigResolver (nearest-group-wins, walking
 * ancestry to the org root with the module's inheritance rules). Reuses the
 * platform's one config store per the standing constraint — no parallel config.
 *
 * Returns the resolved `value` (scalar or decoded array) or null when unset, so
 * the EventNotifier's gate falls back to OFF (default-safe).
 */
final class EffectiveConfigAdapter implements EventConfigPort
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
