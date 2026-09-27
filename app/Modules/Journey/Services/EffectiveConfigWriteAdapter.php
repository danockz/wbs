<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use Throwable;
use WBS\Admin\Services\EffectiveConfigResolver;

/**
 * Production ConfigWriterPort: persists involvement config through the existing
 * hierarchical EffectiveConfigResolver::set (the platform's single config store).
 * Reuses the platform's one config system per the standing constraint — no
 * parallel config store, and the same versioning/inheritance the reader honours.
 *
 * Uses the default inheritance mode (ancestor_default_child_override) so an
 * org-root setting cascades to descendants unless a nearer group overrides it —
 * exactly how the involvement reader resolves most-specific-wins.
 */
final class EffectiveConfigWriteAdapter implements ConfigWriterPort
{
    public function __construct(private readonly EffectiveConfigResolver $resolver)
    {
    }

    public function set(string $organizationId, string $groupId, string $capability, mixed $value, ?string $actorId = null): bool
    {
        try {
            $res = $this->resolver->set($organizationId, $groupId, $capability, $value, 'ancestor_default_child_override', $actorId);
        } catch (Throwable) {
            return false;
        }

        return $res->ok;
    }
}
