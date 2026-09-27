<?php

declare(strict_types=1);

namespace WBS\Streaming\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;
use WBS\Streaming\Config\Services as StreamingServices;

/**
 * Relay health + mid-session failure response (SRS FR-STR-013).
 *
 * Health ingestion (heartbeat) is a trusted server-to-server signal from the
 * relay/monitor and is gated by `authorize:stream.moderate`. Organizer failure
 * response actions (report / acknowledge / activate-bypass / resolve) are also
 * gated by `authorize:stream.moderate`. Read surfaces (health / incidents) are
 * available to authenticated organizers.
 *
 * The incidents page is now a bespoke OPERATOR CONSOLE (not raw JSON / the
 * generic admin page): it lists a stream's relay incidents newest-first, offers a
 * "report a failure" form, and — per OPEN/ACKNOWLEDGED incident — the stage-
 * appropriate controls: acknowledge, activate the documented single-destination
 * bypass (choosing a destination), and resolve (with a note). Each control POSTs
 * to a webcsrf-guarded route and is PRG-redirected back to the incidents console
 * with a localized flash. The acting operator comes from the authenticated
 * session (a posted actor_id is honored only for explicit API callers). API
 * clients keep the identical JSON payloads.
 */
final class StreamRelayController extends BaseController
{
    /** Ingest a relay health sample; may auto-open an incident + alert. */
    public function heartbeat(string $streamId = '')
    {
        return $this->respondWith(StreamingServices::streamRelay()->heartbeat(
            $this->orgId(),
            $streamId,
            $this->input(),
        ));
    }

    /** Current relay health snapshot + open incidents + bypass procedure. */
    public function health(string $streamId = '')
    {
        $result = StreamingServices::streamRelay()->health($this->orgId(), $streamId);
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, 'Relay health', $streamId);
        }

        return $this->respondWith($result, 'WBS\Streaming\Views\relay_health', null, ['health' => $result->ok ? $result->data : null]);
    }

    /** Incident history + operator console for a stream. */
    public function incidents(string $streamId = '')
    {
        $rows = StreamingServices::streamRelay()->incidents(
            $this->orgId(),
            $streamId,
            (int) $this->field('limit', 50),
        );
        if ($this->wantsJson()) {
            return $this->respondAdmin(Result::ok([
                'stream_id' => $streamId,
                'incidents' => $rows,
                'count'     => count($rows),
            ]), 'Relay incidents', $streamId);
        }

        // Candidate destinations for the bypass control come from the health
        // snapshot's documented bypass procedure (ready/active/error targets).
        $destinations = [];
        $health = StreamingServices::streamRelay()->health($this->orgId(), $streamId);
        if ($health->ok) {
            $destinations = $health->data['bypass_procedure']['candidate_destinations'] ?? [];
        }

        return $this->respondWith(
            Result::ok(['stream_id' => $streamId, 'incidents' => $rows, 'count' => count($rows)]),
            'WBS\Streaming\Views\relay_incidents',
            null,
            [
                'incidents'    => $rows,
                'streamId'     => $streamId,
                'destinations' => $destinations,
                // Token the global webcsrfissue filter minted this request, so the
                // inline console forms satisfy the webcsrf check.
                'csrf'         => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Organizer: manually raise a relay-failure incident. */
    public function report(string $streamId = '')
    {
        $result = StreamingServices::streamRelay()->reportFailure(
            $this->orgId(),
            $streamId,
            $this->currentUserId('actor_id'),
            $this->input(),
        );

        return $this->respondRelayDecision($result, $streamId, 'reportedFlash');
    }

    /** Moderator: acknowledge an open incident. */
    public function acknowledge(string $incidentId = '')
    {
        $result = StreamingServices::streamRelay()->acknowledge(
            $this->orgId(),
            $incidentId,
            $this->currentUserId('actor_id'),
        );

        return $this->respondRelayDecision($result, $this->field('stream_id'), 'acknowledgedFlash');
    }

    /** Operator: record activation of the direct-single-destination bypass. */
    public function bypass(string $incidentId = '')
    {
        $result = StreamingServices::streamRelay()->activateBypass(
            $this->orgId(),
            $incidentId,
            $this->currentUserId('actor_id'),
            (string) ($this->field('destination_id', '') ?? ''),
        );

        return $this->respondRelayDecision($result, $this->field('stream_id'), 'bypassFlash');
    }

    /** Operator: resolve an incident and restore healthy relay tracking. */
    public function resolve(string $incidentId = '')
    {
        $result = StreamingServices::streamRelay()->resolve(
            $this->orgId(),
            $incidentId,
            $this->currentUserId('actor_id'),
            (string) ($this->field('note', '') ?? ''),
        );

        return $this->respondRelayDecision($result, $this->field('stream_id'), 'resolvedFlash');
    }

    /**
     * Post/Redirect/Get for a browser relay-console write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the stream's incidents console
     * with a localized success flash, or the failing Result's message as an error
     * flash. When no stream id is known (an API caller omitting it), a browser
     * falls back to the streams index.
     */
    private function respondRelayDecision(Result $result, ?string $streamId, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        $streamId = (string) ($streamId ?? '');
        $to = $streamId !== ''
            ? '/streams/' . rawurlencode($streamId) . '/relay/incidents'
            : '/streams';

        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Streaming.relayIncidents.' . $okKey));
    }
}
