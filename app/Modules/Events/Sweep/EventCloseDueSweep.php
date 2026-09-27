<?php

declare(strict_types=1);

namespace WBS\Events\Sweep;

use WBS\Events\Config\Services as EventServices;
use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;

/**
 * Sweep adapter (Theme C): auto-complete published events that finished over the
 * grace window ago. Wraps the already-idempotent EventCloser::processDueClosures()
 * (the same operation as the `events:close-due` reference command) behind the
 * unified SweepContract. Config-gated per group (default OFF). Finding L3.
 */
final class EventCloseDueSweep implements SweepContract
{
    public function key(): string
    {
        return 'events.close-due';
    }

    public function description(): string
    {
        return 'Auto-complete published events that finished over the grace window ago (config-gated, default off).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $grace = isset($options['grace']) ? (int) $options['grace'] : 6;
        $limit = isset($options['limit']) ? (int) $options['limit'] : 500;

        $r = EventServices::eventCloser()->processDueClosures($organizationId, $grace, $limit);

        return SweepResult::ok((int) ($r['completed'] ?? 0), [
            'scanned'       => (int) ($r['scanned'] ?? 0),
            'completed'     => (int) ($r['completed'] ?? 0),
            'no_attendance' => (int) ($r['no_attendance'] ?? 0),
            'skipped_gated' => (int) ($r['skipped_gated'] ?? 0),
        ]);
    }
}
