<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * The seam EventService uses to fan lifecycle changes out to the roster (gap G3).
 * Keeping this behind a port means EventService's guard logic (L1/G6/G7) stays
 * unit-testable with a trivial spy, and the heavy Notifications dependency is
 * injected only in the composition root.
 *
 * Every method is a no-op when the injected notifier is null (EventService is
 * constructed without notifications in the standalone tests) and when the
 * feature gate for the event's context is off (DEFAULT OFF).
 */
interface EventNotifierPort
{
    /**
     * Notify the roster that a published event was cancelled. Completes G7's
     * notify side. Idempotent per (event, roster member) via a dedupe key keyed
     * on the cancellation instant.
     *
     * @param array<string,mixed> $event the event row (post-cancel)
     * @return int number of recipients staged (0 when gated off / empty roster)
     */
    public function notifyCancelled(array $event, string $cancelledAt): int;

    /**
     * Notify the roster that a published event's consequential details changed
     * (time / venue / access URL / mode). Silent for cosmetic-only edits.
     *
     * @param array<string,mixed> $event the event row (post-update)
     * @param list<string> $changedFields the editable columns that actually changed
     * @return int number of recipients staged
     */
    public function notifyUpdated(array $event, array $changedFields, string $updatedAt): int;

    /**
     * Notify people who were PROMOTED off the waitlist into a seat that opened
     * when a published event's capacity was raised (gap G8). Idempotent per
     * (event, promoted user) via a dedupe key keyed on the promotion instant.
     * Only the named users are notified — not the whole roster.
     *
     * @param array<string,mixed> $event the event row (post-update)
     * @param list<string> $userIds the users promoted waitlisted → registered
     * @return int number of recipients staged
     */
    public function notifyPromoted(array $event, array $userIds, string $promotedAt): int;
}
