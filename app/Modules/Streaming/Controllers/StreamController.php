<?php

declare(strict_types=1);

namespace WBS\Streaming\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;
use WBS\Streaming\Config\Services as StreamingServices;

/**
 * Stream lifecycle + destinations + access (SRS FR-STR-002/003/006/012).
 *
 * The index and the organizer dashboard are now bespoke WRITE surfaces (they used
 * to render read-only, sending every write action to the generic data-page):
 *   - index carries a "create a stream" form (title / access / schedule);
 *   - the dashboard carries the stage-appropriate lifecycle controls — add a
 *     destination (draft/scheduled), provision pending destinations, go live,
 *     end, and link an external archive (VOD) once ended.
 * Each control POSTs to a webcsrf-guarded route; the controller PRG-redirects back
 * to the originating page with a localized flash (JSON is preserved for API
 * clients). The creator identity comes from the authenticated session.
 */
final class StreamController extends BaseController
{
    /** GET streams — the stream list + a create-a-stream form. */
    public function index()
    {
        $orgId   = $this->orgId();
        $streams = StreamingServices::streams()->listForOrg($orgId, (int) $this->field('limit', 50));

        return $this->respondWith(
            Result::ok(['streams' => $streams, 'count' => count($streams)]),
            htmlView: 'WBS\Streaming\Views\index',
            viewData: [
                'result' => ['streams' => $streams],
                'title'  => 'Streams',
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function create()
    {
        $in      = $this->input();
        $orgId   = $this->orgId();
        $creator = $this->currentUserId('created_by');

        return $this->respondStreamDecision(
            StreamingServices::streams()->create($orgId, $creator, $in),
            '/streams',
            'createdFlash',
        );
    }

    public function addDestination(string $streamId = '')
    {
        return $this->respondStreamDecision(
            StreamingServices::streams()->addDestination($streamId, $this->input()),
            $this->dashboardPath($streamId),
            'destinationFlash',
        );
    }

    /** Create the provider broadcast(s) for pending destinations (S1). */
    public function provision(string $streamId = '')
    {
        return $this->respondStreamDecision(
            StreamingServices::streams()->provisionDestinations($streamId),
            $this->dashboardPath($streamId),
            'provisionedFlash',
        );
    }

    public function goLive(string $streamId = '')
    {
        return $this->respondStreamDecision(
            StreamingServices::streams()->goLive($streamId),
            $this->dashboardPath($streamId),
            'liveFlash',
        );
    }

    public function end(string $streamId = '')
    {
        return $this->respondStreamDecision(
            StreamingServices::streams()->end($streamId),
            $this->dashboardPath($streamId),
            'endedFlash',
        );
    }

    public function archive(string $streamId = '')
    {
        $in = $this->input();

        return $this->respondStreamDecision(
            StreamingServices::streams()->linkArchive(
                $streamId,
                (string) ($in['provider'] ?? ''),
                (string) ($in['external_url'] ?? ''),
                (string) ($in['access_policy'] ?? 'restricted'),
            ),
            $this->dashboardPath($streamId),
            'archivedFlash',
        );
    }

    public function dashboard(string $streamId = '')
    {
        $result = StreamingServices::streams()->organizerDashboard($streamId);

        return $this->respondWith(
            $result,
            htmlView: 'WBS\Streaming\Views\dashboard',
            viewData: [
                'result'   => $result->data,
                'title'    => 'Stream dashboard',
                'streamId' => $streamId,
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function access(string $streamId = '')
    {
        $viewerId = $this->actorId();

        return $this->respondPage(
            StreamingServices::streams()->canView($streamId, $viewerId),
            'stream_access',
            static fn (array $d): array => ['record' => $d],
        );
    }

    /** Canonical dashboard path for a stream (PRG target). */
    private function dashboardPath(string $streamId): string
    {
        return $streamId !== '' ? '/streams/' . rawurlencode($streamId) . '/dashboard' : '/streams';
    }

    /**
     * Post/Redirect/Get for a browser stream-lifecycle write: API clients keep the
     * raw Result (JSON); browsers are redirected to $to with a localized success
     * flash, or the failing Result's message as an error flash.
     */
    private function respondStreamDecision(Result $result, string $to, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Streaming.lifecycle.' . $okKey));
    }
}
