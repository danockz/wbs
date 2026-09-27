<?php

declare(strict_types=1);

/**
 * Announcement console + inbox. Full-path so /announcements matches
 * without a trailing slash. Trailing-slash twins included.
 */
if (! isset($routes)) {
    return;
}

$idx = '\WBS\Announcements\Controllers\AnnouncementController::index';
$create = '\WBS\Announcements\Controllers\AnnouncementController::create';
$inbox = '\WBS\Announcements\Controllers\AnnouncementController::inbox';
$ack = '\WBS\Announcements\Controllers\AnnouncementController::ack/$1';
$submit = '\WBS\Announcements\Controllers\AnnouncementController::submit/$1';
$approve = '\WBS\Announcements\Controllers\AnnouncementController::approve/$1';
$cancel = '\WBS\Announcements\Controllers\AnnouncementController::cancel/$1';

$read = ['filter' => ['auth', 'authorize:notification.send,any']];
$write = ['filter' => ['auth', 'authorize:notification.send,any', 'webcsrf']];
$auth = ['filter' => 'auth'];

$routes->get('announcements', $idx, $read);
$routes->get('announcements/', $idx, $read);
$routes->post('announcements', $create, $write);
$routes->post('announcements/', $create, $write);
$routes->get('announcements/inbox', $inbox, $auth);
$routes->get('announcements/inbox/', $inbox, $auth);
$routes->post('announcements/(:segment)/ack', $ack, ['filter' => ['auth', 'webcsrf']]);
$routes->post('announcements/(:segment)/submit', $submit, $write);
$routes->post('announcements/(:segment)/approve', $approve, ['filter' => ['auth', 'authorize:notification.broadcast.approve,any', 'webcsrf']]);
$routes->post('announcements/(:segment)/cancel', $cancel, $write);
