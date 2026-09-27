<?php

declare(strict_types=1);

namespace WBS\Groups\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Groups\Services\GroupsDashboardWidgetProvider;
use WBS\Gamification\Services\GamificationDashboardWidgetProvider;
use WBS\Identity\Services\IdentityDashboardWidgetProvider;
use WBS\Journey\Services\JourneyDashboardWidgetProvider;
use WBS\Events\Services\EventsDashboardWidgetProvider;
use WBS\Contributions\Services\ContributionsDashboardWidgetProvider;
use WBS\Courses\Services\CoursesDashboardWidgetProvider;
use WBS\Community\Services\CommunityDashboardWidgetProvider;
use WBS\Announcements\Services\AnnouncementsDashboardWidgetProvider;
use WBS\Notifications\Services\NotificationsDashboardWidgetProvider;
use WBS\AccessControl\Services\AccessControlDashboardWidgetProvider;
use WBS\Reporting\Services\ReportingDashboardWidgetProvider;
use WBS\Referrals\Services\ReferralsDashboardWidgetProvider;
use WBS\Meetings\Services\MeetingsDashboardWidgetProvider;
use WBS\Streaming\Services\StreamingDashboardWidgetProvider;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Groups service bindings (SRS §6.3, FR-GRP-*). Auto-discovered.
 */
class Services extends BaseService
{
    private static ?BirthdayService $birthdays = null;
    private static ?GroupDashboardService $groupDashboard = null;

    /**
     * Hierarchical group-aware birthdays (hub + calendar overlay + notify).
     * Module-local cache — not getSharedInstance — so tests can construct
     * BirthdayService directly without fighting the CI4 shared bag.
     */
    public static function birthdays(): BirthdayService
    {
        if (self::$birthdays === null) {
            self::$birthdays = new BirthdayService(
                Database::connect(),
                SharedServices::clock(),
                new BirthdayConfigAdapter(AdminServices::effectiveConfig()),
            );
        }

        return self::$birthdays;
    }

    /**
     * Hierarchical group dashboard service.
     * Collects and renders widgets from all modules.
     */
    public static function groupDashboard(bool $getShared = true): GroupDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('groupDashboard');
        }

        $service = new GroupDashboardService(
            Database::connect(),
            SharedServices::clock(),
        );

        // Register all module widget providers
        $service->registerProvider(new GroupsDashboardWidgetProvider());
        $service->registerProvider(new GamificationDashboardWidgetProvider());
        $service->registerProvider(new IdentityDashboardWidgetProvider());
        $service->registerProvider(new JourneyDashboardWidgetProvider());
        $service->registerProvider(new EventsDashboardWidgetProvider());
        $service->registerProvider(new ContributionsDashboardWidgetProvider());
        $service->registerProvider(new CoursesDashboardWidgetProvider());
        $service->registerProvider(new CommunityDashboardWidgetProvider());
        $service->registerProvider(new AnnouncementsDashboardWidgetProvider());
        $service->registerProvider(new NotificationsDashboardWidgetProvider());
        $service->registerProvider(new AccessControlDashboardWidgetProvider());
        $service->registerProvider(new ReportingDashboardWidgetProvider());
        $service->registerProvider(new ReferralsDashboardWidgetProvider());
        $service->registerProvider(new MeetingsDashboardWidgetProvider());
        $service->registerProvider(new StreamingDashboardWidgetProvider());

        return $service;
    }

    public static function groups(bool $getShared = true): GroupService
    {
        if ($getShared) {
            return static::getSharedInstance('groups');
        }

        return new GroupService(Database::connect(), SharedServices::clock(), self::groupKinds(false));
    }

    /**
     * Configurable group-kind taxonomy (the "kind" axis: department / activity
     * team / ministry / committee). Pure classification — does not affect scope.
     */
    public static function groupKinds(bool $getShared = true): GroupKindService
    {
        if ($getShared) {
            return static::getSharedInstance('groupKinds');
        }

        return new GroupKindService(
            Database::connect(),
            SharedServices::clock(),
            AuditServices::auditLogger(),
        );
    }

    /** Read model for public group landing pages (/g and /g/{slug}). */
    public static function groupPublic(bool $getShared = true): GroupPublicService
    {
        if ($getShared) {
            return static::getSharedInstance('groupPublic');
        }

        return new GroupPublicService(
            Database::connect(),
            SharedServices::clock(),
            self::memberships(),
            IdentityServices::credentialSetup(),
            // users-writer parity: public join normalizes optional phone with
            // the SAME identity tooling as self-registration (field-sync).
            IdentityServices::phoneNormalizer(),
            IdentityServices::identityPolicies(),
        );
    }

    /**
     * Cross-cutting group links (leadership-responsibility model). Reuses the
     * shared GroupScopeResolver so link validation and access-time coverage share
     * the same hierarchy rule.
     */
    public static function crosscut(bool $getShared = true): GroupCrosscutService
    {
        if ($getShared) {
            return static::getSharedInstance('crosscut');
        }

        return new GroupCrosscutService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
            AuditServices::auditLogger(),
        );
    }

    public static function memberships(bool $getShared = true): GroupMembershipService
    {
        if ($getShared) {
            return static::getSharedInstance('memberships');
        }

        return new GroupMembershipService(
            Database::connect(),
            SharedServices::clock(),
            AuditServices::auditLogger(),
            // M7: joining/leaving a group signals the journey through the
            // platform's OWN rule engine (the membership half of "integration"),
            // via a port so Groups stays decoupled from the Journey module
            // (which already depends on Groups — a direct dep would be a cycle).
            new JourneySignalAdapter(JourneyServices::journeySignals()),
        );
    }

    /** Group lifecycle state machine — archive/merge/dissolve (FR-GRP-005). */
    public static function groupLifecycle(bool $getShared = true): GroupLifecycleService
    {
        if ($getShared) {
            return static::getSharedInstance('groupLifecycle');
        }

        return new GroupLifecycleService(
            Database::connect(),
            SharedServices::clock(),
            AuditServices::auditLogger(),
            self::groups(false),
            SharedServices::outbox(false),
        );
    }
}
