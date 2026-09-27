<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

/**
 * Narrow seam the M6 verify-expiry sweep uses to read hierarchical group config
 * (feature gate + tunable windows), without Identity depending on the Admin
 * module directly. The production adapter wraps EffectiveConfigResolver (walking
 * group ancestry to the org root); tests supply an in-memory map.
 *
 * Feature-gating is HIERARCHICAL GROUP CONFIG, DEFAULT OFF: value() returns null
 * when unset and the sweep treats null/false as "off".
 */
interface LifecycleConfigPort
{
    /** The effective value for a group's capability, or null when unset. */
    public function value(string $groupId, string $capability): mixed;

    /** The org-root group id for org-level config defaults, or null if none. */
    public function orgRootGroup(string $organizationId): ?string;
}
