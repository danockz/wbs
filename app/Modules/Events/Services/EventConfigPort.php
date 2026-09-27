<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * Narrow port for reading hierarchical group config, so the EventNotifier depends
 * on this one method rather than the whole Admin module. The production adapter
 * wraps EffectiveConfigResolver (walking group ancestry to the org root with the
 * platform's inheritance rules — the single config store, per standing rule);
 * tests supply a trivial in-memory implementation.
 *
 * Feature-gating is HIERARCHICAL GROUP CONFIG, DEFAULT OFF: value() returns null
 * when unset and the notifier treats null/false as "off".
 */
interface EventConfigPort
{
    /** The effective value for a group's capability, or null when unset. */
    public function value(string $groupId, string $capability): mixed;
}
