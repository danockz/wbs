<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use WBS\Groups\Config\Services as GroupServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * GET me/birthdays — signed-in hub of own + peer (7d) + ancestor-leader (30d)
 * birthdays. Month+day only; no year, no age.
 */
final class BirthdayController extends BaseController
{
    public function index()
    {
        $orgId  = $this->orgId();
        $userId = (string) ($this->actorId() ?? '');
        $hub    = GroupServices::birthdays()->forUser($orgId, $userId);

        return $this->respondWith(
            Result::ok($hub),
            htmlView: 'WBS\\Groups\\Views\\birthdays',
            viewData: [
                'hub'   => $hub,
                'title' => (string) lang('Groups.birthdays.heading'),
            ],
        );
    }
}
