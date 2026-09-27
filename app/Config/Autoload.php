<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\AutoloadConfig;

/**
 * Autoload configuration.
 *
 * Registers each WBS module as its own PSR-4 namespace so CodeIgniter's module
 * auto-discovery picks up their Config/Routes.php, Config/Services.php,
 * Database/Migrations and Filters automatically (SRS §6.3 modular HMVC).
 */
class Autoload extends AutoloadConfig
{
    /**
     * @var array<string, list<string>|string>
     */
    public $psr4 = [
        APP_NAMESPACE => APPPATH,

        // WBS modules — one PSR-4 root each (required for module discovery).
        'WBS\\Shared'        => APPPATH . 'Modules/Shared',
        'WBS\\Identity'      => APPPATH . 'Modules/Identity',
        'WBS\\AccessControl' => APPPATH . 'Modules/AccessControl',
        'WBS\\Groups'        => APPPATH . 'Modules/Groups',
        'WBS\\Referrals'     => APPPATH . 'Modules/Referrals',
        'WBS\\Geo'           => APPPATH . 'Modules/Geo',
        'WBS\\Notifications' => APPPATH . 'Modules/Notifications',
        'WBS\\Announcements' => APPPATH . 'Modules/Announcements',
        'WBS\\Contributions' => APPPATH . 'Modules/Contributions',
        'WBS\\Events'        => APPPATH . 'Modules/Events',
        'WBS\\Courses'       => APPPATH . 'Modules/Courses',
        'WBS\\Streaming'     => APPPATH . 'Modules/Streaming',
        'WBS\\Meetings'      => APPPATH . 'Modules/Meetings',
        'WBS\\Community'     => APPPATH . 'Modules/Community',
        'WBS\\Gamification'  => APPPATH . 'Modules/Gamification',
        'WBS\\Journey'       => APPPATH . 'Modules/Journey',
        'WBS\\Reporting'     => APPPATH . 'Modules/Reporting',
        'WBS\\Integrations'  => APPPATH . 'Modules/Integrations',
        'WBS\\Admin'         => APPPATH . 'Modules/Admin',
        'WBS\\Audit'         => APPPATH . 'Modules/Audit',
    ];

    /**
     * @var array<string, string>
     */
    public $classmap = [];

    /**
     * @var list<string>
     */
    public $files = [];

    /**
     * @var list<string>
     */
    public $helpers = [];
}
