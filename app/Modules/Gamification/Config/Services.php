<?php

declare(strict_types=1);

namespace WBS\Gamification\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Gamification\Services\AchievementService;
use WBS\Gamification\Services\ActivityCatalogService;
use WBS\Gamification\Services\BadgeService;
use WBS\Gamification\Services\CampaignService;
use WBS\Gamification\Services\ConfigReviewAdapter;
use WBS\Gamification\Services\ConfigService;
use WBS\Gamification\Services\FollowUpService;
use WBS\Gamification\Services\FraudReviewAgingService;
use WBS\Gamification\Services\PointsEngineRejectionAdapter;
use WBS\Gamification\Services\LeaderboardService;
use WBS\Gamification\Services\PointsEngine;
use WBS\Gamification\Services\RankService;
use WBS\Gamification\Services\RollupService;
use WBS\Gamification\Services\RuleService;
use WBS\Gamification\Services\SeasonService;
use WBS\Gamification\Services\StreakService;
use WBS\Journey\Config\Services as JourneyServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Gamification service bindings (SRS FR-GAM-*). Auto-discovered.
 */
class Services extends BaseService
{
    /** Module-local shared instance (avoids the global 'campaigns' key collision). */
    private static ?CampaignService $sharedCampaigns = null;

    /** Module-local shared instance (avoids the global 'rules' key collision). */
    private static ?RuleService $sharedRules = null;

    public static function seasons(bool $getShared = true): SeasonService
    {
        if ($getShared) {
            return static::getSharedInstance('seasons');
        }

        return new SeasonService(Database::connect(), SharedServices::clock(), self::config());
    }

    /**
     * G2 — open fraud-review aging (remind → escalate → optional timeout). Uses
     * the PUBLIC points engine so a timeout auto-reject posts a compensating
     * reversal, plus notifications for the reminder/escalation fan-out.
     */
    public static function fraudReviewAging(bool $getShared = true): FraudReviewAgingService
    {
        if ($getShared) {
            return static::getSharedInstance('fraudReviewAging');
        }

        return new FraudReviewAgingService(
            Database::connect(),
            SharedServices::clock(),
            new ConfigReviewAdapter(self::config()),
            new PointsEngineRejectionAdapter(self::pointsEngine()),
            NotificationServices::notifications(),
        );
    }

    public static function streaks(bool $getShared = true): StreakService
    {
        if ($getShared) {
            return static::getSharedInstance('streaks');
        }

        return new StreakService(Database::connect(), SharedServices::clock(), SharedServices::groupScope());
    }

    /**
     * Badge catalog CRUD + manual grant/revoke (org + group scoped, most-
     * specific-wins like the other award catalogs).
     */
    public static function badges(bool $getShared = true): BadgeService
    {
        if ($getShared) {
            return static::getSharedInstance('badges');
        }

        return new BadgeService(Database::connect(), SharedServices::clock(), SharedServices::groupScope());
    }

    /**
     * Group campaigns / "projects" — time-boxed, group-scoped, target-based
     * competitions with repeatable awards and configurable top-N recognition.
     */
    public static function campaigns(bool $getShared = true): CampaignService
    {
        // NOTE: uses a module-local shared cache rather than
        // getSharedInstance('campaigns'): the framework's shared registry is
        // keyed by a global string, and WBS\Notifications also exposes a
        // campaigns() service, so a shared 'campaigns' key collides across
        // modules and can return the wrong CampaignService type.
        if ($getShared) {
            return static::$sharedCampaigns ??= self::campaigns(false);
        }

        return new CampaignService(Database::connect(), SharedServices::clock());
    }

    public static function ranks(bool $getShared = true): RankService
    {
        if ($getShared) {
            return static::getSharedInstance('ranks');
        }

        return new RankService(Database::connect(), SharedServices::clock(), SharedServices::groupScope());
    }

    public static function rules(bool $getShared = true): RuleService
    {
        // NOTE: uses a module-local shared cache rather than
        // getSharedInstance('rules'): the framework's shared registry is keyed by
        // a global string, and WBS\AccessControl also exposes a rules() service,
        // so a shared 'rules' key collides across modules and can return the
        // wrong RuleService type (see the campaigns() note above).
        if ($getShared) {
            return static::$sharedRules ??= self::rules(false);
        }

        return new RuleService(Database::connect(), SharedServices::clock());
    }

    /**
     * Activity catalog — the Win/Build/Send grouping of earning activities for
     * the admin console and member "ways to earn" view.
     */
    public static function activityCatalog(bool $getShared = true): ActivityCatalogService
    {
        if ($getShared) {
            return static::getSharedInstance('activityCatalog');
        }

        return new ActivityCatalogService(
            Database::connect(),
            SharedServices::clock(),
            SharedServices::groupScope(),
        );
    }

    /** Typed runtime configuration store for the gamification subsystem. */
    public static function config(bool $getShared = true): ConfigService
    {
        if ($getShared) {
            return static::getSharedInstance('config');
        }

        return new ConfigService(Database::connect(), SharedServices::clock());
    }

    /**
     * Configurable follow-ups (Build/Send activity). Wired WITH the public
     * points engine so recording a follow-up awards points to the follower.
     */
    public static function followUps(bool $getShared = true): FollowUpService
    {
        if ($getShared) {
            return static::getSharedInstance('followUps');
        }

        return new FollowUpService(
            Database::connect(),
            SharedServices::clock(),
            self::pointsEngine(),
            SharedServices::groupScope(),
            JourneyServices::journeySignals(),
        );
    }

    /**
     * Achievements engine. It uses a BARE PointsEngine (no achievement hook) so
     * that posting spendable bonus points on unlock can never recurse back into
     * achievement evaluation.
     */
    public static function achievements(bool $getShared = true): AchievementService
    {
        if ($getShared) {
            return static::getSharedInstance('achievements');
        }

        $barePoints = new PointsEngine(
            Database::connect(),
            SharedServices::clock(),
            self::seasons(),
            null,
            null,
            self::rollup(),
        );

        return new AchievementService(
            Database::connect(),
            SharedServices::clock(),
            $barePoints,
            self::seasons(),
            self::streaks(),
            self::ranks(),
            SharedServices::groupScope(),
        );
    }

    public static function leaderboard(bool $getShared = true): LeaderboardService
    {
        if ($getShared) {
            return static::getSharedInstance('leaderboard');
        }

        return new LeaderboardService(
            Database::connect(),
            SharedServices::clock(),
            self::seasons(),
            self::ranks(),
        );
    }

    /**
     * Public points engine — WITH the achievement hook, so a final award
     * triggers achievement evaluation for the subject.
     */
    public static function pointsEngine(bool $getShared = true): PointsEngine
    {
        if ($getShared) {
            return static::getSharedInstance('pointsEngine');
        }

        return new PointsEngine(
            Database::connect(),
            SharedServices::clock(),
            self::seasons(),
            self::achievements(),
            self::campaigns(),
            self::rollup(),
        );
    }

    /**
     * Ancestor roll-up + group-attribution service backing group/ancestor/
     * cross-cut ranking (design doc Part B). Pure derivation of point_ledger +
     * group_closure; used by PointsEngine on every final award/reversal and by
     * the gamification:rebuild-rollup command.
     */
    public static function rollup(bool $getShared = true): RollupService
    {
        if ($getShared) {
            return static::getSharedInstance('rollup');
        }

        return new RollupService(Database::connect(), SharedServices::clock());
    }
}
