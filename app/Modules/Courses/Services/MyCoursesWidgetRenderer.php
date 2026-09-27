<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the user's courses widget.
 */
final class MyCoursesWidgetRenderer implements WidgetRenderer
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
            ->select('c.id, c.title, c.category, en.status, en.enrolled_at, en.completed_at')
            ->join('courses c', 'c.id = en.course_id', 'left')
            ->where('en.organization_id', $orgId)
            ->where('en.user_id', $userId)
            ->orderBy('en.enrolled_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Courses.dashboard.noCourses')) . '</p>';
        }

        $html = '<ul class="courses-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Courses.dashboard.untitledCourse'));
            $category = esc($row['category'] ?? '');
            $status = esc($row['status'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="course-title">' . $title . '</span>';
            if ($category !== '') {
                $html .= '<span class="course-category">' . $category . '</span>';
            }
            $html .= '<span class="course-status status-' . esc(strtolower($status)) . '">' . $status . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
