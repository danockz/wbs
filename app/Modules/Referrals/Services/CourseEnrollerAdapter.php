<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Courses\Services\EnrollmentService;
use WBS\Shared\Support\Result;

/**
 * Production CourseEnrollerPort: forwards a follow-up course enrolment to the
 * platform's own Courses\EnrollmentService::enroll() (idempotent, reward hooks),
 * reusing the Courses module wholesale — no forked enrolment path.
 */
final class CourseEnrollerAdapter implements CourseEnrollerPort
{
    public function __construct(private readonly EnrollmentService $enrollments)
    {
    }

    public function enroll(string $organizationId, string $courseId, string $userId, ?string $cohortId = null): Result
    {
        // Translate the narrow port signature into EnrollmentService's $opts array
        // (a bare null cohort must NOT be passed as $opts — that param is typed
        // array). A follow-up enrolment is leader/staff/follow-up-assisted, a
        // valid invite source (G5), so mark it assisted => invite-only courses
        // admit it.
        $opts = ['assisted' => true];
        if ($cohortId !== null && $cohortId !== '') {
            $opts['cohort_id'] = $cohortId;
        }

        return $this->enrollments->enroll($organizationId, $courseId, $userId, $opts);
    }
}
