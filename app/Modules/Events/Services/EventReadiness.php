<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * Publish-readiness assessment (lifecycle-guards workstream, gap G6).
 *
 * PURE + DB-FREE: given an event row (and "now" in UTC) it reports whether the
 * event is coherent enough to publish. Two tiers:
 *
 *   - **blockers** — hard problems that make `publish()` refuse (a past start,
 *     a virtual/hybrid event with no join URL, a missing title/start, an
 *     inverted time range). These mirror the create/update validator but apply
 *     at the publish gate, catching rows that predate stricter validation.
 *   - **warnings** — soft, non-blocking advice surfaced on the event page so an
 *     organizer can publish with eyes open (no description, no capacity set, a
 *     physical event with no venue, no end time).
 *
 * Messages are stable `Events.readiness.*` lang keys (English-fallback
 * translated by the view). `ready` is true iff there are no blockers.
 */
final class EventReadiness
{
    /**
     * @param array<string,mixed> $event  the events row
     * @param string              $nowUtc current UTC time, 'Y-m-d H:i:s'
     * @return array{ready:bool,blockers:list<string>,warnings:list<string>}
     */
    public static function assess(array $event, string $nowUtc): array
    {
        $blockers = [];
        $warnings = [];

        $title = trim((string) ($event['title'] ?? ''));
        $start = trim((string) ($event['starts_at'] ?? ''));
        $end   = trim((string) ($event['ends_at'] ?? ''));
        $mode  = (string) ($event['mode'] ?? 'physical');
        $url   = trim((string) ($event['access_url'] ?? ''));

        // ---- Hard blockers --------------------------------------------------
        if ($title === '') {
            $blockers[] = 'Events.readiness.blockTitle';
        }
        if ($start === '') {
            $blockers[] = 'Events.readiness.blockStart';
        } else {
            $ts = strtotime($start);
            $tn = strtotime($nowUtc);
            if ($ts !== false && $tn !== false && $ts <= $tn) {
                $blockers[] = 'Events.readiness.blockPast';
            }
        }
        if ($start !== '' && $end !== '') {
            $ts = strtotime($start);
            $te = strtotime($end);
            if ($ts !== false && $te !== false && $te < $ts) {
                $blockers[] = 'Events.readiness.blockTimeRange';
            }
        }
        if (in_array($mode, EventValidator::VIRTUAL_MODES, true) && $url === '') {
            $blockers[] = 'Events.readiness.blockAccessUrl';
        }

        // ---- Soft warnings --------------------------------------------------
        if (trim((string) ($event['description'] ?? '')) === '') {
            $warnings[] = 'Events.readiness.warnDescription';
        }
        if ($event['capacity'] === null || (string) ($event['capacity'] ?? '') === '') {
            $warnings[] = 'Events.readiness.warnCapacity';
        }
        if ($end === '') {
            $warnings[] = 'Events.readiness.warnEndTime';
        }
        // A physical/hybrid event with no venue set.
        $needsVenue = $mode === 'physical' || $mode === 'hybrid';
        if ($needsVenue && trim((string) ($event['venue_id'] ?? '')) === '') {
            $warnings[] = 'Events.readiness.warnVenue';
        }

        return [
            'ready'    => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }
}
