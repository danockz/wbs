<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Identity module widget provider for the hierarchical group dashboard.
 * Focuses on membership and people-related widgets.
 */
final class IdentityDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My profile summary
            new GroupDashboardWidget(
                module: 'Identity',
                key: 'my_profile',
                labelKey: 'Identity.dashboard.myProfile',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyProfileWidgetRenderer::class,
                order: 10,
                section: 'people',
            ),

            // My group memberships
            new GroupDashboardWidget(
                module: 'Identity',
                key: 'my_memberships',
                labelKey: 'Identity.dashboard.myMemberships',
                permission: null,
                capability: null,
                scope: 'membership',
                rendererClass: MyMembershipsWidgetRenderer::class,
                order: 20,
                section: 'people',
            ),

            // Recent members (for leaders)
            new GroupDashboardWidget(
                module: 'Identity',
                key: 'recent_members',
                labelKey: 'Identity.dashboard.recentMembers',
                permission: 'identity.manage',
                capability: null,
                scope: 'membership_desc',
                rendererClass: RecentMembersWidgetRenderer::class,
                order: 30,
                section: 'people',
            ),

            // Membership conflicts (for admins)
            new GroupDashboardWidget(
                module: 'Identity',
                key: 'membership_conflicts',
                labelKey: 'Identity.dashboard.membershipConflicts',
                permission: 'identity.manage',
                capability: null,
                scope: 'membership_desc',
                rendererClass: MembershipConflictsWidgetRenderer::class,
                order: 40,
                section: 'people',
            ),
        ];
    }
}
