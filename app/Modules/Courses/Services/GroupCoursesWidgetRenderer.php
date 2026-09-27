<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Groups\Support\WidgetRenderer;
use WBS\Shared\Support\Clock;

/**
 * Renders the group courses widget.
 */
final class GroupCoursesWidgetRenderer implements WidgetRenderer
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

        $rows = $this->db->table('courses')
            ->select('id, title, category, status')
            ->where('organization_id', $orgId)
            ->whereIn('group_id', $groupIds)
            ->where('status', 'active')
            ->orderBy('title', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        if ($rows === []) {
            return '<p class="widget-empty">' . esc(lang('Courses.dashboard.noGroupCourses')) . '</p>';
        }

        $html = '<ul class="courses-list">';
        foreach ($rows as $row) {
            $title = esc($row['title'] ?? lang('Courses.dashboard.untitledCourse'));
            $category = esc($row['category'] ?? '');
            
            $html .= '<li>';
            $html .= '<span class="course-title">' . $title . '</span>';
            if ($category !== '') {
                $html .= '<span class="course-category">' . $category . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
