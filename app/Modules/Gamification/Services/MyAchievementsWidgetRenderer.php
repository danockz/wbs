<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's achievements widget.
 */
final class MyAchievementsWidgetRenderer implements WidgetRenderer
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

        // Unlocked achievements
        $unlocked = $this->db->table('user_achievements ua')
            ->select('ad.code, ad.name, ad.category, ua.unlocked_at')
            ->join('achievement_definitions ad', 'ad.id = ua.achievement_id', 'left')
            ->where('ua.organization_id', $orgId)
            ->where('ua.subject_id', $userId)
            ->orderBy('ua.unlocked_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($unlocked === []) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noAchievements')) . '</p>';
        }

        $html = '<ul class="achievements-list">';
        foreach ($unlocked as $row) {
            $name = esc($row['name'] ?? $row['code'] ?? '');
            $category = esc($row['category'] ?? '');
            $date = esc($this->formatDate($row['unlocked_at'] ?? ''));
            $html .= '<li>';
            $html .= '<span class="achievement-name">' . $name . '</span>';
            if ($category !== '') {
                $html .= '<span class="achievement-category">' . $category . '</span>';
            }
            $html .= '<span class="achievement-date">' . $date . '</span>';
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
        return $ts ? date('M j, Y', $ts) : $date;
    }
}
