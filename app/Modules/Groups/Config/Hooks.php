<?php

declare(strict_types=1);

namespace WBS\Groups\Config;

use CodeIgniter\Events\Events;
use WBS\Groups\Support\WidgetCacheInvalidator;

/**
 * Hooks for the Groups module.
 *
 * Sets up event listeners for cache invalidation and other module-level concerns.
 */
class Hooks
{
    /**
     * Register event listeners.
     *
     * @return void
     */
    public static function register(): void
    {
        // Invalidate dashboard cache when user updates their profile
        Events::on('userUpdated', function ($userId) {
            self::invalidateUserDashboard($userId);
        });

        // Invalidate dashboard cache when group is updated
        Events::on('groupUpdated', function ($groupId) {
            self::invalidateGroupDashboard($groupId);
        });

        // Invalidate dashboard cache when group membership changes
        Events::on('membershipChanged', function ($userId, $groupId) {
            self::invalidateUserGroupDashboard($userId, $groupId);
        });

        // Invalidate dashboard cache when permissions change
        Events::on('permissionsUpdated', function ($userId) {
            self::invalidateUserDashboard($userId);
        });
    }

    /**
     * Invalidate all dashboard widgets for a user.
     *
     * @param int|string $userId
     */
    private static function invalidateUserDashboard(int|string $userId): void
    {
        try {
            $cache = service('cache');
            WidgetCacheInvalidator::invalidateUser((int) $userId, $cache);
        } catch (\Throwable $e) {
            // Log error but don't break the application
            log_message('error', 'Failed to invalidate user dashboard cache: ' . $e->getMessage());
        }
    }

    /**
     * Invalidate all dashboard widgets for a group.
     *
     * @param int|string $groupId
     */
    private static function invalidateGroupDashboard(int|string $groupId): void
    {
        try {
            $cache = service('cache');
            WidgetCacheInvalidator::invalidateGroup((int) $groupId, $cache);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to invalidate group dashboard cache: ' . $e->getMessage());
        }
    }

    /**
     * Invalidate all dashboard widgets for a user and group combination.
     *
     * @param int|string $userId
     * @param int|string $groupId
     */
    private static function invalidateUserGroupDashboard(int|string $userId, int|string $groupId): void
    {
        try {
            $cache = service('cache');
            WidgetCacheInvalidator::invalidateUserGroup((int) $userId, (int) $groupId, $cache);
        } catch (\Throwable $e) {
            log_message('error', 'Failed to invalidate user-group dashboard cache: ' . $e->getMessage());
        }
    }
}
