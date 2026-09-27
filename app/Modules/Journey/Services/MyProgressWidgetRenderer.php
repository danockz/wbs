<?php

declare(strict_types=1);

namespace WBS\Journey\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's journey progress widget.
 */
final class MyProgressWidgetRenderer implements WidgetRenderer
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
        // Get journey milestones/progress
        $limit = $options['limit'] ?? 5;

        // Get completed journey milestones
        $completed = $this->db->table('user_journey_milestones ujm')
            ->select('jm.name, jm.description, ujm.completed_at')
            ->join('journey_milestones jm', 'jm.id = ujm.milestone_id', 'left')
            ->where('ujm.organization_id', $orgId)
            ->where('ujm.user_id', $userId)
            ->where('ujm.status', 'completed')
            ->orderBy('ujm.completed_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($completed === []) {
            return '<p class="widget-empty">' . esc(lang('Journey.dashboard.noProgress')) . '</p>';
        }

        $html = '<ul class="progress-list">';
        foreach ($completed as $row) {
            $name = esc($row['name'] ?? '');
            $description = esc($row['description'] ?? '');
            $date = esc($this->formatDate($row['completed_at'] ?? ''));
            
            $html .= '<li>';
            $html .= '<span class="milestone-name">' . $name . '</span>';
            if ($description !== '') {
                $html .= '<span class="milestone-desc">' . $description . '</span>';
            }
            $html .= '<span class="milestone-date">' . $date . '</span>';
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
