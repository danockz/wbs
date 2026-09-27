<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

use CodeIgniter\Cache\CacheInterface;
use DateTimeInterface;

/**
 * Caching layer for dashboard widgets.
 *
 * Caches rendered widget HTML and widget data to reduce database queries
 * and improve dashboard loading performance. Implements a multi-level cache
 * strategy with different TTLs based on widget type and data volatility.
 */
final class WidgetCache
{
    private const string PREFIX = 'dashboard_widget_';
    
    /** Default TTL in seconds for different widget types */
    private const array DEFAULT_TTLS = [
        'static' => 3600,      // 1 hour - static content
        'summary' => 300,      // 5 minutes - summary stats
        'list' => 180,         // 3 minutes - lists of items
        'realtime' => 60,      // 1 minute - near real-time data
        'live' => 0,           // No cache - live data
    ];

    /**
     * @param object $cache Cache driver with get(), save(), delete(), getKeys() methods
     * @param int $defaultTtl Default TTL in seconds if not specified per widget
     */
    public function __construct(
        private readonly object $cache,
        private readonly int $defaultTtl = 180,
    ) {
    }

    /**
     * Generate a cache key for a widget.
     *
     * @param string $widgetId The widget identifier
     * @param int $userId The user ID
     * @param int|null $groupId The current group ID (or null for user scope)
     * @param string|null $scope The widget scope
     * @return string
     */
    public function key(string $widgetId, int $userId, ?int $groupId, ?string $scope = null): string
    {
        $parts = [
            self::PREFIX . $widgetId,
            'u' . $userId,
        ];
        
        if ($groupId !== null) {
            $parts[] = 'g' . $groupId;
        }
        
        if ($scope !== null) {
            $parts[] = 's' . $scope;
        }
        
        return implode('_', $parts);
    }

    /**
     * Get cached widget HTML.
     *
     * @param string $widgetId The widget identifier
     * @param int $userId The user ID
     * @param int|null $groupId The current group ID
     * @param string|null $scope The widget scope
     * @return string|null Cached HTML or null if not cached
     */
    public function get(string $widgetId, int $userId, ?int $groupId, ?string $scope = null): ?string
    {
        $key = $this->key($widgetId, $userId, $groupId, $scope);
        return $this->cache->get($key);
    }

    /**
     * Store rendered widget HTML in cache.
     *
     * @param string $widgetId The widget identifier
     * @param int $userId The user ID
     * @param int|null $groupId The current group ID
     * @param string $html The rendered HTML
     * @param string|null $cacheType The cache type (static, summary, list, realtime, live)
     * @param string|null $scope The widget scope
     * @param DateTimeInterface|int|null $ttl TTL in seconds or DateTimeInterface
     */
    public function set(
        string $widgetId,
        int $userId,
        ?int $groupId,
        string $html,
        ?string $cacheType = null,
        ?string $scope = null,
        DateTimeInterface|int|null $ttl = null,
    ): void {
        $key = $this->key($widgetId, $userId, $groupId, $scope);
        $seconds = $this->resolveTtl($cacheType, $ttl);
        
        if ($seconds > 0) {
            $this->cache->save($key, $html, $seconds);
        }
    }

    /**
     * Delete cached widget HTML.
     *
     * @param string $widgetId The widget identifier
     * @param int $userId The user ID
     * @param int|null $groupId The current group ID
     * @param string|null $scope The widget scope
     */
    public function delete(string $widgetId, int $userId, ?int $groupId, ?string $scope = null): void
    {
        $key = $this->key($widgetId, $userId, $groupId, $scope);
        $this->cache->delete($key);
    }

    /**
     * Clear all cached widgets for a user.
     *
     * @param int $userId The user ID
     */
    public function clearUser(int $userId): void
    {
        $prefix = self::PREFIX . '_u' . $userId;
        $keys = $this->cache->getKeys();
        foreach ($keys as $key) {
            if (str_starts_with($key, self::PREFIX) && str_contains($key, '_u' . $userId)) {
                $this->cache->delete($key);
            }
        }
    }

    /**
     * Clear all cached widgets for a group.
     *
     * @param int $groupId The group ID
     */
    public function clearGroup(int $groupId): void
    {
        $prefix = self::PREFIX . '_g' . $groupId;
        $keys = $this->cache->getKeys();
        foreach ($keys as $key) {
            if (str_starts_with($key, self::PREFIX) && str_contains($key, '_g' . $groupId)) {
                $this->cache->delete($key);
            }
        }
    }

    /**
     * Clear all cached widgets for a user and group combination.
     *
     * @param int $userId The user ID
     * @param int $groupId The group ID
     */
    public function clearUserGroup(int $userId, int $groupId): void
    {
        $keys = $this->cache->getKeys();
        foreach ($keys as $key) {
            if (str_starts_with($key, self::PREFIX) && 
                str_contains($key, '_u' . $userId) && 
                str_contains($key, '_g' . $groupId)) {
                $this->cache->delete($key);
            }
        }
    }

    /**
     * Clear all dashboard widget cache.
     */
    public function clearAll(): void
    {
        $this->clearByPrefix(self::PREFIX);
    }

    /**
     * Check if a widget is cached.
     *
     * @param string $widgetId The widget identifier
     * @param int $userId The user ID
     * @param int|null $groupId The current group ID
     * @param string|null $scope The widget scope
     * @return bool
     */
    public function has(string $widgetId, int $userId, ?int $groupId, ?string $scope = null): bool
    {
        $key = $this->key($widgetId, $userId, $groupId, $scope);
        return $this->cache->get($key) !== null;
    }

    /**
     * Resolve TTL from cache type or provided value.
     *
     * @param string|null $cacheType The cache type
     * @param DateTimeInterface|int|null $ttl Provided TTL
     * @return int TTL in seconds
     */
    private function resolveTtl(?string $cacheType, DateTimeInterface|int|null $ttl): int
    {
        if ($ttl !== null) {
            if ($ttl instanceof DateTimeInterface) {
                return max(0, $ttl->getTimestamp() - time());
            }
            return max(0, (int) $ttl);
        }
        
        if ($cacheType !== null && isset(self::DEFAULT_TTLS[$cacheType])) {
            return self::DEFAULT_TTLS[$cacheType];
        }
        
        return $this->defaultTtl;
    }

    /**
     * Clear cache entries by prefix.
     *
     * @param string $prefix The prefix to match
     */
    private function clearByPrefix(string $prefix): void
    {
        // Get all cache keys and filter by prefix
        // This is a simple implementation; production may need optimization
        $keys = $this->cache->getKeys();
        
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix)) {
                $this->cache->delete($key);
            }
        }
    }
}
