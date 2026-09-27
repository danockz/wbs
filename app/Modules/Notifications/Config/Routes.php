<?php

declare(strict_types=1);

/**
 * Notification template + credential consoles.
 * Loaded via Config\Routing::$routeFiles (and module discovery) so a stale
 * app/Config/Routes.php on the host cannot 404 GET notifications/templates.
 */
if (! isset($routes)) {
    return;
}

$read = ['filter' => ['auth', 'authorize:provider.configure,any']];
$write = ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']];

$routes->get('notifications/templates', '\WBS\Notifications\Controllers\TemplateController::index', $read);
$routes->post('notifications/templates', '\WBS\Notifications\Controllers\TemplateController::create', $write);
$routes->get('notifications/templates/(:segment)/edit', '\WBS\Notifications\Controllers\TemplateController::edit/$1', $read);
$routes->post('notifications/templates/(:segment)/retire', '\WBS\Notifications\Controllers\TemplateController::retire/$1', $write);
$routes->post('notifications/templates/(:segment)', '\WBS\Notifications\Controllers\TemplateController::revise/$1', $write);

$routes->get('notifications/credentials', '\WBS\Notifications\Controllers\GroupCredentialController::index', $read);
$routes->post('notifications/credentials/connections', '\WBS\Notifications\Controllers\GroupCredentialController::create', $write);
$routes->post('notifications/credentials/connections/(:segment)/secrets', '\WBS\Notifications\Controllers\GroupCredentialController::setSecret/$1', ['filter' => ['auth', 'ratelimit:provider.configure', 'webcsrf']]);
$routes->post('notifications/credentials/connections/(:segment)/grants', '\WBS\Notifications\Controllers\GroupCredentialController::grant/$1', $write);
$routes->post('notifications/credentials/grants/(:segment)/revoke', '\WBS\Notifications\Controllers\GroupCredentialController::revokeGrant/$1', $write);
