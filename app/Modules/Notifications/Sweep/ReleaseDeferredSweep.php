<?php

declare(strict_types=1);

namespace WBS\Notifications\Sweep;

use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, N1): release notification deliveries that were DEFERRED
 * by the RetentionPolicyGate (quiet-hours / frequency-cap) once their
 * `defer_until` has arrived. Without this pass a deferred row sat forever —
 * nothing watched the clock. Wraps the idempotent
 * NotificationService::releaseDeferred() (bounded batch, `status='deferred' AND
 * defer_until <= now` watermark), so a re-run releases only newly-due rows.
 */
final class ReleaseDeferredSweep implements SweepContract
{
    public function key(): string
    {
        return 'notifications.release-deferred';
    }

    public function description(): string
    {
        return 'Release deferred notification deliveries whose defer_until has arrived (re-queue for dispatch).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit    = isset($options['limit']) ? (int) $options['limit'] : 500;
        $released = NotificationServices::notifications()->releaseDeferred($organizationId, $limit);

        return SweepResult::ok($released, ['released' => $released]);
    }
}
