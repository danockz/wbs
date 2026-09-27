<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * Narrow port the EventNotifier uses to read the event roster and to persist the
 * reminder-sweep watermark, so the notifier depends on this one seam instead of
 * a live database connection. The production adapter (EventRosterDbAdapter) wraps
 * the BaseConnection; tests supply a trivial in-memory implementation.
 *
 * Resource discipline: every method is ONE bounded, indexed query — the sweep is
 * a single range read over `events` (KEY ev_org_idx / starts_at) and the roster
 * fan-out is a single read over `event_registrations` (KEY er_event_idx). There
 * is never a per-registrant round-trip beyond the one bulk read.
 */
interface EventRosterPort
{
    /**
     * Active registrants for an event. Cancel/update fan-out includes waitlisted
     * people (they care that the event changed); the reminder sweep passes only
     * confirmed registrations.
     *
     * @param list<string> $statuses registration statuses to include
     * @return list<array{user_id:string, group_attribution?:?string}>
     */
    public function activeRegistrants(string $eventId, array $statuses = ['registered']): array;

    /**
     * Published events whose start falls inside [notBeforeUtc, notAfterUtc) that
     * have NOT yet been reminded (last_reminded_at IS NULL).
     *
     * @return list<array<string,mixed>> event rows (id, organization_id, group_id, title, starts_at)
     */
    public function dueForReminder(?string $organizationId, string $notBeforeUtc, string $notAfterUtc, int $limit): array;

    /** Advance the reminder watermark so a re-run of the sweep never double-sends. */
    public function markReminded(string $eventId, string $at): void;

    /**
     * Published events whose scheduled finish (`ends_at`, falling back to
     * `starts_at` when open-ended) is at or before `finishedByUtc` — i.e. they
     * ran and are due to be auto-closed (gap L3). Still-open and future events
     * are excluded; the terminal-status transition in complete() is what makes a
     * re-run idempotent, so no watermark is needed here.
     *
     * @return list<array<string,mixed>> event rows (id, organization_id, group_id)
     */
    public function dueForClose(?string $organizationId, string $finishedByUtc, int $limit): array;

    /**
     * The org-root group id (shallowest active group) — the config context for an
     * org-wide (group-less) event, so an org-level default can enable notifications
     * everywhere. Null when the org has no groups (defaults apply → gate stays off).
     */
    public function orgRootGroup(string $organizationId): ?string;
}
