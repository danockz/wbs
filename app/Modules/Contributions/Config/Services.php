<?php

declare(strict_types=1);

namespace WBS\Contributions\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Contributions\Services\CauseService;
use WBS\Contributions\Services\CommitmentService;
use WBS\Contributions\Services\ContributionService;
use WBS\Contributions\Services\LedgerService;
use WBS\Contributions\Services\ManualContributionService;
use WBS\Contributions\Services\MetricsService;
use WBS\Contributions\Services\PartnershipService;
use WBS\Contributions\Services\ReconciliationService;
use WBS\Contributions\Services\RefundService;
use WBS\Contributions\Services\RewardCoordinator;
use WBS\Contributions\Services\WebhookInboxService;
use WBS\Gamification\Config\Services as GamificationServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Contributions (VBCS) service bindings (SRS FR-VBCS-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function causes(bool $getShared = true): CauseService
    {
        if ($getShared) {
            return static::getSharedInstance('causes');
        }

        return new CauseService(Database::connect(), SharedServices::clock());
    }

    public static function ledger(bool $getShared = true): LedgerService
    {
        if ($getShared) {
            return static::getSharedInstance('ledger');
        }

        return new LedgerService(Database::connect(), SharedServices::clock());
    }

    public static function contributions(bool $getShared = true): ContributionService
    {
        if ($getShared) {
            return static::getSharedInstance('contributions');
        }

        return new ContributionService(
            Database::connect(),
            SharedServices::clock(),
            self::ledger(false),
            SharedServices::outbox(false),
            IntegrationServices::providerReliability(),
        );
    }

    public static function webhookInbox(bool $getShared = true): WebhookInboxService
    {
        if ($getShared) {
            return static::getSharedInstance('webhookInbox');
        }

        return new WebhookInboxService(Database::connect(), SharedServices::clock());
    }

    public static function reconciliation(bool $getShared = true): ReconciliationService
    {
        if ($getShared) {
            return static::getSharedInstance('reconciliation');
        }

        return new ReconciliationService(Database::connect(), SharedServices::clock());
    }

    public static function refunds(bool $getShared = true): RefundService
    {
        if ($getShared) {
            return static::getSharedInstance('refunds');
        }

        return new RefundService(
            Database::connect(),
            SharedServices::clock(),
            self::ledger(false),
            SharedServices::outbox(false),
        );
    }

    public static function metrics(bool $getShared = true): MetricsService
    {
        if ($getShared) {
            return static::getSharedInstance('metrics');
        }

        return new MetricsService(
            Database::connect(),
            SharedServices::clock(),
            ReferralServices::sponsorships(false),
        );
    }

    public static function partnership(bool $getShared = true): PartnershipService
    {
        if ($getShared) {
            return static::getSharedInstance('partnership');
        }

        return new PartnershipService(
            Database::connect(),
            SharedServices::clock(),
            self::metrics(false),
            SharedServices::outbox(false),
        );
    }

    public static function commitments(bool $getShared = true): CommitmentService
    {
        if ($getShared) {
            return static::getSharedInstance('commitments');
        }

        return new CommitmentService(
            Database::connect(),
            SharedServices::clock(),
            self::causes(false),
            NotificationServices::notifications(false),
        );
    }

    public static function manualContributions(bool $getShared = true): ManualContributionService
    {
        if ($getShared) {
            return static::getSharedInstance('manualContributions');
        }

        return new ManualContributionService(
            Database::connect(),
            SharedServices::clock(),
            self::causes(false),
            self::ledger(false),
            SharedServices::outbox(false),
        );
    }

    public static function rewardCoordinator(bool $getShared = true): RewardCoordinator
    {
        if ($getShared) {
            return static::getSharedInstance('rewardCoordinator');
        }

        return new RewardCoordinator(
            GamificationServices::pointsEngine(false),
            SharedServices::idempotencyStore(false),
            self::metrics(false),
            self::partnership(false),
            Database::connect(),
        );
    }
}
