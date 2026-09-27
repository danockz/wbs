<?php

declare(strict_types=1);

namespace WBS\Community\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Community\Config\Services as CommunityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Content reporting + moderation endpoints (SRS FR-COM-003).
 *
 * `act` is gated by the authorize:community.moderate filter on the route; the
 * service still enforces mandatory reason + records evidence.
 *
 * Both writes now PRG-redirect a browser back to the feed with a localized flash
 * (report -> a member flagging content; act -> a moderator hiding/locking/etc.),
 * while API clients keep the identical JSON payloads. The reporter/moderator id
 * comes from the authenticated session, not a spoofable form field.
 */
final class ModerationController extends BaseController
{
    public function report()
    {
        $in       = $this->input();
        $orgId    = $this->orgId();
        $reporter = $this->currentUserId('reporter_id');

        return $this->respondModerationDecision(
            CommunityServices::moderation()->report(
                $orgId,
                (string) ($in['subject_type'] ?? ''),
                (string) ($in['subject_id'] ?? ''),
                $reporter,
                (string) ($in['reason_code'] ?? ''),
                (string) ($in['detail'] ?? ''),
            ),
            'reportedFlash',
        );
    }

    public function act()
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        $mod   = $this->currentUserId('moderator_id');

        return $this->respondModerationDecision(
            CommunityServices::moderation()->act(
                $orgId,
                $mod,
                (string) ($in['subject_type'] ?? ''),
                (string) ($in['subject_id'] ?? ''),
                (string) ($in['action'] ?? ''),
                (string) ($in['reason'] ?? ''),
                $in['policy_basis'] ?? null,
                $in['report_id'] ?? null,
            ),
            'moderatedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser moderation write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the feed with a localized
     * success flash, or the failing Result's message as an error flash.
     */
    private function respondModerationDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/community/feed';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Community.admin.' . $okKey));
    }
}
