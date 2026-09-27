<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's active streaks widget.
 */
final class MyStreaksWidgetRenderer implements WidgetRenderer
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

        $seasonId = $season['id'] ?? null;

        $q = $this->db->table('user_streaks us')
            ->select('us.streak_code, sd.name, sd.cadence, sd.icon, us.current_count, us.best_count')
            ->join('streak_definitions sd', 'sd.code = us.streak_code AND sd.organization_id = us.organization_id', 'left')
            ->where('us.organization_id', $orgId)
            ->where('us.subject_id', $userId);

        if ($seasonId !== null) {
            $q->groupStart()->where('us.season_id', $seasonId)->orWhere('us.season_id', null)->groupEnd();
        }

        $rows = $q->orderBy('us.current_count', 'DESC')->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noStreaks')) . '</p>';
        }

        $html = '<ul class="streaks-list">';
        foreach ($rows as $row) {
            $icon = $row['icon'] ?? '';
            $name = esc($row['name'] ?? $row['streak_code'] ?? '');
            $current = esc((string) ($row['current_count'] ?? 0));
            $best = esc((string) ($row['best_count'] ?? 0));
            $cadence = esc($row['cadence'] ?? '');
            $html .= '<li>';
            if ($icon !== '') {
                $html .= '<span class="streak-icon">' . esc($icon) . '</span>';
            }
            $html .= '<span class="streak-name">' . $name . '</span>';
            if ($cadence !== '') {
                $html .= '<span class="streak-cadence">' . $cadence . '</span>';
            }
            $html .= '<span class="streak-count">' . esc(lang('Gamification.dashboard.currentBest', [$current, $best])) . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
