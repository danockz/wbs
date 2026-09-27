<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the recent members widget for leaders.
 */
final class RecentMembersWidgetRenderer implements WidgetRenderer
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
        $limit = $options['limit'] ?? 5;
        $groupIds = $scopeData['group_ids'] ?? [];

        if ($groupIds === []) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.noGroupsInScope')) . '</p>';
        }

        // Get recent members in the scope groups
        $rows = $this->db->table('group_members gm')
            ->select('u.id, u.display_name, u.email, gm.joined_at, gm.role')
            ->join('users u', 'u.id = gm.user_id', 'inner')
            ->where('gm.organization_id', $orgId)
            ->whereIn('gm.group_id', $groupIds)
            ->where('gm.status', 'active')
            ->orderBy('gm.joined_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Identity.dashboard.noRecentMembers')) . '</p>';
        }

        $html = '<ul class="recent-members-list">';
        foreach ($rows as $row) {
            $name = esc($row['display_name'] ?? lang('Identity.dashboard.anonymous'));
            $email = esc($row['email'] ?? '');
            $role = esc($row['role'] ?? '');
            $joined = esc($this->formatDate($row['joined_at'] ?? ''));
            
            $html .= '<li>';
            $html .= '<span class="member-name">' . $name . '</span>';
            if ($email !== '') {
                $html .= '<span class="member-email">' . $email . '</span>';
            }
            if ($role !== '') {
                $html .= '<span class="member-role">' . $role . '</span>';
            }
            $html .= '<span class="joined-date">' . esc(lang('Identity.dashboard.joinedOn', [$joined])) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    private function formatDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts ? date('M j', $ts) : $date;
    }
}
