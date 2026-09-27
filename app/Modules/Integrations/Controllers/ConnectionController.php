<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Provider connection lifecycle (SRS FR-INT-005/006).
 *
 * draft -> tested -> pending_approval -> active. Credentials are write-only and
 * never returned. Payment/notification activation enforces SoD in the service.
 *
 * The new GET index renders a bespoke connections dashboard (no raw JSON to a
 * browser): a "connect a provider" form plus every connection with the stage-
 * appropriate controls — store a credential slot, record a test (a pass moves
 * draft -> tested), submit for approval (tested -> pending_approval), and activate
 * (payment/notification need a different approver; others activate directly). Each
 * browser write POSTs to a webcsrf-guarded route and is PRG-redirected back to
 * /integrations/connections with a localized flash. The requester/approver come
 * from the authenticated session so SoD is enforced by identity, not a spoofable
 * field. API clients keep the identical JSON payloads. Secret material is never
 * echoed back — the credential form only confirms a slot was stored.
 */
final class ConnectionController extends BaseController
{
    /** GET connections — the provider connections dashboard. */
    public function index()
    {
        $status      = $this->field('status');
        $connections = IntegrationServices::connections()->listForOrg(
            $this->orgId(),
            $status !== null ? (string) $status : null,
        );

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['connections' => $connections]));
        }

        return $this->respondWith(
            Result::ok(['connections' => $connections]),
            'WBS\Integrations\Views\connections',
            null,
            [
                'connections' => $connections,
                'adapters'    => IntegrationServices::adapterCatalog()->activeAdapters(),
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function create()
    {
        $in    = $this->input();
        $orgId = $this->orgId();
        // The requester is the authenticated actor (SoD is enforced against this
        // at activation), falling back to a posted field only for API clients.
        $requestedBy = $this->actorId() ?? (string) ($in['requested_by'] ?? '');

        return $this->respondConnectionDecision(
            IntegrationServices::connections()->create($orgId, (string) $requestedBy, $in),
            'createdFlash',
        );
    }

    public function setCredential(string $connectionId = '')
    {
        $in = $this->input();

        return $this->respondConnectionDecision(
            IntegrationServices::connections()->setCredential(
                $connectionId,
                (string) ($in['slot'] ?? ''),
                (string) ($in['secret'] ?? ''),
            ),
            'credentialFlash',
        );
    }

    public function test(string $connectionId = '')
    {
        $in = $this->input();

        return $this->respondConnectionDecision(
            IntegrationServices::connections()->recordTest(
                $connectionId,
                (string) ($in['operation'] ?? 'healthCheck'),
                (bool) ($in['pass'] ?? false),
                (bool) ($in['sandbox'] ?? true),
                (array) ($in['detail'] ?? []),
            ),
            'testedFlash',
        );
    }

    public function submit(string $connectionId = '')
    {
        return $this->respondConnectionDecision(
            IntegrationServices::connections()->submitForApproval($connectionId),
            'submittedFlash',
        );
    }

    public function activate(string $connectionId = '')
    {
        // The approver is the authenticated actor; the service rejects self-approval
        // (SoD) for payment/notification. A posted approver_id is honored only for
        // explicit API callers.
        $approver = $this->actorId() ?? (string) $this->field('approver_id', '');

        return $this->respondConnectionDecision(
            IntegrationServices::connections()->activate($connectionId, (string) $approver),
            'activatedFlash',
        );
    }

    /**
     * Post/Redirect/Get for a browser connection write: API clients keep the raw
     * Result (JSON); browsers are redirected back to the connections dashboard with
     * a localized success flash, or the failing Result's message as an error flash.
     */
    private function respondConnectionDecision(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/integrations/connections';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Integrations.connections.' . $okKey));
    }
}
