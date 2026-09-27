<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Shared\Support\Clock;

/**
 * Event lifecycle → roster notifications (gap G3).
 *
 * Three flows, all reusing the platform Notifications module (idempotent send +
 * RetentionPolicyGate + outbox `notification.dispatch`) — nothing is forked:
 *
 *   1. CANCEL  — completes G7's notify side: a published event that is cancelled
 *                tells its roster (registered + waitlisted) why.
 *   2. UPDATE  — a published event whose CONSEQUENTIAL details change (start/end
 *                time, timezone, venue, mode, access URL) tells its roster. A
 *                cosmetic-only edit (title copy, description) stays silent.
 *   3. REMIND  — a pre-event reminder sweep (spark command) for published events
 *                starting within a window, guarded by last_reminded_at so a
 *                re-run never double-sends.
 *
 * Feature gate (standing rule): HIERARCHICAL GROUP CONFIG via EventConfigPort,
 * DEFAULT OFF. When off — or when the notifier has no config/sender wired — every
 * flow is a silent no-op, so an org that has not opted in is never charged the
 * fan-out and existing behaviour is unchanged.
 *
 * Resource discipline: the roster is read in ONE bulk indexed query per event
 * (EventRosterPort::activeRegistrants); the reminder sweep is ONE bounded range
 * read (dueForReminder) plus a single watermark write per processed event. There
 * is no per-registrant round-trip beyond the one roster read, and the gate is
 * resolved ONCE per event, not once per recipient.
 */
final class EventNotifier implements EventNotifierPort
{
    /** Hierarchical group-config capability. Default OFF. */
    public const CAP_ENABLED = 'events.notifications.enabled';

    /** Notification categories (route to templates + preferences). */
    public const CAT_CANCELLED = 'event_cancelled';
    public const CAT_UPDATED   = 'event_updated';
    public const CAT_REMINDER  = 'event_reminder';
    public const CAT_PROMOTED  = 'event_promoted';

    /** Registration statuses that receive a change/cancel notice. */
    private const CHANGE_AUDIENCE = ['registered', 'waitlisted'];

    /** Only confirmed registrants get a pre-event reminder. */
    private const REMINDER_AUDIENCE = ['registered'];

    /**
     * Editable columns whose change is CONSEQUENTIAL enough to notify the roster.
     * A change to any of these means "the plan the attendee agreed to has moved".
     * Deliberately excludes cosmetic fields (title copy, description, slug,
     * audience, consent_wording, capacity, registration/attendance policy).
     *
     * @var list<string>
     */
    private const MATERIAL_FIELDS = ['starts_at', 'ends_at', 'timezone', 'venue_id', 'mode', 'access_url'];

    public function __construct(
        private readonly Clock $clock,
        private readonly EventRosterPort $roster,
        private readonly ?NotificationSenderPort $sender = null,
        private readonly ?EventConfigPort $config = null,
    ) {
    }

    public function notifyCancelled(array $event, string $cancelledAt): int
    {
        if (! $this->enabledFor($event)) {
            return 0;
        }
        $eventId = (string) ($event['id'] ?? '');
        $orgId   = (string) ($event['organization_id'] ?? '');
        if ($eventId === '' || $orgId === '') {
            return 0;
        }

        $sent = 0;
        foreach ($this->roster->activeRegistrants($eventId, self::CHANGE_AUDIENCE) as $r) {
            $userId = (string) ($r['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $this->sender?->send($orgId, $userId, 'email', self::CAT_CANCELLED, [
                'priority'   => 'high',
                // Keyed on the cancel instant: re-notifying a second cancel of the
                // same event (impossible today, but future-proof) would differ,
                // while a duplicate delivery of THIS cancel is deduped away.
                'dedupe_key' => self::CAT_CANCELLED . ':' . $eventId . ':' . $userId . ':' . $cancelledAt,
                'context'    => $this->context($event, [
                    'reason' => (string) ($event['cancellation_reason'] ?? ''),
                ]),
            ]);
            $sent++;
        }

        return $sent;
    }

    public function notifyUpdated(array $event, array $changedFields, string $updatedAt): int
    {
        $material = array_values(array_intersect(self::MATERIAL_FIELDS, $changedFields));
        if ($material === [] || ! $this->enabledFor($event)) {
            return 0; // cosmetic-only edit, or gated off → silent
        }
        $eventId = (string) ($event['id'] ?? '');
        $orgId   = (string) ($event['organization_id'] ?? '');
        if ($eventId === '' || $orgId === '') {
            return 0;
        }

        // A stable fingerprint of WHAT changed + WHEN, so the same edit deduped,
        // but a later, distinct edit notifies again.
        $stamp = $updatedAt . '|' . implode(',', $material);

        $sent = 0;
        foreach ($this->roster->activeRegistrants($eventId, self::CHANGE_AUDIENCE) as $r) {
            $userId = (string) ($r['user_id'] ?? '');
            if ($userId === '') {
                continue;
            }
            $this->sender?->send($orgId, $userId, 'email', self::CAT_UPDATED, [
                'priority'   => 'normal',
                'dedupe_key' => self::CAT_UPDATED . ':' . $eventId . ':' . $userId . ':' . $stamp,
                'context'    => $this->context($event, [
                    'changed' => implode(',', $material),
                ]),
            ]);
            $sent++;
        }

        return $sent;
    }

    public function notifyPromoted(array $event, array $userIds, string $promotedAt): int
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('strval', $userIds),
            static fn (string $u): bool => $u !== '',
        )));
        if ($userIds === [] || ! $this->enabledFor($event)) {
            return 0; // nobody promoted, or gated off → silent
        }
        $eventId = (string) ($event['id'] ?? '');
        $orgId   = (string) ($event['organization_id'] ?? '');
        if ($eventId === '' || $orgId === '') {
            return 0;
        }

        $sent = 0;
        foreach ($userIds as $userId) {
            $this->sender?->send($orgId, $userId, 'email', self::CAT_PROMOTED, [
                'priority'   => 'high',
                // Keyed on the promotion instant: a later, distinct capacity raise
                // that promotes the same person again notifies again, while a
                // duplicate delivery of THIS promotion is deduped away.
                'dedupe_key' => self::CAT_PROMOTED . ':' . $eventId . ':' . $userId . ':' . $promotedAt,
                'context'    => $this->context($event),
            ]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Reminder sweep (spark command). Published events starting within the next
     * $hours that have not been reminded get one reminder per confirmed
     * registrant, then the watermark advances so a re-run is a no-op.
     *
     * @return array{events:int, notifications:int} counts of events processed and reminders staged
     */
    public function processDueReminders(?string $organizationId = null, int $hours = 24, int $limit = 500): array
    {
        $now         = $this->clock->now();
        $notBefore   = $now->format('Y-m-d H:i:s');
        $notAfter    = $now->modify('+' . max(1, $hours) . ' hours')->format('Y-m-d H:i:s');
        $nowStr      = $this->clock->nowUtcString();
        $due         = $this->roster->dueForReminder($organizationId, $notBefore, $notAfter, max(1, min($limit, 1000)));
        $events      = 0;
        $notified    = 0;

        foreach ($due as $event) {
            $eventId = (string) ($event['id'] ?? '');
            $orgId   = (string) ($event['organization_id'] ?? '');
            if ($eventId === '' || $orgId === '') {
                continue;
            }
            $events++;

            // Advance the watermark FIRST (idempotency): even if the org has not
            // enabled notifications, we still mark it so the sweep does not keep
            // re-scanning the same rows. Gated-off orgs simply stage nothing.
            $this->roster->markReminded($eventId, $nowStr);

            if (! $this->enabledFor($event)) {
                continue;
            }
            foreach ($this->roster->activeRegistrants($eventId, self::REMINDER_AUDIENCE) as $r) {
                $userId = (string) ($r['user_id'] ?? '');
                if ($userId === '') {
                    continue;
                }
                $this->sender?->send($orgId, $userId, 'email', self::CAT_REMINDER, [
                    'priority'   => 'normal',
                    // Keyed on the event start so a rescheduled event (new
                    // starts_at) would reminder again; a re-run for the same start
                    // is deduped.
                    'dedupe_key' => self::CAT_REMINDER . ':' . $eventId . ':' . $userId . ':' . (string) ($event['starts_at'] ?? ''),
                    'context'    => $this->context($event),
                ]);
                $notified++;
            }
        }

        return ['events' => $events, 'notifications' => $notified];
    }

    /**
     * Feature gate: resolve the hierarchical config for the event's context ONCE.
     * DEFAULT OFF — null/false/absent config all mean "off". An org-wide event
     * (no group) resolves against the org-root group so an org-level default can
     * enable it everywhere.
     */
    private function enabledFor(array $event): bool
    {
        if ($this->config === null || $this->sender === null) {
            return false;
        }
        $groupId = (string) ($event['group_id'] ?? '');
        if ($groupId === '') {
            $groupId = (string) ($this->roster->orgRootGroup((string) ($event['organization_id'] ?? '')) ?? '');
        }
        if ($groupId === '') {
            return false;
        }
        $v = $this->config->value($groupId, self::CAP_ENABLED);
        if (is_array($v)) {
            $v = $v['value'] ?? null;
        }

        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && in_array(strtolower($v), ['true', 'on', 'yes'], true));
    }

    /**
     * The shared notification context for an event, merged with per-flow extras.
     * Only non-sensitive, presentational fields — never internal ids beyond the
     * event id nor any private attendee data.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function context(array $event, array $extra = []): array
    {
        return [
            'event_id'  => (string) ($event['id'] ?? ''),
            'title'     => (string) ($event['title'] ?? ''),
            'starts_at' => (string) ($event['starts_at'] ?? ''),
            'timezone'  => (string) ($event['timezone'] ?? 'UTC'),
            'mode'      => (string) ($event['mode'] ?? ''),
        ] + $extra;
    }
}
