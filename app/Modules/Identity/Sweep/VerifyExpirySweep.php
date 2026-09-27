<?php

declare(strict_types=1);

namespace WBS\Identity\Sweep;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, M6): nudge, then expire, stale `pending_verification`
 * accounts. That state was a real starting point (self-registration) but nothing
 * reminded or expired it — an unverified account lingered forever. Wraps the
 * config-gated (DEFAULT OFF), idempotent
 * AccountLifecycleService::processVerifyExpiry(): reminds accounts past the
 * remind window (deduped per round) and deactivates those past the expiry window
 * through the audited transition path. `swept` = reminders + expirations this
 * pass.
 */
final class VerifyExpirySweep implements SweepContract
{
    public function key(): string
    {
        return 'identity.verify-expiry';
    }

    public function description(): string
    {
        return 'Remind and expire stale pending-verification accounts (config-gated, default off).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r        = IdentityServices::accountLifecycle()->processVerifyExpiry($organizationId, $limit);
        $reminded = (int) ($r['reminded'] ?? 0);
        $expired  = (int) ($r['expired'] ?? 0);

        return SweepResult::ok($reminded + $expired, [
            'scanned'       => (int) ($r['scanned'] ?? 0),
            'reminded'      => $reminded,
            'expired'       => $expired,
            'skipped_gated' => (int) ($r['skipped_gated'] ?? 0),
        ]);
    }
}
