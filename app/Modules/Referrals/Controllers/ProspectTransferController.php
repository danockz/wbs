<?php

declare(strict_types=1);

namespace WBS\Referrals\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Prospect-transfer maker-checker endpoints (FR-REF-7 review path).
 *
 * The queue only exists where a subtree sets
 * `referrals.prospect_transfer.requires_review`; everywhere else a due transfer
 * applies on the spot exactly as before. Approval delegates to
 * {@see \WBS\Referrals\Services\ProspectTransferService::apply()}, so this
 * controller changes WHO signs a transfer off, never WHAT one does.
 *
 * `submit` is the maker step; `approve`/`reject` are the checker step, gated by
 * `sponsor.reassign.approve` at the route (the permission budget is frozen at 41
 * bits, and both queues express the same duty: a second leader confirming a
 * re-parenting decision) AND blocked from self-approval by the PDP's
 * segregation-of-duties combinator, re-asserted defensively in the service.
 *
 * Mirrors {@see SponsorReassignmentController} deliberately — same verbs, same
 * content negotiation, same post-redirect-get — so the two queues read alike.
 */
final class ProspectTransferController extends BaseController
{
    /** Landing page for the maker–checker dashboard. */
    private const DASHBOARD = '/referrals/prospect-transfers/pending';

    /** Memo of the checker's leadership scope, keyed by group id. */
    private array $scopeCache = [];

    /** Maker: propose that a contact move to another mentor's group. */
    public function submit()
    {
        $in = $this->input();

        $res = ReferralServices::prospectTransferReviews()->submit(
            $this->orgId(),
            (string) ($in['prospect_id'] ?? ''),
            (string) ($this->actorId() ?? ''),
            [
                'to_owner_user_id' => $in['to_owner_user_id'] ?? '',
                'reason'           => $in['reason'] ?? '',
                'approver_id'      => $in['approver_id'] ?? null,
                'trigger_type'     => $in['trigger_type'] ?? 'manual',
                'trigger_id'       => $in['trigger_id'] ?? null,
            ],
        );

        return $this->afterAction($res);
    }

    /** Requests awaiting the current user as checker (or unassigned). */
    public function pending()
    {
        $actorId  = (string) ($this->actorId() ?? '');
        $requests = ReferralServices::prospectTransferReviews()->pendingForApprover($this->orgId(), $actorId);

        // Leadership scope: the checker's queue is NOT an org-wide inbox. Applied
        // before content negotiation so the JSON API is filtered identically —
        // there is no second, wider queue to reach by asking for JSON.
        $requests = $this->scopeFilter($requests);

        // API clients get JSON; browsers get the bespoke maker–checker dashboard
        // (never raw JSON), with a double-submit CSRF token minted for its forms.
        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['requests' => $requests]));
        }

        $csrf = \WBS\Identity\Config\Services::webAuth()->issueCsrf();
        $body = view('WBS\Referrals\Views\transfer_index', [
            'requests' => $requests,
            'csrf'     => $csrf,
            // The maker proposes for a contact in THEIR OWN book — so the picker
            // is scoped to what they may already see, never the whole org.
            'contacts' => ReferralServices::contactBook()->listForOwner($this->orgId(), $actorId),
            // to_owner_user_id / approver_id are entity references → offer the org
            // roster so both are picked, not typed as raw ids.
            'roster'   => \WBS\Identity\Config\Services::accounts()->listMembers($this->orgId(), 'active'),
        ]);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body)
            ->setCookie($this->csrfCookie($csrf));
    }

    /** One request, with its append-only review trail. */
    public function show(string $requestId = '')
    {
        $req = ReferralServices::prospectTransferReviews()->find($this->orgId(), $requestId);
        if ($req !== null && ! $this->inScope($req)) {
            // Out of scope is indistinguishable from absent: 404, never 403.
            $req = null;
        }
        $result = $req === null
            ? Result::notFound('contact.transfer_request_not_found', 'REQUEST_NOT_FOUND')
            : Result::ok($req);

        // API clients get JSON; browsers get the bespoke detail page (never raw
        // JSON), which shows a not-found panel when the id is unknown.
        return $this->respondWith(
            $result,
            htmlView: 'WBS\Referrals\Views\transfer_show',
            viewData: ['request' => $req, 'requestId' => $requestId],
        );
    }

    /** Checker: approvere-checks the policy, then performs the transfer. */
    public function approve(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::prospectTransferReviews()->approve(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /** Checker: refuse a pending request. The contact stays exactly where it was. */
    public function reject(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::prospectTransferReviews()->reject(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /** Maker (or authorized staff): withdraw a pending request. */
    public function cancel(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::prospectTransferReviews()->cancel(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /**
     * Leadership-scope filter for the queue. A checker sees only requests touching
     * their own subtree; the identical rule is applied to the sponsor-reassignment
     * queue so both review surfaces behave alike.
     *
     * @param list<array<string,mixed>> $requests
     *
     * @return list<array<string,mixed>>
     */
    private function scopeFilter(array $requests): array
    {
        $kept = [];
        foreach ($requests as $r) {
            if ($this->inScope($r)) {
                $kept[] = $r;
            }
        }

        return $kept;
    }

    /**
     * Is this request inside the checker's leadership scope? A transfer touches
     * two groups and EITHER side being covered is enough — a leader sees takeovers
     * INTO their cells and OUT of them.
     *
     * Delegates to `canManageGroupScope()`, i.e. the same PDP that governs
     * everything else, so scope_mode (self / self_and_descendants /
     * descendants_only / groups), hand-picked sets, cross-cut links and
     * break-glass all apply identically. There is deliberately no second scope
     * implementation here. Memoised per group id, so a full queue costs one PDP
     * call per distinct group.
     *
     * @param array<string,mixed> $request
     */
    private function inScope(array $request): bool
    {
        foreach ([$request['from_group_id'] ?? null, $request['to_group_id'] ?? null] as $gid) {
            $gid = is_string($gid) && $gid !== '' ? $gid : null;
            if ($gid === null) {
                continue;
            }
            if (! array_key_exists($gid, $this->scopeCache)) {
                $this->scopeCache[$gid] = $this->canManageGroupScope('sponsor.reassign.approve', $gid);
            }
            if ($this->scopeCache[$gid] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-flight for the mutating verbs: denied when the request is unknown to
     * this org or sits outside the checker's subtree. Null when it may proceed
     * (the service still owns maker ≠ checker and the eligibility re-check).
     */
    private function outOfScope(string $requestId): ?Result
    {
        $req = ReferralServices::prospectTransferReviews()->find($this->orgId(), $requestId);
        if ($req === null) {
            return Result::notFound('contact.transfer_request_not_found', 'REQUEST_NOT_FOUND');
        }

        return $this->inScope($req) ? null : Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
    }

    /**
     * Double-submit CSRF cookie matching the ContactBook/WebSession shape
     * (HttpOnly, SameSite=Lax, Secure over HTTPS) so the dashboard forms pass the
     * `webcsrf` guard on POST.
     *
     * @return array<string,mixed>
     */
    private function csrfCookie(string $value): array
    {
        $secure = str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')
            || (method_exists($this->request, 'isSecure') && $this->request->isSecure());

        return [
            'name'     => 'wbs_csrf',
            'value'    => $value,
            'expires'  => 7200,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * A maker/checker mutation returns JSON to API clients (via respondWith's
     * content negotiation) and, for browsers, a post-redirect-get back to the
     * dashboard — so a page refresh never re-submits and the browser never sees
     * raw JSON.
     */
    private function afterAction(Result $res): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($res);
        }

        return redirect()->to(self::DASHBOARD);
    }
}
