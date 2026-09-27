<?php

declare(strict_types=1);

namespace WBS\Groups\Support;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;

/**
 * Caching wrapper for widget renderers.
 *
 * Wraps a WidgetRenderer to add caching support. The cached version will
 * return cached HTML for subsequent requests until the cache expires.
 */
final class CachingWidgetRenderer implements WidgetRenderer
{
    private readonly WidgetRenderer $inner;
    private readonly WidgetCache $cache;

    /**
     * @param WidgetRenderer $inner The underlying renderer to wrap
     * @param WidgetCache $cache The cache instance
     */
    public function __construct(WidgetRenderer $inner, WidgetCache $cache)
    {
        $this->inner = $inner;
        $this->cache = $cache;
    }

    /**
     * @inheritDoc
     */
    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $widgetId = $scopeData['widget_id'] ?? '';
        $groupId = $scopeData['group_id'] ?? null;
        $scope = $scopeData['scope'] ?? null;
        
        // Try to get from cache
        $cached = $this->cache->get($widgetId, (int) $userId, $groupId, $scope);
        
        if ($cached !== null) {
            return $cached;
        }
        
        // Render and cache
        $html = $this->inner->render($orgId, $userId, $scopeData, $options);
        
        $cacheType = $scopeData['cache_type'] ?? null;
        $this->cache->set(
            $widgetId,
            (int) $userId,
            $groupId,
            $html,
            $cacheType
        );
        
        return $html;
    }

    /**
     * Get the cache key for this widget and scope.
     *
     * @param GroupDashboardWidget $widget
     * @param GroupDashboardScope $scope
     * @param int $userId
     * @return string
     */
    private function getCacheKey(
        GroupDashboardWidget $widget,
        GroupDashboardScope $scope,
        int $userId,
    ): string {
        return $widget->id . '_' . $userId . '_' . ($scope->groupId ?? 'null') . '_' . ($scope->scope ?? 'null');
    }

    /**
     * Invalidate the cache for this widget.
     *
     * @param GroupDashboardWidget $widget
     * @param GroupDashboardScope $scope
     * @param int $userId
     */
    public function invalidate(
        GroupDashboardWidget $widget,
        GroupDashboardScope $scope,
        int $userId,
    ): void {
        $this->cache->delete(
            $widget->id,
            $userId,
            $scope->groupId,
            $scope->scope
        );
    }

    /**
     * Get the underlying renderer.
     *
     * @return WidgetRenderer
     */
    public function getInner(): WidgetRenderer
    {
        return $this->inner;
    }
}
