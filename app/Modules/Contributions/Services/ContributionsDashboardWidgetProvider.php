<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Contributions module widget provider for the hierarchical group dashboard.
 */
final class ContributionsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My giving summary
            new GroupDashboardWidget(
                module: 'Contributions',
                key: 'my_giving',
                labelKey: 'Contributions.dashboard.myGiving',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyGivingWidgetRenderer::class,
                order: 10,
                section: 'giving',
            ),

            // My commitments
            new GroupDashboardWidget(
                module: 'Contributions',
                key: 'my_commitments',
                labelKey: 'Contributions.dashboard.myCommitments',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyCommitmentsWidgetRenderer::class,
                order: 20,
                section: 'giving',
            ),

            // Group giving progress (for leaders)
            new GroupDashboardWidget(
                module: 'Contributions',
                key: 'group_giving',
                labelKey: 'Contributions.dashboard.groupGiving',
                permission: 'contribution.manage',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupGivingWidgetRenderer::class,
                order: 30,
                section: 'giving',
            ),

            // Giving leaderboard (for leaders with report.view)
            new GroupDashboardWidget(
                module: 'Contributions',
                key: 'giving_leaderboard',
                labelKey: 'Contributions.dashboard.givingLeaderboard',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GivingLeaderboardWidgetRenderer::class,
                order: 40,
                section: 'giving',
            ),

            // Causes I support
            new GroupDashboardWidget(
                module: 'Contributions',
                key: 'my_causes',
                labelKey: 'Contributions.dashboard.myCauses',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyCausesWidgetRenderer::class,
                order: 50,
                section: 'giving',
            ),
        ];
    }
}
