<?php

declare(strict_types=1);

namespace WBS\Community\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Community module widget provider for the hierarchical group dashboard.
 */
final class CommunityDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Community',
                key: 'group_feed',
                labelKey: 'Community.dashboard.groupFeed',
                permission: null,
                capability: null,
                scope: 'membership',
                rendererClass: GroupFeedWidgetRenderer::class,
                order: 10,
                section: 'community',
            ),
            new GroupDashboardWidget(
                module: 'Community',
                key: 'recent_posts',
                labelKey: 'Community.dashboard.recentPosts',
                permission: null,
                capability: null,
                scope: 'membership_desc',
                rendererClass: RecentPostsWidgetRenderer::class,
                order: 20,
                section: 'community',
            ),
        ];
    }
}
