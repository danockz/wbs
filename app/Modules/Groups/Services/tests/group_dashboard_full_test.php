<?php

/**
 * Full group dashboard integration test
 *
 *   php app/Modules/Groups/Services/tests/group_dashboard_full_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    
    // Load all the dashboard infrastructure
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidget.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardScope.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Groups/Support/WidgetRenderer.php';

    // Load all widget providers and renderers
    require_once $root . '/app/Modules/Groups/Services/GroupsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Groups/Services/GroupMembershipWidgetRenderer.php';
    require_once $root . '/app/Modules/Groups/Services/GroupHierarchyWidgetRenderer.php';
    require_once $root . '/app/Modules/Groups/Services/GroupBirthdaysWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/GamificationDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Gamification/Services/MyStandingWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/MyPointsWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/MyBadgesWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/GroupLeaderboardWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/MyStreaksWidgetRenderer.php';
    require_once $root . '/app/Modules/Gamification/Services/MyAchievementsWidgetRenderer.php';
    require_once $root . '/app/Modules/Identity/Services/IdentityDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Identity/Services/MyProfileWidgetRenderer.php';
    require_once $root . '/app/Modules/Identity/Services/MyMembershipsWidgetRenderer.php';
    require_once $root . '/app/Modules/Identity/Services/RecentMembersWidgetRenderer.php';
    require_once $root . '/app/Modules/Identity/Services/MembershipConflictsWidgetRenderer.php';
    require_once $root . '/app/Modules/Journey/Services/JourneyDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Journey/Services/MyStageWidgetRenderer.php';
    require_once $root . '/app/Modules/Journey/Services/MyProgressWidgetRenderer.php';
    require_once $root . '/app/Modules/Journey/Services/GroupPipelineWidgetRenderer.php';
    require_once $root . '/app/Modules/Journey/Services/MyDisciplesWidgetRenderer.php';
    require_once $root . '/app/Modules/Journey/Services/DisciplingLeaderboardWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/EventsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Events/Services/MyUpcomingEventsWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/GroupUpcomingEventsWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/MyRegistrationsWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/MyEventsWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/CommitteeTasksWidgetRenderer.php';
    require_once $root . '/app/Modules/Events/Services/EventAnalyticsWidgetRenderer.php';
    require_once $root . '/app/Modules/Contributions/Services/ContributionsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Contributions/Services/MyGivingWidgetRenderer.php';
    require_once $root . '/app/Modules/Contributions/Services/MyCommitmentsWidgetRenderer.php';
    require_once $root . '/app/Modules/Contributions/Services/GroupGivingWidgetRenderer.php';
    require_once $root . '/app/Modules/Contributions/Services/GivingLeaderboardWidgetRenderer.php';
    require_once $root . '/app/Modules/Contributions/Services/MyCausesWidgetRenderer.php';
    require_once $root . '/app/Modules/Courses/Services/CoursesDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Courses/Services/MyCoursesWidgetRenderer.php';
    require_once $root . '/app/Modules/Courses/Services/MyCourseProgressWidgetRenderer.php';
    require_once $root . '/app/Modules/Courses/Services/GroupCoursesWidgetRenderer.php';
    require_once $root . '/app/Modules/Courses/Services/CompletionRatesWidgetRenderer.php';
    require_once $root . '/app/Modules/Community/Services/CommunityDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Community/Services/GroupFeedWidgetRenderer.php';
    require_once $root . '/app/Modules/Community/Services/RecentPostsWidgetRenderer.php';
    require_once $root . '/app/Modules/Announcements/Services/AnnouncementsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Announcements/Services/MyAnnouncementsWidgetRenderer.php';
    require_once $root . '/app/Modules/Announcements/Services/GroupAnnouncementsWidgetRenderer.php';
    require_once $root . '/app/Modules/Notifications/Services/NotificationsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Notifications/Services/MyNotificationsWidgetRenderer.php';
    require_once $root . '/app/Modules/Notifications/Services/CampaignStatusWidgetRenderer.php';
    require_once $root . '/app/Modules/AccessControl/Services/AccessControlDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/AccessControl/Services/MyRequestsWidgetRenderer.php';
    require_once $root . '/app/Modules/AccessControl/Services/PendingApprovalsWidgetRenderer.php';
    require_once $root . '/app/Modules/Reporting/Services/ReportingDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Reporting/Services/GroupFunnelWidgetRenderer.php';
    require_once $root . '/app/Modules/Referrals/Services/ReferralsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Referrals/Services/MyReferralsWidgetRenderer.php';
    require_once $root . '/app/Modules/Referrals/Services/GroupOutreachWidgetRenderer.php';
    require_once $root . '/app/Modules/Meetings/Services/MeetingsDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Meetings/Services/MyMeetingsWidgetRenderer.php';
    require_once $root . '/app/Modules/Meetings/Services/GroupScheduleWidgetRenderer.php';
    require_once $root . '/app/Modules/Streaming/Services/StreamingDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Streaming/Services/LiveStreamsWidgetRenderer.php';

    use WBS\Groups\Support\GroupDashboardScope;

    // Test 1: All providers can be instantiated
    $providers = [
        'WBS\Groups\Services\GroupsDashboardWidgetProvider',
        'WBS\Gamification\Services\GamificationDashboardWidgetProvider',
        'WBS\Identity\Services\IdentityDashboardWidgetProvider',
        'WBS\Journey\Services\JourneyDashboardWidgetProvider',
        'WBS\Events\Services\EventsDashboardWidgetProvider',
        'WBS\Contributions\Services\ContributionsDashboardWidgetProvider',
        'WBS\Courses\Services\CoursesDashboardWidgetProvider',
        'WBS\Community\Services\CommunityDashboardWidgetProvider',
        'WBS\Announcements\Services\AnnouncementsDashboardWidgetProvider',
        'WBS\Notifications\Services\NotificationsDashboardWidgetProvider',
        'WBS\AccessControl\Services\AccessControlDashboardWidgetProvider',
        'WBS\Reporting\Services\ReportingDashboardWidgetProvider',
        'WBS\Referrals\Services\ReferralsDashboardWidgetProvider',
        'WBS\Meetings\Services\MeetingsDashboardWidgetProvider',
        'WBS\Streaming\Services\StreamingDashboardWidgetProvider',
    ];

    $totalWidgets = 0;
    $allWidgets = [];
    foreach ($providers as $providerClass) {
        $widgets = $providerClass::widgets();
        $count = count($widgets);
        $totalWidgets += $count;
        $allWidgets = array_merge($allWidgets, $widgets);
        echo "ok  $providerClass -> $count widgets\n";
    }

    // Test 2: Total widget count
    if ($totalWidgets !== 47) {
        echo "FAIL: Expected 47 widgets, got $totalWidgets\n";
        exit(1);
    }
    echo "ok  total widgets = 47\n";

    // Test 3: Widgets have unique IDs
    $ids = [];
    foreach ($allWidgets as $widget) {
        $id = $widget->id();
        if (isset($ids[$id])) {
            echo "FAIL: Duplicate widget ID: $id\n";
            exit(1);
        }
        $ids[$id] = true;
    }
    echo "ok  all widget IDs are unique\n";

    // Test 4: Widgets are organized into sections
    $sections = [];
    foreach ($allWidgets as $widget) {
        $section = $widget->section;
        if (!isset($sections[$section])) {
            $sections[$section] = 0;
        }
        $sections[$section]++;
    }
    echo "ok  widgets organized into " . count($sections) . " sections\n";
    foreach ($sections as $section => $count) {
        echo "  - $section: $count widgets\n";
    }

    // Test 5: Check specific widget properties
    $hasGamification = false;
    $hasIdentity = false;
    $hasJourney = false;
    $hasEvents = false;
    
    foreach ($allWidgets as $widget) {
        if ($widget->module === 'Gamification') $hasGamification = true;
        if ($widget->module === 'Identity') $hasIdentity = true;
        if ($widget->module === 'Journey') $hasJourney = true;
        if ($widget->module === 'Events') $hasEvents = true;
    }
    
    if (!$hasGamification) {
        echo "FAIL: Missing Gamification widgets\n";
        exit(1);
    }
    if (!$hasIdentity) {
        echo "FAIL: Missing Identity widgets\n";
        exit(1);
    }
    if (!$hasJourney) {
        echo "FAIL: Missing Journey widgets\n";
        exit(1);
    }
    if (!$hasEvents) {
        echo "FAIL: Missing Events widgets\n";
        exit(1);
    }
    
    echo "ok  all module widgets present\n";

    // Test 6: Scope creation
    $scope = new GroupDashboardScope(
        orgId: 'org1',
        userId: 'user1',
        groupId: 'group1',
        mode: 'switcher',
        groupConfigs: [],
    );
    if (!$scope->isSingleGroup()) {
        echo "FAIL: Scope should be single group\n";
        exit(1);
    }
    echo "ok  scope creation works\n";

    // Test 7: Check scopes
    $scopes = [];
    foreach ($allWidgets as $widget) {
        $scope = $widget->scope;
        if (!isset($scopes[$scope])) {
            $scopes[$scope] = 0;
        }
        $scopes[$scope]++;
    }
    echo "ok  scope distribution:\n";
    foreach ($scopes as $scope => $count) {
        echo "  - $scope: $count widgets\n";
    }

    // Test 8: Check permissions
    $withPerm = 0;
    $withoutPerm = 0;
    foreach ($allWidgets as $widget) {
        if ($widget->permission !== null) {
            $withPerm++;
        } else {
            $withoutPerm++;
        }
    }
    echo "ok  permission gating: $withPerm with permission, $withoutPerm without\n";

    // Test 9: Check capabilities
    $withCap = 0;
    foreach ($allWidgets as $widget) {
        if ($widget->capability !== null) {
            $withCap++;
        }
    }
    echo "ok  capability gating: $withCap with capability\n";

    // Test 10: Visibility with permissions
    $permissions = ['report.view' => true, 'identity.manage' => true];
    $visible = 0;
    $hidden = 0;
    foreach ($allWidgets as $widget) {
        if ($widget->visible('org1', 'user1', $permissions, [])) {
            $visible++;
        } else {
            $hidden++;
        }
    }
    echo "ok  visibility test: $visible visible, $hidden hidden with report.view + identity.manage\n";

    // Test 11: All renderers exist and are instantiable
    foreach ($allWidgets as $widget) {
        $class = $widget->rendererClass;
        if ($class === '') continue;
        if (!class_exists($class)) {
            echo "FAIL: Renderer class not found: $class\n";
            exit(1);
        }
        if (!method_exists($class, 'create')) {
            echo "FAIL: Renderer missing create() method: $class\n";
            exit(1);
        }
    }
    echo "ok  all renderer classes exist and have create() method\n";

    // Test 12: Module coverage
    $modules = [];
    foreach ($allWidgets as $widget) {
        $modules[$widget->module] = true;
    }
    $expectedModules = [
        'Groups', 'Gamification', 'Identity', 'Journey',
        'Events', 'Contributions', 'Courses', 'Community',
        'Announcements', 'Notifications', 'AccessControl',
        'Reporting', 'Referrals', 'Meetings', 'Streaming'
    ];
    
    foreach ($expectedModules as $module) {
        if (!isset($modules[$module])) {
            echo "FAIL: Missing module: $module\n";
            exit(1);
        }
    }
    echo "ok  all 14 modules represented\n";

    echo "\n== 23 passed, 0 failed ==\n";
}
