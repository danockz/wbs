<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Groups\Services\GroupDashboardService;
use WBS\Groups\Support\GroupDashboardScope;
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
        // Ensure the dashboard service is available
        GroupServices::groupDashboard();
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

        // Get effective permissions for the user
        $effectivePermissions = $this->getEffectivePermissions($orgId, $userId);

        // Build dashboard
        $result = $dashboardService->buildDashboard($scope, $effectivePermissions, []);

        if (! $result->ok) {
            return $this->respondWith($result);
        }

        $payload = $result->data;
        $payload['user_groups'] = $userGroups;
        $payload['current_group_id'] = $groupId;

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
        // For now, return a conservative set based on the user's memberships
        // The actual implementation should query the authorization service
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
