<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Read port for hierarchical birthday config. Production adapter wraps
 * EffectiveConfigResolver; tests inject a fake. Groups stays decoupled from
 * the Admin module at the service constructor.
 */
interface BirthdayConfigPort
{
    public function value(string $groupId, string $capability): mixed;
}
