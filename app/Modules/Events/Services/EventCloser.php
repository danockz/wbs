<?php

declare(strict_types=1);

namespace WBS\Events\Services;

use WBS\Shared\Support\Clock;

/**
 * Close automation — auto-complete published events that have finished (gap L3).
 *
 * A published event that has already ended lingers in `published` forever unless
 * an organizer remembers to press "Complete". That stale state is dishonest
 * (the mobilization/attendance reports and certificate flows key off the
 * terminal status) and blocks the post-event phase. This sweep closes that gap:
 * for every published event whose scheduled finish is far enough in the past, it
 * calls the SAME EventService::complete() the manual button uses — so the
 * completed / completed_no_attendance decision, the completed_at stamp and the
 * `event.completed` domain event are produced through one code path, never forked.
 *
 * Feature gate (standing rule): HIERARCHICAL GROUP CONFIG via EventConfigPort,
 * capability `events.autoclose.enabled`, DEFAULT OFF. When off — or when no
 * config port is wired — the sweep is a silent no-op, so an org that has not
 * opted in is never auto-closed and existing behaviour is unchanged. The manual
 * complete() button is unaffected by this gate (an organizer can always close
 * their own event by hand).
 *
 * Grace window: an event is only eligible once at least `graceHours` have passed
 * since its scheduled finish, so a running-late or slightly-overrunning event is
 * never closed out from under its attendees.
 *
 * Resource discipline: ONE bounded, indexed range read per run (dueForClose over
 * KEY ev_close_idx), the gate resolved ONCE per event (not per anything), and no
 * per-event round-trip beyond the complete() call itself. complete()'s terminal
 * status write is the idempotency guard, so a re-run never double-closes.
 */
final class EventCloser
{
    /** Hierarchical group-config capability. Default OFF. */
    public const CAP_ENABLED = 'events.autoclose.enabled';

    /** Default hours to wait after an event's scheduled finish before closing. */
    public const DEFAULT_GRACE_HOURS = 6;

    public function __construct(
        private readonly Clock $clock,
        private readonly EventService $events,
        private readonly EventRosterPort $roster,
        private readonly ?EventConfigPort $config = null,
    ) {
    }

    /**
     * Auto-complete finished published events.
     *
     * @param  ?string $organizationId limit to one org, or null for every org
     * @param  int     $graceHours     hours to wait past the scheduled finish
     * @param  int     $limit          max events to process in one run
     * @return array{scanned:int, completed:int, skipped_gated:int, no_attendance:int}
     */
    public function processDueClosures(?string $organizationId = null, int $graceHours = self::DEFAULT_GRACE_HOURS, int $limit = 500): array
    {
        $graceHours  = $graceHours >= 0 ? $graceHours : self::DEFAULT_GRACE_HOURS;
        $finishedBy  = $this->clock->now()->modify('-' . $graceHours . ' hours')->format('Y-m-d H:i:s');
        $due         = $this->roster->dueForClose($organizationId, $finishedBy, max(1, min($limit, 1000)));

        $scanned      = 0;
        $completed    = 0;
        $skippedGated = 0;
        $noAttendance = 0;

        foreach ($due as $event) {
            $eventId = (string) ($event['id'] ?? '');
            if ($eventId === '') {
                continue;
            }
            $scanned++;

            // Gate is resolved ONCE per event. Default OFF — an org that has not
            // opted in is skipped and left published for a manual close.
            if (! $this->enabledFor($event)) {
                $skippedGated++;
                continue;
            }

            $r = $this->events->complete($eventId);
            if (! $r->ok) {
                // A concurrent cancel/complete raced us (BAD_STATE) — harmless;
                // leave it for the report and move on.
                continue;
            }
            // A dedupe re-hit (already terminal) is not a fresh close.
            if (($r->meta['deduplicated'] ?? false) === true) {
                continue;
            }
            $completed++;
            if ((string) ($r->data['status'] ?? '') === 'completed_no_attendance') {
                $noAttendance++;
            }
        }

        return [
            'scanned'       => $scanned,
            'completed'     => $completed,
            'skipped_gated' => $skippedGated,
            'no_attendance' => $noAttendance,
        ];
    }

    /**
     * Feature gate: resolve the hierarchical config for the event's context ONCE.
     * DEFAULT OFF — null/false/absent config all mean "off". An org-wide event
     * (no group) resolves against the org-root group so an org-level default can
     * enable it everywhere. Mirrors EventNotifier::enabledFor exactly.
     *
     * @param array<string,mixed> $event
     */
    private function enabledFor(array $event): bool
    {
        if ($this->config === null) {
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
}
