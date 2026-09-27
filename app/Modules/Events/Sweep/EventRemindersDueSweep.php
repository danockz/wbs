<?php

declare(strict_types=1);

namespace WBS\Events\Sweep;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C): stage pre-event reminders for events starting within
 * the look-ahead window. Wraps the already-idempotent
 * EventNotifier::processDueReminders() (the `events:reminders-due` reference
 * command) behind the unified SweepContract. Idempotent via the
 * last_reminded_at watermark + notification dedupe key. Finding G3(events).
 */
final class EventRemindersDueSweep implements SweepContract
{
    public function key(): string
    {
        return 'events.reminders-due';
    }

    public function description(): string
    {
        return 'Stage pre-event reminders for published events starting within the look-ahead window.';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $hours = isset($options['hours']) ? (int) $options['hours'] : 24;
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = EventServices::eventNotifier()->processDueReminders($organizationId, $hours, $limit);

        return SweepResult::ok((int) ($r['events'] ?? 0), [
            'events'        => (int) ($r['events'] ?? 0),
            'notifications' => (int) ($r['notifications'] ?? 0),
        ]);
    }
}
