<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

/**
 * Narrow port for the hierarchical, DEFAULT-OFF feature gate the relay-health
 * sweep (ST4) consults, so StreamRelayService depends on this one method rather
 * than the whole Admin module. The production adapter wraps
 * Admin\SettingsService::isEnabled (org-wide flag with an optional per-group
 * override — the single feature-flag store, per the standing constraint); tests
 * supply a trivial in-memory implementation.
 *
 * Gating is opt-in: enabled() returns false when the flag is unset, so a stream
 * is only auto-ended by the sweep in an org/group that has explicitly turned the
 * relay-health sweep ON.
 */
interface StreamFeatureGatePort
{
    /**
     * Is $flagKey enabled for this org (optionally narrowed to $groupId)?
     * MUST default to false when no flag row exists.
     */
    public function enabled(string $organizationId, string $flagKey, ?string $groupId = null): bool;
}
