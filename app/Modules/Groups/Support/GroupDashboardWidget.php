<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * A widget that can appear on the hierarchical group dashboard.
 *
 * Each module can expose zero or more widgets. A widget declares:
 * - its module + internal key
 * - the permission bit(s) or config capability that gates it
 * - the scope model it needs (membership / descendants / ancestors / etc.)
 * - a renderer class that implements WidgetRenderer
 *
 * The dashboard service collects all registered widgets, filters by the viewer's
 * effective permissions and the current group scope, and renders the ones that
 * are visible. Widgets are hidden entirely when the user lacks the required
 * permission or the feature config is OFF (fail-closed).
 */
final readonly class GroupDashboardWidget
{
    /**
     * @param string $module      Module name (e.g., 'Events', 'Contributions')
     * @param string $key         Unique key within the module (e.g., 'upcoming_events')
     * @param string $labelKey    Lang key for the widget title (e.g., 'Events.dashboard.upcoming')
     * @param string|null $permission Required permission bit; null = always visible if config on
     * @param string|null $capability Required group config capability; null = no config gate
     * @param string $scope       One of: 'self', 'membership', 'membership_desc', 'descendants', 'ancestors', 'ancestor_only'
     * @param string $rendererClass Class name that implements WidgetRenderer
     * @param int $order           Display order within its section (lower = earlier)
     * @param string $section      Section to group under (e.g., 'events', 'giving', 'people')
     */
    public function __construct(
        public string $module,
        public string $key,
        public string $labelKey,
        public ?string $permission = null,
        public ?string $capability = null,
        public string $scope = 'membership',
        public string $rendererClass = '',
        public int $order = 100,
        public string $section = 'default',
    ) {
    }

    /** Return a unique identifier for this widget. */
    public function id(): string
    {
        return $this->module . '.' . $this->key;
    }

    /**
     * Check if this widget should be shown for the given context.
     * Fail-closed: if permission is required and user doesn't have it, hide.
     * If capability is required and effective config is off, hide.
     */
    public function visible(
        string $orgId,
        string $userId,
        array $effectivePermissions,
        array $effectiveConfig,
    ): bool {
        // Permission gate
        if ($this->permission !== null) {
            if (! ($effectivePermissions[$this->permission] ?? false)) {
                return false;
            }
        }

        // Config capability gate
        if ($this->capability !== null) {
            $cfg = $effectiveConfig[$this->capability] ?? null;
            if (! is_array($cfg) || ($cfg['enabled'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
