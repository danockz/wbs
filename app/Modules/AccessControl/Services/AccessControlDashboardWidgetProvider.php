<?php

declare(strict_types=1);

namespace WBS\AccessControl\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * AccessControl module widget provider for the hierarchical group dashboard.
 */
final class AccessControlDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    public static function widgets(): array
    {
        return [
            new GroupDashboardWidget(
                module: 'AccessControl',
                key: 'my_requests',
                labelKey: 'AccessControl.dashboard.myRequests',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyRequestsWidgetRenderer::class,
                order: 10,
                section: 'access',
                cacheType: GroupDashboardWidget::CACHE_LIST,
            ),
            new GroupDashboardWidget(
                module: 'AccessControl',
                key: 'pending_approvals',
                labelKey: 'AccessControl.dashboard.pendingApprovals',
                permission: 'access.request.approve',
                capability: null,
                scope: 'membership_desc',
                rendererClass: PendingApprovalsWidgetRenderer::class,
                order: 20,
                section: 'access',
                cacheType: GroupDashboardWidget::CACHE_LIVE,
            ),
        ];
    }
}
