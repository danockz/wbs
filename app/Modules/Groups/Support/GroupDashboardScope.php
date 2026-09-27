<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Value object describing the current dashboard scope.
 *
 * The dashboard can show data for:
 * - A specific selected group (with its ancestors/descendants per widget scope)
 * - A rollup across all of the user's in-scope groups
 *
 * Each widget then interprets this scope according to its own $scope setting.
 */
final readonly class GroupDashboardScope
{
    /**
     * @param string $orgId          Organization
     * @param string $userId         Authenticated user
     * @param string|null $groupId   Currently selected group (null = rollup across all user groups)
     * @param string $mode          'switcher' (one group at a time) or 'rollup' (all user groups)
     * @param array<string,array<string,mixed>> $groupConfigs Effective configs keyed by group_id
     */
    public function __construct(
        public string $orgId,
        public string $userId,
        public ?string $groupId = null,
        public string $mode = 'switcher',
        public array $groupConfigs = [],
    ) {
    }

    /** True when we're showing a single selected group. */
    public function isSingleGroup(): bool
    {
        return $this->groupId !== null;
    }

    /** True when we're showing a rollup across the user's groups. */
    public function isRollup(): bool
    {
        return $this->groupId === null;
    }

    /**
     * Return the effective config for the current scope group, or the org default.
     * For rollup mode, returns an empty array (widgets should aggregate).
     */
    public function effectiveConfig(string $capability): ?array
    {
        if ($this->groupId !== null) {
            return $this->groupConfigs[$this->groupId][$capability] ?? null;
        }
        return null;
    }
}
