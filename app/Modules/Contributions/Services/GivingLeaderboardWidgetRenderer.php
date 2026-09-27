<?php

declare(strict_types=1);

namespace WBS\Contributions\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the giving leaderboard widget.
 */
final class GivingLeaderboardWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Contributions.dashboard.noGroupsInScope')) . '</p>';
        }

        $year = date('Y');

        $rows = $this->db->query(
            'SELECT 
                u.id, u.display_name,
                COALESCE(SUM(c.amount_minor),0) AS total_given
             FROM users u
             LEFT JOIN contributions c ON c.organization_id = ? AND c.user_id = u.id AND c.state = "succeeded" AND YEAR(c.created_at) = ?
             JOIN group_members gm ON gm.organization_id = ? AND gm.user_id = u.id
             WHERE u.organization_id = ?
               AND gm.group_id IN (' . $this->placeholders($groupIds) . ')
             GROUP BY u.id, u.display_name
             HAVING total_given > 0
             ORDER BY total_given DESC, u.display_name ASC
             LIMIT ?',
            array_merge([$orgId, $year, $orgId, $orgId], $groupIds, [$limit]),
        )->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Contributions.dashboard.noGivingData')) . '</p>';
        }

        $html = '<ol class="leaderboard-list">';
        foreach ($rows as $i => $row) {
            $rank = $i + 1;
            $name = esc($row['display_name'] ?? lang('Contributions.dashboard.anonymous'));
            $amount = esc($this->formatAmount((int) ($row['total_given'] ?? 0)));
            $html .= '<li><span class="rank">' . esc((string) $rank) . '</span> <span class="name">' . $name . '</span> <span class="amount">' . $amount . '</span></li>';
        }
        $html .= '</ol>';

        return $html;
    }

    private function formatAmount(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2);
    }

    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
