<?php

declare(strict_types=1);

namespace WBS\Admin\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Admin\Services\EffectiveConfigResolver;
use WBS\Admin\Services\SettingsService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Admin service bindings (SRS FR-GRP-006 / FR-ACL-007). Auto-discovered.
 */
class Services extends BaseService
{
    public static function settings(bool $getShared = true): SettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('settings');
        }

        return new SettingsService(Database::connect(), SharedServices::clock());
    }

    public static function effectiveConfig(bool $getShared = true): EffectiveConfigResolver
    {
        if ($getShared) {
            return static::getSharedInstance('effectiveConfig');
        }

        return new EffectiveConfigResolver(Database::connect(), SharedServices::clock());
    }
}
