<?php

/**
 * Widget Cache Invalidator tests
 *
 *   php app/Modules/Groups/Support/tests/widget_cache_invalidator_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    
    require_once $root . '/app/Modules/Groups/Support/WidgetCache.php';
    require_once $root . '/app/Modules/Groups/Support/WidgetCacheInvalidator.php';

    use WBS\Groups\Support\WidgetCacheInvalidator;

    // Simple mock cache for testing
    class SimpleMockCacheForInvalidator
    {
        private array $store = [];
        private array $deleted = [];

        public function get($key)
        {
            return $this->store[$key] ?? null;
        }

        public function save($key, $value, $ttl = 60)
        {
            $this->store[$key] = $value;
            return true;
        }

        public function delete($key)
        {
            unset($this->store[$key]);
            $this->deleted[$key] = true;
            return true;
        }

        public function getKeys()
        {
            return array_keys($this->store);
        }

        public function getDeletedKeys()
        {
            return array_keys($this->deleted);
        }

        public function clearDeleted()
        {
            $this->deleted = [];
        }
    }

    $cache = new SimpleMockCacheForInvalidator();

    // Test 1: Invalidate user cache
    $cache->save('dashboard_widget_Identity.my_profile_u123', 'html1', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123', 'html2', 180);
    $cache->save('dashboard_widget_Gamification.my_points_u123', 'html3', 180);
    $cache->save('dashboard_widget_Identity.my_profile_u456', 'html4', 180);
    
    $count = WidgetCacheInvalidator::invalidateUser(123, $cache);
    if ($count !== 3) {
        echo "FAIL: Expected 3 invalidations for user 123, got $count\n";
        exit(1);
    }
    
    // Verify the right keys were deleted
    $deleted = $cache->getDeletedKeys();
    if (!in_array('dashboard_widget_Identity.my_profile_u123', $deleted) ||
        !in_array('dashboard_widget_Groups.membership_summary_u123', $deleted) ||
        !in_array('dashboard_widget_Gamification.my_points_u123', $deleted)) {
        echo "FAIL: Wrong keys deleted for user 123\n";
        exit(1);
    }
    
    // Verify user 456's cache is still there
    if (!in_array('dashboard_widget_Identity.my_profile_u456', $cache->getKeys())) {
        echo "FAIL: User 456 cache was incorrectly deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate user cache\n";
    $cache->clearDeleted();

    // Test 2: Invalidate group cache
    $cache->save('dashboard_widget_Groups.membership_summary_u123_g456', 'html1', 180);
    $cache->save('dashboard_widget_Groups.hierarchy_nav_u123_g456', 'html2', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u789_g456', 'html3', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123_g789', 'html4', 180);
    
    $count = WidgetCacheInvalidator::invalidateGroup(456, $cache);
    if ($count !== 3) {
        echo "FAIL: Expected 3 invalidations for group 456, got $count\n";
        exit(1);
    }
    
    $deleted = $cache->getDeletedKeys();
    if (!in_array('dashboard_widget_Groups.membership_summary_u123_g456', $deleted) ||
        !in_array('dashboard_widget_Groups.hierarchy_nav_u123_g456', $deleted) ||
        !in_array('dashboard_widget_Groups.membership_summary_u789_g456', $deleted)) {
        echo "FAIL: Wrong keys deleted for group 456\n";
        exit(1);
    }
    
    // Verify group 789's cache for user 123 is still there
    if (!in_array('dashboard_widget_Groups.membership_summary_u123_g789', $cache->getKeys())) {
        echo "FAIL: Group 789 cache for user 123 was incorrectly deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate group cache\n";
    $cache->clearDeleted();

    // Test 3: Invalidate user-group cache
    $cache->save('dashboard_widget_Groups.membership_summary_u123_g456', 'html1', 180);
    $cache->save('dashboard_widget_Groups.hierarchy_nav_u123_g456', 'html2', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123_g789', 'html3', 180);
    
    $count = WidgetCacheInvalidator::invalidateUserGroup(123, 456, $cache);
    if ($count !== 2) {
        echo "FAIL: Expected 2 invalidations for user 123 group 456, got $count\n";
        exit(1);
    }
    
    $deleted = $cache->getDeletedKeys();
    if (!in_array('dashboard_widget_Groups.membership_summary_u123_g456', $deleted) ||
        !in_array('dashboard_widget_Groups.hierarchy_nav_u123_g456', $deleted)) {
        echo "FAIL: Wrong keys deleted for user 123 group 456\n";
        exit(1);
    }
    
    // Verify other user-group cache is still there
    if (!in_array('dashboard_widget_Groups.membership_summary_u123_g789', $cache->getKeys())) {
        echo "FAIL: Other user-group cache was incorrectly deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate user-group cache\n";
    $cache->clearDeleted();

    // Test 4: Invalidate specific widget
    $cache->save('dashboard_widget_Identity.my_profile_u123', 'html1', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123', 'html2', 180);
    
    $count = WidgetCacheInvalidator::invalidateWidget('Identity.my_profile', 123, $cache);
    if ($count !== 1) {
        echo "FAIL: Expected 1 invalidation for widget, got $count\n";
        exit(1);
    }
    
    if ($cache->get('dashboard_widget_Identity.my_profile_u123') !== null) {
        echo "FAIL: Widget cache not deleted\n";
        exit(1);
    }
    
    // Verify other widget is still there
    if ($cache->get('dashboard_widget_Groups.membership_summary_u123') === null) {
        echo "FAIL: Other widget was incorrectly deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate specific widget\n";
    $cache->clearDeleted();

    // Test 5: Invalidate module
    $cache->save('dashboard_widget_Identity.my_profile_u123', 'html1', 180);
    $cache->save('dashboard_widget_Identity.my_memberships_u123', 'html2', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123', 'html3', 180);
    
    $count = WidgetCacheInvalidator::invalidateModule('Identity', 123, $cache);
    if ($count !== 2) {
        echo "FAIL: Expected 2 invalidations for Identity module, got $count\n";
        exit(1);
    }
    
    $deleted = $cache->getDeletedKeys();
    if (!in_array('dashboard_widget_Identity.my_profile_u123', $deleted) ||
        !in_array('dashboard_widget_Identity.my_memberships_u123', $deleted)) {
        echo "FAIL: Wrong keys deleted for Identity module\n";
        exit(1);
    }
    
    // Verify Groups module cache is still there
    if (!in_array('dashboard_widget_Groups.membership_summary_u123', $cache->getKeys())) {
        echo "FAIL: Groups module cache was incorrectly deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate module cache\n";
    $cache->clearDeleted();

    // Test 6: Invalidate all
    $cache->save('dashboard_widget_Identity.my_profile_u123', 'html1', 180);
    $cache->save('dashboard_widget_Groups.membership_summary_u123', 'html2', 180);
    $cache->save('dashboard_widget_Gamification.my_points_u123', 'html3', 180);
    
    $count = WidgetCacheInvalidator::invalidateAll($cache);
    if ($count !== 3) {
        echo "FAIL: Expected 3 invalidations for all, got $count\n";
        exit(1);
    }
    
    if (count($cache->getKeys()) !== 0) {
        echo "FAIL: Not all cache entries deleted\n";
        exit(1);
    }
    
    echo "ok  invalidate all cache\n";

    echo "\n== 6 passed, 0 failed ==\n";
}
