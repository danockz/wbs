<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Groups module widget provider for the hierarchical dashboard.
 *
 * Demonstrates the pattern for other modules to follow.
 */
final class GroupsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // Group membership summary
            new GroupDashboardWidget(
                module: 'Groups',
                key: 'membership_summary',
                labelKey: 'Groups.dashboard.widgets.membershipSummary',
                permission: null, // Always visible
                capability: null,
                scope: 'membership',
                rendererClass: GroupMembershipWidgetRenderer::class,
                order: 10,
                section: 'people',
            ),

            // Group hierarchy navigation
            new GroupDashboardWidget(
                module: 'Groups',
                key: 'hierarchy_nav',
                labelKey: 'Groups.dashboard.widgets.hierarchyNav',
                permission: null,
                capability: null,
                scope: 'membership',
                rendererClass: GroupHierarchyWidgetRenderer::class,
                order: 20,
                section: 'people',
            ),

            // Birthdays widget (if enabled)
            new GroupDashboardWidget(
                module: 'Groups',
                key: 'birthdays',
                labelKey: 'Groups.dashboard.widgets.birthdays',
                permission: null,
                capability: 'groups.birthdays',
                scope: 'ancestor_only',
                rendererClass: GroupBirthdaysWidgetRenderer::class,
                order: 30,
                section: 'people',
            ),
        ];
    }
}
