<?php

declare(strict_types=1);

namespace Config;

/**
 * Dashboard configuration.
 *
 * Central configuration for the hierarchical group dashboard.
 * Controls caching, auto-discovery, and default behaviors.
 */
class Dashboard
{
    /**
     * Enable dashboard feature.
     * Set to false to disable the entire dashboard.
     */
    public bool $enabled = true;

    /**
     * Enable auto-discovery of widget providers.
     * When true, the system automatically scans module namespaces
     * for classes implementing GroupDashboardWidgetProvider.
     */
    public bool $autoDiscover = true;

    /**
     * Namespaces to scan for widget providers.
     * Add custom module namespaces here if they're not in the default list.
     *
     * @var list<string>
     */
    public array $providerNamespaces = [
        'WBS\\Groups\\Services',
        'WBS\\Gamification\\Services',
        'WBS\\Identity\\Services',
        'WBS\\Journey\\Services',
        'WBS\\Events\\Services',
        'WBS\\Contributions\\Services',
        'WBS\\Courses\\Services',
        'WBS\\Community\\Services',
        'WBS\\Announcements\\Services',
        'WBS\\Notifications\\Services',
        'WBS\\AccessControl\\Services',
        'WBS\\Reporting\\Services',
        'WBS\\Referrals\\Services',
        'WBS\\Meetings\\Services',
        'WBS\\Streaming\\Services',
    ];

    /**
     * Enable widget caching.
     * When true, rendered widget HTML is cached to improve performance.
     */
    public bool $cacheEnabled = true;

    /**
     * Default cache TTL in seconds.
     * This is the fallback TTL when a widget doesn't specify its own.
     * Individual widgets can override this with their cacheType property.
     */
    public int $defaultCacheTtl = 180; // 3 minutes

    /**
     * Cache TTLs for different cache types (in seconds).
     *
     * @var array<string, int>
     */
    public array $cacheTtls = [
        'static' => 3600,     // 1 hour
        'summary' => 300,     // 5 minutes
        'list' => 180,        // 3 minutes
        'realtime' => 60,     // 1 minute
        'live' => 0,          // No caching
    ];

    /**
     * Maximum number of widgets to display per section.
     * Set to 0 or null for unlimited.
     */
    public ?int $maxWidgetsPerSection = null;

    /**
     * Maximum total widgets to display.
     * Set to 0 or null for unlimited.
     */
    public ?int $maxTotalWidgets = null;

    /**
     * Default section for widgets that don't specify one.
     */
    public string $defaultSection = 'default';

    /**
     * Section order for dashboard display.
     * Sections are displayed in this order.
     *
     * @var list<string>
     */
    public array $sectionOrder = [
        'people',
        'gamification',
        'journey',
        'events',
        'giving',
        'learning',
        'community',
        'comms',
        'access',
        'reports',
        'streaming',
        'default',
    ];

    /**
     * Show section headers.
     * When true, section names are displayed as headers.
     */
    public bool $showSectionHeaders = true;

    /**
     * Show widget badges (permission, config, scope).
     * Useful for debugging and transparency.
     */
    public bool $showDebugBadges = false;

    /**
     * Enable group switcher.
     * When true, users can switch between their groups.
     */
    public bool $groupSwitcherEnabled = true;

    /**
     * Show empty state messages.
     * When true, helpful messages are shown when no widgets are available.
     */
    public bool $showEmptyStates = true;
}
