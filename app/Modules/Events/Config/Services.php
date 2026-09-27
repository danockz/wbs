<?php

declare(strict_types=1);

namespace WBS\Events\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Events\Services\AnalyticsService;
use WBS\Events\Services\CommitteeDecisionService;
use WBS\Events\Services\CommitteeService;
use WBS\Events\Services\DelegationAuthorityAdapter;
use WBS\Events\Services\EffectiveConfigAdapter;
use WBS\Events\Services\EventWorkService;
use WBS\Events\Services\EventNotifier;
use WBS\Events\Services\EventRosterDbAdapter;
use WBS\Events\Services\NotificationSenderAdapter;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Events\Services\EventCloser;
use WBS\Events\Services\CheckinService;
use WBS\Events\Services\CalendarSettingsService;
use WBS\Events\Services\CertificateRenderer;
use WBS\Events\Services\CertificateService;
use WBS\Events\Services\EventService;
use WBS\Events\Services\ExpenseService;
use WBS\Events\Services\FeedTokenService;
use WBS\Events\Services\InvitationService;
use WBS\Events\Services\IcsFeedService;
use WBS\Events\Services\FeedbackService;
use WBS\Events\Services\KioskService;
use WBS\Events\Services\LogisticsService;
use WBS\Events\Services\MediaService;
use WBS\Events\Services\OrderRefundService;
use WBS\Events\Services\RegistrationService;
use WBS\Events\Services\ReportService;
use WBS\Events\Services\TicketingService;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Security\SecretBox;

/**
 * Events service bindings (SRS FR-EVT-*). Auto-discovered.
 */
class Services extends BaseService
{
    /** @var array<string, object> */
    private static array $local = [];

    /**
     * Module-local shared cache. CI4 getSharedInstance() goes through
     * AppServices::__callStatic and returns null when the factory is not yet
     * in the discovery cache (GET /event-committees, /certificates/templates, …).
     *
     * @template T of object
     * @param callable(): T $make
     * @return T
     */
    private static function local(string $key, callable $make): object
    {
        $hit = self::$local[$key] ?? null;
        if (is_object($hit)) {
            return $hit;
        }
        $inst = $make();
        self::$local[$key] = $inst;

        return $inst;
    }

    public static function calendarSettings(bool $getShared = true): CalendarSettingsService
    {
        if ($getShared) {
            return self::local('calendarSettings', static fn () => self::calendarSettings(false));
        }

        return new CalendarSettingsService(Database::connect(), SharedServices::clock());
    }

    public static function events(bool $getShared = true): EventService
    {
        if ($getShared) {
            return static::getSharedInstance('events');
        }

        return new EventService(Database::connect(), SharedServices::clock(), static::eventNotifier(), static::orderRefunds(), SharedServices::outbox(false), static::eventRegistrations(), static::eventCommittees());
    }

    /**
     * Roster-notification coordinator (gap G3): reuses the platform Notifications
     * module (idempotent send + gate + outbox), the hierarchical config store for
     * the DEFAULT-OFF feature gate, and reads the roster/reminder watermark
     * through a DB adapter. Injected into EventService (cancel/update fan-out) and
     * driven by the reminder sweep command.
     */
    public static function eventNotifier(bool $getShared = true): EventNotifier
    {
        if ($getShared) {
            return static::getSharedInstance('eventNotifier');
        }

        $db = Database::connect();

        return new EventNotifier(
            SharedServices::clock(),
            new EventRosterDbAdapter($db),
            new NotificationSenderAdapter(NotificationServices::notifications(false)),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
        );
    }

    /**
     * Close-automation sweep (gap L3): auto-completes finished published events
     * through EventService::complete(), config-gated per group (default OFF).
     * Driven by the `events:close-due` command; reuses the same roster adapter +
     * hierarchical config port as the reminder sweep.
     */
    public static function eventCloser(bool $getShared = true): EventCloser
    {
        if ($getShared) {
            return static::getSharedInstance('eventCloser');
        }

        $db = Database::connect();

        return new EventCloser(
            SharedServices::clock(),
            static::events(),
            new EventRosterDbAdapter($db),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
        );
    }

    public static function eventRegistrations(bool $getShared = true): RegistrationService
    {
        if ($getShared) {
            return static::getSharedInstance('eventRegistrations');
        }

        return new RegistrationService(Database::connect(), SharedServices::clock(), static::eventInvitations());
    }

    /**
     * Event invitation allow-list + eligibility gate (gap G5). Makes
     * `registration_policy = 'invite'` real: an invite-only event is fail-closed
     * unless the registrant is assisted, a member of the event's group subtree,
     * directly invited (user/email/phone), or holds a valid link token.
     */
    public static function eventInvitations(bool $getShared = true): InvitationService
    {
        if ($getShared) {
            return static::getSharedInstance('eventInvitations');
        }

        return new InvitationService(Database::connect(), SharedServices::clock());
    }

    /**
     * Narrow notification sender for delivering DIRECT event invitations
     * (email/SMS) carrying the shareable invite link (gap G5). Reuses the
     * Notifications module wholesale via the same adapter the EventNotifier uses.
     */
    public static function eventInviteSender(bool $getShared = true): NotificationSenderAdapter
    {
        if ($getShared) {
            return static::getSharedInstance('eventInviteSender');
        }

        return new NotificationSenderAdapter(NotificationServices::notifications(false));
    }

    public static function eventAnalytics(bool $getShared = true): AnalyticsService
    {
        if ($getShared) {
            return static::getSharedInstance('eventAnalytics');
        }

        return new AnalyticsService(Database::connect(), SharedServices::clock());
    }

    public static function eventIcsFeed(bool $getShared = true): IcsFeedService
    {
        if ($getShared) {
            return static::getSharedInstance('eventIcsFeed');
        }

        return new IcsFeedService();
    }

    public static function eventFeedToken(bool $getShared = true): FeedTokenService
    {
        if ($getShared) {
            return static::getSharedInstance('eventFeedToken');
        }

        $key = (string) (getenv('EVENT_FEED_SIGNING_KEY') ?: 'wbs-feed-key');

        return new FeedTokenService($key);
    }

    public static function checkin(bool $getShared = true): CheckinService
    {
        if ($getShared) {
            return static::getSharedInstance('checkin');
        }

        $key = (string) (getenv('CHECKIN_SIGNING_KEY') ?: 'wbs-checkin-key');

        return new CheckinService(
            Database::connect(),
            SharedServices::clock(),
            $key,
            GamificationServices::pointsEngine(),
            JourneyServices::journeySignals(),
        );
    }

    public static function ticketing(bool $getShared = true): TicketingService
    {
        if ($getShared) {
            return static::getSharedInstance('ticketing');
        }

        return new TicketingService(Database::connect(), SharedServices::clock(), static::eventRegistrations());
    }

    /**
     * Event ticket-order refunds — maker-checker money reversal (gap L2). Mirrors
     * the VBCS RefundService: request → approve (SOD) → execute, staging an
     * `order.refund` outbox dispatch and releasing the freed seats (waitlist
     * auto-promotes) via the registrar.
     */
    public static function orderRefunds(bool $getShared = true): OrderRefundService
    {
        if ($getShared) {
            return static::getSharedInstance('orderRefunds');
        }

        return new OrderRefundService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::outbox(false),
            static::eventRegistrations(),
        );
    }

    public static function kiosks(bool $getShared = true): KioskService
    {
        if ($getShared) {
            return static::getSharedInstance('kiosks');
        }

        return new KioskService(
            Database::connect(),
            SharedServices::clock(),
            new SecretBox(SharedServices::keyProvider()),
            static::checkin(),
        );
    }

    public static function logistics(bool $getShared = true): LogisticsService
    {
        if ($getShared) {
            return static::getSharedInstance('logistics');
        }

        return new LogisticsService(Database::connect(), SharedServices::clock());
    }

    public static function expenses(bool $getShared = true): ExpenseService
    {
        if ($getShared) {
            return static::getSharedInstance('expenses');
        }

        return new ExpenseService(Database::connect(), SharedServices::clock());
    }

    public static function feedback(bool $getShared = true): FeedbackService
    {
        if ($getShared) {
            return static::getSharedInstance('feedback');
        }

        return new FeedbackService(Database::connect(), SharedServices::clock());
    }

    public static function certificates(bool $getShared = true): CertificateService
    {
        if ($getShared) {
            return self::local('certificates', static fn () => self::certificates(false));
        }

        return new CertificateService(Database::connect(), SharedServices::clock(), SharedServices::queue());
    }

    /**
     * PDF renderer for event certificates (SRS FR-EVT-013). Writes artifacts
     * under the framework writable dir; the public verification base URL (used
     * inside the embedded QR) comes from env when configured.
     */
    public static function certificateRenderer(bool $getShared = true): CertificateRenderer
    {
        if ($getShared) {
            return static::getSharedInstance('certificateRenderer');
        }

        $storageDir = defined('WRITEPATH') ? rtrim(WRITEPATH, '/\\') : rtrim(sys_get_temp_dir(), '/\\');
        $verifyBase = (string) (getenv('CERTIFICATE_VERIFY_URL') ?: '');

        return new CertificateRenderer(
            Database::connect(),
            $storageDir,
            $verifyBase,
        );
    }

    public static function media(bool $getShared = true): MediaService
    {
        if ($getShared) {
            return static::getSharedInstance('media');
        }

        return new MediaService(Database::connect(), SharedServices::clock(), SharedServices::queue());
    }

    public static function reports(bool $getShared = true): ReportService
    {
        if ($getShared) {
            return static::getSharedInstance('reports');
        }

        return new ReportService(Database::connect(), SharedServices::clock(), SharedServices::groupScope());
    }
    /**
     * Event committees (optional, pre-event project management). Gated by the
     * hierarchical group capability `event_committee` through the same config port
     * the notifier and closer use, so it inherits down the tree and is DEFAULT OFF.
     * Authority is delegation: appointments cut time-bounded `delegations` rows via
     * the ACL module's own service (containment, depth and duration enforced there),
     * scoped by GroupScopeResolver and audited.
     */
    public static function eventCommittees(bool $getShared = true): CommitteeService
    {
        if ($getShared) {
            return self::local('eventCommittees', static fn () => self::eventCommittees(false));
        }

        return new CommitteeService(
            Database::connect(),
            SharedServices::clock(),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
            SharedServices::groupScope(),
            static::committeeAuthority(),
            AuditServices::auditLogger(),
        );
    }

    /**
     * The committee's authority seam: delegation (grant/revoke a bounded `event.*`
     * capability) plus the PDP question "does this subject hold it over that group?".
     * Both go to the AccessControl module, so containment, depth, duration, MAC, SoD
     * and RuBAC denies are decided there — once, for the whole platform.
     */
    public static function committeeAuthority(bool $getShared = true): DelegationAuthorityAdapter
    {
        if ($getShared) {
            return self::local('committeeAuthority', static fn () => self::committeeAuthority(false));
        }

        return new DelegationAuthorityAdapter(
            AccessControlServices::delegations(),
            AccessControlServices::authorization(),
        );
    }

    /**
     * The event's work engine: workstreams, tasks, dependencies, milestones and the
     * derived progress/late/at-risk numbers. Shares the committee's config gate and
     * asks CommitteeService who may work the plan (leader of the subtree, or an
     * active member).
     */
    public static function eventWork(bool $getShared = true): EventWorkService
    {
        if ($getShared) {
            return self::local('eventWork', static fn () => self::eventWork(false));
        }

        return new EventWorkService(
            Database::connect(),
            SharedServices::clock(),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
            static::eventCommittees(),
            static::committeeAuthority(),
            AuditServices::auditLogger(),
        );
    }

    /**
     * The committee's oversight queue: decisions that need the group leader's
     * approval before they take effect (how many, is `event_committee.oversight`).
     * Mirrors the platform's other maker-checker queues — SoD, the decider's own
     * subtree, a re-check at approve and no expiry.
     */
    public static function committeeDecisions(bool $getShared = true): CommitteeDecisionService
    {
        if ($getShared) {
            return self::local('committeeDecisions', static fn () => self::committeeDecisions(false));
        }

        return new CommitteeDecisionService(
            Database::connect(),
            SharedServices::clock(),
            new EffectiveConfigAdapter(AdminServices::effectiveConfig()),
            static::eventCommittees(),
            static::eventWork(),
            static::committeeAuthority(),
            AuditServices::auditLogger(),
        );
    }
}
