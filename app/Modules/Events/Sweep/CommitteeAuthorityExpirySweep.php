<?php

declare(strict_types=1);

namespace WBS\Events\Sweep;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter: expire committee memberships whose window has passed.
 *
 * A committee member's authority is a time-bounded delegation cut for one event's
 * window (the event plus its hand-over grace), so it stops being HONOURED the
 * moment it lapses — the PDP only ever counts active, unexpired delegations. What
 * lapsing does not do by itself is tidy the rows: the membership would still read
 * `active` on the committee console and the `delegations` row would still read
 * `active` on the leader's delegations screen, both of which are dishonest about
 * who can currently act.
 *
 * This wraps the already-idempotent CommitteeService::expireDue(): it marks each
 * lapsed membership `expired` and revokes its delegation (which cascades to
 * anything the member sub-delegated, because a derived authority cannot outlive
 * its source). A second pass in the same window finds nothing.
 *
 * Not config-gated: it only ever runs for memberships that exist, and memberships
 * can only exist where the `event_committee` capability is already on.
 */
final class CommitteeAuthorityExpirySweep implements SweepContract
{
    public function key(): string
    {
        return 'events.committee-expiry';
    }

    public function description(): string
    {
        return 'Expire event-committee memberships past their window and revoke the delegations they carried.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = EventServices::eventCommittees()->expireDue($organizationId, $limit);

        $expired = (int) ($r['expired'] ?? 0);

        return SweepResult::ok($expired, [
            'scanned' => (int) ($r['scanned'] ?? 0),
            'expired' => $expired,
            'revoked' => (int) ($r['revoked'] ?? 0),
        ]);
    }
}
