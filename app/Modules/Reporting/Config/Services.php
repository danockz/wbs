<?php

declare(strict_types=1);

namespace WBS\Reporting\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Reporting\Services\DashboardService;
use WBS\Reporting\Services\ExportService;
use WBS\Reporting\Services\MemberDashboardService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Reporting service bindings (SRS FR-RPT-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function dashboards(bool $getShared = true): DashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('dashboards');
        }

        return new DashboardService(Database::connect(), SharedServices::clock());
    }

    public static function exports(bool $getShared = true): ExportService
    {
        if ($getShared) {
            return static::getSharedInstance('exports');
        }

        return new ExportService(Database::connect(), SharedServices::clock());
    }

    public static function memberDashboard(bool $getShared = true): MemberDashboardService
    {
        if ($getShared) {
            return static::getSharedInstance('memberDashboard');
        }

        return new MemberDashboardService(Database::connect(), SharedServices::clock());
    }
}
