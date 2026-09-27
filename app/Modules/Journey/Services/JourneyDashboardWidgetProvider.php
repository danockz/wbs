<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Journey module widget provider for the hierarchical group dashboard.
 * Focuses on discipleship journey widgets.
 */
final class JourneyDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My current journey stage
            new GroupDashboardWidget(
                module: 'Journey',
                key: 'my_stage',
                labelKey: 'Journey.dashboard.myStage',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyStageWidgetRenderer::class,
                order: 10,
                section: 'journey',
            ),

            // My journey progress
            new GroupDashboardWidget(
                module: 'Journey',
                key: 'my_progress',
                labelKey: 'Journey.dashboard.myProgress',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyProgressWidgetRenderer::class,
                order: 20,
                section: 'journey',
            ),

            // Group pipeline (for leaders)
            new GroupDashboardWidget(
                module: 'Journey',
                key: 'group_pipeline',
                labelKey: 'Journey.dashboard.groupPipeline',
                permission: null,
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupPipelineWidgetRenderer::class,
                order: 30,
                section: 'journey',
            ),

            // Disciples under my care
            new GroupDashboardWidget(
                module: 'Journey',
                key: 'my_disciples',
                labelKey: 'Journey.dashboard.myDisciples',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyDisciplesWidgetRenderer::class,
                order: 40,
                section: 'journey',
            ),

            // Discipling leaderboard (for leaders)
            new GroupDashboardWidget(
                module: 'Journey',
                key: 'discipling_leaderboard',
                labelKey: 'Journey.dashboard.disciplingLeaderboard',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: DisciplingLeaderboardWidgetRenderer::class,
                order: 50,
                section: 'journey',
            ),
        ];
    }
}
