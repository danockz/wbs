<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's earned badges widget.
 */
final class MyBadgesWidgetRenderer implements WidgetRenderer
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
        $limit = $options['limit'] ?? 6;

        $rows = $this->db->table('badge_awards ba')
            ->select('b.code, b.name, b.icon, ba.awarded_at')
            ->join('badges b', 'b.id = ba.badge_id', 'left')
            ->where('ba.organization_id', $orgId)
            ->where('ba.subject_id', $userId)
            ->where('ba.state', 'awarded')
            ->orderBy('ba.awarded_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noBadges')) . '</p>';
        }

        $html = '<div class="badges-grid">';
        foreach ($rows as $row) {
            $icon = $row['icon'] ?? '';
            $name = esc($row['name'] ?? $row['code'] ?? '');
            $date = esc($this->formatDate($row['awarded_at'] ?? ''));
            $html .= '<div class="badge-item" title="' . esc(lang('Gamification.dashboard.awardedOn', [$date])) . '">';
            if ($icon !== '') {
                $html .= '<span class="badge-icon">' . esc($icon) . '</span>';
            }
            $html .= '<span class="badge-name">' . $name . '</span>';
            $html .= '</div>';
        }
        $html .= '</div>';

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
