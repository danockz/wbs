<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the course completion rates widget.
 */
final class CompletionRatesWidgetRenderer implements WidgetRenderer
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
            return '<p class="widget-empty">' . esc(lang('Courses.dashboard.noGroupsInScope')) . '</p>';
        }

        $rows = $this->db->table('courses c')
            ->select('c.id, c.title, 
                COUNT(CASE WHEN en.status = "completed" THEN 1 END) AS completed,
                COUNT(*) AS total')
            ->join('enrollments en', 'en.course_id = c.id AND en.organization_id = c.organization_id', 'left')
            ->join('group_members gm', 'gm.user_id = en.user_id AND gm.organization_id = en.organization_id')
            ->where('c.organization_id', $orgId)
            ->whereIn('gm.group_id', $groupIds)
            ->groupBy('c.id, c.title')
            ->orderBy('completed * 1.0 / NULLIF(total, 0)', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Courses.dashboard.noData')) . '</p>';
        }

        $html = '<ul class="completion-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Courses.dashboard.untitledCourse'));
            $completed = (int) ($row['completed'] ?? 0);
            $total = (int) ($row['total'] ?? 0);
            $rate = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
            
            $html .= '<li>';
            $html .= '<span class="course-title">' . $title . '</span>';
            $html .= '<span class="completion-count">' . esc((string) $completed) . '/' . esc((string) $total) . '</span>';
            $html .= '<div class="progress-bar"><div class="progress-fill" style="width:' . esc((string) $rate) . '%"></div></div>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
