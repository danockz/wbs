<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Referrals module widget provider for the hierarchical group dashboard.
 */
final class ReferralsDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'Referrals',
                key: 'my_referrals',
                labelKey: 'Referrals.dashboard.myReferrals',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyReferralsWidgetRenderer::class,
                order: 10,
                section: 'people',
                cacheType: GroupDashboardWidget::CACHE_SUMMARY,
            ),
            new GroupDashboardWidget(
                module: 'Referrals',
                key: 'group_outreach',
                labelKey: 'Referrals.dashboard.groupOutreach',
                permission: null,
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupOutreachWidgetRenderer::class,
                order: 20,
                section: 'people',
                cacheType: GroupDashboardWidget::CACHE_SUMMARY,
            ),
        ];
    }
}
