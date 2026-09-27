<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's current gamification standing widget.
 */
final class MyStandingWidgetRenderer implements WidgetRenderer
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

        // Get user's points
        $row = $this->db->query(
            'SELECT COALESCE(SUM(points),0) AS total FROM point_ledger
             WHERE organization_id = ? AND season_id = ? AND subject_id = ?
               AND subject_type = "user" AND state = "final" AND archived = 0',
            [$orgId, $seasonId, $userId],
        )->getRowArray();

        $points = (int) ($row['total'] ?? 0);

        // Get rank
        $ranks = $this->db->table('rank_definitions')
            ->select('code, name, min_points, icon, color')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->orderBy('min_points', 'ASC')
            ->get()->getResultArray();

        $rank = $this->resolveRank($points, $ranks);

        $html = '<div class="standing-widget">';
        $html .= '<div class="standing-rank">' . esc($rank['current']['name'] ?? lang('Gamification.dashboard.unranked')) . '</div>';
        if ($rank['next'] !== null && $rank['points_to_next'] !== null) {
            $html .= '<div class="standing-progress">';
            $html .= '<div class="progress-bar">';
            $pct = $this->rankProgressPercent($points, $rank['current'], $rank['next']);
            $html .= '<div class="progress-fill" style="width:' . esc((string) $pct) . '%"></div>';
            $html .= '</div>';
            $html .= '<div class="progress-text">' . esc(lang('Gamification.dashboard.ptsToNext', [(string) $rank['points_to_next']])) . '</div>';
            $html .= '</div>';
        }
        $html .= '<div class="standing-points">' . esc(lang('Gamification.dashboard.pointsLabel')) . ': <strong>' . esc((string) $points) . '</strong></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Pure rank resolver: given a point total and the ascending rank ladder,
     * return the current rank, the next rank, and points needed to reach it.
     *
     * @param list<array<string,mixed>> $ranks ascending by min_points
     *
     * @return array{current: ?array<string,mixed>, next: ?array<string,mixed>, points_to_next: ?int}
     */
    private function resolveRank(int $points, array $ranks): array
    {
        $current = null;
        $next = null;

        foreach ($ranks as $r) {
            $min = (int) ($r['min_points'] ?? 0);
            if ($points >= $min) {
                $current = $r;
            } elseif ($next === null) {
                $next = $r;
            }
        }

        $toNext = $next !== null ? max(0, (int) $next['min_points'] - $points) : null;

        return ['current' => $current, 'next' => $next, 'points_to_next' => $toNext];
    }

    /**
     * Percent progress from the current rank's floor toward the next rank's floor.
     */
    private function rankProgressPercent(int $points, ?array $current, ?array $next): int
    {
        if ($current === null || $next === null
            || ! isset($current['min_points'], $next['min_points'])) {
            return 100;
        }

        $span = (int) $next['min_points'] - (int) $current['min_points'];
        if ($span <= 0) {
            return 100;
        }

        $progressed = $points - (int) $current['min_points'];

        return (int) max(0, min(100, round(($progressed / $span) * 100)));
    }
}
