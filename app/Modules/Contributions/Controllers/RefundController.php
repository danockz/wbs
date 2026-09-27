<?php

declare(strict_types=1);

namespace WBS\Contributions\Controllers;

use WBS\Contributions\Config\Services as ContributionServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Refund maker-checker endpoints (SRS FR-VBCS-007).
 *
 * request -> approve -> execute. Approval enforces SoD (approver != requester);
 * the service returns 403 SOD_SELF_APPROVAL if violated.
 *
 * GET contributions/refunds/pending is a browser CONSOLE: the actionable refund
 * queue with a per-item approve control (on `requested`) and execute control (on
 * `approved`). Browsers get PRG redirects with localized flashes; API clients
 * keep JSON. The approve/execute POSTs are webcsrf-guarded. Refund `request`
 * is initiated from a contribution and stays JSON (no dedicated browser form).
 */
final class RefundController extends BaseController
{
    /**
     * GET contributions/refunds/pending — the refund maker-checker QUEUE:
     * open refund requests (awaiting approval or execution). API clients get JSON.
     */
    public function pending()
    {
        $rows = ContributionServices::refunds()->pending(
            $this->orgId(),
            (int) $this->field('limit', 50),
            (int) $this->field('offset', 0),
        );

        return $this->respondWith(
            Result::ok($rows),
            htmlView: 'WBS\Contributions\Views\refund_pending',
            viewData: [
                'result' => $rows,
                'title'  => 'Refunds — pending',
                // Token the global webcsrfissue filter minted this request, so the
                // inline approve/execute forms satisfy the webcsrf check.
                'csrf'   => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function request(string $contributionId = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        return $this->respondWith(ContributionServices::refunds()->request(
            $orgId,
            $contributionId,
            (int) ($in['amount_minor'] ?? 0),
            (string) ($in['requested_by'] ?? $this->currentUserId('requested_by')),
            (string) ($in['reason'] ?? ''),
        ));
    }

    public function approve(string $refundId = '')
    {
        $result = ContributionServices::refunds()->approve(
            $refundId,
            (string) $this->currentUserId('approver_id'),
        );

        return $this->respondRefundDecision($result, 'approvedFlash');
    }

    public function execute(string $refundId = '')
    {
        $in = $this->input();

        $result = ContributionServices::refunds()->execute($refundId, [
            'provider'          => $in['provider'] ?? null,
            'provider_refund_id' => $in['provider_refund_id'] ?? null,
        ]);

        return $this->respondRefundDecision($result, 'executedFlash');
    }

    /**
     * PRG for a browser refund decision: redirect back to the refund queue with a
     * success/error flash; API clients keep the JSON Result. Success copy comes
     * from the localized refundPending flash keys; failures (bad-state, SoD
     * self-approval, not-approved) surface the Result message.
     */
    private function respondRefundDecision(Result $result, string $flashKey)
    {
        if (! $this->wantsJson()) {
            return redirect()->to('/contributions/refunds/pending')->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('Contributions.refundPending.' . $flashKey) : $result->message),
            );
        }

        return $this->respondWith($result);
    }
}
