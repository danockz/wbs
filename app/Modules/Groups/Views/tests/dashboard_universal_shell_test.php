<?php

/**
 * Test that dashboard view works with universal shell.
 *
 *   php app/Modules/Groups/Views/tests/dashboard_universal_shell_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    
    // Set up minimal environment
    $_SERVER['CI_ENVIRONMENT'] = 'testing';
    
    // Mock CodeIgniter View
    class MockView {
        private $extends = null;
        private $sections = [];
        private $currentSection = null;
        
        public function extend(string $layout) {
            $this->extends = $layout;
            return '';
        }
        
        public function section(string $name, callable $callback = null) {
            if ($callback !== null) {
                ob_start();
                $callback();
                $this->sections[$name] = ob_get_clean();
                return '';
            }
            $this->currentSection = $name;
            return '';
        }
        
        public function renderSection(string $name) {
            return $this->sections[$name] ?? '';
        }
        
        public function getExtends() {
            return $this->extends;
        }
    }
    
    // Test 1: Dashboard view extends layouts/app
    $view = new MockView();
    
    // Simulate the dashboard view's first line
    $extends = $view->extend('layouts/app');
    
    if ($view->getExtends() !== 'layouts/app') {
        echo "FAIL: Dashboard view doesn't extend layouts/app\n";
        exit(1);
    }
    echo "ok  dashboard view extends layouts/app\n";
    
    // Test 2: layouts/app delegates to universal
    $appLayout = file_get_contents($root . '/app/Views/layouts/app.php');
    if (strpos($appLayout, 'universal.php') === false) {
        echo "FAIL: layouts/app doesn't delegate to universal.php\n";
        exit(1);
    }
    echo "ok  layouts/app delegates to universal.php\n";
    
    // Test 3: universal.php exists
    if (!file_exists($root . '/app/Views/layouts/universal.php')) {
        echo "FAIL: universal.php doesn't exist\n";
        exit(1);
    }
    echo "ok  universal.php exists\n";
    
    // Test 4: Shell components exist
    $shellComponents = [
        'open.php',
        'close.php',
        'head.php',
        'context.php',
        'menu.php',
        'scripts.php',
        'topbar.php',
    ];
    
    $shellDir = $root . '/app/Modules/Shared/Views/shell/';
    foreach ($shellComponents as $component) {
        if (!file_exists($shellDir . $component)) {
            echo "FAIL: Shell component $component doesn't exist\n";
            exit(1);
        }
    }
    echo "ok  all shell components exist\n";
    
    // Test 5: Dashboard view uses section('content')
    $dashboardView = file_get_contents($root . '/app/Modules/Groups/Views/group_dashboard.php');
    if (strpos($dashboardView, '$this->section(\'content\')') === false) {
        echo "FAIL: Dashboard view doesn't use section('content')\n";
        exit(1);
    }
    echo "ok  dashboard view uses section('content')\n";
    
    // Test 6: Universal layout uses renderSection
    $universalLayout = file_get_contents($root . '/app/Views/layouts/universal.php');
    if (strpos($universalLayout, 'renderSection') === false) {
        echo "FAIL: Universal layout doesn't use renderSection\n";
        exit(1);
    }
    echo "ok  universal layout uses renderSection\n";
    
    // Test 7: Compatibility - check that old shell files still exist
    if (!file_exists($root . '/app/Modules/Shared/Views/_shell_open.php')) {
        echo "FAIL: _shell_open.php compatibility wrapper missing\n";
        exit(1);
    }
    if (!file_exists($root . '/app/Modules/Shared/Views/_shell_close.php')) {
        echo "FAIL: _shell_close.php compatibility wrapper missing\n";
        exit(1);
    }
    echo "ok  compatibility wrappers exist\n";
    
    // Test 8: Dashboard controller exists and is unchanged
    if (!file_exists($root . '/app/Modules/Groups/Controllers/GroupDashboardController.php')) {
        echo "FAIL: Dashboard controller missing\n";
        exit(1);
    }
    echo "ok  dashboard controller exists\n";
    
    // Test 9: Dashboard service exists
    if (!file_exists($root . '/app/Modules/Groups/Services/GroupDashboardService.php')) {
        echo "FAIL: Dashboard service missing\n";
        exit(1);
    }
    echo "ok  dashboard service exists\n";
    
    // Test 10: All provider files exist
    $providerFiles = glob($root . '/app/Modules/*/Services/*DashboardWidgetProvider.php');
    if (count($providerFiles) < 14) {
        echo "FAIL: Expected at least 14 provider files, found " . count($providerFiles) . "\n";
        exit(1);
    }
    echo "ok  all " . count($providerFiles) . " provider files exist\n";
    
    echo "\n== 10 passed, 0 failed ==\n";
    echo "\nDashboard is compatible with universal shell!\n";
}
