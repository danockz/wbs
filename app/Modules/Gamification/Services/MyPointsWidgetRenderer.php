<?php

declare(strict_types=1);

namespace WBS\Gamification\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's points summary widget.
 */
final class MyPointsWidgetRenderer implements WidgetRenderer
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

        // Get recent point transactions
        $rows = $this->db->table('point_ledger')
            ->select('activity_code, points, description, created_at')
            ->where('organization_id', $orgId)
            ->where('subject_id', $userId)
            ->where('subject_type', 'user')
            ->where('state', 'final')
            ->where('archived', 0)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Gamification.dashboard.noRecentPoints')) . '</p>';
        }

        $html = '<ul class="points-list">';
        foreach ($rows as $row) {
            $activity = esc($row['activity_code'] ?? $row['description'] ?? lang('Gamification.dashboard.unknownActivity'));
            $pts = esc((string) ($row['points'] ?? 0));
            $sign = ($row['points'] ?? 0) >= 0 ? '+' : '';
            $date = esc($this->formatDate($row['created_at'] ?? ''));
            $html .= '<li><span class="activity">' . $activity . '</span> <span class="points ' . (($row['points'] ?? 0) >= 0 ? 'positive' : 'negative') . '">' . $sign . $pts . '</span> <span class="date">' . $date . '</span></li>';
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
