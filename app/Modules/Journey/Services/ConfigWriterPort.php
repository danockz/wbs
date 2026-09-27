<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

/**
 * Narrow write port the InvolvementService uses to PERSIST hierarchical group
 * config, mirroring the read-only {@see ConfigResolverPort}. The engine depends
 * on this one method instead of the whole Admin module; the production adapter
 * wraps EffectiveConfigResolver::set (the platform's single config store — no
 * parallel config system, per the standing constraint), and tests supply a
 * trivial in-memory implementation.
 */
interface ConfigWriterPort
{
    /**
     * Persist a value for a group's capability. The value may be a scalar
     * (e.g. a window in days), a boolean (the enable flag), or an associative
     * array (thresholds / weights). Returns true on success, false on failure —
     * the caller decides how to surface it.
     */
    public function set(string $organizationId, string $groupId, string $capability, mixed $value, ?string $actorId = null): bool;
}
