<?php

declare(strict_types=1);

namespace WBS\Courses\Services;

use WBS\Groups\Support\GroupDashboardWidget;
use WBS\Groups\Support\GroupDashboardWidgetProvider;

/**
 * Courses module widget provider for the hierarchical group dashboard.
 */
final class CoursesDashboardWidgetProvider implements GroupDashboardWidgetProvider
{
    /**
     * @return list<GroupDashboardWidget>
     */
    public static function widgets(): array
    {
        return [
            // My courses
            new GroupDashboardWidget(
                module: 'Courses',
                key: 'my_courses',
                labelKey: 'Courses.dashboard.myCourses',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyCoursesWidgetRenderer::class,
                order: 10,
                section: 'learning',
            ),

            // My course progress
            new GroupDashboardWidget(
                module: 'Courses',
                key: 'my_progress',
                labelKey: 'Courses.dashboard.myProgress',
                permission: null,
                capability: null,
                scope: 'self',
                rendererClass: MyCourseProgressWidgetRenderer::class,
                order: 20,
                section: 'learning',
            ),

            // Group courses (for leaders)
            new GroupDashboardWidget(
                module: 'Courses',
                key: 'group_courses',
                labelKey: 'Courses.dashboard.groupCourses',
                permission: 'course.create',
                capability: null,
                scope: 'membership_desc',
                rendererClass: GroupCoursesWidgetRenderer::class,
                order: 30,
                section: 'learning',
            ),

            // Group completion rates (for leaders)
            new GroupDashboardWidget(
                module: 'Courses',
                key: 'completion_rates',
                labelKey: 'Courses.dashboard.completionRates',
                permission: 'report.view',
                capability: null,
                scope: 'membership_desc',
                rendererClass: CompletionRatesWidgetRenderer::class,
                order: 40,
                section: 'learning',
            ),
        ];
    }
}
