<?php

declare(strict_types=1);

namespace WBS\Streaming\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Streaming\Services\OverlayService;
use WBS\Streaming\Services\SettingsFeatureGateAdapter;
use WBS\Streaming\Services\StreamEngagementService;
use WBS\Streaming\Services\StreamGivingService;
use WBS\Streaming\Services\StreamRelayService;
use WBS\Streaming\Services\StreamService;

/**
 * Streaming service bindings (SRS FR-STR-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function streams(bool $getShared = true): StreamService
    {
        if ($getShared) {
            return static::getSharedInstance('streams');
        }

        return new StreamService(
            Database::connect(),
            SharedServices::clock(),
            AccessControlServices::authorization(),
            IntegrationServices::streamProviders(),
            IntegrationServices::providerReliability(),
        );
    }

    public static function streamEngagement(bool $getShared = true): StreamEngagementService
    {
        if ($getShared) {
            return static::getSharedInstance('streamEngagement');
        }

        $salt = (string) (getenv('STREAM_IP_SALT') ?: getenv('REFERRAL_IP_SALT') ?: 'wbs-stream-salt');

        return new StreamEngagementService(
            Database::connect(),
            SharedServices::clock(),
            $salt,
            ContributionServices::contributions(),
        );
    }

    public static function overlays(bool $getShared = true): OverlayService
    {
        if ($getShared) {
            return static::getSharedInstance('overlays');
        }

        return new OverlayService(
            Database::connect(),
            SharedServices::clock(),
            ContributionServices::causes(),
        );
    }

    public static function streamGiving(bool $getShared = true): StreamGivingService
    {
        if ($getShared) {
            return static::getSharedInstance('streamGiving');
        }

        return new StreamGivingService(
            Database::connect(),
            SharedServices::clock(),
            ContributionServices::contributions(),
            ContributionServices::causes(),
        );
    }

    public static function streamRelay(bool $getShared = true): StreamRelayService
    {
        if ($getShared) {
            return static::getSharedInstance('streamRelay');
        }

        return new StreamRelayService(
            Database::connect(),
            SharedServices::clock(),
            NotificationServices::notifications(),
            new SettingsFeatureGateAdapter(AdminServices::settings()),
        );
    }
}
