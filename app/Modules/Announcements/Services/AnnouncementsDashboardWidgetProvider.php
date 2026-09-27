<?php

declare(strict_types=1);

namespace WBS\Announcements\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Announcements module widget provider for the hierarchical group dashboard.
 */
final class AnnouncementsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Announcements',
                key: 'my_announcements',
                labelKey: 'Announcements.dashboard.myAnnouncements',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyAnnouncementsWidgetRenderer::class,
                order: 10,
                section: 'comms',
            ),
            new GroupDashboardWidget(
                module: 'Announcements',
                key: 'group_announcements',
                labelKey: 'Announcements.dashboard.groupAnnouncements',
                permission: 'notification.send',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupAnnouncementsWidgetRenderer::class,
                order: 20,
                section: 'comms',
            ),
        ];
    }
}
