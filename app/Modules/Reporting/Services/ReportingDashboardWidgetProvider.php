<?php

declare(strict_types=1);

namespace WBS\Reporting\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Reporting module widget provider for the hierarchical group dashboard.
 */
final class ReportingDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Reporting',
                key: 'group_funnel',
                labelKey: 'Reporting.dashboard.groupFunnel',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupFunnelWidgetRenderer::class,
                order: 10,
                section: 'reports',
                cacheType: GroupDashboardWidget::CACHE_SUMMARY,
            ),
        ];
    }
}
