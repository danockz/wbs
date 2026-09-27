<?php

declare(strict_types=1);

namespace WBS\Audit\Config;

use CodeIgniter\Config\BaseService;
use Config\Database;
use WBS\Audit\Services\AuditLogger;
use WBS\Shared\Config\Services as SharedServices;

/**
 * Audit service bindings (SRS §6.3, NFR-SEC-007). Auto-discovered.
 */
class Services extends BaseService
{
    public static function auditLogger(bool $getShared = true): AuditLogger
    {
        if ($getShared) {
            return static::getSharedInstance('auditLogger');
        }

        return new AuditLogger(Database::connect(), SharedServices::clock());
    }
}
