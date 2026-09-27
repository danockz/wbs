<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group leaderboard widget (top members by points).
 */
final class GroupLeaderboardWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noGroupsInScope')) . '</p>';
        }

        // Get current season
        $season = $this->db->table('gamification_seasons')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('season_year', 'DESC')
            ->get()->getRowArray();

        if ($season === null) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noActiveSeason')) . '</p>';
        }

        $seasonId = $season['id'];

        // Get top users by points in the scope groups
        $rows = $this->db->query(
            'SELECT u.id, u.display_name, COALESCE(SUM(pl.points),0) AS total_points
             FROM users u
             LEFT JOIN point_ledger pl ON pl.organization_id = ? AND pl.season_id = ? AND pl.subject_id = u.id AND pl.subject_type = "user" AND pl.state = "final" AND pl.archived = 0
             LEFT JOIN group_members gm ON gm.organization_id = ? AND gm.user_id = u.id
             WHERE u.organization_id = ?
               AND gm.group_id IN (' . $this->placeholders($groupIds) . ')
             GROUP BY u.id, u.display_name
             ORDER BY total_points DESC, u.display_name ASC
             LIMIT ?',
            array_merge([$orgId, $seasonId, $orgId, $orgId], $groupIds, [$limit]),
        )->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noLeaderboardData')) . '</p>';
        }

        $html = '<ol class="leaderboard-list">';
        foreach ($rows as $i => $row) {
            $rank = $i + 1;
            $name = esc($row['display_name'] ?? lang('Gamification.dashboard.anonymous'));
            $points = esc((string) ($row['total_points'] ?? 0));
            $html .= '<li><span class="rank">' . esc((string) $rank) . '</span> <span class="name">' . $name . '</span> <span class="points">' . $points . '</span></li>';
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
