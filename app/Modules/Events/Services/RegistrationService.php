<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event registration with ATOMIC capacity control (SRS FR-EVT-016).
 *
 *  - One registration per person/event (UNIQUE er_person_uq).
 *  - Capacity counts confirmed registrations + live (unexpired) holds under a
 *    row-locking transaction, so payment success alone can never oversell.
 *  - When full, the person is placed on an ordered waitlist rather than rejected.
 */
final class RegistrationService
{
    private const HOLD_TTL_SECONDS = 600; // 10-minute inventory hold

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        // Invite-policy gate (gap G5). Optional so the atomic-capacity unit tests
        // can construct the service without it; when null, an 'invite' event is
        // treated fail-closed for non-assisted callers (INVITE_REQUIRED) so the
        // gate can never be silently skipped by a missing dependency.
        private readonly ?InvitationService $invitations = null,
    ) {
    }

    /**
     * Register (or waitlist) a person for an event.
     *
     * @param array<string,mixed> $opts group_attribution, source_ref, rsvp_state
     */
    /**
     * The viewer's own registration for an event, or null when they have none
     * (or only a cancelled one). Powers the RSVP/cancel controls on the event
     * page. One bounded read — resource-light.
     *
     * @return array{id:string,status:string,rsvp_state:string}|null
     */
    public function registrationFor(string $eventId, string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }
        $row = $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)
            ->get()->getRowArray();
        if ($row === null || (string) ($row['status'] ?? '') === 'cancelled') {
            return null;
        }

        return [
            'id'         => (string) $row['id'],
            'status'     => (string) ($row['status'] ?? 'registered'),
            'rsvp_state' => (string) ($row['rsvp_state'] ?? 'yes'),
        ];
    }

    /**
     * A member's own EVENTS HUB: everything about the events they are involved in,
     * in one place — split into upcoming (registered, not yet run) and past
     * (attended / historical), each enriched with the member's registration
     * status/RSVP, whether they checked in, their issued certificate (with public
     * verification id), and their paid ticket count. A member-facing read; the
     * caller supplies the authenticated user id (never trust a body param).
     *
     * RESOURCE-LIGHT: five bounded reads total regardless of history size — one
     * per source table (registrations, attendance, certificates, orders) keyed on
     * the user, then ONE batched events read over the union of referenced event
     * ids. No per-event fan-out.
     *
     * @return Result data: {
     *   user_id, now, counts:{upcoming,past,attended,certificates,tickets},
     *   upcoming:[event+my_* fields], past:[event+my_* fields]
     * }
     */
    public function myEvents(string $organizationId, string $userId, int $limit = 100): Result
    {
        if ($userId === '') {
            return Result::fail('BAD_USER', 'event.bad_user', 422);
        }
        $limit = max(1, min(500, $limit));
        $now   = $this->clock->nowUtcString();

        // 1) My registrations (exclude cancelled) keyed by event.
        $regByEvent = [];
        foreach (
            $this->db->table('event_registrations')
                ->select('event_id, status, rsvp_state')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status !=', 'cancelled')
                ->limit($limit)
                ->get()->getResultArray() as $r
        ) {
            $regByEvent[(string) $r['event_id']] = [
                'status'     => (string) ($r['status'] ?? 'registered'),
                'rsvp_state' => (string) ($r['rsvp_state'] ?? 'yes'),
            ];
        }

        // 2) My attendance (present rows) keyed by event.
        $attendedEvents = [];
        foreach (
            $this->db->table('event_attendance')
                ->select('event_id, checked_in_at')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'present')
                ->limit($limit)
                ->get()->getResultArray() as $a
        ) {
            $attendedEvents[(string) $a['event_id']] = (string) ($a['checked_in_at'] ?? '');
        }

        // 3) My issued certificates keyed by event.
        $certByEvent = [];
        foreach (
            $this->db->table('event_certificates')
                ->select('event_id, verification_id, status')
                ->where('user_id', $userId)
                ->whereIn('status', ['issued', 'revoked'])
                ->limit($limit)
                ->get()->getResultArray() as $c
        ) {
            $certByEvent[(string) $c['event_id']] = [
                'verification_id' => (string) ($c['verification_id'] ?? ''),
                'status'          => (string) ($c['status'] ?? ''),
            ];
        }

        // 4) My paid tickets (count of order lines) keyed by event.
        $ticketByEvent = [];
        foreach (
            $this->db->table('event_orders')
                ->select('event_id, quantity')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'paid')
                ->limit($limit)
                ->get()->getResultArray() as $o
        ) {
            $eid                 = (string) $o['event_id'];
            $ticketByEvent[$eid] = ($ticketByEvent[$eid] ?? 0) + (int) ($o['quantity'] ?? 0);
        }

        // Union of all referenced event ids → ONE batched events read.
        $ids = array_values(array_unique(array_merge(
            array_keys($regByEvent),
            array_keys($attendedEvents),
            array_keys($certByEvent),
            array_keys($ticketByEvent),
        )));

        $events = [];
        if ($ids !== []) {
            foreach (
                $this->db->table('events')
                    ->select('id, title, status, mode, starts_at, ends_at, capacity')
                    ->where('organization_id', $organizationId)
                    ->whereIn('id', $ids)
                    ->get()->getResultArray() as $e
            ) {
                $events[(string) $e['id']] = $e;
            }
        }

        $upcoming = [];
        $past     = [];
        foreach ($ids as $eid) {
            $e = $events[$eid] ?? null;
            if ($e === null) {
                continue; // event deleted / not in scope
            }
            $starts   = (string) ($e['starts_at'] ?? '');
            $status   = (string) ($e['status'] ?? '');
            $attended = isset($attendedEvents[$eid]);
            $row      = [
                'id'                 => $eid,
                'title'              => (string) ($e['title'] ?? ''),
                'status'             => $status,
                'mode'               => (string) ($e['mode'] ?? ''),
                'starts_at'          => $starts,
                'ends_at'            => (string) ($e['ends_at'] ?? ''),
                'my_registration'    => $regByEvent[$eid]['status'] ?? null,
                'my_rsvp'            => $regByEvent[$eid]['rsvp_state'] ?? null,
                'attended'           => $attended,
                'checked_in_at'      => $attendedEvents[$eid] ?? null,
                'certificate'        => $certByEvent[$eid] ?? null,
                'tickets'            => $ticketByEvent[$eid] ?? 0,
            ];
            // Past = has run (attended, completed, cancelled) or start is in the
            // past; everything else with a future start is upcoming.
            $isPast = $attended
                || in_array($status, ['completed', 'completed_no_attendance', 'cancelled'], true)
                || ($starts !== '' && $starts < $now);
            if ($isPast) {
                $past[] = $row;
            } else {
                $upcoming[] = $row;
            }
        }

        // Upcoming soonest-first; past most-recent-first.
        usort($upcoming, static fn ($a, $b) => ($a['starts_at'] ?? '') <=> ($b['starts_at'] ?? ''));
        usort($past, static fn ($a, $b) => ($b['starts_at'] ?? '') <=> ($a['starts_at'] ?? ''));

        return Result::ok([
            'user_id' => $userId,
            'now'     => $now,
            'counts'  => [
                'upcoming'     => count($upcoming),
                'past'         => count($past),
                'attended'     => count($attendedEvents),
                'certificates' => count($certByEvent),
                'tickets'      => array_sum($ticketByEvent),
            ],
            'upcoming' => $upcoming,
            'past'     => $past,
        ]);
    }

    /**
     * Lazy MENU BADGE count: how many UPCOMING events the member is actively
     * registered for (status=registered, not cancelled/waitlisted), optionally
     * narrowed to a set of organizing groups.
     *
     * "My events" is HIERARCHICAL-GROUP related: when a scope is active the caller
     * passes the resolved subtree (self + descendants) as `$groupIds`, so the
     * badge reflects only events organized within the member's current scope;
     * `null` = org-wide (every group). An empty array means "a scope with no
     * groups" → 0 (never silently widen to org-wide).
     *
     * Resource-light by design (this runs off the cached menu hot path, per
     * docs/DYNAMIC-MENU-DESIGN.md §6/§8.5): ONE indexed COUNT with a single JOIN,
     * no per-row fan-out, no view assembly. Safe to call with its own short TTL.
     */
    public function upcomingCount(string $organizationId, string $userId, ?array $groupIds = null): int
    {
        if ($userId === '') {
            return 0;
        }
        if (is_array($groupIds) && $groupIds === []) {
            return 0; // an active scope that resolves to no groups shows nothing
        }
        $now = $this->clock->nowUtcString();

        $q = $this->db->table('event_registrations er')
            ->join('events e', 'e.id = er.event_id', 'inner')
            ->where('er.organization_id', $organizationId)
            ->where('er.user_id', $userId)
            ->where('er.status', 'registered')
            ->where('e.starts_at >=', $now)
            ->where('e.status !=', 'cancelled')
            // L4 — an archived event never contributes to the "my events" badge.
            ->where('e.archived_at IS NULL', null, false);

        if (is_array($groupIds)) {
            // Bound the IN list defensively; the subtree is already finite.
            $q->whereIn('e.group_id', array_values(array_slice(array_unique($groupIds), 0, 2000)));
        }

        return (int) $q->countAllResults();
    }

    public function register(string $organizationId, string $eventId, string $userId, array $opts = []): Result
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if ($event['status'] !== 'published') {
            return Result::fail('NOT_OPEN', 'event.not_open', 409, ['status' => $event['status']]);
        }
        if ($event['registration_policy'] === 'closed') {
            return Result::fail('REG_CLOSED', 'event.reg_closed', 409);
        }

        // Invite-policy gate (gap G5): an invite-only event is FAIL-CLOSED. A
        // person may register only when eligible — staff/leader/follow-up
        // assisted, a member of the event's group subtree, a direct
        // user/email/phone invite, or a valid link token. `assisted_by` in opts
        // means an authorized organizer is registering on someone's behalf.
        if ($event['registration_policy'] === 'invite') {
            $reason = 'assisted';
            if ($this->invitations !== null) {
                $elig = $this->invitations->eligibility($event, $userId, [
                    'assisted'     => ($opts['assisted_by'] ?? null) !== null && (string) $opts['assisted_by'] !== '',
                    'invite_token' => (string) ($opts['invite_token'] ?? ''),
                ]);
                if (! $elig->ok) {
                    return $elig; // INVITE_REQUIRED (403) / REG_CLOSED
                }
                $reason = (string) ($elig->data['reason'] ?? 'assisted');
            } elseif (($opts['assisted_by'] ?? null) === null || (string) $opts['assisted_by'] === '') {
                // No gate wired and not an assisted call → fail closed.
                return Result::fail('INVITE_REQUIRED', 'Events.invite.errInviteRequired', 403, ['eligible' => false]);
            }
            // Remember the winning signal so we can consume a single-use invite
            // after the registration commits.
            $opts['__invite_reason'] = $reason;
        }

        // Idempotent: already registered/waitlisted?
        $existing = $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)
            ->get()->getRowArray();
        if ($existing !== null && $existing['status'] !== 'cancelled') {
            // Already on the roster — keep the seat but let the member CHANGE their
            // RSVP intent (yes/maybe/no) without re-queuing capacity.
            $newRsvp = (string) ($opts['rsvp_state'] ?? $existing['rsvp_state'] ?? 'yes');
            $changed = $newRsvp !== (string) ($existing['rsvp_state'] ?? 'yes');
            if ($changed) {
                $this->db->table('event_registrations')
                    ->where('id', $existing['id'])
                    ->update(['rsvp_state' => $newRsvp, 'updated_at' => $this->clock->nowUtcString()]);
            }

            return Result::ok([
                'registration_id' => $existing['id'],
                'status'          => $existing['status'],
                'rsvp_state'      => $newRsvp,
            ], 200, ['deduplicated' => ! $changed, 'rsvp_updated' => $changed]);
        }

        $now      = $this->clock->nowUtcString();
        $capacity = $event['capacity'] !== null ? (int) $event['capacity'] : null;

        $this->db->transStart();

        $waitlisted = false;
        if ($capacity !== null) {
            $this->expireStaleHolds($eventId);

            // Count confirmed registrations + live holds atomically.
            $confirmed = (int) ($this->db->query(
                'SELECT COUNT(*) AS c FROM event_registrations
                 WHERE event_id = ? AND status = "registered" FOR UPDATE',
                [$eventId],
            )->getRowArray()['c'] ?? 0);
            $held = (int) ($this->db->query(
                'SELECT COALESCE(SUM(quantity),0) AS c FROM ticket_holds
                 WHERE event_id = ? AND status = "held" AND expires_at > ? FOR UPDATE',
                [$eventId, $now],
            )->getRowArray()['c'] ?? 0);

            if (($confirmed + $held) >= $capacity) {
                $waitlisted = true;
            }
        }

        try {
            if ($waitlisted) {
                $pos = (int) ($this->db->query(
                    'SELECT COALESCE(MAX(position),0) AS p FROM waitlist_entries WHERE event_id = ?',
                    [$eventId],
                )->getRowArray()['p'] ?? 0) + 1;

                $regId = Uuid::v7();
                $this->db->table('event_registrations')->insert([
                    'id'                => $regId,
                    'organization_id'   => $organizationId,
                    'event_id'          => $eventId,
                    'user_id'           => $userId,
                    'group_attribution' => $opts['group_attribution'] ?? null,
                    'source_ref'        => $opts['source_ref'] ?? null,
                    'status'            => 'waitlisted',
                    'rsvp_state'        => $opts['rsvp_state'] ?? 'yes',
                    'created_at'        => $now,
                ]);
                $this->db->table('waitlist_entries')->insert([
                    'id'              => Uuid::v7(),
                    'organization_id' => $organizationId,
                    'event_id'        => $eventId,
                    'user_id'         => $userId,
                    'position'        => $pos,
                    'status'          => 'waiting',
                    'created_at'      => $now,
                ]);
            } else {
                $regId = Uuid::v7();
                $this->db->table('event_registrations')->insert([
                    'id'                => $regId,
                    'organization_id'   => $organizationId,
                    'event_id'          => $eventId,
                    'user_id'           => $userId,
                    'group_attribution' => $opts['group_attribution'] ?? null,
                    'source_ref'        => $opts['source_ref'] ?? null,
                    'status'            => 'registered',
                    'rsvp_state'        => $opts['rsvp_state'] ?? 'yes',
                    'created_at'        => $now,
                ]);
            }
        } catch (Throwable) {
            $this->db->transComplete();

            return Result::fail('REGISTER_FAILED', 'event.register_failed', 409);
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return Result::fail('REGISTER_FAILED', 'event.register_failed', 500);
        }

        // Consume the winning single-use invite/token so it can't be reused
        // (no-op for assisted / group-member signals, which have no invite row).
        if ($this->invitations !== null && isset($opts['__invite_reason'])) {
            $this->invitations->consumeFor($eventId, $userId, (string) $opts['__invite_reason'], [
                'invite_token' => (string) ($opts['invite_token'] ?? ''),
            ]);
        }

        return Result::created([
            'registration_id' => $regId,
            'status'          => $waitlisted ? 'waitlisted' : 'registered',
        ]);
    }

    /** Create a short-lived atomic inventory hold (e.g. before payment). */
    public function hold(string $organizationId, string $eventId, string $userId, int $quantity = 1): Result
    {
        $now     = $this->clock->now();
        $expires = $now->modify('+' . self::HOLD_TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $this->expireStaleHolds($eventId);

        try {
            $id = Uuid::v7();
            $this->db->table('ticket_holds')->insert([
                'id'              => $id,
                'organization_id' => $organizationId,
                'event_id'        => $eventId,
                'user_id'         => $userId,
                'quantity'        => $quantity,
                'status'          => 'held',
                'expires_at'      => $expires,
                'created_at'      => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (Throwable) {
            return Result::fail('HOLD_EXISTS', 'event.hold_exists', 409);
        }

        return Result::created(['hold_id' => $id, 'expires_at' => $expires]);
    }

    // -- E-B2: account-teardown / merge reactions ----------------------------

    /** Event statuses that are still "live" — a registration on one holds a real,
     *  future seat worth releasing. Past/cancelled/completed events are history. */
    private const UPCOMING_EVENT_STATUSES = ['draft', 'published'];

    /**
     * E-B2 (teardown half) — RELEASE a deactivated / suspended / anonymized
     * person's future event footprint so their seats and holds return to
     * inventory instead of silently blocking others.
     *
     * For every UPCOMING event (draft/published) the subject has an active
     * registration on:
     *   - a `registered` seat is cancelled and the next waitlisted person is
     *     promoted into the freed seat (same promotion path as cancel());
     *   - a `waitlisted` entry is cancelled and its `waitlist_entries` row
     *     expired (they held no seat, so nobody is promoted).
     * All of the subject's live inventory `ticket_holds` are released, and any
     * stray `waiting` waitlist rows are expired.
     *
     * Registrations on events that already ran (completed / cancelled) are left
     * as historical facts. SYSTEM authority, fault-isolated caller, idempotent
     * (a re-run finds no active rows).
     *
     * @return Result data: released_registrations, promoted, released_holds,
     *                expired_waitlist
     */
    public function releaseActiveForSubject(string $organizationId, string $userId, string $reasonCode): Result
    {
        if ($organizationId === '' || $userId === '') {
            return Result::fail('REG_TEARDOWN_BAD_INPUT', 'event.reg_teardown_bad_input', 422);
        }

        $now = $this->clock->nowUtcString();

        $regs = $this->db->table('event_registrations')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereIn('status', ['registered', 'waitlisted'])
            ->get()->getResultArray();

        $released  = 0;
        $promoted  = [];
        foreach ($regs as $reg) {
            $eventId = (string) $reg['event_id'];
            $event   = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
            // Only release seats on events that have not run yet; a completed /
            // cancelled event's registration is history and stays put.
            if ($event === null || ! in_array((string) ($event['status'] ?? ''), self::UPCOMING_EVENT_STATUSES, true)) {
                continue;
            }

            $wasRegistered = (string) $reg['status'] === 'registered';
            $this->db->table('event_registrations')
                ->where('id', $reg['id'])
                ->update(['status' => 'cancelled', 'updated_at' => $now]);
            $released++;

            // A cancelled waitlisted entry also expires its waitlist row.
            if (! $wasRegistered) {
                $this->db->table('waitlist_entries')
                    ->where('event_id', $eventId)->where('user_id', $userId)->where('status', 'waiting')
                    ->update(['status' => 'expired']);

                continue;
            }

            // A freed CONFIRMED seat promotes the next waiting person.
            $next = $this->db->table('waitlist_entries')
                ->where('event_id', $eventId)->where('status', 'waiting')
                ->orderBy('position', 'ASC')->get()->getRowArray();
            if ($next !== null) {
                $this->db->table('waitlist_entries')->where('id', $next['id'])->update(['status' => 'promoted']);
                $this->db->table('event_registrations')
                    ->where('event_id', $eventId)->where('user_id', $next['user_id'])
                    ->update(['status' => 'registered', 'updated_at' => $now]);
                $promoted[] = (string) $next['user_id'];
            }
        }

        // Release the subject's live inventory holds (any event).
        $this->db->table('ticket_holds')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'held')
            ->update(['status' => 'released']);
        $releasedHolds = $this->db->affectedRows();

        // Expire any remaining waiting waitlist rows (belt-and-braces for rows
        // whose registration was already cleared).
        $this->db->table('waitlist_entries')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', 'waiting')
            ->update(['status' => 'expired']);
        $expiredWaitlist = $this->db->affectedRows();

        return Result::ok([
            'released_registrations' => $released,
            'promoted'               => $promoted,
            'released_holds'         => $releasedHolds,
            'expired_waitlist'       => $expiredWaitlist,
            'reason_code'            => $reasonCode,
        ]);
    }

    /**
     * E-B2 (merge half) — RE-POINT the LOSER's event footprint to the SURVIVOR so
     * the survivor inherits the loser's registrations and attendance, honouring
     * the per-event UNIQUE constraints:
     *
     *   - registration (UNIQUE event_id,user_id): re-point to the survivor when
     *     the survivor has no live registration for that event; otherwise cancel
     *     the loser's as superseded (kept as history, never deleted);
     *   - attendance (UNIQUE active_key = event_id:user_id): re-point the loser's
     *     `present` attendance (and its active_key) to the survivor when the
     *     survivor has no active attendance for that event; otherwise void the
     *     loser's;
     *   - live holds (UNIQUE event_id,user_id,status): re-point to the survivor
     *     when free, else release the loser's;
     *   - waitlist (UNIQUE event_id,user_id): re-point when free, else expire the
     *     loser's.
     *
     * Group attribution on a registration is never rewritten (attribution is a
     * fact). loser == survivor / empty inputs -> all-zero no-op. Idempotent.
     *
     * @return array{registrations_repointed:int,registrations_superseded:int,attendance_repointed:int,attendance_voided:int,holds_repointed:int,holds_released:int,waitlist_repointed:int,waitlist_expired:int}
     */
    public function reassignForMerge(string $organizationId, string $loserId, string $survivorId): array
    {
        $zero = [
            'registrations_repointed'  => 0,
            'registrations_superseded' => 0,
            'attendance_repointed'     => 0,
            'attendance_voided'        => 0,
            'holds_repointed'          => 0,
            'holds_released'           => 0,
            'waitlist_repointed'       => 0,
            'waitlist_expired'         => 0,
        ];
        if ($organizationId === '' || $loserId === '' || $survivorId === '' || $loserId === $survivorId) {
            return $zero;
        }

        $now = $this->clock->nowUtcString();
        $out = $zero;

        // Helper: build a keyed set of the survivor's existing event ids for a
        // table, restricted to the given non-cancelled/active statuses.
        $survivorEvents = function (string $table, array $keepStatuses) use ($organizationId, $survivorId): array {
            $set = [];
            foreach (
                $this->db->table($table)
                    ->where('organization_id', $organizationId)
                    ->where('user_id', $survivorId)
                    ->get()->getResultArray() as $r
            ) {
                if ($keepStatuses === [] || in_array((string) ($r['status'] ?? ''), $keepStatuses, true)) {
                    $set[(string) $r['event_id']] = true;
                }
            }

            return $set;
        };

        // 1) Registrations.
        $survReg = $survivorEvents('event_registrations', ['registered', 'waitlisted']);
        foreach (
            $this->db->table('event_registrations')
                ->where('organization_id', $organizationId)
                ->where('user_id', $loserId)
                ->whereIn('status', ['registered', 'waitlisted'])
                ->get()->getResultArray() as $reg
        ) {
            if (isset($survReg[(string) $reg['event_id']])) {
                $this->db->table('event_registrations')->where('id', $reg['id'])
                    ->update(['status' => 'cancelled', 'updated_at' => $now]);
                $out['registrations_superseded']++;
            } else {
                $this->db->table('event_registrations')->where('id', $reg['id'])
                    ->update(['user_id' => $survivorId, 'updated_at' => $now]);
                $survReg[(string) $reg['event_id']] = true; // guard intra-loser dupes
                $out['registrations_repointed']++;
            }
        }

        // 2) Attendance.
        $survAtt = $survivorEvents('event_attendance', ['present']);
        foreach (
            $this->db->table('event_attendance')
                ->where('organization_id', $organizationId)
                ->where('user_id', $loserId)
                ->where('status', 'present')
                ->get()->getResultArray() as $att
        ) {
            $eventId = (string) $att['event_id'];
            if (isset($survAtt[$eventId])) {
                $this->db->table('event_attendance')->where('id', $att['id'])
                    ->update(['status' => 'voided', 'active_key' => null]);
                $out['attendance_voided']++;
            } else {
                $this->db->table('event_attendance')->where('id', $att['id'])
                    ->update(['user_id' => $survivorId, 'active_key' => $eventId . ':' . $survivorId]);
                $survAtt[$eventId] = true;
                $out['attendance_repointed']++;
            }
        }

        // 3) Live holds.
        $survHold = [];
        foreach (
            $this->db->table('ticket_holds')
                ->where('organization_id', $organizationId)
                ->where('user_id', $survivorId)
                ->where('status', 'held')
                ->get()->getResultArray() as $r
        ) {
            $survHold[(string) $r['event_id']] = true;
        }
        foreach (
            $this->db->table('ticket_holds')
                ->where('organization_id', $organizationId)
                ->where('user_id', $loserId)
                ->where('status', 'held')
                ->get()->getResultArray() as $hold
        ) {
            if (isset($survHold[(string) $hold['event_id']])) {
                $this->db->table('ticket_holds')->where('id', $hold['id'])
                    ->update(['status' => 'released']);
                $out['holds_released']++;
            } else {
                $this->db->table('ticket_holds')->where('id', $hold['id'])
                    ->update(['user_id' => $survivorId]);
                $survHold[(string) $hold['event_id']] = true;
                $out['holds_repointed']++;
            }
        }

        // 4) Waitlist entries.
        $survWait = [];
        foreach (
            $this->db->table('waitlist_entries')
                ->where('organization_id', $organizationId)
                ->where('user_id', $survivorId)
                ->where('status', 'waiting')
                ->get()->getResultArray() as $r
        ) {
            $survWait[(string) $r['event_id']] = true;
        }
        foreach (
            $this->db->table('waitlist_entries')
                ->where('organization_id', $organizationId)
                ->where('user_id', $loserId)
                ->where('status', 'waiting')
                ->get()->getResultArray() as $wl
        ) {
            if (isset($survWait[(string) $wl['event_id']])) {
                $this->db->table('waitlist_entries')->where('id', $wl['id'])
                    ->update(['status' => 'expired']);
                $out['waitlist_expired']++;
            } else {
                $this->db->table('waitlist_entries')->where('id', $wl['id'])
                    ->update(['user_id' => $survivorId]);
                $survWait[(string) $wl['event_id']] = true;
                $out['waitlist_repointed']++;
            }
        }

        return $out;
    }

    /** Cancel a registration and promote the next waitlisted person. */
    public function cancel(string $eventId, string $userId): Result
    {
        $this->db->transStart();
        $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('user_id', $userId)
            ->update(['status' => 'cancelled', 'updated_at' => $this->clock->nowUtcString()]);

        $next = $this->db->table('waitlist_entries')
            ->where('event_id', $eventId)->where('status', 'waiting')
            ->orderBy('position', 'ASC')->get()->getRowArray();
        if ($next !== null) {
            $this->db->table('waitlist_entries')->where('id', $next['id'])->update(['status' => 'promoted']);
            $this->db->table('event_registrations')
                ->where('event_id', $eventId)->where('user_id', $next['user_id'])
                ->update(['status' => 'registered', 'updated_at' => $this->clock->nowUtcString()]);
        }
        $this->db->transComplete();

        return Result::ok(['event_id' => $eventId, 'promoted' => $next['user_id'] ?? null]);
    }

    /**
     * G8 — WAITLIST PROMOTION ON CAPACITY RAISE. When an event's `capacity` is
     * increased (or lifted to unlimited), the seats that just opened should be
     * filled from the waitlist in FIFO order — the symmetric counterpart of the
     * promote-on-cancel path above, reusing the SAME promotion transition
     * (`waitlist_entries` waiting → promoted, `event_registrations` waitlisted →
     * registered) rather than forking it.
     *
     * This is CORRECTNESS, not an opt-in feature: leaving people waitlisted after
     * seats opened is a bug, so — exactly like cancel() — the state change is
     * UNCONDITIONAL (the NOTIFICATION to the promoted registrants is the part the
     * G3 notifier feature-gates, default OFF).
     *
     * Headroom is computed the same way register() gates capacity: a seat is
     * taken by a confirmed `registered` row OR a live (unexpired) `held` ticket,
     * so headroom = capacity − (confirmed + held). A null capacity means
     * unlimited → every waiting entry (up to `$limit`) is promoted. Runs inside a
     * row-locking transaction so a concurrent register()/cancel() can never
     * oversell. Idempotent: with no headroom or no waiting entries it promotes 0.
     *
     * Only a still-live event (draft/published) promotes; a completed/cancelled
     * event's waitlist is history. `$limit` bounds a single pass.
     *
     * @return array{promoted:list<string>, capacity:int|null, headroom:int|null}
     */
    public function promoteWaitlistToCapacity(string $eventId, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $none  = ['promoted' => [], 'capacity' => null, 'headroom' => null];

        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null || ! in_array((string) ($event['status'] ?? ''), self::UPCOMING_EVENT_STATUSES, true)) {
            return $none;
        }
        $capacity = $event['capacity'] !== null ? (int) $event['capacity'] : null;

        $now = $this->clock->nowUtcString();
        $this->db->transStart();

        // Seats already taken: confirmed registrations + live holds (FOR UPDATE so
        // a concurrent register() serializes against this promotion).
        $confirmed = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM event_registrations
             WHERE event_id = ? AND status = "registered" FOR UPDATE',
            [$eventId],
        )->getRowArray()['c'] ?? 0);
        $held = (int) ($this->db->query(
            'SELECT COALESCE(SUM(quantity),0) AS c FROM ticket_holds
             WHERE event_id = ? AND status = "held" AND expires_at > ? FOR UPDATE',
            [$eventId, $now],
        )->getRowArray()['c'] ?? 0);

        // Headroom: null capacity = unlimited (promote everyone waiting).
        if ($capacity === null) {
            $take = $limit;
        } else {
            $headroom = $capacity - ($confirmed + $held);
            if ($headroom <= 0) {
                $this->db->transComplete();

                return ['promoted' => [], 'capacity' => $capacity, 'headroom' => max(0, $headroom)];
            }
            $take = min($headroom, $limit);
        }

        $waiting = $this->db->table('waitlist_entries')
            ->where('event_id', $eventId)->where('status', 'waiting')
            ->orderBy('position', 'ASC')->get()->getResultArray();

        $promoted = [];
        foreach ($waiting as $entry) {
            if (count($promoted) >= $take) {
                break;
            }
            $userId = (string) ($entry['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            // Reuse the exact promote transition: entry waiting → promoted, and
            // the person's registration waitlisted → registered. Guarded on the
            // source statuses so a row already moved by a racing cancel() is a
            // no-op (idempotent).
            $this->db->table('waitlist_entries')
                ->where('id', $entry['id'])->where('status', 'waiting')
                ->update(['status' => 'promoted']);
            $this->db->table('event_registrations')
                ->where('event_id', $eventId)->where('user_id', $userId)->where('status', 'waitlisted')
                ->update(['status' => 'registered', 'updated_at' => $now]);
            $promoted[] = $userId;
        }

        $this->db->transComplete();
        if ($this->db->transStatus() === false) {
            return $none;
        }

        return [
            'promoted' => $promoted,
            'capacity' => $capacity,
            'headroom' => $capacity === null ? null : max(0, $capacity - ($confirmed + $held) - count($promoted)),
        ];
    }

    private function expireStaleHolds(string $eventId): void
    {
        $this->db->table('ticket_holds')
            ->where('event_id', $eventId)
            ->where('status', 'held')
            ->where('expires_at <=', $this->clock->nowUtcString())
            ->update(['status' => 'expired']);
    }

    /**
     * E-C1 — batch inventory hygiene: release every `held` ticket-hold whose
     * `expires_at` has passed, platform-wide (or scoped to one org), so seats
     * return to inventory and a buyer whose old hold lapsed isn't wedged out of
     * re-purchasing by the `th_active_uq (event_id, user_id, status)` UNIQUE key.
     *
     * The lazy per-event `expireStaleHolds()` only fires when SOMEONE tries a new
     * hold on that event; a quiet event accumulates stale `held` rows forever.
     * This is the background equivalent — the same idempotent transition
     * (`held` + `expires_at <= now` → `expired`), never touching a live hold, so
     * a second pass in the same window releases 0. Capacity maths already ignore
     * expired holds (`status = "held" AND expires_at > now`), so this only cleans
     * state; it can never over- or under-count a seat.
     *
     * Pure inventory hygiene: no config gate, no notifications (mirrors the
     * session-prune sweep). Bounded by `$limit` rows per pass.
     *
     * @return array{scanned:int,expired:int}
     */
    public function processExpiredHolds(?string $organizationId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $now   = $this->clock->nowUtcString();

        $q = $this->db->table('ticket_holds')
            ->select('id')
            ->where('status', 'held')
            ->where('expires_at <=', $now)
            ->orderBy('expires_at', 'ASC');
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $rows = $q->get($limit)->getResultArray();

        $ids = array_map(static fn ($r) => (string) $r['id'], $rows);
        if ($ids === []) {
            return ['scanned' => 0, 'expired' => 0];
        }

        // Re-assert status/expiry in the UPDATE so a hold consumed or refreshed
        // between the SELECT and here is never clobbered (idempotent, race-safe).
        $this->db->table('ticket_holds')
            ->whereIn('id', $ids)
            ->where('status', 'held')
            ->where('expires_at <=', $now)
            ->update(['status' => 'expired']);

        $expired = $this->db->affectedRows();

        return [
            'scanned' => count($ids),
            'expired' => is_int($expired) && $expired >= 0 ? $expired : count($ids),
        ];
    }
}
