<?php

/**
 * Widget Cache tests
 *
 *   php app/Modules/Groups/Support/tests/widget_cache_test.php
 */

namespace {
    $root = dirname(__DIR__, 5);
    
    require_once $root . '/app/Modules/Groups/Support/WidgetCache.php';

    use WBS\Groups\Support\WidgetCache;

    // Simple mock cache for testing
    class SimpleMockCache
    {
        private array $store = [];
        private array $expiry = [];

        public function get($key)
        {
            if (isset($this->store[$key]) && (!isset($this->expiry[$key]) || $this->expiry[$key] > time())) {
                return $this->store[$key];
            }
            return null;
        }

        public function save($key, $value, $ttl = 60)
        {
            $this->store[$key] = $value;
            $this->expiry[$key] = $ttl > 0 ? time() + $ttl : null;
            return true;
        }

        public function delete($key)
        {
            unset($this->store[$key], $this->expiry[$key]);
            return true;
        }

        public function getKeys()
        {
            return array_keys($this->store);
        }
    }

    // Test 1: Cache can be created
    $mockCache = new SimpleMockCache();
    $cache = new WidgetCache($mockCache, 180);
    echo "ok  cache created\n";

    // Test 2: Cache key generation
    $key = $cache->key('test.widget', 123, 456, 'membership');
    if ($key !== 'dashboard_widget_test.widget_u123_g456_smembership') {
        echo "FAIL: cache key mismatch: $key\n";
        exit(1);
    }
    echo "ok  cache key generation\n";

    // Test 3: Cache key with null group
    $key2 = $cache->key('test.widget', 123, null, 'self');
    if ($key2 !== 'dashboard_widget_test.widget_u123_sself') {
        echo "FAIL: cache key with null group mismatch: $key2\n";
        exit(1);
    }
    echo "ok  cache key with null group\n";

    // Test 4: Cache set and get
    $cache->set('test.widget', 123, 456, '<div>Test HTML</div>', 'list', 'membership');
    $html = $cache->get('test.widget', 123, 456, 'membership');
    if ($html !== '<div>Test HTML</div>') {
        echo "FAIL: cache get failed\n";
        exit(1);
    }
    echo "ok  cache set and get\n";

    // Test 5: Cache miss returns null
    $miss = $cache->get('nonexistent', 123, 456, 'membership');
    if ($miss !== null) {
        echo "FAIL: cache should return null for miss\n";
        exit(1);
    }
    echo "ok  cache miss returns null\n";

    // Test 6: Cache has check
    if (! $cache->has('test.widget', 123, 456, 'membership')) {
        echo "FAIL: cache has check failed\n";
        exit(1);
    }
    echo "ok  cache has check\n";

    // Test 7: Cache delete
    $cache->delete('test.widget', 123, 456, 'membership');
    if ($cache->get('test.widget', 123, 456, 'membership') !== null) {
        echo "FAIL: cache delete failed\n";
        exit(1);
    }
    echo "ok  cache delete\n";

    // Test 8: Clear user cache
    $cache->set('test.widget1', 123, 456, 'html1', 'list');
    $cache->set('test.widget2', 123, 456, 'html2', 'list');
    $cache->set('test.widget3', 123, 789, 'html3', 'list');
    $cache->clearUser(123);
    if ($cache->get('test.widget1', 123, 456) !== null || 
        $cache->get('test.widget2', 123, 456) !== null ||
        $cache->get('test.widget3', 123, 789) !== null) {
        echo "FAIL: clearUser didn't clear all user cache\n";
        exit(1);
    }
    echo "ok  clear user cache\n";

    // Test 9: Clear group cache
    $cache->set('test.widget1', 123, 456, 'html1', 'list');
    $cache->set('test.widget2', 123, 456, 'html2', 'list');
    $cache->set('test.widget3', 999, 456, 'html3', 'list');
    $cache->clearGroup(456);
    if ($cache->get('test.widget1', 123, 456) !== null || 
        $cache->get('test.widget2', 123, 456) !== null ||
        $cache->get('test.widget3', 999, 456) !== null) {
        echo "FAIL: clearGroup didn't clear all group cache\n";
        exit(1);
    }
    echo "ok  clear group cache\n";

    // Test 10: Clear all cache
    $cache->set('test.widget1', 123, 456, 'html1', 'list');
    $cache->set('test.widget2', 123, 456, 'html2', 'list');
    $cache->clearAll();
    if ($cache->get('test.widget1', 123, 456) !== null || 
        $cache->get('test.widget2', 123, 456) !== null) {
        echo "FAIL: clearAll didn't clear all cache\n";
        exit(1);
    }
    echo "ok  clear all cache\n";

    echo "\n== 10 passed, 0 failed ==\n";
}
