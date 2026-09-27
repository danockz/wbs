<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Messaging\OutboxService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Event lifecycle (SRS FR-EVT-001/011).
 *
 * Times are stored UTC with an explicit IANA timezone; presentation converts.
 * The post-event guard (FR-EVT-011) refuses to "complete" with certificate
 * artefacts when nobody attended — it records completed_no_attendance instead.
 */
final class EventService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        // Optional roster-notification seam (gap G3). Null in the standalone
        // guard tests (which exercise the pure state machine); wired in the
        // composition root. When null, cancel()/update() record but do not
        // notify — exactly the pre-G3 behaviour.
        private readonly ?EventNotifierPort $notifier = null,
        // Optional refund seam (gap L2). When wired, cancelling a PUBLISHED event
        // auto-creates refund REQUESTS for every paid order (approval + execution
        // stay manual/auditable). Null → cancel records + notifies but does not
        // stage refunds (the pre-L2 behaviour).
        private readonly ?OrderRefundService $refunds = null,
        // Optional outbox seam (gap L3). When wired, completing an event stages an
        // idempotent `event.completed` domain event so downstream workers (rewards,
        // reports, certificate artefacts) can react. Null → complete() flips state
        // but emits nothing (the pre-L3 behaviour, preserved for the guard tests).
        private readonly ?OutboxService $outbox = null,
        // Optional registration seam (gap G8). When wired, raising an event's
        // capacity promotes waitlisted registrations FIFO into the freed seats
        // (reusing RegistrationService's promotion primitive). Null → update()
        // still records the new capacity but promotes nobody (pre-G8 behaviour,
        // preserved for the pure state-machine guard tests).
        private readonly ?RegistrationService $registrations = null,
        // Optional committee seam (event committees). When wired, completing or
        // cancelling an event dissolves its committee and revokes every member's
        // delegated authority, so nothing a committee was empowered to do outlives
        // the event. Idempotent, and a no-op when the event never had a committee
        // or when the seam is not wired (the pre-committee behaviour).
        private readonly ?CommitteeService $committees = null,
    ) {
    }

    /**
     * Columns a create/update may set from caller input. Anything outside this
     * allow-list (id, organization_id, status, created_by/at, counters …) can
     * never be mass-assigned from a request body.
     *
     * @var list<string>
     */
    private const EDITABLE = [
        'group_id', 'title', 'slug', 'description', 'type', 'mode', 'venue_id',
        'access_url', 'timezone', 'starts_at', 'ends_at', 'capacity',
        'registration_policy', 'attendance_policy', 'audience', 'consent_wording',
    ];

    /**
     * L4 — statuses from which an event may be ARCHIVED: a settled event only. A
     * `published` event is live (cancel/complete it first); archiving one is a
     * conflict, never a silent hide of an open, registerable event.
     *
     * @var list<string>
     */
    private const ARCHIVABLE_STATUSES = ['draft', 'cancelled', 'completed', 'completed_no_attendance'];

    /** @param array<string,mixed> $data */
    public function create(string $organizationId, string $createdBy, array $data): Result
    {
        if (($problem = EventValidator::validate($data, false)) !== null) {
            return $problem;
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('events')->insert([
            'id'                  => $id,
            'organization_id'     => $organizationId,
            'group_id'            => $data['group_id'] ?? null,
            'title'               => $data['title'],
            'slug'                => $data['slug'] ?? null,
            'description'         => $data['description'] ?? null,
            'type'                => $data['type'] ?? null,
            'mode'                => $data['mode'] ?? 'physical',
            'venue_id'            => $data['venue_id'] ?? null,
            'access_url'          => $data['access_url'] ?? null,
            'timezone'            => $data['timezone'] ?? 'UTC',
            'starts_at'           => $data['starts_at'],
            'ends_at'             => $data['ends_at'] ?? null,
            'capacity'            => $data['capacity'] ?? null,
            'registration_policy' => $data['registration_policy'] ?? 'open',
            'attendance_policy'   => $data['attendance_policy'] ?? 'checkin',
            'audience'            => isset($data['audience']) ? json_encode($data['audience'], JSON_UNESCAPED_UNICODE) : null,
            'consent_wording'     => $data['consent_wording'] ?? null,
            'status'              => 'draft',
            'created_by'          => $createdBy,
            'created_at'          => $now,
        ]);

        return Result::created(['id' => $id, 'status' => 'draft']);
    }

    /**
     * Edit an existing event (SRS FR-EVT-001) — the pre-event curation path that
     * was previously missing entirely (an event could only be created, then
     * publish/cancel/complete). A PARTIAL update: only the whitelisted columns
     * actually present in `$data` are written, so callers can change one field
     * (a time, a venue, the capacity) without resupplying the whole record.
     *
     * Validation reuses the SAME shared EventValidator as create(), in partial
     * mode: enum/time-range/capacity/virtual-URL rules apply to whatever is
     * supplied, but title/starts_at are not forced to be re-sent. To validate the
     * time range and the virtual-URL rule correctly against fields the caller did
     * NOT resend, the current row is merged under the incoming values first.
     *
     * A terminal event (cancelled / completed*) is frozen — editing it is a
     * conflict, not a silent overwrite of history.
     *
     * @param array<string,mixed> $data
     */
    public function update(string $eventId, array $data): Result
    {
        $event = $this->find($eventId);
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if (in_array((string) $event['status'], ['cancelled', 'completed', 'completed_no_attendance'], true)) {
            return Result::fail('BAD_STATE', 'event.bad_state', 409, ['status' => $event['status']]);
        }

        // Keep only editable, actually-present keys.
        $changes = [];
        foreach (self::EDITABLE as $col) {
            if (array_key_exists($col, $data)) {
                $changes[$col] = $data[$col];
            }
        }
        if ($changes === []) {
            return Result::fail('NO_CHANGES', 'event.no_changes', 422);
        }

        // Validate the MERGED view so cross-field rules (ends_at >= starts_at,
        // virtual mode needs access_url) see the effective post-edit event, even
        // when the relevant partner field was not resubmitted.
        $merged = array_merge($event, $changes);
        if (($problem = EventValidator::validate($merged, true)) !== null) {
            return $problem;
        }

        // Normalize the columns that need encoding/casting before write.
        if (array_key_exists('audience', $changes)) {
            $changes['audience'] = $changes['audience'] === null || $changes['audience'] === ''
                ? null
                : (is_string($changes['audience']) ? $changes['audience'] : json_encode($changes['audience'], JSON_UNESCAPED_UNICODE));
        }
        if (array_key_exists('capacity', $changes)) {
            $changes['capacity'] = ($changes['capacity'] === null || $changes['capacity'] === '')
                ? null
                : (int) $changes['capacity'];
        }

        // Field keys that actually changed value (pre-normalization), so the
        // notifier can decide whether the edit is CONSEQUENTIAL to the roster.
        $changedFields = array_keys($changes);

        // G8 — did this edit RAISE capacity (incl. lifting a finite cap to
        // unlimited)? Compare the normalized new value against the stored one.
        $oldCapacity      = $event['capacity'] !== null ? (int) $event['capacity'] : null;
        $capacityRaised   = false;
        if (array_key_exists('capacity', $changes)) {
            $newCapacity    = $changes['capacity']; // already normalized to int|null above
            $capacityRaised = ($newCapacity === null && $oldCapacity !== null)          // finite → unlimited
                || ($newCapacity !== null && $oldCapacity !== null && $newCapacity > $oldCapacity); // higher finite cap
        }

        $now                   = $this->clock->nowUtcString();
        $changes['updated_at'] = $now;
        $this->db->table('events')->where('id', $eventId)->update($changes);

        // G8 — promote waitlisted registrations into the seats that just opened,
        // FIFO, reusing RegistrationService's promotion primitive. This is a
        // correctness step (unconditional when seats opened); only a PUBLISHED
        // event has a live roster worth promoting/notifying.
        $promoted = [];
        if ($capacityRaised && $this->registrations !== null && (string) $event['status'] === 'published') {
            $promoted = $this->registrations->promoteWaitlistToCapacity($eventId)['promoted'] ?? [];
        }

        // Roster fan-out (gap G3): only a PUBLISHED event notifies on a material
        // change (time/venue/mode/access URL); the notifier is a no-op for
        // cosmetic edits, when the feature gate is off, or when not wired.
        $notified = 0;
        if ($this->notifier !== null && (string) $event['status'] === 'published') {
            $merged['capacity'] = $changes['capacity'] ?? $oldCapacity;
            $eventRow           = array_merge($merged, ['id' => $eventId]);
            $notified           = $this->notifier->notifyUpdated($eventRow, $changedFields, $now);
            // G8 — tell the promoted registrants specifically (not the whole
            // roster) that a seat opened for them. Gated + deduped in the notifier.
            if ($promoted !== []) {
                $notified += $this->notifier->notifyPromoted($eventRow, $promoted, $now);
            }
        }

        return Result::ok([
            'id'       => $eventId,
            'status'   => (string) $event['status'],
            'notified' => $notified,
            'promoted' => $promoted,
        ]);
    }

    /**
     * Publish-readiness report for an event WITHOUT mutating it (gap G6) — powers
     * the soft checklist on the event page. Pure assessment over the stored row;
     * `blockers` would make publish() refuse, `warnings` are advisory.
     */
    public function readiness(string $eventId): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        return Result::ok(['event_id' => $eventId]
            + EventReadiness::assess($e, $this->clock->nowUtcString()));
    }

    public function publish(string $eventId): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if ($e['status'] !== 'draft') {
            return Result::fail('BAD_STATE', 'event.bad_state', 409, ['status' => $e['status']]);
        }

        // Readiness gate (G6): refuse to publish an incoherent event (past start,
        // virtual with no join URL, inverted time range, missing title/start).
        // Warnings are non-blocking and surfaced on the page, not here.
        $check = EventReadiness::assess($e, $this->clock->nowUtcString());
        if (! $check['ready']) {
            return Result::fail('NOT_READY', 'Events.lifecycle.errNotReady', 422, [
                'blockers' => $check['blockers'],
            ]);
        }

        $this->db->table('events')->where('id', $eventId)->update([
            'status'     => 'published',
            'updated_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['id' => $eventId, 'status' => 'published']);
    }

    /**
     * Cancel an event (gap G7). A guarded, auditable transition: only a
     * draft/published event can be cancelled (a completed/already-cancelled event
     * is a conflict, never a silent overwrite of history), a reason is required,
     * and who/when is recorded. Bumps `updated_at` so the ICS `SEQUENCE` advances
     * and subscribers auto-drop the event (the feed already emits STATUS:CANCELLED).
     */
    public function cancel(string $eventId, string $reason = '', ?string $cancelledBy = null): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if (! in_array((string) $e['status'], ['draft', 'published'], true)) {
            return Result::fail('BAD_STATE', 'Events.lifecycle.errCancelBadState', 409, ['status' => $e['status']]);
        }
        if (trim($reason) === '') {
            return Result::fail('REASON_REQUIRED', 'Events.lifecycle.errCancelReasonRequired', 422);
        }

        $wasPublished = (string) $e['status'] === 'published';
        $now          = $this->clock->nowUtcString();
        $this->db->table('events')->where('id', $eventId)->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at'        => $now,
            'cancelled_by'        => $cancelledBy,
            'updated_at'          => $now,
        ]);

        // Roster fan-out (gap G3, completes G7's notify side): only a PUBLISHED
        // event had a roster expecting to attend, so only that transition
        // notifies. A draft never reached anyone. No-op when gated off / not
        // wired. NOTE (L2): money reversal for paid registrations is a separate
        // prerequisite — this notice does not itself refund.
        $notified = 0;
        if ($this->notifier !== null && $wasPublished) {
            $notified = $this->notifier->notifyCancelled(
                array_merge($e, ['id' => $eventId, 'status' => 'cancelled', 'cancellation_reason' => $reason]),
                $now,
            );
        }

        // Money reversal (gap L2): a cancelled event must give paid attendees their
        // money back. Auto-create refund REQUESTS for every paid order; approval +
        // execution stay a maker-checker step (finance), so this never moves money
        // unilaterally. Idempotent per order, so a re-cancel is safe. Only a
        // PUBLISHED event could have taken payments worth reversing.
        $refundsRequested = 0;
        if ($this->refunds !== null && $wasPublished) {
            $fan = $this->refunds->requestForCancelledEvent(
                (string) $e['organization_id'],
                $eventId,
                $cancelledBy ?? 'system',
            );
            $refundsRequested = $fan['requested'];
        }

        // Domain event (gap E-B1): announce the cancellation ONCE so downstream
        // workers can react — chiefly Meetings (MT5), which cancels the linked
        // meeting and expires outstanding join tokens so a dead event's video
        // room can't still be entered. The status write above is the idempotency
        // guard: a re-cancel short-circuits on the BAD_STATE check before reaching
        // here, so this stages exactly once. No-op when the outbox is not wired.
        $this->outbox?->stage('event', $eventId, 'event.cancelled', [
            'event_id'            => $eventId,
            'organization_id'     => (string) ($e['organization_id'] ?? ''),
            'group_id'            => $e['group_id'] !== null ? (string) $e['group_id'] : null,
            'status'              => 'cancelled',
            'was_published'       => $wasPublished,
            'cancellation_reason' => $reason,
            'cancelled_by'        => $cancelledBy,
            'cancelled_at'        => $now,
        ], (string) ($e['organization_id'] ?? '') ?: null);

        // Committee teardown: a cancelled event has no plan left to run, so its
        // committee is dissolved and each member's bounded delegation is revoked —
        // cascading to anything they sub-delegated, because a derived authority
        // cannot outlive the event it was cut for.
        $committee = $this->committees?->onEventClosed(
            (string) ($e['organization_id'] ?? ''),
            $eventId,
            $cancelledBy,
        );

        return Result::ok([
            'id'                  => $eventId,
            'status'              => 'cancelled',
            'notified'            => $notified,
            'refunds_requested'   => $refundsRequested,
            'committee_dissolved' => (bool) ($committee['dissolved'] ?? false),
        ]);
    }

    /**
     * Post-event guard (FR-EVT-011): mark completed only when someone attended,
     * else completed_no_attendance (certificates/artefacts are skipped upstream).
     *
     * State guard (gap L1): only a PUBLISHED event can be completed — a
     * draft/cancelled event never "ran", and completing it would corrupt the
     * state the report/certificate flows key off. Re-completing an
     * already-completed event is idempotent (no flip-flop of the suffix).
     */
    public function complete(string $eventId): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        $status = (string) $e['status'];
        if ($status === 'completed' || $status === 'completed_no_attendance') {
            return Result::ok([
                'id'     => $eventId,
                'status' => $status,
            ], 200, ['deduplicated' => true]);
        }
        if ($status !== 'published') {
            return Result::fail('BAD_STATE', 'Events.lifecycle.errCompleteBadState', 409, ['status' => $status]);
        }

        $attended = $this->db->table('event_attendance')
            ->where('event_id', $eventId)->where('status', 'present')
            ->countAllResults();

        $now       = $this->clock->nowUtcString();
        $newStatus = $attended >= 1 ? 'completed' : 'completed_no_attendance';
        $this->db->table('events')->where('id', $eventId)->update([
            'status'       => $newStatus,
            'completed_at' => $now,
            'updated_at'   => $now,
        ]);

        // Domain event (gap L3): announce the completion ONCE so downstream workers
        // (rewards, reports, certificate artefacts) can react. The status write
        // above is the idempotency guard — a re-complete short-circuits on the
        // terminal status before reaching here, so this stages exactly once per
        // event. No-op when the outbox seam is not wired (pre-L3 behaviour).
        $this->outbox?->stage('event', $eventId, 'event.completed', [
            'event_id'          => $eventId,
            'organization_id'   => (string) ($e['organization_id'] ?? ''),
            'group_id'          => $e['group_id'] !== null ? (string) $e['group_id'] : null,
            'status'            => $newStatus,
            'actual_attendance' => $attended,
            'completed_at'      => $now,
        ], (string) ($e['organization_id'] ?? '') ?: null);

        // Committee teardown (close-out): the event has run, so the committee that
        // managed it stands down and its delegated authority expires with it. The
        // rows stay — a dissolved committee is the event's project history, and the
        // close-out report reads its plan, milestones and decisions.
        $committee = $this->committees?->onEventClosed((string) ($e['organization_id'] ?? ''), $eventId);

        return Result::ok([
            'id'                  => $eventId,
            'status'              => $newStatus,
            'actual_attendance'   => $attended,
            'committee_dissolved' => (bool) ($committee['dissolved'] ?? false),
        ]);
    }

    /**
     * L4 — ARCHIVE an event: file a finished/mistaken event away so it leaves the
     * active index, calendar feeds and analytics WITHOUT being deleted. A soft
     * flag (`archived_at`/`archived_by`) orthogonal to `status`, so the event's
     * terminal status and all its history (attendance, orders, certificates,
     * audit) are preserved and the move is fully reversible via unarchive().
     *
     * Gated to a SETTLED event: only a `draft` (mistaken/test) or a terminal
     * event (`cancelled` / `completed` / `completed_no_attendance`) may be
     * archived. A `published` event is LIVE — cancel or complete it first — so
     * archiving one is a conflict, not a silent hide of an open event. Archiving
     * an already-archived event is idempotent.
     */
    public function archive(string $eventId, ?string $archivedBy = null): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if (($e['archived_at'] ?? null) !== null) {
            return Result::ok(['id' => $eventId, 'status' => (string) $e['status'], 'archived' => true], 200, ['deduplicated' => true]);
        }
        if (! in_array((string) $e['status'], self::ARCHIVABLE_STATUSES, true)) {
            return Result::fail('BAD_STATE', 'Events.lifecycle.errArchiveBadState', 409, ['status' => $e['status']]);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('events')->where('id', $eventId)->update([
            'archived_at' => $now,
            'archived_by' => $archivedBy,
            'updated_at'  => $now,
        ]);

        return Result::ok(['id' => $eventId, 'status' => (string) $e['status'], 'archived' => true]);
    }

    /**
     * L4 — UNARCHIVE: restore an archived event to the active lists. Reversible
     * counterpart of archive(); clears the soft flag and restores whatever
     * terminal/draft status the event had (archival never changed it).
     * Unarchiving a live (non-archived) event is idempotent.
     */
    public function unarchive(string $eventId): Result
    {
        $e = $this->find($eventId);
        if ($e === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }
        if (($e['archived_at'] ?? null) === null) {
            return Result::ok(['id' => $eventId, 'status' => (string) $e['status'], 'archived' => false], 200, ['deduplicated' => true]);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('events')->where('id', $eventId)->update([
            'archived_at' => null,
            'archived_by' => null,
            'updated_at'  => $now,
        ]);

        return Result::ok(['id' => $eventId, 'status' => (string) $e['status'], 'archived' => false]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $eventId): ?array
    {
        return $this->db->table('events')->where('id', $eventId)->get()->getRowArray() ?: null;
    }

    /**
     * Upcoming/recent events for an organization (SRS FR-EVT-001), newest start
     * first. A lightweight list for the events index page and API.
     *
     * @return list<array<string,mixed>>
     */
    public function listForOrg(string $organizationId, int $limit = 50, string $archived = 'active'): array
    {
        $q = $this->db->table('events')
            ->select('id, title, status, starts_at, ends_at, capacity, mode, archived_at')
            ->where('organization_id', $organizationId);
        // L4 — the index shows ACTIVE (not-archived) events by default; a filter
        // can switch to the archive ('archived') or show everything ('all').
        if ($archived === 'active') {
            $q->where('archived_at IS NULL', null, false);
        } elseif ($archived === 'archived') {
            $q->where('archived_at IS NOT NULL', null, false);
        }

        return $q->orderBy('starts_at', 'DESC')
            ->limit(max(1, min($limit, 200)))
            ->get()->getResultArray();
    }

    /**
     * Events whose start falls within an inclusive UTC datetime window — the
     * feed behind the calendar month view. ONE bounded, indexed range read
     * (resource-light): the calendar renders whatever a single month contains.
     *
     * @param string $fromUtc inclusive lower bound  (Y-m-d H:i:s)
     * @param string $toUtc   exclusive-ish upper bound (Y-m-d H:i:s)
     * @param list<string>|null $groupIds hierarchical scope (empty list → no rows)
     * @param list<string>|null $types    visible event kinds (empty/null → all)
     * @param bool $publishedOnly when true, drafts/cancelled of those groups stay hidden
     *                           (used for HIGHER-group events a descendant inherits)
     * @return list<array<string,mixed>>
     */
    public function listInRange(string $organizationId, string $fromUtc, string $toUtc, ?string $groupId = null, ?array $groupIds = null, ?array $types = null, bool $publishedOnly = false): array
    {
        if ($groupIds !== null && $groupIds === []) {
            return [];
        }
        $q = $this->db->table('events')
            ->select('id, title, status, starts_at, ends_at, capacity, mode, type, group_id, timezone')
            ->where('organization_id', $organizationId)
            ->where('starts_at >=', $fromUtc)
            ->where('starts_at <', $toUtc)
            // L4 — archived events drop out of the calendar.
            ->where('archived_at IS NULL', null, false);
        if ($publishedOnly) {
            $q->where('status', 'published');
        }
        if ($groupIds !== null) {
            $q->whereIn('group_id', $groupIds);
        } elseif ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }
        if ($types !== null && $types !== []) {
            $q->whereIn('type', $types);
        }

        return $q->orderBy('starts_at', 'ASC')->limit(500)->get()->getResultArray();
    }

    /**
     * PUBLISHED events for an iCalendar subscription feed. Resource-light: ONE
     * bounded read, forward-looking window (from `$fromUtc`), widened only with
     * the columns the .ics needs (description/timezone/access_url/updated_at).
     * Cancelled/draft events are excluded so a subscriber's calendar only ever
     * shows real, publishable events; optional `$groupId` narrows the feed.
     *
     * @param list<string>|null $groupIds hierarchical scope (empty list → no rows)
     * @param list<string>|null $types    visible event kinds (empty/null → all)
     * @return list<array<string,mixed>>
     */
    public function feedInRange(string $organizationId, string $fromUtc, ?string $groupId = null, int $limit = 500, ?array $groupIds = null, ?array $types = null): array
    {
        $limit = max(1, min(1000, $limit));
        if ($groupIds !== null && $groupIds === []) {
            return [];
        }
        $q     = $this->db->table('events')
            ->select('id, title, description, status, mode, access_url, timezone, starts_at, ends_at, updated_at, created_at, type, group_id')
            ->where('organization_id', $organizationId)
            ->where('status', 'published')
            ->where('starts_at >=', $fromUtc)
            // L4 — archived events never appear in the iCal subscription feed.
            ->where('archived_at IS NULL', null, false);
        if ($groupIds !== null) {
            $q->whereIn('group_id', $groupIds);
        } elseif ($groupId !== null && $groupId !== '') {
            $q->where('group_id', $groupId);
        }
        if ($types !== null && $types !== []) {
            $q->whereIn('type', $types);
        }

        return $q->orderBy('starts_at', 'ASC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Live expected-attendance report (SRS FR-EVT-006).
     *
     * Distinguishes the four quantities the SRS demands — invitation count,
     * responses, confirmed registrations, and expected attendees — rather than
     * collapsing them into one figure. "Expected" applies a simple confidence
     * model: confirmed registrations count fully, positive RSVPs without a
     * confirmed registration count at a configurable show-rate, and a waitlist
     * contributes only up to remaining capacity. Logistics estimates and an
     * uncertainty band are derived from the same figures so organizers see the
     * range, not a false-precise number.
     */
    public function expectedAttendance(string $eventId, float $rsvpShowRate = 0.6): Result
    {
        $event = $this->db->table('events')->where('id', $eventId)->get()->getRowArray();
        if ($event === null) {
            return Result::notFound('event.not_found', 'EVENT_NOT_FOUND');
        }

        // Invitations: EVENT-SCOPED figures (gap G4) — direct invitations issued
        // for THIS event plus sign-ups redeemed through its shareable link. This
        // replaces the old "count ALL org prospects" inflation, which reported the
        // whole address book regardless of the event.
        $inviteCounts = (new InvitationService($this->db, $this->clock))->countInvitationsFor($eventId);
        $invitations  = $inviteCounts['total'];

        $confirmed = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('status', 'registered')->countAllResults();

        // Positive RSVPs that are not (yet) a confirmed registration.
        $positiveRsvp = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->where('rsvp_state', 'yes')
            ->where('status !=', 'registered')->countAllResults();

        $waitlisted = (int) $this->db->table('waitlist_entries')
            ->where('event_id', $eventId)->where('status', 'waiting')->countAllResults();

        $responses = (int) $this->db->table('event_registrations')
            ->where('event_id', $eventId)->countAllResults();

        $capacity = $event['capacity'] !== null ? (int) $event['capacity'] : null;

        // Expected: confirmed (full) + positive RSVP * show-rate, capped by capacity.
        $expected = $confirmed + (int) round($positiveRsvp * $rsvpShowRate);
        if ($capacity !== null) {
            $expected = min($expected, $capacity);
        }

        // Uncertainty band: lower = confirmed only; upper = confirmed + all positive
        // RSVP + waitlist promotable to remaining capacity.
        $remaining = $capacity !== null ? max(0, $capacity - $confirmed) : null;
        $upper     = $confirmed + $positiveRsvp + ($remaining !== null ? min($waitlisted, $remaining) : $waitlisted);
        if ($capacity !== null) {
            $upper = min($upper, $capacity);
        }

        return Result::ok([
            'event_id'   => $eventId,
            'title'      => $event['title'],
            'status'     => $event['status'],
            'capacity'   => $capacity,
            'counts'     => [
                'invitations'           => $invitations,
                'invitations_direct'    => $inviteCounts['direct'],
                'invitations_link'      => $inviteCounts['redemptions'],
                'responses'             => $responses,
                'confirmed_registrations' => $confirmed,
                'positive_rsvp_pending' => $positiveRsvp,
                'waitlisted'            => $waitlisted,
            ],
            'expected'   => [
                'point_estimate' => $expected,
                'lower_bound'    => $confirmed,
                'upper_bound'    => $upper,
                'show_rate'      => $rsvpShowRate,
            ],
            'logistics'  => [
                'seating'   => $upper,                          // plan for the upper band
                'materials' => $upper,
                'catering'  => (int) round($expected * 1.1),    // 10% buffer on estimate
            ],
        ]);
    }
}
