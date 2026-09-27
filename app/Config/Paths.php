<?php

declare(strict_types=1);

namespace Config;

/**
 * Holds the paths that are used by the system to locate the main directories,
 * frameworks, etc. Modifying these allows you to re-structure your application,
 * share a framework install between apps, or move the writable location.
 *
 * NOTE: all paths are relative to this file's location (app/Config), resolved
 * with realpath() by the framework where needed.
 */
class Paths
{
    /**
     * The name of the directory that holds the CodeIgniter framework "system"
     * files (installed via Composer under vendor/codeigniter4/framework).
     */
    public string $systemDirectory = __DIR__ . '/../../vendor/codeigniter4/framework/system';

    /**
     * The path to the application directory.
     */
    public string $appDirectory = __DIR__ . '/..';

    /**
     * The path to the writable directory (logs, cache, session, uploads).
     */
    public string $writableDirectory = __DIR__ . '/../../writable';

    /**
     * The path to the tests directory.
     */
    public string $testsDirectory = __DIR__ . '/../../tests';

    /**
     * The path to the views directory (relative to appDirectory unless absolute).
     */
    public string $viewDirectory = __DIR__ . '/../Views';
}
