<?php

declare(strict_types=1);

namespace WBS\Announcements\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Announcements\Services\AnnouncementAudienceResolver;
use WBS\Announcements\Services\AnnouncementService;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Config\Services as SharedServices;

class Services extends BaseService
{
    private static ?AnnouncementAudienceResolver $audience = null;

    private static ?AnnouncementService $announcements = null;

    public static function announcementAudience(bool $getShared = true): AnnouncementAudienceResolver
    {
        if ($getShared) {
            return self::$audience ??= self::announcementAudience(false);
        }

        return new AnnouncementAudienceResolver(Database::connect());
    }

    public static function announcements(bool $getShared = true): AnnouncementService
    {
        if ($getShared) {
            return self::$announcements ??= self::announcements(false);
        }

        return new AnnouncementService(
            Database::connect(),
            SharedServices::clock(),
            self::announcementAudience(false),
            NotificationServices::notifications(false),
        );
    }
}
