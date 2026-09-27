<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the discipling leaderboard widget.
 */
final class DisciplingLeaderboardWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noGroupsInScope')) . '</p>';
        }

        // Get users with the most disciples in the scope groups
        $rows = $this->db->query(
            'SELECT u.id, u.display_name, COUNT(*) AS disciple_count
             FROM user_relationships ur
             JOIN users u ON u.id = ur.user_id AND u.organization_id = ?
             JOIN group_members gm ON gm.user_id = u.id AND gm.organization_id = ?
             WHERE ur.organization_id = ?
               AND ur.relationship_type = "discipling"
               AND ur.status = "active"
               AND gm.group_id IN (' . $this->placeholders($groupIds) . ')
             GROUP BY u.id, u.display_name
             ORDER BY disciple_count DESC, u.display_name ASC
             LIMIT ?',
            array_merge([$orgId, $orgId, $orgId], $groupIds, [$limit]),
        )->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noDisciplingData')) . '</p>';
        }

        $html = '<ol class="leaderboard-list">';
        foreach ($rows as $i => $row) {
            $rank = $i + 1;
            $name = esc($row['display_name'] ?? lang('Journey.dashboard.anonymous'));
            $count = esc((string) ($row['disciple_count'] ?? 0));
            $html .= '<li><span class="rank">' . esc((string) $rank) . '</span> <span class="name">' . $name . '</span> <span class="count">' . esc(lang('Journey.dashboard.discipleCount', [$count])) . '</span></li>';
        }
        $html .= '</ol>';

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
