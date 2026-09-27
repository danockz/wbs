<?php

declare(strict_types=1);

namespace WBS\Notifications\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Notifications module widget provider for the hierarchical group dashboard.
 */
final class NotificationsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Notifications',
                key: 'my_notifications',
                labelKey: 'Notifications.dashboard.myNotifications',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyNotificationsWidgetRenderer::class,
                order: 10,
                section: 'comms',
                cacheType: GroupDashboardWidget::CACHE_LIVE,
            ),
            new GroupDashboardWidget(
                module: 'Notifications',
                key: 'campaign_status',
                labelKey: 'Notifications.dashboard.campaignStatus',
                permission: 'notification.send',
                capability: null,
                scope: 'membership_desc',
                rendererClass: CampaignStatusWidgetRenderer::class,
                order: 30,
                section: 'comms',
                cacheType: GroupDashboardWidget::CACHE_SUMMARY,
            ),
        ];
    }
}
