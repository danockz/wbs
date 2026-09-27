<?php

declare(strict_types=1);

namespace WBS\Meetings\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Events\Config\Services as EventServices;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Meetings\Services\EventAttendanceAdapter;
use WBS\Meetings\Services\MeetingService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Meetings service bindings (SRS FR-MTG-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function meetings(bool $getShared = true): MeetingService
    {
        if ($getShared) {
            return static::getSharedInstance('meetings');
        }

        return new MeetingService(
            Database::connect(),
            SharedServices::clock(),
            IntegrationServices::meetingProviders(),
            IntegrationServices::providerReliability(),
            // MT2 — reconcile provider evidence into platform attendance through
            // the Events check-in path (no forked attendance writer).
            new EventAttendanceAdapter(EventServices::checkin()),
        );
    }
}
