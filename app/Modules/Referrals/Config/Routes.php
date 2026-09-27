<?php

declare(strict_types=1);

/**
 * Member integration self-service + mentor confirmation queue.
 */
if (! isset($routes)) {
    return;
}

$routes->get('my/integration', '\WBS\Referrals\Controllers\IntegrationController::mine', ['filter' => 'auth']);
$routes->post('my/integration', '\WBS\Referrals\Controllers\IntegrationController::declareSelf', ['filter' => ['auth', 'webcsrf']]);
$routes->get('me/integration-decisions', '\WBS\Referrals\Controllers\IntegrationController::queue', ['filter' => 'auth']);
$routes->post('me/integration-decisions/confirm/(:segment)', '\WBS\Referrals\Controllers\IntegrationController::confirm/$1', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/integration-decisions/reject/(:segment)', '\WBS\Referrals\Controllers\IntegrationController::reject/$1', ['filter' => ['auth', 'webcsrf']]);
