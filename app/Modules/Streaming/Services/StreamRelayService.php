<?php

declare(strict_types=1);

namespace WBS\Streaming\Services;

use CodeIgniter\Database\BaseConnection;
use Throwable;
use WBS\Notifications\Services\NotificationService;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Relay health monitoring + mid-session failure response (SRS FR-STR-013).
 *
 *  - heartbeat(): ingest a relay health signal. A 'down' (or repeated
 *    'degraded') signal on a live stream OPENS an incident and fires immediate
 *    alerts — in-app to the organizer plus a configured fallback channel — and
 *    honestly marks metric tracking degraded.
 *  - reportFailure(): manual organizer-triggered incident (same alerting path).
 *  - acknowledge(): a moderator acknowledges the incident.
 *  - activateBypass(): records that the documented DIRECT-SINGLE-DESTINATION
 *    bypass has been engaged (relay out of the path) and to which destination.
 *  - resolve(): close the incident, restore healthy relay_state.
 *  - health()/incidents()/bypassProcedure(): read-side surfaces for the console.
 *
 * The platform never silently implies normal tracking: while an incident is
 * open, streams.relay_state is degraded/down and realTimeMetrics reports it.
 */
final class StreamRelayService
{
    /** Consecutive degraded samples that escalate to an incident. */
    private const DEGRADED_ESCALATION = 3;

    /** ST4: feature flag that must be ON (org or group) for the sweep to act. */
    public const RELAY_SWEEP_FLAG = 'streaming.relay_health_sweep';

    /** ST4: default minutes without a heartbeat before a live stream is stale. */
    private const DEFAULT_STALE_MINUTES = 5;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ?NotificationService $notifications = null,
        private readonly ?StreamFeatureGatePort $featureGate = null,
    ) {
    }

    /**
     * Record a relay health sample and react to failures.
     *
     * @param array<string,mixed> $data status(healthy|degraded|down),
     *   destination_id, latency_ms, source, note
     */
    public function heartbeat(string $organizationId, string $streamId, array $data): Result
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        if (! in_array($status, ['healthy', 'degraded', 'down'], true)) {
            return Result::fail('BAD_STATUS', 'stream.relay_bad_status', 422);
        }

        $stream = $this->db->table('streams')
            ->where('id', $streamId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('stream_relay_health')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'stream_id'       => $streamId,
            'destination_id'  => $data['destination_id'] ?? null,
            'status'          => $status,
            'latency_ms'      => isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'source'          => in_array($data['source'] ?? '', ['heartbeat', 'provider', 'manual'], true) ? (string) $data['source'] : 'heartbeat',
            'note'            => isset($data['note']) ? (string) $data['note'] : null,
            'created_at'      => $now,
        ]);

        // Only a LIVE stream can suffer a mid-session relay failure.
        $isLive = ($stream['status'] ?? '') === 'live';
        $incident = null;

        if ($isLive && $status === 'down') {
            $incident = $this->ensureIncident($organizationId, $stream, 'critical', 'monitor', $data);
        } elseif ($isLive && $status === 'degraded') {
            // Escalate only after repeated degraded samples in a short window.
            $recentDegraded = $this->db->table('stream_relay_health')
                ->where('stream_id', $streamId)->where('status', 'degraded')
                ->where('created_at >=', $this->clock->now()->modify('-2 minutes')->format('Y-m-d H:i:s.u'))
                ->countAllResults();
            if ($recentDegraded >= self::DEGRADED_ESCALATION) {
                $incident = $this->ensureIncident($organizationId, $stream, 'warning', 'monitor', $data);
            } else {
                $this->setRelayState($streamId, 'degraded');
            }
        } elseif ($status === 'healthy') {
            // A healthy beat does NOT auto-close an open incident (a human
            // resolves it), but if there is no open incident, clear a transient
            // degraded marker.
            if (! $this->hasOpenIncident($streamId)) {
                $this->setRelayState($streamId, 'healthy', clearDegraded: true);
            }
        }

        return Result::ok([
            'stream_id'   => $streamId,
            'status'      => $status,
            'incident_id' => $incident['id'] ?? null,
            'relay_state' => $this->currentRelayState($streamId),
        ]);
    }

    /** Manually raise a relay-failure incident (organizer/moderator). */
    public function reportFailure(string $organizationId, string $streamId, string $actorId, array $data = []): Result
    {
        $stream = $this->db->table('streams')
            ->where('id', $streamId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }
        $severity = ($data['severity'] ?? '') === 'warning' ? 'warning' : 'critical';
        $incident = $this->ensureIncident($organizationId, $stream, $severity, 'manual', $data + ['actor_id' => $actorId]);

        return Result::created([
            'incident_id' => $incident['id'],
            'stream_id'   => $streamId,
            'status'      => $incident['status'],
            'bypass'      => $this->bypassProcedureData($stream),
        ]);
    }

    public function acknowledge(string $organizationId, string $incidentId, string $actorId): Result
    {
        $inc = $this->findIncident($organizationId, $incidentId);
        if ($inc === null) {
            return Result::notFound('stream.incident_not_found', 'INCIDENT_NOT_FOUND');
        }
        if ($inc['status'] === 'resolved') {
            return Result::fail('BAD_STATE', 'stream.incident_resolved', 409);
        }
        $now = $this->clock->nowUtcMicro();
        $this->db->table('stream_relay_incidents')->where('id', $incidentId)->update([
            'status'          => 'acknowledged',
            'acknowledged_by' => $actorId,
            'acknowledged_at' => $now,
            'updated_at'      => $now,
        ]);

        return Result::ok(['incident_id' => $incidentId, 'status' => 'acknowledged']);
    }

    /**
     * Record activation of the documented direct-single-destination bypass:
     * the operator points the encoder straight at ONE destination, taking the
     * relay out of the path. Metrics remain marked degraded (the relay is no
     * longer compositing overlays / fanning out).
     */
    public function activateBypass(string $organizationId, string $incidentId, string $actorId, ?string $destinationId = null): Result
    {
        $inc = $this->findIncident($organizationId, $incidentId);
        if ($inc === null) {
            return Result::notFound('stream.incident_not_found', 'INCIDENT_NOT_FOUND');
        }
        if ($inc['status'] === 'resolved') {
            return Result::fail('BAD_STATE', 'stream.incident_resolved', 409);
        }

        // Validate the chosen destination belongs to this stream, if provided.
        if ($destinationId !== null && $destinationId !== '') {
            $dest = $this->db->table('stream_destinations')
                ->where('id', $destinationId)->where('stream_id', $inc['stream_id'])
                ->get()->getRowArray();
            if ($dest === null) {
                return Result::notFound('stream.destination_not_found', 'DESTINATION_NOT_FOUND');
            }
        }

        $now = $this->clock->nowUtcMicro();
        $this->db->table('stream_relay_incidents')->where('id', $incidentId)->update([
            'bypass_activated'      => 1,
            'bypass_destination_id' => $destinationId ?: null,
            'bypass_activated_at'   => $now,
            'metrics_degraded'      => 1,
            'updated_at'            => $now,
        ]);
        $this->db->table('streams')->where('id', $inc['stream_id'])->update([
            'relay_state'           => 'down',
            'bypass_destination_id' => $destinationId ?: null,
            'updated_at'            => $now,
        ]);

        return Result::ok([
            'incident_id'           => $incidentId,
            'bypass_activated'      => true,
            'bypass_destination_id' => $destinationId ?: null,
        ]);
    }

    /** Resolve an incident and restore healthy relay tracking. */
    public function resolve(string $organizationId, string $incidentId, string $actorId, string $note = ''): Result
    {
        $inc = $this->findIncident($organizationId, $incidentId);
        if ($inc === null) {
            return Result::notFound('stream.incident_not_found', 'INCIDENT_NOT_FOUND');
        }
        if ($inc['status'] === 'resolved') {
            return Result::ok(['incident_id' => $incidentId, 'status' => 'resolved'], 200, ['already' => true]);
        }
        $now = $this->clock->nowUtcMicro();
        $this->db->table('stream_relay_incidents')->where('id', $incidentId)->update([
            'status'          => 'resolved',
            'resolved_by'     => $actorId,
            'resolved_at'     => $now,
            'resolution_note' => $note !== '' ? $note : null,
            'metrics_degraded' => 0,
            'updated_at'      => $now,
        ]);
        // Restore healthy state only if no OTHER incident remains open.
        if (! $this->hasOpenIncident((string) $inc['stream_id'], $incidentId)) {
            $this->setRelayState((string) $inc['stream_id'], 'healthy', clearDegraded: true, clearBypass: true);
        }

        return Result::ok(['incident_id' => $incidentId, 'status' => 'resolved']);
    }

    /** Current relay health snapshot for the console/dashboard. */
    public function health(string $organizationId, string $streamId): Result
    {
        $stream = $this->db->table('streams')
            ->select('id, status, relay_state, degraded_since, bypass_destination_id')
            ->where('id', $streamId)->where('organization_id', $organizationId)
            ->get()->getRowArray();
        if ($stream === null) {
            return Result::notFound('stream.not_found', 'STREAM_NOT_FOUND');
        }

        $latest = $this->db->table('stream_relay_health')
            ->where('stream_id', $streamId)
            ->orderBy('created_at', 'DESC')->limit(1)
            ->get()->getRowArray();

        $open = $this->db->table('stream_relay_incidents')
            ->where('stream_id', $streamId)->whereIn('status', ['open', 'acknowledged'])
            ->orderBy('detected_at', 'DESC')
            ->get()->getResultArray();

        return Result::ok([
            'stream_id'        => $streamId,
            'relay_state'      => $stream['relay_state'],
            'degraded_since'   => $stream['degraded_since'],
            'metrics_degraded' => $open !== [],
            'latest_sample'    => $latest,
            'open_incidents'   => $open,
            'bypass_procedure' => $this->bypassProcedureData($stream),
        ]);
    }

    /** @return list<array<string,mixed>> incident history for a stream. */
    public function incidents(string $organizationId, string $streamId, int $limit = 50): array
    {
        return $this->db->table('stream_relay_incidents')
            ->where('organization_id', $organizationId)->where('stream_id', $streamId)
            ->orderBy('detected_at', 'DESC')->limit(max(1, min(200, $limit)))
            ->get()->getResultArray();
    }

    /**
     * Is metric tracking currently degraded for this stream? Used by
     * realTimeMetrics to mark degradation honestly.
     */
    public function metricsDegraded(string $streamId): bool
    {
        return $this->hasOpenIncident($streamId);
    }

    /**
     * ST4 — RELAY/STREAM HEALTH SWEEP. Relay heartbeats are ingested but nothing
     * acts on their ABSENCE: a stream stuck `live` after its relay stops sending
     * heartbeats never transitions. This bounded, idempotent pass auto-ends
     * `live` streams whose last heartbeat is older than the staleness window (or,
     * for a stream that never sent one, whose go-live was longer ago than the
     * window). For each it: stamps relay_state=`down`, opens a monitor incident
     * if none is open (reusing the same ensureIncident path as a `down` beat so
     * organizers are alerted once), transitions the stream to `ended`, and audits
     * via the incident. A second pass in the same window ends 0 (the stream is no
     * longer `live`), so it is safe to re-run.
     *
     * Feature-gated, DEFAULT OFF: a stream is only auto-ended when the
     * `streaming.relay_health_sweep` flag is enabled for its org (or its group).
     * When no gate is wired the sweep is inert (returns nothing).
     *
     * Resource-light: one bounded, indexed query over `live` streams
     * (`st_org_idx` on (organization_id, status)); the per-stream latest-beat
     * lookup uses `srh_stream_idx` on (stream_id, created_at).
     *
     * @param string|null $organizationId null = every org (each gated on its own flag)
     * @param int         $limit          max streams ended per pass
     * @param int|null    $staleMinutes   heartbeat-staleness window (default 5)
     *
     * @return array{scanned:int, ended:int, skipped_gate:int, stream_ids:list<string>}
     */
    public function sweepStaleStreams(?string $organizationId = null, int $limit = 200, ?int $staleMinutes = null): array
    {
        $limit   = max(1, min($limit, 1000));
        $minutes = $staleMinutes !== null && $staleMinutes > 0 ? $staleMinutes : self::DEFAULT_STALE_MINUTES;
        $cutoff  = $this->clock->now()->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s.u');

        $q = $this->db->table('streams')
            ->select('id, organization_id, group_id, status, started_at')
            ->where('status', 'live')
            ->orderBy('started_at', 'ASC')
            ->limit($limit);
        if ($organizationId !== null && $organizationId !== '') {
            $q->where('organization_id', $organizationId);
        }
        $live = $q->get()->getResultArray();

        $scanned = 0;
        $ended   = 0;
        $skipped = 0;
        $endedIds = [];

        foreach ($live as $stream) {
            $scanned++;
            $streamId = (string) $stream['id'];
            $orgId    = (string) $stream['organization_id'];
            $groupId  = isset($stream['group_id']) && $stream['group_id'] !== '' ? (string) $stream['group_id'] : null;

            // Gate per stream: default OFF, org-wide flag with optional group override.
            if ($this->featureGate === null
                || ! $this->featureGate->enabled($orgId, self::RELAY_SWEEP_FLAG, $groupId)) {
                $skipped++;
                continue;
            }

            // Latest heartbeat for this stream (any status).
            $latest = $this->db->table('stream_relay_health')
                ->select('created_at')
                ->where('stream_id', $streamId)
                ->orderBy('created_at', 'DESC')->limit(1)
                ->get()->getRowArray();

            // Reference time = last beat, else go-live time. A stream with neither
            // (no started_at and no beats) is left alone — we can't judge staleness.
            $reference = $latest['created_at'] ?? ($stream['started_at'] ?? null);
            if ($reference === null || $reference === '') {
                continue;
            }
            if ((string) $reference >= $cutoff) {
                continue; // fresh enough
            }

            // Stale: alert once (monitor incident) then auto-end. Wrapped so one
            // bad row can't abort the batch.
            try {
                $this->db->transStart();
                $this->ensureIncident($orgId, $stream, 'critical', 'sweep', [
                    'note'  => 'Auto-ended: no relay heartbeat for ' . $minutes . ' minutes.',
                    'cause' => 'relay_heartbeat_stale',
                ]);
                $this->db->table('streams')->where('id', $streamId)->where('status', 'live')->update([
                    'status'     => 'ended',
                    'ended_at'   => $this->clock->nowUtcMicro(),
                    'updated_at' => $this->clock->nowUtcMicro(),
                ]);
                $this->db->transComplete();
                if ($this->db->transStatus()) {
                    $ended++;
                    $endedIds[] = $streamId;
                }
            } catch (Throwable $e) {
                log_message('error', 'StreamRelayService::sweepStaleStreams failed for ' . $streamId . ': ' . $e->getMessage());
            }
        }

        return [
            'scanned'      => $scanned,
            'ended'        => $ended,
            'skipped_gate' => $skipped,
            'stream_ids'   => $endedIds,
        ];
    }

    // --------------------------------------------------------------- internals

    /**
     * Open an incident if none is currently open, fire alerts once, and mark the
     * stream's relay state. Idempotent per open incident.
     *
     * @param array<string,mixed> $stream
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed> the open incident row
     */
    private function ensureIncident(string $organizationId, array $stream, string $severity, string $detectedBy, array $data): array
    {
        $streamId = (string) $stream['id'];
        $existing = $this->db->table('stream_relay_incidents')
            ->where('stream_id', $streamId)->whereIn('status', ['open', 'acknowledged'])
            ->orderBy('detected_at', 'DESC')->limit(1)
            ->get()->getRowArray();

        // Downgrade the relay state regardless (down for critical, degraded for warning).
        $this->setRelayState($streamId, $severity === 'critical' ? 'down' : 'degraded');

        if ($existing !== null) {
            return $existing;
        }

        $id  = Uuid::v7();
        $now = $this->clock->nowUtcMicro();
        $affected = $data['affected_destinations'] ?? $this->erroredDestinations($streamId);
        $alerts   = $this->fireAlerts($organizationId, $stream, $id, $severity, $data['note'] ?? null);

        $this->db->table('stream_relay_incidents')->insert([
            'id'                    => $id,
            'organization_id'       => $organizationId,
            'stream_id'             => $streamId,
            'severity'              => $severity,
            'status'                => 'open',
            'cause'                 => isset($data['note']) ? (string) $data['note'] : (isset($data['cause']) ? (string) $data['cause'] : null),
            'affected_destinations' => $affected !== [] ? json_encode($affected) : null,
            'detected_by'           => $detectedBy,
            'detected_at'           => $now,
            'alerts_sent'           => $alerts !== [] ? json_encode($alerts) : null,
            'metrics_degraded'      => 1,
            'created_at'            => $now,
        ]);

        return [
            'id'     => $id,
            'status' => 'open',
        ];
    }

    /**
     * Immediate alerts: in-app to the organizer + a configured fallback channel.
     * Best-effort — an alerting failure must never crash the failure handler.
     * The delivery attempts are logged on the incident for accountability.
     *
     * @param array<string,mixed> $stream
     *
     * @return list<array<string,mixed>>
     */
    private function fireAlerts(string $organizationId, array $stream, string $incidentId, string $severity, ?string $note): array
    {
        $log       = [];
        $organizer = (string) ($stream['created_by'] ?? '');
        $at        = $this->clock->nowUtcString();
        $context   = [
            'stream_id'   => $stream['id'],
            'incident_id' => $incidentId,
            'severity'    => $severity,
            'title'       => $stream['title'] ?? null,
            'note'        => $note,
        ];

        if ($this->notifications !== null && $organizer !== '') {
            // In-app (primary) + email (configured fallback). Distinct dedupe
            // keys so both fire for the same incident.
            foreach (['in_app', 'email'] as $channel) {
                $ok = false;
                try {
                    $res = $this->notifications->send($organizationId, $organizer, $channel, 'stream_relay_failure', [
                        'priority'   => 'high',
                        'dedupe_key' => 'relay-failure:' . $incidentId . ':' . $channel,
                        'context'    => $context,
                    ]);
                    $ok = $res->ok;
                } catch (Throwable) {
                    $ok = false;
                }
                $log[] = ['channel' => $channel, 'target' => $organizer, 'at' => $at, 'ok' => $ok];
            }
        } else {
            $log[] = ['channel' => 'in_app', 'target' => $organizer, 'at' => $at, 'ok' => false, 'reason' => 'notifications_unavailable'];
        }

        return $log;
    }

    /** @return list<string> destination ids currently in error. */
    private function erroredDestinations(string $streamId): array
    {
        $rows = $this->db->table('stream_destinations')
            ->select('id')->where('stream_id', $streamId)->where('status', 'error')
            ->get()->getResultArray();

        return array_map(static fn ($r) => (string) $r['id'], $rows);
    }

    private function hasOpenIncident(string $streamId, ?string $excludeId = null): bool
    {
        $q = $this->db->table('stream_relay_incidents')
            ->where('stream_id', $streamId)->whereIn('status', ['open', 'acknowledged']);
        if ($excludeId !== null) {
            $q->where('id !=', $excludeId);
        }

        return $q->countAllResults() > 0;
    }

    private function findIncident(string $organizationId, string $incidentId): ?array
    {
        return $this->db->table('stream_relay_incidents')
            ->where('id', $incidentId)->where('organization_id', $organizationId)
            ->get()->getRowArray() ?: null;
    }

    private function currentRelayState(string $streamId): string
    {
        $row = $this->db->table('streams')->select('relay_state')->where('id', $streamId)->get()->getRowArray();

        return (string) ($row['relay_state'] ?? 'healthy');
    }

    private function setRelayState(string $streamId, string $state, bool $clearDegraded = false, bool $clearBypass = false): void
    {
        $update = ['relay_state' => $state, 'updated_at' => $this->clock->nowUtcMicro()];
        if ($state !== 'healthy') {
            // Stamp degraded_since once (don't overwrite an earlier failure time).
            $row = $this->db->table('streams')->select('degraded_since')->where('id', $streamId)->get()->getRowArray();
            if (empty($row['degraded_since'])) {
                $update['degraded_since'] = $this->clock->nowUtcString();
            }
        }
        if ($clearDegraded) {
            $update['degraded_since'] = null;
        }
        if ($clearBypass) {
            $update['bypass_destination_id'] = null;
        }
        $this->db->table('streams')->where('id', $streamId)->update($update);
    }

    /**
     * The documented direct-single-destination bypass procedure, surfaced to the
     * operator alongside the incident. Lists the stream's own destinations as
     * candidate direct targets.
     *
     * @param array<string,mixed> $stream
     *
     * @return array<string,mixed>
     */
    private function bypassProcedureData(array $stream): array
    {
        $candidates = $this->db->table('stream_destinations')
            ->select('id, provider, label, status, external_ref')
            ->where('stream_id', $stream['id'])
            ->whereIn('status', ['ready', 'active', 'error'])
            ->get()->getResultArray();

        return [
            'summary' => 'Relay fan-out has failed. To keep broadcasting, point the encoder '
                . 'directly at ONE destination, bypassing the relay. Overlays and multi-'
                . 'destination fan-out will be unavailable until the relay is restored.',
            'steps' => [
                'Choose one destination below as the single direct target.',
                'In the encoder (OBS/hardware), set the server/key for that destination directly (from the provider dashboard).',
                'Start streaming directly to that destination.',
                'Call activate-bypass with the chosen destination to record it; metrics stay marked degraded.',
                'When the relay is healthy again, stop the direct feed, resume relay, and resolve the incident.',
            ],
            'candidate_destinations' => $candidates,
            'current_bypass_destination_id' => $stream['bypass_destination_id'] ?? null,
        ];
    }
}
