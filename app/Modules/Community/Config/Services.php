<?php

declare(strict_types=1);

namespace WBS\Community\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Community\Services\ContentSanitizer;
use WBS\Community\Services\FeedService;
use WBS\Community\Services\ModerationService;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Community service bindings (SRS FR-COM-*). Auto-discovered.
 */
class Services extends BaseService
{
    public static function contentSanitizer(bool $getShared = true): ContentSanitizer
    {
        if ($getShared) {
            return static::getSharedInstance('contentSanitizer');
        }

        return new ContentSanitizer();
    }

    public static function feed(bool $getShared = true): FeedService
    {
        if ($getShared) {
            return static::getSharedInstance('feed');
        }

        return new FeedService(
            Database::connect(),
            SharedServices::clock(),
            static::contentSanitizer(),
            AccessControlServices::authorization(),
        );
    }

    public static function moderation(bool $getShared = true): ModerationService
    {
        if ($getShared) {
            return static::getSharedInstance('moderation');
        }

        return new ModerationService(Database::connect(), SharedServices::clock());
    }
}
