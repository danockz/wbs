<?php

declare(strict_types=1);

namespace WBS\Notifications\Sweep;

use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, N4): build + dispatch daily/weekly notification
 * DIGESTS.
 *
 * `digest_frequency` supported `instant|daily|weekly|off` but only `off` did
 * anything — a `daily`/`weekly` user was treated like `instant`. Now the
 * RetentionPolicyGate HOLDS each such message as `digest_pending` (with a
 * `defer_until` at the next window boundary), and this pass bundles all held
 * messages for a (user, channel) whose window has closed into ONE digest
 * dispatch, marking each bundled row `digested`. Wraps the bounded, idempotent
 * NotificationService::buildDigests().
 */
final class BuildDigestsSweep implements SweepContract
{
    public function key(): string
    {
        return 'notifications.build-digests';
    }

    public function description(): string
    {
        return 'Bundle held daily/weekly notifications into per-recipient digests and dispatch them.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 1000;

        $r = NotificationServices::notifications()->buildDigests($organizationId, $limit);

        // "Swept" = digests actually dispatched this pass.
        return SweepResult::ok((int) $r['digests'], [
            'digests'  => (int) $r['digests'],
            'messages' => (int) $r['messages'],
        ]);
    }
}
