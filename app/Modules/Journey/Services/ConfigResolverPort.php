<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * Narrow port the InvolvementService uses to read hierarchical group config,
 * so the engine depends on this one method instead of the whole Admin module.
 * The production adapter wraps EffectiveConfigResolver (walking group ancestry to
 * the org root); tests supply a trivial in-memory implementation.
 */
interface ConfigResolverPort
{
    /**
     * The effective value for a group's capability, or null when unset. May be a
     * scalar (e.g. a window in days) or an associative array (e.g. thresholds /
     * weights). Precedence + inheritance + English fallback are the adapter's job.
     */
    public function value(string $groupId, string $capability): mixed;
}
