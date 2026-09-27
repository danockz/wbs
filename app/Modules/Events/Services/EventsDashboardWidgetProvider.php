<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Events module widget provider for the hierarchical group dashboard.
 */
final class EventsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My upcoming events
            new GroupDashboardWidget(
                module: 'Events',
                key: 'my_upcoming',
                labelKey: 'Events.dashboard.myUpcoming',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyUpcomingEventsWidgetRenderer::class,
                order: 10,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_REALTIME,
            ),

            // Group upcoming events
            new GroupDashboardWidget(
                module: 'Events',
                key: 'group_upcoming',
                labelKey: 'Events.dashboard.groupUpcoming',
                permission: null,
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupUpcomingEventsWidgetRenderer::class,
                order: 20,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_REALTIME,
            ),

            // My registrations
            new GroupDashboardWidget(
                module: 'Events',
                key: 'my_registrations',
                labelKey: 'Events.dashboard.myRegistrations',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyRegistrationsWidgetRenderer::class,
                order: 30,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_LIST,
            ),

            // Events I'm organizing
            new GroupDashboardWidget(
                module: 'Events',
                key: 'my_events',
                labelKey: 'Events.dashboard.myEvents',
                permission: 'event.create',
                capability: null,
                scope: 'self',
                rendererClass: MyEventsWidgetRenderer::class,
                order: 40,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_LIST,
            ),

            // Committee tasks (if enabled)
            new GroupDashboardWidget(
                module: 'Events',
                key: 'committee_tasks',
                labelKey: 'Events.dashboard.committeeTasks',
                permission: null,
                capability: 'event_committee',
                scope: 'membership',
                rendererClass: CommitteeTasksWidgetRenderer::class,
                order: 50,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_REALTIME,
            ),

            // Event analytics (for leaders)
            new GroupDashboardWidget(
                module: 'Events',
                key: 'event_analytics',
                labelKey: 'Events.dashboard.eventAnalytics',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: EventAnalyticsWidgetRenderer::class,
                order: 60,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_SUMMARY,
            ),
        ];
    }
}
