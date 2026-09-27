<?php

declare(strict_types=1);

namespace WBS\Referrals\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Courses\Config\Services as CourseServices;
use WBS\Events\Config\Services as EventServices;
use WBS\Geo\Config\Services as GeoServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Referrals\Services\ContactBookService;
use WBS\Referrals\Services\CourseEnrollerAdapter;
use WBS\Referrals\Services\EventRegistrarAdapter;
use WBS\Referrals\Services\FollowUpNotifierAdapter;
use WBS\Referrals\Services\FraudService;
use WBS\Referrals\Services\GroupMembershipAdapter;
use WBS\Referrals\Services\IntegrationService;
use WBS\Referrals\Services\JourneySignalAdapter;
use WBS\Referrals\Services\ProspectGroupResolver;
use WBS\Referrals\Services\ProspectTransferReviewService;
use WBS\Referrals\Services\ProspectTransferService;
use WBS\Referrals\Services\ReferralService;
use WBS\Referrals\Services\SponsorReassignmentService;
use WBS\Referrals\Services\SponsorResolver;
use WBS\Referrals\Services\SponsorshipService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Referrals service bindings (SRS §6.3, FR-MEM-*). Auto-discovered.
 */
class Services extends BaseService
{
    /** Local cache — do NOT use getSharedInstance('integration'). CI4 routes that
     *  through AppServices::__callStatic, which returns null while accounts →
     *  journeySignals → journey is still constructing (GET /me, /me/contacts). */
    private static ?IntegrationService $integrationInstance = null;

    public static function sponsorships(bool $getShared = true): SponsorshipService
    {
        if ($getShared) {
            return static::getSharedInstance('sponsorships');
        }

        return new SponsorshipService(Database::connect(), SharedServices::clock());
    }

    /**
     * Automatic-sponsor resolver: given a registration's optional explicit
     * sponsor and/or target group, returns the hierarchical group leader (or
     * delegate) who becomes the new member's sponsor (FR-MEM-001). Read-only.
     */
    public static function sponsorResolver(bool $getShared = true): SponsorResolver
    {
        if ($getShared) {
            return static::getSharedInstance('sponsorResolver');
        }

        return new SponsorResolver(Database::connect());
    }

    /** Sponsor-reassignment maker-checker workflow (FR-MEM-002). */
    public static function sponsorReassignments(bool $getShared = true): SponsorReassignmentService
    {
        if ($getShared) {
            return static::getSharedInstance('sponsorReassignments');
        }

        return new SponsorReassignmentService(
            Database::connect(),
            SharedServices::clock(),
            self::sponsorships(false),
            AuditServices::auditLogger(),
        );
    }

    /**
     * Integration lifecycle (FR-REF-3b): the standalone dated decisions —
     * salvation, water baptism, Holy Spirit baptism, foundation course — with
     * self-declaration + maker-checker confirmation + derivation from course
     * enrolment/completion, gated by the hierarchical group config
     * `referrals.integration_decisions` (default OFF).
     */
    public static function integration(bool $getShared = true): IntegrationService
    {
        // Always construct. Shared CI4 locator returns null while Identity
        // accounts → journey is building (GET /me, /my/integration, /me/contacts).
        if ($getShared && self::$integrationInstance instanceof IntegrationService) {
            return self::$integrationInstance;
        }

        $fresh = new IntegrationService(
            Database::connect(),
            SharedServices::clock(),
            AdminServices::effectiveConfig(),
            SharedServices::groupScope(),
            self::sponsorships(false),
            AuditServices::auditLogger(),
        );
        if ($getShared) {
            self::$integrationInstance = $fresh;
        }

        return $fresh;
    }

    public static function fraud(bool $getShared = true): FraudService
    {
        if ($getShared) {
            return static::getSharedInstance('fraud');
        }

        return new FraudService(Database::connect(), SharedServices::clock());
    }

    public static function referrals(bool $getShared = true): ReferralService
    {
        if ($getShared) {
            return static::getSharedInstance('referrals');
        }

        $salt = (string) (getenv('REFERRAL_IP_SALT') ?: 'wbs-ip-salt');

        return new ReferralService(
            Database::connect(),
            SharedServices::clock(),
            $salt,
            self::fraud(),
            // M4: a conversion opens the person's membership journey through the
            // platform's OWN rule engine (entry rule), reusing the same seam the
            // ContactBookService already uses — no forked journey path.
            new JourneySignalAdapter(JourneyServices::journeySignals()),
            // Field-sync: landing captures inherit the referrer's placement
            // (same ProspectGroupResolver every other capture path uses).
            self::prospectGroups(false),
            // Optional integration-decision inputs on the landing (gated by the
            // placement group's `capture_inputs` list, default empty).
            self::integration(false),
        );
    }

    /**
     * Where a prospect BELONGS: the group of the mentor/sponsor who owns them.
     * A prospect is never offered a choice of group (onboarding model), so this
     * derives placement from a person's own membership.
     */
    public static function prospectGroups(bool $getShared = true): ProspectGroupResolver
    {
        if ($getShared) {
            return static::getSharedInstance('prospectGroups');
        }

        return new ProspectGroupResolver(Database::connect());
    }

    /**
     * Inactivity transfer: moves a quiet prospect's belonging (and sponsor) to
     * the mentor who actually follows them up. The quiet period is HIERARCHICAL
     * GROUP CONFIG (`referrals.prospect_transfer`, default OFF), resolved through
     * the platform's own EffectiveConfigResolver — not env, not a global.
     */
    public static function prospectTransfers(bool $getShared = true): ProspectTransferService
    {
        if ($getShared) {
            return static::getSharedInstance('prospectTransfers');
        }

        return new ProspectTransferService(
            Database::connect(),
            SharedServices::clock(),
            self::prospectGroups(false),
            AdminServices::effectiveConfig(),
            GroupServices::memberships(),
            self::sponsorships(false),
            AuditServices::auditLogger(),
        );
    }

    /**
     * Maker-checker gate over the inactivity transfer: when a subtree sets
     * `referrals.prospect_transfer.requires_review`, a due transfer is queued here
     * for a second leader (maker != checker, eligibility re-checked at approve)
     * instead of being applied on the spot. Approval delegates to
     * {@see ProspectTransferService::apply()}, so the queue changes WHO signs off,
     * never WHAT a transfer does.
     */
    public static function prospectTransferReviews(bool $getShared = true): ProspectTransferReviewService
    {
        if ($getShared) {
            return static::getSharedInstance('prospectTransferReviews');
        }

        return new ProspectTransferReviewService(
            Database::connect(),
            SharedServices::clock(),
            self::prospectTransfers(false),
            AuditServices::auditLogger(),
        );
    }

    /**
     * Address book / outreach contact management (birds-eye downline, triage,
     * consent-gated GPS tagging, staff bulk, dated decisions). Built on the
     * enriched `prospects` table; uses Geo LocationService for consent-gated
     * coordinate persistence.
     */
    public static function contactBook(bool $getShared = true): ContactBookService
    {
        if ($getShared) {
            return static::getSharedInstance('contactBook');
        }

        return new ContactBookService(
            Database::connect(),
            SharedServices::clock(),
            GeoServices::location(),
            SharedServices::groupScope(),
            self::sponsorResolver(),
            self::sponsorships(),
            // Follow-up attendance flows through the platform's OWN registrar /
            // enroller so it obeys the pre-event gates (capacity/waitlist/
            // published-only) and is picked up by event change/cancel/reminder
            // notifications — reusing Events/Courses, not forking.
            new EventRegistrarAdapter(EventServices::eventRegistrations()),
            new CourseEnrollerAdapter(CourseServices::enrollments()),
            // R3: a `join_group` decision becomes a real belonging + a journey
            // signal, routed through the platform's OWN membership + journey
            // services (join policy, conflict rules, stage ladder) — no fork.
            new GroupMembershipAdapter(GroupServices::memberships()),
            new JourneySignalAdapter(JourneyServices::journeySignals()),
            // R4: the follow-up sweep reminds owners of due contacts through the
            // platform's gated NotificationService (opt-out/quiet-hours/dedupe).
            new FollowUpNotifierAdapter(NotificationServices::notifications()),
            // Onboarding: placement is derived from the mentor's own group (the
            // prospect is not offered a choice), and a quiet prospect transfers to
            // the mentor who follows them up.
            self::prospectGroups(false),
            self::prospectTransfers(false),
            self::prospectTransferReviews(false),
            // users-writer parity: contact→user promotion normalizes phone with
            // the SAME identity tooling as self-registration + org-default
            // locale/timezone (field-sync).
            IdentityServices::phoneNormalizer(),
            IdentityServices::identityPolicies(),
            // Assisted integration-decision inputs on create/bulk/guest
            // (gated by the placement group's `capture_inputs`, default []).
            self::integration(false),
        );
    }
}
