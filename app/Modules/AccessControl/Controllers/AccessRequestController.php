<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Access-request / approval workflow endpoints (SRS FR-ACL-004).
 *
 * The subject a request is FOR comes from the body; the maker (requested_by)
 * and every reviewer come from the authenticated session, never client input,
 * so maker-checker cannot be spoofed. Review actions are additionally gated by
 * `authorize:access.request.approve` in Routes.php and by the PDP in the service.
 */
final class AccessRequestController extends BaseController
{
    /** Submit an access request (self-service or on behalf of another subject). */
    public function submit()
    {
        $in = $this->input();

        return $this->respondWith(AccessControlServices::accessRequests()->submit(
            $this->orgId(),
            (string) ($in['subject_id'] ?? $this->currentUserId()),
            $this->currentUserId('requested_by'),
            $in,
        ));
    }

    public function show(string $requestId = '')
    {
        $req    = AccessControlServices::accessRequests()->find($requestId);
        $result = $req === null
            ? Result::notFound('access.request_not_found', 'REQUEST_NOT_FOUND')
            : Result::ok($req);

        // API clients get JSON; browsers get the bespoke access-request-detail view.
        if ($this->wantsJson()) {
            return $this->respondAdmin($result, lang('AccessControl.accessRequest'), $requestId);
        }

        return $this->respondWith(
            $result,
            'WBS\AccessControl\Views\access_request_show',
            null,
            [
                'request' => $req,
                // Token the global webcsrfissue filter minted this request, so the
                // inline approve/reject/revoke/renew forms satisfy the webcsrf check.
                'csrf'    => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** Pending requests nominated to the current approver. */
    public function pending()
    {
        $rows = AccessControlServices::accessRequests()->pendingForApprover(
            $this->orgId(),
            $this->currentUserId(),
        );

        // API clients get JSON; browsers get the bespoke approval-queue view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok(['requests' => $rows, 'count' => count($rows)]),
                lang('AccessControl.accessRequestsPending'),
            );
        }

        return $this->respondWith(
            Result::ok(['requests' => $rows, 'count' => count($rows)]),
            'WBS\AccessControl\Views\access_requests_pending',
            null,
            [
                'requests' => $rows,
                'csrf'     => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function approve(string $requestId = '')
    {
        $result = AccessControlServices::accessRequests()->approve(
            $this->orgId(),
            $requestId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        );

        return $this->respondDecision($result, $requestId, 'approvedFlash');
    }

    public function reject(string $requestId = '')
    {
        $result = AccessControlServices::accessRequests()->reject(
            $this->orgId(),
            $requestId,
            $this->currentUserId('actor_id'),
            $this->field('note'),
        );

        return $this->respondDecision($result, $requestId, 'rejectedFlash');
    }

    public function revoke(string $requestId = '')
    {
        $result = AccessControlServices::accessRequests()->revoke(
            $this->orgId(),
            $requestId,
            $this->currentUserId('actor_id'),
            (string) $this->field('reason', ''),
        );

        return $this->respondDecision($result, $requestId, 'revokedFlash');
    }

    public function renew(string $requestId = '')
    {
        $in = $this->input();

        $result = AccessControlServices::accessRequests()->renew(
            $this->orgId(),
            $requestId,
            $this->currentUserId('actor_id'),
            (int) ($in['extra_days'] ?? 0),
            $in['note'] ?? null,
        );

        return $this->respondDecision($result, $requestId, 'renewedFlash');
    }

    /**
     * Shared PRG helper for the four review actions: browsers are redirected back
     * to the request detail with a success/error flash; API clients get JSON.
     */
    private function respondDecision(Result $result, string $requestId, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/access-requests/' . rawurlencode($requestId))->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.accessReqView.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
