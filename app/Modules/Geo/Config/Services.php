<?php

declare(strict_types=1);

namespace WBS\Geo\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Geo\Services\GeoImportService;
use WBS\Geo\Services\GeoResolverService;
use WBS\Geo\Services\LocationService;
use WBS\Geo\Services\LocationSyncService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Geo service bindings (SRS §8). Auto-discovered.
 */
class Services extends BaseService
{
    public static function geoImport(bool $getShared = true): GeoImportService
    {
        if ($getShared) {
            return static::getSharedInstance('geoImport');
        }

        return new GeoImportService(Database::connect(), SharedServices::clock());
    }

    public static function location(bool $getShared = true): LocationService
    {
        if ($getShared) {
            return static::getSharedInstance('location');
        }

        return new LocationService(Database::connect(), SharedServices::clock(), static::locationSync());
    }

    public static function resolver(bool $getShared = true): GeoResolverService
    {
        if ($getShared) {
            return static::getSharedInstance('resolver');
        }

        return new GeoResolverService(Database::connect());
    }

    public static function locationSync(bool $getShared = true): LocationSyncService
    {
        if ($getShared) {
            return static::getSharedInstance('locationSync');
        }

        return new LocationSyncService(
            Database::connect(),
            SharedServices::clock(),
            static::resolver(),
        );
    }
}
