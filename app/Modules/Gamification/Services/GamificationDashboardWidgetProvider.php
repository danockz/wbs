<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Gamification module widget provider for the hierarchical group dashboard.
 */
final class GamificationDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My personal standing
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'my_standing',
                labelKey: 'Gamification.dashboard.myStanding',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyStandingWidgetRenderer::class,
                order: 10,
                section: 'gamification',
            ),

            // My points summary
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'my_points',
                labelKey: 'Gamification.dashboard.myPoints',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyPointsWidgetRenderer::class,
                order: 20,
                section: 'gamification',
            ),

            // My badges
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'my_badges',
                labelKey: 'Gamification.dashboard.myBadges',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyBadgesWidgetRenderer::class,
                order: 30,
                section: 'gamification',
            ),

            // Group leaderboard (if user has permission)
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'group_leaderboard',
                labelKey: 'Gamification.dashboard.groupLeaderboard',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupLeaderboardWidgetRenderer::class,
                order: 40,
                section: 'gamification',
            ),

            // My streaks
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'my_streaks',
                labelKey: 'Gamification.dashboard.myStreaks',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyStreaksWidgetRenderer::class,
                order: 50,
                section: 'gamification',
            ),

            // My achievements
            new GroupDashboardWidget(
                module: 'Gamification',
                key: 'my_achievements',
                labelKey: 'Gamification.dashboard.myAchievements',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyAchievementsWidgetRenderer::class,
                order: 60,
                section: 'gamification',
            ),
        ];
    }
}
