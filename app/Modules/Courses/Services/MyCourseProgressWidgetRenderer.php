<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's course progress widget.
 */
final class MyCourseProgressWidgetRenderer implements WidgetRenderer
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

        $rows = $this->db->table('enrollments en')
            ->select('c.id, c.title, en.progress_pct, en.last_activity_at')
            ->join('courses c', 'c.id = en.course_id', 'left')
            ->where('en.organization_id', $orgId)
            ->where('en.user_id', $userId)
            ->where('en.status', 'active')
            ->orderBy('en.last_activity_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Courses.dashboard.noProgress')) . '</p>';
        }

        $html = '<ul class="progress-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Courses.dashboard.untitledCourse'));
            $pct = (int) ($row['progress_pct'] ?? 0);
            
            $html .= '<li>';
            $html .= '<span class="course-title">' . $title . '</span>';
            $html .= '<div class="progress-bar"><div class="progress-fill" style="width:' . esc((string) $pct) . '%"></div></div>';
            $html .= '<span class="progress-pct">' . esc((string) $pct) . '%</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
