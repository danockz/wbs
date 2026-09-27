<?php

declare(strict_types=1);

namespace WBS\Events\Sweep;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C, E-C1): release expired ticket-holds back to inventory.
 *
 * A `ticket_holds` row is a short-lived atomic inventory reservation. The
 * checkout/hold paths only expire stale holds LAZILY (when someone tries a new
 * hold on that same event), so a quiet event accumulates `held` rows past their
 * `expires_at` forever — those seats never re-enter capacity maths and, worse,
 * the `th_active_uq (event_id, user_id, status)` UNIQUE key wedges the buyer out
 * of ever holding again. This wraps the already-idempotent
 * RegistrationService::processExpiredHolds() (bounded batch, re-asserts
 * status+expiry in the UPDATE) so a second pass in the same window releases 0.
 *
 * Pure inventory hygiene — no config gate, no notifications (mirrors the
 * session-prune sweep).
 */
final class ExpireHoldsSweep implements SweepContract
{
    public function key(): string
    {
        return 'events.expire-holds';
    }

    public function description(): string
    {
        return 'Release expired ticket-holds back to inventory so lapsed reservations stop blocking seats.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = EventServices::eventRegistrations()->processExpiredHolds($organizationId, $limit);

        $expired = (int) ($r['expired'] ?? 0);

        return SweepResult::ok($expired, [
            'scanned' => (int) ($r['scanned'] ?? 0),
            'expired' => $expired,
        ]);
    }
}
