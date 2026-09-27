<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use WBS\Shared\Support\Result;

/**
 * Narrow seam the ContactBookService uses to enrol a contact in a COURSE as part
 * of a follow-up. The production adapter wraps Courses\EnrollmentService::enroll()
 * (idempotent per course/user, provisions the enrollment + reward hooks); tests
 * supply a trivial in-memory implementation.
 *
 * @see \WBS\Courses\Services\EnrollmentService::enroll()
 */
interface CourseEnrollerPort
{
    public function enroll(string $organizationId, string $courseId, string $userId, ?string $cohortId = null): Result;
}
