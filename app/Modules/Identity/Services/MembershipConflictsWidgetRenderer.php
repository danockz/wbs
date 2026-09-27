<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the membership conflicts widget for admins.
 */
final class MembershipConflictsWidgetRenderer implements WidgetRenderer
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    public static function create(): static
    {
        return new static(\Config\Database::connect(), new Clock());
    }

    public function render(string $orgId, string $userId, array $scopeData, array $options): string
    {
        $limit = $options['limit'] ?? 10;
        $groupIds = $scopeData['group_ids'] ?? [];

        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.noGroupsInScope')) . '</p>';
        }

        // Find users with multiple active memberships in the scope
        $rows = $this->db->query(
            'SELECT u.id, u.display_name, COUNT(*) AS membership_count, GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ", ") AS groups
             FROM group_members gm
             JOIN users u ON u.id = gm.user_id AND u.organization_id = ?
             JOIN groups g ON g.id = gm.group_id
             WHERE gm.organization_id = ?
               AND gm.status = "active"
               AND gm.group_id IN (' . $this->placeholders($groupIds) . ')
             GROUP BY u.id, u.display_name
             HAVING membership_count > 1
             ORDER BY membership_count DESC, u.display_name ASC
             LIMIT ?',
            array_merge([$orgId, $orgId], $groupIds, [$limit]),
        )->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.noConflicts')) . '</p>';
        }

        $html = '<ul class="conflicts-list">';
        foreach ($rows as $row) {
            $name = esc($row['display_name'] ?? lang('Identity.dashboard.anonymous'));
            $count = esc((string) ($row['membership_count'] ?? 0));
            $groups = esc($row['groups'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="user-name">' . $name . '</span>';
            $html .= '<span class="count">' . esc(lang('Identity.dashboard.inGroups', [$count])) . '</span>';
            $html .= '<span class="groups">' . $groups . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Generate placeholders for IN clause.
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
