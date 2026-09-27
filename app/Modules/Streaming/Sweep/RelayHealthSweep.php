<?php

declare(strict_types=1);

namespace WBS\Streaming\Sweep;

use WBS\Shared\Sweep\SweepContract;
use WBS\Shared\Sweep\SweepResult;
use WBS\Streaming\Config\Services as StreamingServices;

/**
 * Sweep adapter (Theme C, ST4): auto-end streams stuck `live` after their relay
 * stopped sending heartbeats. Relay health is INGESTED (StreamRelayService::
 * heartbeat) but nothing acted on its ABSENCE — a stream whose encoder/relay
 * dies keeps reporting `live` forever, holding viewers on a dead broadcast and
 * skewing "currently live" surfaces.
 *
 * Wraps the bounded, idempotent StreamRelayService::sweepStaleStreams(): it
 * finds `live` streams whose last heartbeat (or go-live, if none) is older than
 * the staleness window, opens a monitor incident (alert once) and transitions
 * them to `ended`. A second pass in the same window ends 0.
 *
 * Feature-gated, DEFAULT OFF: a stream is only auto-ended when the
 * `streaming.relay_health_sweep` flag is enabled for its org (or group).
 */
final class RelayHealthSweep implements SweepContract
{
    public function key(): string
    {
        return 'streaming.relay-health';
    }

    public function description(): string
    {
        return 'Auto-end streams stuck live with no relay heartbeat past the staleness window (gated, default off).';
    }

    public function run(?string $organizationId, array $options = []): SweepResult
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 200;
        $stale = isset($options['stale_minutes']) ? (int) $options['stale_minutes'] : null;

        $r = StreamingServices::streamRelay()->sweepStaleStreams($organizationId, $limit, $stale);

        return SweepResult::ok((int) $r['ended'], [
            'scanned'      => (int) $r['scanned'],
            'ended'        => (int) $r['ended'],
            'skipped_gate' => (int) $r['skipped_gate'],
        ]);
    }
}
