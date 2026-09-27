<?php

declare(strict_types=1);

namespace WBS\Notifications\Sweep;

use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, N2): fan out APPROVED+QUEUED notification campaigns.
 * The lifecycle draft→pending_approval→approved→queued→sent had no consumer of
 * `queued`, so an approved campaign never actually reached its audience. Wraps the
 * idempotent CampaignService::fanOutAllQueued() (each campaign flips to `sent`
 * once dispatched, so a re-run re-selects nothing; per-recipient sends dedupe on
 * (campaign, user)). Separation of duties (approved_by ≠ requested_by) is already
 * enforced upstream at approve().
 */
final class RunQueuedCampaignsSweep implements SweepContract
{
    public function key(): string
    {
        return 'notifications.run-queued-campaigns';
    }

    public function description(): string
    {
        return 'Fan out approved+queued notification campaigns to their audiences.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 100;
        $count = NotificationServices::campaigns()->fanOutAllQueued($organizationId, $limit);

        return SweepResult::ok($count, ['campaigns_fanned_out' => $count]);
    }
}
