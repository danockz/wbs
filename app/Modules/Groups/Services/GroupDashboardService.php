<?php

declare(strict_types=1);

namespace WBS\Groups\Services;

use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\CachingWidgetRenderer;
use WBS\Groups\Support\GroupDashboardScope;
use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;
use WBS\Groups\Support\WidgetCache;
use WBS\Groups\Support\WidgetProviderDiscovery;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;

/**
 * Hierarchical group dashboard service.
 *
 * Collects widgets from all registered providers, filters by the viewer's
 * effective permissions and the current group scope, and renders the visible
 * widgets. Server-side rendered, mobile-first, fail-closed on permissions/config.
 */
final class GroupDashboardService
{
    /** @var list<GroupDashboardWidgetProvider> */
    private array $providers = [];

    /** @var array<string,WidgetRenderer> Cache of renderer instances */
    private array $renderers = [];

    /** @var bool Whether auto-discovery has been performed */
    private bool $discovered = false;

    /** @var WidgetCache|null Cache instance for widget HTML */
    private ?WidgetCache $cache = null;

    /** @var bool Whether caching is enabled */
    private bool $cachingEnabled = false;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        bool $autoDiscover = true,
        ?CacheInterface $cacheDriver = null,
        bool $enableCaching = false,
        int $cacheTtl = 180,
    ) {
        if ($autoDiscover) {
            $this->discoverProviders();
        }
        
        if ($enableCaching && $cacheDriver !== null) {
            $this->cache = new WidgetCache($cacheDriver, $cacheTtl);
            $this->cachingEnabled = true;
        }
    }

    /**
     * Register a widget provider (called by module bootstrap).
     */
    public function registerProvider(GroupDashboardWidgetProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * Auto-discover and register all widget providers from known namespaces.
     */
    private function discoverProviders(): void
    {
        if ($this->discovered) {
            return;
        }
        
        WidgetProviderDiscovery::discoverAndRegister($this);
        $this->discovered = true;
    }

    /**
     * Manually trigger discovery (useful if providers are added after construction).
     */
    public function discover(): void
    {
        $this->discoverProviders();
    }

    /**
     * Collect all widgets from registered providers.
     *
     * @return list<GroupDashboardWidget>
     */
    public function collectWidgets(): array
    {
        $widgets = [];
        foreach ($this->providers as $provider) {
            foreach ($provider::widgets() as $widget) {
                $widgets[] = $widget;
            }
        }
        return $widgets;
    }

    /**
     * Build the dashboard payload for a user and scope.
     *
     * @param array<string,bool> $effectivePermissions User's effective permissions
     * @param array<string,array<string,mixed>> $effectiveConfigs Effective configs by group_id
     */
    public function buildDashboard(
        GroupDashboardScope $scope,
        array $effectivePermissions,
        array $effectiveConfigs,
    ): Result {
        $widgets = $this->collectWidgets();
        $visible = [];
        $sections = [];

        foreach ($widgets as $widget) {
            if (! $widget->visible($scope->orgId, $scope->userId, $effectivePermissions, $effectiveConfigs)) {
                continue;
            }

            $visible[] = $widget;
            if (! isset($sections[$widget->section])) {
                $sections[$widget->section] = [];
            }
            $sections[$widget->section][] = $widget;
        }

        // Sort sections by first widget order, then widgets within each section
        uksort($sections, function (string $a, string $b) use ($sections): int {
            $firstA = reset($sections[$a]);
            $firstB = reset($sections[$b]);
            if ($firstA === false || $firstB === false) {
                return 0;
            }
            return $firstA->order <=> $firstB->order;
        });

        foreach ($sections as &$sectionWidgets) {
            usort($sectionWidgets, fn ($a, $b) => $a->order <=> $b->order);
        }
        unset($sectionWidgets);

        return Result::ok([
            'scope' => [
                'org_id' => $scope->orgId,
                'user_id' => $scope->userId,
                'group_id' => $scope->groupId,
                'mode' => $scope->mode,
            ],
            'sections' => $sections,
            'widget_count' => count($visible),
            'as_of' => $this->clock->nowUtcString(),
        ]);
    }

    /**
     * Render a specific widget's HTML.
     *
     * @param array<string,mixed> $scopeData Resolved scope data for the widget
     */
    public function renderWidget(GroupDashboardWidget $widget, array $scopeData): string
    {
        if ($widget->rendererClass === '') {
            return '';
        }

        if (! class_exists($widget->rendererClass)) {
            return '';
        }

        $rendererClass = $widget->rendererClass;
        
        if (! isset($this->renderers[$rendererClass])) {
            // Try to use create() factory method if available
            if (method_exists($rendererClass, 'create')) {
                $renderer = $rendererClass::create();
            } else {
                $renderer = new $rendererClass();
            }
            if (! $renderer instanceof WidgetRenderer) {
                return '';
            }
            
            // Wrap with caching if enabled
            if ($this->cachingEnabled && $this->cache !== null) {
                $renderer = new CachingWidgetRenderer($renderer, $this->cache);
            }
            
            $this->renderers[$rendererClass] = $renderer;
        }

        // Add widget metadata to scope data for cache key generation
        $scopeData['widget_id'] = $widget->id;
        $scopeData['cache_type'] = $widget->cacheType;

        return $this->renderers[$rendererClass]->render(
            $scopeData['org_id'] ?? '',
            $scopeData['user_id'] ?? '',
            $scopeData,
            $scopeData['options'] ?? [],
        );
    }

    /**
     * Get the user's in-scope groups for the switcher.
     *
     * Returns groups the user is a member of, plus any groups they can view
     * via permission bits. Ordered by hierarchy path.
     *
     * @return list<array{id:string, name:string, path:string, role:string}>
     */
    public function getUserGroups(string $orgId, string $userId): array
    {
        $rows = $this->db->table('group_members gm')
            ->select('g.id, g.name, g.path, gm.role, gm.joined_at')
            ->join('groups g', 'g.id = gm.group_id', 'inner')
            ->where('gm.organization_id', $orgId)
            ->where('gm.user_id', $userId)
            ->orderBy('g.path', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id' => (string) ($r['id'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'path' => (string) ($r['path'] ?? ''),
            'role' => (string) ($r['role'] ?? ''),
            'joined_at' => $r['joined_at'] ?? null,
        ], $rows);
    }

    /**
     * Resolve the scope data for a widget based on its scope setting.
     *
     * @param string $widgetScope The widget's scope setting
     * @param string $orgId
     * @param string $userId
     * @param string|null $selectedGroupId
     *
     * @return array<string,mixed>
     */
    public function resolveWidgetScope(
        string $widgetScope,
        string $orgId,
        string $userId,
        ?string $selectedGroupId = null,
    ): array {
        $scopeData = [
            'org_id' => $orgId,
            'user_id' => $userId,
            'group_id' => $selectedGroupId,
        ];

        switch ($widgetScope) {
            case 'self':
                // Only the user's own data, regardless of group
                $scopeData['subject_id'] = $userId;
                $scopeData['subject_type'] = 'user';
                break;

            case 'membership':
                // Groups the user is a member of
                $scopeData['group_ids'] = array_map(
                    fn (array $g) => $g['id'],
                    $this->getUserGroups($orgId, $userId),
                );
                break;

            case 'membership_desc':
                // Membership groups + their descendants
                $groups = $this->getUserGroups($orgId, $userId);
                $groupIds = array_map(fn (array $g) => $g['id'], $groups);
                $descendants = $this->getDescendantGroupIds($orgId, $groupIds);
                $scopeData['group_ids'] = array_unique(array_merge($groupIds, $descendants));
                break;

            case 'descendants':
                // Only descendants of the selected group (or user's groups if no selection)
                if ($selectedGroupId !== null) {
                    $scopeData['group_ids'] = $this->getDescendantGroupIds($orgId, [$selectedGroupId]);
                } else {
                    $groups = $this->getUserGroups($orgId, $userId);
                    $scopeData['group_ids'] = $this->getDescendantGroupIds($orgId, array_map(fn (array $g) => $g['id'], $groups));
                }
                break;

            case 'ancestors':
                // Ancestors of the selected group (or user's groups)
                if ($selectedGroupId !== null) {
                    $scopeData['group_ids'] = $this->getAncestorGroupIds($orgId, $selectedGroupId);
                } else {
                    $groups = $this->getUserGroups($orgId, $userId);
                    $allAncestors = [];
                    foreach ($groups as $g) {
                        $allAncestors = array_merge($allAncestors, $this->getAncestorGroupIds($orgId, $g['id']));
                    }
                    $scopeData['group_ids'] = array_unique($allAncestors);
                }
                break;

            case 'ancestor_only':
                // Only ancestor groups (not including the selected group itself)
                if ($selectedGroupId !== null) {
                    $ancestors = $this->getAncestorGroupIds($orgId, $selectedGroupId);
                    $scopeData['group_ids'] = $ancestors;
                } else {
                    $scopeData['group_ids'] = [];
                }
                break;
        }

        return $scopeData;
    }

    /**
     * Get all descendant group IDs for the given group IDs.
     */
    private function getDescendantGroupIds(string $orgId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $rows = $this->db->table('groups')
            ->select('id, path')
            ->where('organization_id', $orgId)
            ->whereIn('id', $groupIds)
            ->get()->getResultArray();

        if ($rows === []) {
            return [];
        }

        $paths = array_map(fn (array $r) => $r['path'], $rows);
        $descendants = $this->db->table('groups')
            ->select('id')
            ->where('organization_id', $orgId)
            ->groupStart();
        foreach ($paths as $path) {
            $this->db->orLike('path', $path . '%/', 'none');
        }
        $this->db->groupEnd()
            ->whereNotIn('id', $groupIds)
            ->get()->getResultArray();

        return array_map(fn (array $r) => (string) $r['id'], $descendants);
    }

    /**
     * Get all ancestor group IDs for a given group ID (excluding the group itself).
     */
    private function getAncestorGroupIds(string $orgId, string $groupId): array
    {
        $row = $this->db->table('groups')
            ->select('path')
            ->where('organization_id', $orgId)
            ->where('id', $groupId)
            ->get()->getRowArray();

        if ($row === null || ! isset($row['path'])) {
            return [];
        }

        $path = trim($row['path'], '/');
        if ($path === '') {
            return [];
        }

        $ids = explode('/', $path);
        return array_filter($ids, fn ($id) => $id !== '');
    }
}
