<?php

declare(strict_types=1);

/**
 * Console routes that 404 when only a stale app/Config/Routes.php is on the host.
 * Full paths (not nested groups) so CI4 matches GET events/calendar/settings etc.
 */
if (! isset($routes)) {
    return;
}

$cert = ['filter' => ['auth', 'authorize:event.certificate.manage,any']];
$certW = ['filter' => ['auth', 'authorize:event.certificate.manage,any', 'webcsrf']];

$routes->get('event-committees', '\WBS\Events\Controllers\CommitteeController::hub', ['filter' => 'auth']);
$routes->get('event-committees/decisions', '\WBS\Events\Controllers\CommitteeController::queue', ['filter' => 'auth']);

$routes->get('certificates/templates', '\WBS\Events\Controllers\CertificateController::templatesConsole', $cert);
$routes->post('certificates/templates', '\WBS\Events\Controllers\CertificateController::createTemplate', $certW);
$routes->get('certificates/templates/(:segment)/edit', '\WBS\Events\Controllers\CertificateController::editTemplate/$1', $cert);
$routes->get('certificates/templates/(:segment)/preview', '\WBS\Events\Controllers\CertificateController::previewTemplate/$1', $cert);
$routes->get('certificates/templates/(:segment)', '\WBS\Events\Controllers\CertificateController::editTemplate/$1', $cert);
$routes->post('certificates/templates/(:segment)/retire', '\WBS\Events\Controllers\CertificateController::retireTemplate/$1', $certW);
$routes->post('certificates/templates/(:segment)', '\WBS\Events\Controllers\CertificateController::reviseTemplate/$1', $certW);

$routes->get('events/calendar/settings', '\WBS\Events\Controllers\EventController::calendarSettings', ['filter' => ['auth', 'authorize:event.create,any']]);
$routes->post('events/calendar/settings', '\WBS\Events\Controllers\EventController::saveCalendarSettings', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
