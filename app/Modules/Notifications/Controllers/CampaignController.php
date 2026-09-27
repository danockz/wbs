<?php

declare(strict_types=1);

namespace WBS\Notifications\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Notifications\Config\Services as NotificationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Broadcast campaign lifecycle (SRS FR-NOT-004/008).
 *
 * draft -> pending_approval -> approved. Approval enforces SoD (approver !=
 * requester) in the service.
 *
 * The GET dashboard now also carries the leader WRITE forms: draft a campaign,
 * submit a draft for approval (freezing the audience count), and approve a pending
 * campaign. Each browser write POSTs to a webcsrf-guarded route and is PRG-
 * redirected back to /notifications/campaigns with a localized flash. The
 * requester/approver default to the current actor so SoD is enforced by identity,
 * not a spoofable form field. API clients keep the identical JSON payloads.
 */
final class CampaignController extends BaseController
{
    /** GET campaigns — list this org's campaigns (optionally by ?status=). */
    public function index()
    {
        $status    = $this->field('status');
        $campaigns = NotificationServices::campaigns()->list(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        // Browsers get the bespoke campaigns dashboard (no raw JSON); API JSON.
        return $this->respondWith(
            Result::ok(['campaigns' => $campaigns]),
            'WBS\Notifications\Views\campaigns',
            null,
            [
                'campaigns' => $campaigns,
                'csrf'      => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        // The requester is the authenticated actor (falls back to a posted field
        // only for API clients that set it explicitly).
        $requestedBy = $this->actorId() ?? (string) ($in['requested_by'] ?? '');

        return $this->respondCampaignDecision(
            NotificationServices::campaigns()->create($orgId, (string) $requestedBy, $in),
            'createdFlash',
        );
    }

    public function submit(string $campaignId = '')
    {
        return $this->respondCampaignDecision(
            NotificationServices::campaigns()->submitForApproval(
                $campaignId,
                (int) $this->field('audience_count', 0),
            ),
            'submittedFlash',
        );
    }

    public function approve(string $campaignId = '')
    {
        // The approver is the authenticated actor; the service rejects self-approval
        // (SoD). A posted approver_id is only honored for explicit API callers.
        $approver = $this->actorId() ?? (string) $this->field('approver_id', '');

        return $this->respondCampaignDecision(
            NotificationServices::campaigns()->approve($campaignId, (string) $approver),
            'approvedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser campaign write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the campaigns dashboard with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function respondCampaignDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/notifications/campaigns';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Notifications.admin.' . $okKey));
    }
}
