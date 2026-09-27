<?php

declare(strict_types=1);

namespace WBS\Courses\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Courses\Services\CourseService;
use WBS\Courses\Services\EnrollmentService;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Referrals\Services\IntegrationDecisionsAdapter;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Courses service bindings (SRS FR-CRS-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function courses(bool $getShared = true): CourseService
    {
        if ($getShared) {
            return static::getSharedInstance('courses');
        }

        return new CourseService(Database::connect(), SharedServices::clock());
    }

    public static function enrollments(bool $getShared = true): EnrollmentService
    {
        if ($getShared) {
            return static::getSharedInstance('enrollments');
        }

        return new EnrollmentService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::outbox(false),
            // A foundation-category enrolment/completion satisfies the "foundation
            // course" integration decision (FR-REF-3b). Best-effort + config-gated.
            new IntegrationDecisionsAdapter(ReferralServices::integration(false)),
        );
    }
}
