<?php

/**
 * Group Dashboard Service tests
 *
 *   php app/Modules/Groups/Services/tests/group_dashboard_service_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidget.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardScope.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Groups/Support/WidgetRenderer.php';
    require_once $root . '/app/Modules/Groups/Services/GroupsDashboardWidgetProvider.php';

    use WBS\Groups\Services\GroupsDashboardWidgetProvider;
    use WBS\Groups\Support\GroupDashboardScope;
    use WBS\Groups\Support\GroupDashboardWidget;

    // Test widget creation
    $widget = new GroupDashboardWidget(
        module: 'Test',
        key: 'test_widget',
        labelKey: 'Test.widget',
        permission: null,
        capability: null,
        scope: 'membership',
        rendererClass: '',
        order: 10,
        section: 'default',
    );

    $ok = function () use ($widget): bool {
        $test = $widget->id() === 'Test.test_widget';
        if (! $test) {
            echo "FAIL: widget id\n";
            return false;
        }
        return true;
    };

    if (! $ok()) {
        exit(1);
    }

    // Test visibility
    $visible = $widget->visible('org1', 'user1', [], []);
    if (! $visible) {
        echo "FAIL: widget should be visible with no permission/capability\n";
        exit(1);
    }

    // Test visibility with permission
    $widgetWithPerm = new GroupDashboardWidget(
        module: 'Test',
        key: 'perm_widget',
        labelKey: 'Test.permWidget',
        permission: 'test.perm',
        capability: null,
        scope: 'membership',
        rendererClass: '',
        order: 10,
        section: 'default',
    );

    $visible = $widgetWithPerm->visible('org1', 'user1', [], []);
    if ($visible) {
        echo "FAIL: widget should not be visible without permission\n";
        exit(1);
    }

    $visible = $widgetWithPerm->visible('org1', 'user1', ['test.perm' => true], []);
    if (! $visible) {
        echo "FAIL: widget should be visible with permission\n";
        exit(1);
    }

    // Test visibility with capability
    $widgetWithCap = new GroupDashboardWidget(
        module: 'Test',
        key: 'cap_widget',
        labelKey: 'Test.capWidget',
        permission: null,
        capability: 'test.cap',
        scope: 'membership',
        rendererClass: '',
        order: 10,
        section: 'default',
    );

    $visible = $widgetWithCap->visible('org1', 'user1', [], []);
    if ($visible) {
        echo "FAIL: widget should not be visible with capability off\n";
        exit(1);
    }

    $visible = $widgetWithCap->visible('org1', 'user1', [], ['test.cap' => ['enabled' => false]]);
    if ($visible) {
        echo "FAIL: widget should not be visible with capability disabled\n";
        exit(1);
    }

    $visible = $widgetWithCap->visible('org1', 'user1', [], ['test.cap' => ['enabled' => true]]);
    if (! $visible) {
        echo "FAIL: widget should be visible with capability enabled\n";
        exit(1);
    }

    // Test scope
    $scope = new GroupDashboardScope(
        orgId: 'org1',
        userId: 'user1',
        groupId: 'group1',
        mode: 'switcher',
        groupConfigs: [],
    );

    if (! $scope->isSingleGroup()) {
        echo "FAIL: scope should be single group\n";
        exit(1);
    }

    $scopeRollup = new GroupDashboardScope(
        orgId: 'org1',
        userId: 'user1',
        groupId: null,
        mode: 'switcher',
        groupConfigs: [],
    );

    if (! $scopeRollup->isRollup()) {
        echo "FAIL: scope should be rollup\n";
        exit(1);
    }

    // Test widget provider
    $widgets = GroupsDashboardWidgetProvider::widgets();
    if (count($widgets) === 0) {
        echo "FAIL: widget provider should return widgets\n";
        exit(1);
    }

    $hasMembership = false;
    $hasHierarchy = false;
    $hasBirthdays = false;
    foreach ($widgets as $w) {
        if ($w->key === 'membership_summary') {
            $hasMembership = true;
        }
        if ($w->key === 'hierarchy_nav') {
            $hasHierarchy = true;
        }
        if ($w->key === 'birthdays') {
            $hasBirthdays = true;
        }
    }
    if (! $hasMembership) {
        echo "FAIL: widget provider should include membership_summary\n";
        exit(1);
    }
    if (! $hasHierarchy) {
        echo "FAIL: widget provider should include hierarchy_nav\n";
        exit(1);
    }
    if (! $hasBirthdays) {
        echo "FAIL: widget provider should include birthdays\n";
        exit(1);
    }

    // Test widget ordering
    $membershipWidget = null;
    $hierarchyWidget = null;
    foreach ($widgets as $w) {
        if ($w->key === 'membership_summary') {
            $membershipWidget = $w;
        }
        if ($w->key === 'hierarchy_nav') {
            $hierarchyWidget = $w;
        }
    }
    if ($membershipWidget === null || $hierarchyWidget === null) {
        echo "FAIL: missing widgets for ordering test\n";
        exit(1);
    }
    if ($membershipWidget->order >= $hierarchyWidget->order) {
        echo "FAIL: membership should come before hierarchy\n";
        exit(1);
    }

    echo "ok  widget id generation\n";
    echo "ok  widget visibility without permission\n";
    echo "ok  widget visibility with missing permission\n";
    echo "ok  widget visibility with permission\n";
    echo "ok  widget visibility with capability off\n";
    echo "ok  widget visibility with capability disabled\n";
    echo "ok  widget visibility with capability enabled\n";
    echo "ok  scope single group detection\n";
    echo "ok  scope rollup detection\n";
    echo "ok  widget provider returns widgets\n";
    echo "ok  widget provider includes membership_summary\n";
    echo "ok  widget provider includes hierarchy_nav\n";
    echo "ok  widget provider includes birthdays\n";
    echo "ok  widget ordering\n";

    echo "\n== 14 passed, 0 failed ==\n";
}
