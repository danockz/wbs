<?php

declare(strict_types=1);

namespace WBS\Meetings\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Meetings\Config\Services as MeetingServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Meeting integration endpoints (SRS FR-MTG-001..003).
 *
 * The GET side renders the self-contained schedule dashboard, which now also
 * carries the leader WRITE forms (schedule a meeting, change status, grant a
 * participant a join token). Each browser write POSTs to a webcsrf-guarded route
 * and is PRG-redirected back to /meetings with a localized flash so a reload never
 * re-submits and a browser never sees raw JSON. API clients (Accept JSON, ?format=
 * json, XHR) keep the exact same JSON payloads — only the HTML representation gains
 * the forms + redirect.
 */
final class MeetingController extends BaseController
{
    /** GET meetings — list this org's meetings (optionally by ?status=). */
    public function index()
    {
        $status   = $this->field('status');
        $meetings = MeetingServices::meetings()->list(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        // Browsers get the bespoke schedule dashboard (no raw JSON); API JSON.
        return $this->respondWith(
            Result::ok(['meetings' => $meetings]),
            'WBS\Meetings\Views\schedule',
            null,
            [
                'meetings' => $meetings,
                // user_id on the per-meeting grant form is an entity reference →
                // offer the org roster so it's picked, not typed as a raw id.
                'roster'   => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondMeetingDecision(
            MeetingServices::meetings()->create($orgId, $in),
            'createdFlash',
        );
    }

    public function grant(string $meetingId = '')
    {
        $in = $this->input();

        return $this->respondMeetingDecision(
            MeetingServices::meetings()->grantAccess(
                $meetingId,
                (string) ($in['user_id'] ?? ''),
                (string) ($in['role'] ?? 'attendee'),
                (int) ($in['ttl_seconds'] ?? 3600),
            ),
            'grantedFlash',
        );
    }

    public function transition(string $meetingId = '')
    {
        return $this->respondMeetingDecision(
            MeetingServices::meetings()->transition(
                $meetingId,
                (string) $this->field('status', ''),
            ),
            'transitionedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser meeting write: API clients keep the raw
     * Result (JSON), browsers are redirected back to the schedule with a localized
     * success flash; a failing Result flashes its message as an error. The grant
     * token is one-time and MUST NOT be flashed, so success only confirms.
     */
    private function respondMeetingDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        if (! $result->ok) {
            return redirect()->to('/meetings')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/meetings')->with('success', lang('Meetings.admin.' . $okKey));
    }
}
