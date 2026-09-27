<?php

declare(strict_types=1);

namespace WBS\Referrals\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Referrals\Config\Services as ReferralServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Sponsor-reassignment maker-checker endpoints (SRS FR-MEM-002).
 *
 * `submit` is the maker step (any authenticated member of staff with the base
 * capability); `approve`/`reject` are the checker step, gated by
 * `sponsor.reassign.approve` at the route AND blocked from self-approval by the
 * PDP's segregation-of-duties combinator (and defensively in the service).
 */
final class SponsorReassignmentController extends BaseController
{
    /** Memo of the checker's leadership scope, keyed by member id. */
    private array $scopeCache = [];

    /** Maker: open a reassignment request for a member. */
    public function submit()
    {
        $in = $this->input();

        $res = ReferralServices::sponsorReassignments()->submit(
            $this->orgId(),
            (string) ($in['member_id'] ?? ''),
            (string) ($this->actorId() ?? ''),
            [
                'new_sponsor_id' => $in['new_sponsor_id'] ?? '',
                'reason'         => $in['reason'] ?? '',
                'approver_id'    => $in['approver_id'] ?? null,
                'effective_at'   => $in['effective_at'] ?? null,
            ],
        );

        return $this->afterAction($res);
    }

    /** Requests awaiting the current user as approver (or unassigned). */
    public function pending()
    {
        $requests = ReferralServices::sponsorReassignments()->pendingForApprover(
            $this->orgId(),
            (string) ($this->actorId() ?? ''),
        );

        // Leadership scope: the checker's queue is NOT an org-wide inbox — the same
        // rule the prospect-transfer queue applies. Filtered before content
        // negotiation so the JSON API cannot be used to read a wider queue.
        $requests = $this->scopeFilter($requests);

        // API clients get JSON; browsers get the bespoke maker–checker dashboard
        // (never raw JSON), with a double-submit CSRF token minted for its forms.
        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok(['requests' => $requests]));
        }

        $csrf = \WBS\Identity\Config\Services::webAuth()->issueCsrf();
        $body = view('WBS\Referrals\Views\reassign_index', [
            'requests' => $requests,
            'csrf'     => $csrf,
            // member_id / new_sponsor_id / approver_id are entity references →
            // offer the org roster so all three are picked, not typed as raw ids.
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

    public function show(string $requestId = '')
    {
        $req = ReferralServices::sponsorReassignments()->find($this->orgId(), $requestId);
        if ($req !== null && ! $this->inScope($req)) {
            // Out of scope is indistinguishable from absent: 404, never 403.
            $req = null;
        }
        $result = $req === null
            ? Result::notFound('sponsorship.reassign_not_found', 'REASSIGN_NOT_FOUND')
            : Result::ok($req);

        // API clients get JSON; browsers get the bespoke detail page (never raw
        // JSON), which shows a not-found panel when the id is unknown.
        return $this->respondWith(
            $result,
            htmlView: 'WBS\Referrals\Views\reassign_show',
            viewData: ['request' => $req, 'requestId' => $requestId],
        );
    }

    /** Checker: approve (performs the re-parent + recalculation). */
    public function approve(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::sponsorReassignments()->approve(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /** Checker: reject a pending request. */
    public function reject(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::sponsorReassignments()->reject(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /** Maker (or authorized staff): cancel a pending request. */
    public function cancel(string $requestId = '')
    {
        if ($out = $this->outOfScope($requestId)) {
            return $this->afterAction($out);
        }

        return $this->afterAction(ReferralServices::sponsorReassignments()->cancel(
            $this->orgId(),
            $requestId,
            (string) ($this->actorId() ?? ''),
            $this->field('note'),
        ));
    }

    /** Landing page for the maker–checker dashboard. */
    private const DASHBOARD = '/referrals/sponsor-reassignments/pending';

    /**
     * Leadership-scope filter for the queue, keyed on the member's own group.
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
     * Is this reassignment inside the checker's leadership scope? The request
     * concerns a member, so scope is tested against that member's own primary
     * group (`GroupScopeResolver::primaryMembershipGroup` — the same membership
     * fallback gamification attribution uses, so a member's effective group is
     * resolved identically everywhere). A member with no active membership
     * resolves to a NULL target, which only an org-wide grant covers: fail-closed.
     *
     * Delegates to `canManageGroupScope()`, i.e. the same PDP that governs
     * everything else, so scope_mode (self / self_and_descendants /
     * descendants_only / groups), hand-picked sets, cross-cut links and
     * break-glass all apply identically. Memoised per member id.
     *
     * @param array<string,mixed> $request
     */
    private function inScope(array $request): bool
    {
        $memberId = isset($request['member_id']) ? (string) $request['member_id'] : '';
        if ($memberId === '') {
            return false;
        }
        if (! array_key_exists($memberId, $this->scopeCache)) {
            $group = SharedServices::groupScope()->primaryMembershipGroup($this->orgId(), $memberId);
            $this->scopeCache[$memberId] = $this->canManageGroupScope('sponsor.reassign.approve', $group);
        }

        return $this->scopeCache[$memberId] === true;
    }

    /**
     * Pre-flight for the mutating verbs: denied when the request is unknown to
     * this org or sits outside the checker's subtree. Null when it may proceed
     * (the service still owns maker ≠ checker and the eligibility re-check).
     */
    private function outOfScope(string $requestId): ?Result
    {
        $req = ReferralServices::sponsorReassignments()->find($this->orgId(), $requestId);
        if ($req === null) {
            return Result::notFound('sponsorship.reassign_not_found', 'REASSIGN_NOT_FOUND');
        }

        return $this->inScope($req) ? null : Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
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
