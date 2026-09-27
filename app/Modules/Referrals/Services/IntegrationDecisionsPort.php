<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

/**
 * The seam Courses calls so a real enrolment/completion can satisfy the
 * foundation-course decision without the Courses module knowing about decisions.
 *
 * Best-effort by contract: implementations must never throw into the enrolment
 * or completion path (the adapter swallows failures — a decision row must never
 * be able to fail a learner's enrolment).
 */
interface IntegrationDecisionsPort
{
    public function onEnrolled(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void;

    public function onCompleted(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void;
}
