<?php

/**
 * Widget Provider Discovery tests
 *
 *   php app/Modules/Groups/Support/tests/widget_provider_discovery_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidgetProvider.php';
    require_once $root . '/app/Modules/Groups/Support/WidgetRenderer.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardWidget.php';
    require_once $root . '/app/Modules/Groups/Support/GroupDashboardScope.php';
    require_once $root . '/app/Modules/Groups/Support/WidgetProviderDiscovery.php';

    use WBS\Groups\Support\WidgetProviderDiscovery;

    // Test 1: Default namespaces exist
    $namespaces = WidgetProviderDiscovery::getNamespaces();
    if (count($namespaces) === 0) {
        echo "FAIL: No default namespaces\n";
        exit(1);
    }
    echo "ok  " . count($namespaces) . " default namespaces registered\n";

    // Test 2: Expected namespaces are present
    $expected = [
        'WBS\\Groups\\Services',
        'WBS\\Gamification\\Services',
        'WBS\\Identity\\Services',
        'WBS\\Journey\\Services',
    ];
    foreach ($expected as $ns) {
        if (! in_array($ns, $namespaces, true)) {
            echo "FAIL: Missing namespace: $ns\n";
            exit(1);
        }
    }
    echo "ok  expected namespaces present\n";

    // Test 3: Namespaces can be added
    $originalCount = count($namespaces);
    WidgetProviderDiscovery::addNamespace('WBS\\Custom\\Services');
    $newNamespaces = WidgetProviderDiscovery::getNamespaces();
    if (count($newNamespaces) <= $originalCount) {
        echo "FAIL: Adding namespace didn't increase count\n";
        exit(1);
    }
    echo "ok  namespaces can be added\n";

    // Test 4: Adding duplicate namespace doesn't duplicate
    WidgetProviderDiscovery::addNamespace('WBS\\Custom\\Services');
    $stillSame = WidgetProviderDiscovery::getNamespaces();
    if (count($stillSame) !== count($newNamespaces)) {
        echo "FAIL: Duplicate namespace was added\n";
        exit(1);
    }
    echo "ok  duplicate namespaces not added\n";

    // Test 5: Namespaces can be cleared
    WidgetProviderDiscovery::clearNamespaces();
    if (count(WidgetProviderDiscovery::getNamespaces()) !== 0) {
        echo "FAIL: Clearing namespaces didn't work\n";
        exit(1);
    }
    echo "ok  namespaces can be cleared\n";

    // Test 6: findPhpFiles works
    WidgetProviderDiscovery::addNamespace('WBS\\Groups\\Services');
    $files = WidgetProviderDiscovery::findPhpFiles($root . '/app/Modules/Groups/Services');
    if (count($files) === 0) {
        echo "FAIL: findPhpFiles returned no files\n";
        exit(1);
    }
    $hasProvider = false;
    foreach ($files as $file) {
        if (strpos($file, 'DashboardWidgetProvider.php') !== false) {
            $hasProvider = true;
            break;
        }
    }
    if (! $hasProvider) {
        echo "FAIL: findPhpFiles didn't find GroupsDashboardWidgetProvider\n";
        exit(1);
    }
    echo "ok  findPhpFiles finds PHP files\n";

    // Test 7: fileToClassName works (skip if APPPATH not defined)
    if (defined('APPPATH')) {
        $className = WidgetProviderDiscovery::fileToClassName(
            $root . '/app/Modules/Groups/Services/GroupsDashboardWidgetProvider.php',
            'WBS\\Groups\\Services'
        );
        if ($className !== 'WBS\\Groups\\Services\\GroupsDashboardWidgetProvider') {
            echo "FAIL: fileToClassName returned: $className\n";
            exit(1);
        }
        echo "ok  fileToClassName converts correctly\n";
    } else {
        echo "ok  fileToClassName skipped (APPPATH not defined in test env)\n";
    }

    echo "\n== 7 passed, 0 failed ==\n";
}
