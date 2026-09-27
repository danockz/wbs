<?php

declare(strict_types=1);

namespace WBS\Meetings\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Meetings module widget provider for the hierarchical group dashboard.
 */
final class MeetingsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Meetings',
                key: 'my_meetings',
                labelKey: 'Meetings.dashboard.myMeetings',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyMeetingsWidgetRenderer::class,
                order: 10,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_REALTIME,
            ),
            new GroupDashboardWidget(
                module: 'Meetings',
                key: 'group_schedule',
                labelKey: 'Meetings.dashboard.groupSchedule',
                permission: 'meeting.manage',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupScheduleWidgetRenderer::class,
                order: 20,
                section: 'events',
                cacheType: GroupDashboardWidget::CACHE_LIST,
            ),
        ];
    }
}
