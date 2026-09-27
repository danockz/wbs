<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Groups\Services\GroupDashboardService;
use WBS\Groups\Support\GroupDashboardScope;
use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Hierarchical group dashboard controller.
 *
 * Provides a dynamic, group-aware dashboard that pulls widgets from every
 * module. Server-rendered, mobile-first, fail-closed on permissions/config.
 */
final class GroupDashboardController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * GET me/groups — the hierarchical group dashboard.
     *
     * Shows widgets from all modules for the user's in-scope groups.
     * Supports a group switcher to focus on a specific group.
     */
    public function index()
    {
        $orgId = $this->orgId();
        $userId = $this->currentUserId();
        $groupId = $this->field('group_id'); // optional: focus on a specific group

        $dashboardService = GroupServices::groupDashboard();

        // Get user's groups for the switcher
        $userGroups = $dashboardService->getUserGroups($orgId, $userId);

        // If no group_id provided and user has groups, default to first group
        if ($groupId === null && $userGroups !== []) {
            $groupId = $userGroups[0]['id'] ?? null;
        }

        // Build scope
        $scope = new GroupDashboardScope(
            orgId: $orgId,
            userId: $userId,
            groupId: $groupId,
            mode: 'switcher',
            groupConfigs: $this->loadGroupConfigs($orgId, $userGroups),
        );

        // Get effective permissions and configs for the user
        $effectivePermissions = $this->getEffectivePermissions($orgId, $userId);
        $effectiveConfigs = $this->loadEffectiveConfigs($orgId, $userGroups);

        // Build dashboard - get visible widgets
        $result = $dashboardService->buildDashboard($scope, $effectivePermissions, $effectiveConfigs);

        if (! $result->ok) {
            return $this->respondWith($result);
        }

        $payload = $result->data;
        $payload['user_groups'] = $userGroups;
        $payload['current_group_id'] = $groupId;

        // Collect all widgets and render their HTML
        $allWidgets = $dashboardService->collectWidgets();
        $sections = $payload['sections'] ?? [];
        
        // Resolve scope data for each widget based on its scope setting
        $resolvedScopes = [];
        foreach ($allWidgets as $widget) {
            $resolvedScopes[$widget->id()] = $dashboardService->resolveWidgetScope(
                $widget->scope,
                $orgId,
                $userId,
                $groupId,
            );
        }

        // Render each widget's HTML
        $renderedWidgets = [];
        foreach ($sections as $sectionKey => $sectionWidgets) {
            foreach ($sectionWidgets as $widget) {
                $widgetId = $widget->id();
                $scopeData = $resolvedScopes[$widgetId] ?? [];
                
                // Add options if any
                $scopeData['options'] = [];
                
                $html = $this->renderWidget($dashboardService, $widget, $scopeData);
                $renderedWidgets[$widgetId] = [
                    'widget' => $widget,
                    'html' => $html,
                    'scopeData' => $scopeData,
                ];
            }
        }

        $payload['rendered_widgets'] = $renderedWidgets;

        return $this->respondWith(
            Result::ok($payload),
            htmlView: 'WBS\Groups\Views\group_dashboard',
            viewData: [
                'result' => $payload,
                'title' => 'Group Dashboard',
                'csrf' => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /**
     * Render a widget's HTML.
     */
    private function renderWidget(
        GroupDashboardService $service,
        GroupDashboardWidget $widget,
        array $scopeData,
    ): string {
        // If widget has a renderer, use it
        if ($widget->rendererClass !== '' && class_exists($widget->rendererClass)) {
            try {
                $renderer = $widget->rendererClass::create();
                return $renderer->render(
                    $scopeData['org_id'] ?? '',
                    $scopeData['user_id'] ?? '',
                    $scopeData,
                    $scopeData['options'] ?? [],
                );
            } catch (\Throwable $e) {
                // Log error but don't crash the dashboard
                log_message('error', 'Dashboard widget render error: ' . $e->getMessage());
                return '<p class="widget-error">' . esc(lang('Groups.dashboard.widgetError')) . '</p>';
            }
        }

        // Fallback placeholder
        $label = lang($widget->labelKey) !== $widget->labelKey ? lang($widget->labelKey) : $widget->labelKey;
        return '<p class="widget-placeholder">' . esc(lang('Groups.dashboard.widgetComingSoon', [$label])) . '</p>';
    }

    /**
     * Load effective group configs for the user's groups.
     *
     * @return array<string,array<string,array<string,mixed>>> group_id -> capability -> config
     */
    private function loadGroupConfigs(string $orgId, array $userGroups): array
    {
        $groupIds = array_map(fn (array $g) => $g['id'], $userGroups);
        if ($groupIds === []) {
            return [];
        }

        $configs = GroupServices::effectiveConfig()->forGroups($orgId, $groupIds);
        $result = [];
        foreach ($configs as $config) {
            $groupId = $config['group_id'] ?? '';
            if ($groupId === '') {
                continue;
            }
            $capability = $config['capability'] ?? '';
            if ($capability === '') {
                continue;
            }
            $result[$groupId][$capability] = $config['resolved'] ?? [];
        }

        return $result;
    }

    /**
     * Load effective configs flattened for visibility checks.
     *
     * @return array<string,array<string,mixed>> capability -> config
     */
    private function loadEffectiveConfigs(string $orgId, array $userGroups): array
    {
        $groupIds = array_map(fn (array $g) => $g['id'], $userGroups);
        if ($groupIds === []) {
            return [];
        }

        $configs = GroupServices::effectiveConfig()->forGroups($orgId, $groupIds);
        $result = [];
        foreach ($configs as $config) {
            $capability = $config['capability'] ?? '';
            if ($capability === '') {
                continue;
            }
            // For now, use the first group's config for each capability
            // In practice, widgets would check their specific group's config
            if (! isset($result[$capability])) {
                $result[$capability] = $config['resolved'] ?? [];
            }
        }

        return $result;
    }

    /**
     * Get the user's effective permissions.
     *
     * This is a simplified version that checks the user's direct permissions
     * and role-based grants. In production, this should integrate with the
     * AuthorizationService.
     *
     * @return array<string,bool>
     */
    private function getEffectivePermissions(string $orgId, string $userId): array
    {
        $permissions = [];

        // Check for admin permissions
        $isAdmin = $this->db->table('role_assignments')
            ->where('organization_id', $orgId)
            ->where('subject_id', $userId)
            ->where('subject_type', 'user')
            ->whereIn('role_code', ['admin', 'super_admin'])
            ->countAllResults() > 0;

        if ($isAdmin) {
            $permissions['admin.manage'] = true;
            $permissions['report.view'] = true;
            $permissions['report.export'] = true;
            $permissions['identity.manage'] = true;
            $permissions['contribution.manage'] = true;
            $permissions['course.create'] = true;
            $permissions['event.create'] = true;
            $permissions['meeting.manage'] = true;
            $permissions['notification.send'] = true;
            $permissions['stream.moderate'] = true;
            $permissions['access.request.approve'] = true;
        }

        // Check for specific permissions
        $grants = $this->db->table('role_assignments ra')
            ->select('rp.permission_code')
            ->join('role_permissions rp', 'rp.role_id = ra.role_id', 'inner')
            ->join('roles r', 'r.id = ra.role_id', 'inner')
            ->where('ra.organization_id', $orgId)
            ->where('ra.subject_id', $userId)
            ->where('ra.subject_type', 'user')
            ->where('r.status', 'active')
            ->get()->getResultArray();

        foreach ($grants as $grant) {
            $code = $grant['permission_code'] ?? '';
            if ($code !== '') {
                $permissions[$code] = true;
            }
        }

        return $permissions;
    }
}
