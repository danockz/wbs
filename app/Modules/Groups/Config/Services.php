<?php

declare(strict_types=1);

namespace WBS\Groups\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Audit\Config\Services as AuditServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Groups\Services\GroupCrosscutService;
use WBS\Groups\Services\GroupKindService;
use WBS\Groups\Services\GroupLifecycleService;
use WBS\Groups\Services\GroupMembershipService;
use WBS\Groups\Services\GroupPublicService;
use WBS\Groups\Services\GroupService;
use WBS\Groups\Services\JourneySignalAdapter;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Groups service bindings (SRS §6.3, FR-GRP-*). Auto-discovered.
 */
class Services extends BaseService
{
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
