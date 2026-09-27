<?php

declare(strict_types=1);

namespace WBS\Courses\Controllers;

use WBS\Courses\Config\Services as CourseServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Enrollment, progress and completion endpoints (SRS FR-CRS-003/004).
 */
final class EnrollmentController extends BaseController
{
    public function enrol(string $courseId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        // A browser learner enrols THEMSELVES (session identity); an API caller
        // may still name any user_id in the body.
        $userId = $this->currentUserId('user_id');

        $result = CourseServices::enrollments()->enroll(
            $orgId,
            $courseId,
            $userId,
            ['cohort_id' => $in['cohort_id'] ?? null],
        );

        return $this->respondEnrollment($result, '/courses/' . rawurlencode($courseId) . '/syllabus', 'enrolledFlash');
    }

    public function completeLesson(string $enrollmentId = '', string $lessonId = '')
    {
        $score = $this->field('score');

        // Ownership (gap CO1): the authenticated caller must own the enrollment;
        // the service denies a mismatch. Never trust the URL enrollment id alone.
        $result = CourseServices::enrollments()->completeLesson(
            $enrollmentId,
            $lessonId,
            $this->currentUserId(),
            $score !== null ? (int) $score : null,
        );

        // Browser forms carry the course id so we can PRG back to the syllabus.
        $courseId = (string) $this->field('course_id', '');
        $to       = $courseId !== '' ? '/courses/' . rawurlencode($courseId) . '/syllabus' : '/courses';

        return $this->respondEnrollment($result, $to, 'lessonDoneFlash');
    }

    public function syllabus(string $courseId = '')
    {
        // Learner-aware: the current member's enrollment + per-lesson progress
        // drives the Enrol / Mark-complete controls. API clients keep the flat
        // lessons list they had before (back-compatible payload).
        $userId = $this->currentUserId();
        $view   = CourseServices::enrollments()->learnerView($this->orgId(), $courseId, $userId);

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok($view['lessons']));
        }

        return $this->respondWith(
            Result::ok($view),
            htmlView: 'WBS\Courses\Views\syllabus',
            viewData: [
                'result'  => $view + ['lessons' => $view['lessons']],
                'title'   => 'Syllabus',
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
                'user_id' => $userId,
            ],
        );
    }

    /**
     * PRG for a browser learner write (enrol / complete lesson): API clients keep
     * the raw Result (JSON); a browser redirects back to the syllabus with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function respondEnrollment(Result $result, string $to, string $okKey)
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', (string) lang('Courses.learner.' . $okKey));
    }
}
