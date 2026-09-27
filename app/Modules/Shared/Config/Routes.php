<?php

declare(strict_types=1);

/**
 * Console aliases loaded with Shared (always on the host). Fixes
 * "Can't find a route for GET: announcements" when app/Config/Routes.php is stale.
 */
if (! isset($routes)) {
    return;
}

$routes->get('announcements', '\WBS\Announcements\Controllers\AnnouncementController::index', ['filter' => ['auth', 'authorize:notification.send,any']]);
$routes->get('announcements/', '\WBS\Announcements\Controllers\AnnouncementController::index', ['filter' => ['auth', 'authorize:notification.send,any']]);
$routes->get('announcements/inbox', '\WBS\Announcements\Controllers\AnnouncementController::inbox', ['filter' => 'auth']);
$routes->get('announcements/inbox/', '\WBS\Announcements\Controllers\AnnouncementController::inbox', ['filter' => 'auth']);
