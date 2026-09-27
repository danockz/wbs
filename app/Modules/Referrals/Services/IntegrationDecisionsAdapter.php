<?php

declare(strict_types=1);

namespace WBS\Referrals\Services;

use Throwable;

/**
 * Courses → integration-decisions bridge ({@see IntegrationDecisionsPort}).
 * Forwards to {@see IntegrationService}, which is idempotent and config-gated;
 * any failure is swallowed so enrolment/completion never depends on a decision
 * row being written.
 */
final class IntegrationDecisionsAdapter implements IntegrationDecisionsPort
{
    public function __construct(private readonly IntegrationService $integration)
    {
    }

    public function onEnrolled(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void {
        try {
            $this->integration->deriveFromEnrolment($organizationId, $userId, $courseId, $enrollmentId, $at, $category, $courseGroupId);
        } catch (Throwable) {
        }
    }

    public function onCompleted(
        string $organizationId,
        string $userId,
        string $courseId,
        string $enrollmentId,
        string $at,
        string $category,
        ?string $courseGroupId,
    ): void {
        try {
            $this->integration->deriveFromCompletion($organizationId, $userId, $courseId, $enrollmentId, $at, $category, $courseGroupId);
        } catch (Throwable) {
        }
    }
}
