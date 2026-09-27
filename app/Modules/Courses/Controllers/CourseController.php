<?php

declare(strict_types=1);

namespace WBS\Courses\Controllers;

use WBS\Courses\Config\Services as CourseServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Course authoring endpoints (SRS FR-CRS-001).
 */
final class CourseController extends BaseController
{
    public function index()
    {
        $orgId   = $this->orgId();
        $courses = CourseServices::courses()->listForOrg($orgId, (int) $this->field('limit', 50));

        return $this->respondWith(
            Result::ok(['courses' => $courses, 'count' => count($courses)]),
            htmlView: 'WBS\Courses\Views\index',
            viewData: ['result' => ['courses' => $courses], 'title' => 'Courses'],
        );
    }

    public function show(string $courseId = '')
    {
        $overview = CourseServices::courses()->overview($courseId);
        if ($overview === null) {
            return $this->respondWith(Result::notFound('course.not_found', 'COURSE_NOT_FOUND'));
        }

        return $this->respondWith(
            Result::ok($overview),
            htmlView: 'WBS\Courses\Views\overview',
            viewData: [
                'result' => $overview,
                'title'  => (string) ($overview['title'] ?? 'Course'),
                // Token minted by WebCsrfIssueFilter on this safe navigation, so the
                // inline publish / add-lesson authoring forms satisfy webcsrf.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET courses/create — render the bespoke "create course" form (issues CSRF). */
    public function createForm()
    {
        return $this->renderForm('WBS\Courses\Views\create');
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        $result = CourseServices::courses()->create($orgId, $in);

        if (! $this->wantsJson()) {
            if ($result->ok) {
                return redirect()->to('/courses/' . (string) ($result->data['course_id'] ?? ''));
            }

            return $this->renderForm('WBS\Courses\Views\create', [
                'error' => (string) $result->message,
                'old'   => $in,
            ]);
        }

        return $this->respondWith($result);
    }

    public function publish(string $courseId = '')
    {
        return $this->respondAuthoring(
            CourseServices::courses()->publish($courseId),
            $courseId,
            'publishedFlash',
        );
    }

    public function addLesson(string $courseId = '')
    {
        return $this->respondAuthoring(
            CourseServices::courses()->addLesson($courseId, $this->input()),
            $courseId,
            'lessonAddedFlash',
        );
    }

    /**
     * PRG for a browser course-authoring write (publish / add-lesson): API clients
     * keep the raw Result (JSON); browsers redirect back to the course overview
     * with a localized success flash, or the failing Result's message as an error
     * flash. The publish/add-lesson controls live on the course overview page.
     */
    private function respondAuthoring(Result $result, string $courseId, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $to = $courseId !== '' ? '/courses/' . rawurlencode($courseId) : '/courses';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Courses.authoring.' . $okKey));
    }
}
