<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

/**
 * Cache invalidation for dashboard widgets.
 *
 * Provides methods to invalidate cached widget HTML when underlying data changes.
 * This ensures users see fresh data after updates.
 *
 * Usage:
 *   // Invalidate all widgets for a user after profile update
 *   WidgetCacheInvalidator::invalidateUser($userId);
 *
 *   // Invalidate widgets for a specific group after group update
 *   WidgetCacheInvalidator::invalidateGroup($groupId);
 *
 *   // Invalidate a specific widget type for a user
 *   WidgetCacheInvalidator::invalidateWidget('Identity.my_profile', $userId);
 */
final class WidgetCacheInvalidator
{
    private const string CACHE_PREFIX = 'dashboard_widget_';

    /**
     * Invalidate all cached widgets for a specific user.
     *
     * Call this when:
     * - User updates their profile
     * - User changes settings
     * - User's permissions change
     * - User logs out
     *
     * @param int $userId The user ID
     * @param object $cache The cache driver (must have delete() and getKeys() methods)
     * @return int Number of cache entries invalidated
     */
    public static function invalidateUser(int $userId, object $cache): int
    {
        $count = 0;
        $keys = $cache->getKeys();
        $prefix = self::CACHE_PREFIX . '_u' . $userId;
        
        foreach ($keys as $key) {
            if (str_starts_with($key, self::CACHE_PREFIX) && str_contains($key, '_u' . $userId)) {
                $cache->delete($key);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Invalidate all cached widgets for a specific group.
     *
     * Call this when:
     * - Group details are updated
     * - Group membership changes
     * - Group hierarchy changes
     *
     * @param int $groupId The group ID
     * @param object $cache The cache driver
     * @return int Number of cache entries invalidated
     */
    public static function invalidateGroup(int $groupId, object $cache): int
    {
        $count = 0;
        $keys = $cache->getKeys();
        
        foreach ($keys as $key) {
            if (str_starts_with($key, self::CACHE_PREFIX) && str_contains($key, '_g' . $groupId)) {
                $cache->delete($key);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Invalidate all cached widgets for a user and group combination.
     *
     * Call this when:
     * - User's role in a group changes
     * - Group-specific data for user changes
     *
     * @param int $userId The user ID
     * @param int $groupId The group ID
     * @param object $cache The cache driver
     * @return int Number of cache entries invalidated
     */
    public static function invalidateUserGroup(int $userId, int $groupId, object $cache): int
    {
        $count = 0;
        $keys = $cache->getKeys();
        
        foreach ($keys as $key) {
            if (str_starts_with($key, self::CACHE_PREFIX) &&
                str_contains($key, '_u' . $userId) &&
                str_contains($key, '_g' . $groupId)) {
                $cache->delete($key);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Invalidate a specific widget for a user.
     *
     * Call this when:
     * - Specific widget data changes
     * - Need to force refresh of one widget
     *
     * @param string $widgetId The widget ID (e.g., 'Identity.my_profile')
     * @param int $userId The user ID
     * @param object $cache The cache driver
     * @param int|null $groupId Optional group ID for scoped widgets
     * @param string|null $scope Optional scope for scoped widgets
     * @return int Number of cache entries invalidated (0 or 1)
     */
    public static function invalidateWidget(
        string $widgetId,
        int $userId,
        object $cache,
        ?int $groupId = null,
        ?string $scope = null,
    ): int {
        $key = self::buildCacheKey($widgetId, $userId, $groupId, $scope);
        
        if ($cache->get($key) !== null) {
            $cache->delete($key);
            return 1;
        }
        
        return 0;
    }

    /**
     * Invalidate all widgets of a specific type for a user.
     *
     * Call this when:
     * - All widgets of a module need refreshing
     * - Module configuration changes
     *
     * @param string $module The module name (e.g., 'Identity', 'Gamification')
     * @param int $userId The user ID
     * @param object $cache The cache driver
     * @return int Number of cache entries invalidated
     */
    public static function invalidateModule(string $module, int $userId, object $cache): int
    {
        $count = 0;
        $keys = $cache->getKeys();
        $prefix = self::CACHE_PREFIX . $module . '.';
        
        foreach ($keys as $key) {
            if (str_starts_with($key, $prefix) && str_contains($key, '_u' . $userId)) {
                $cache->delete($key);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Invalidate all widgets in a specific section for a user.
     *
     * @param string $section The section name (e.g., 'people', 'gamification')
     * @param int $userId The user ID
     * @param object $cache The cache driver
     * @param GroupDashboardService $service The dashboard service
     * @return int Number of cache entries invalidated
     */
    public static function invalidateSection(
        string $section,
        int $userId,
        object $cache,
        GroupDashboardService $service,
    ): int {
        $count = 0;
        $widgets = $service->collectWidgets();
        
        foreach ($widgets as $widget) {
            if ($widget->section === $section) {
                $count += self::invalidateWidget($widget->id(), $userId, $cache, null, null);
            }
        }
        
        return $count;
    }

    /**
     * Invalidate all dashboard cache.
     *
     * Call this when:
     * - Major system update
     * - Cache corruption suspected
     * - Testing
     *
     * @param object $cache The cache driver
     * @return int Number of cache entries invalidated
     */
    public static function invalidateAll(object $cache): int
    {
        $count = 0;
        $keys = $cache->getKeys();
        
        foreach ($keys as $key) {
            if (str_starts_with($key, self::CACHE_PREFIX)) {
                $cache->delete($key);
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Build a cache key for a widget.
     *
     * @param string $widgetId The widget ID
     * @param int $userId The user ID
     * @param int|null $groupId The group ID
     * @param string|null $scope The scope
     * @return string
     */
    private static function buildCacheKey(
        string $widgetId,
        int $userId,
        ?int $groupId,
        ?string $scope,
    ): string {
        $parts = [self::CACHE_PREFIX . $widgetId, 'u' . $userId];
        
        if ($groupId !== null) {
            $parts[] = 'g' . $groupId;
        }
        
        if ($scope !== null) {
            $parts[] = 's' . $scope;
        }
        
        return implode('_', $parts);
    }
}
