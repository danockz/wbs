<?php

declare(strict_types=1);

namespace WBS\AccessControl\Controllers;

use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Delegation of authority (SRS FR-ACL-005).
 *
 * A leader delegates a permission they possess. There is intentionally NO
 * separate "delegate" permission gate on create: the authority to delegate IS
 * holding the underlying permission at a covering scope, which the service
 * verifies against the delegator's own grants (role, direct, or an upstream
 * delegation). The route only requires authentication; every invariant
 * (possess-it, equal/narrower scope, ≤ own duration, max chain depth) is
 * enforced in {@see \WBS\AccessControl\Services\DelegationService}.
 */
final class DelegationController extends BaseController
{
    /** POST delegations — delegate a held permission to a named delegate. */
    public function create()
    {
        $in        = $this->input();
        $delegator = $this->currentUserId('delegator_id');

        $result = AccessControlServices::delegations()->delegate(
            $this->orgId(),
            $delegator,
            $in,
        );

        // API clients get JSON; browsers get PRG back to the delegate's page.
        if (! $this->wantsJson()) {
            $delegate = (string) ($in['delegate_id'] ?? '');
            $to       = $delegate !== ''
                ? '/delegations/received/' . rawurlencode($delegate)
                : '/access-control';

            return redirect()->to($to)->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.delegationsRecvView.createdFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /** POST delegations/{id}/revoke — revoke a delegation (cascades to children). */
    public function revoke(string $delegationId = '')
    {
        $actor = $this->currentUserId('revoked_by');

        $result = AccessControlServices::delegations()->revoke(
            $this->orgId(),
            $actor,
            $delegationId,
            (string) $this->field('reason', ''),
        );

        // API clients get JSON; browsers get PRG back to the page they came from
        // (the received list or the chain view), carried in a posted return path.
        if (! $this->wantsJson()) {
            return redirect()->to($this->safeReturn())->with(
                $result->ok ? 'success' : 'error',
                (string) ($result->ok ? lang('AccessControl.delegationsRecvView.revokedFlash') : $result->message),
            );
        }

        return $this->respondWith($result);
    }

    /**
     * A safe local redirect target from the posted `return` field: it must be a
     * relative path under /delegations (single leading slash, no scheme/host), so
     * a crafted form can't turn PRG into an open redirect. Falls back to the
     * admin console.
     */
    private function safeReturn(): string
    {
        $return = (string) $this->field('return', '');
        if ($return !== '' && str_starts_with($return, '/delegations') && ! str_starts_with($return, '//')) {
            return $return;
        }

        return '/access-control';
    }

    /** GET delegations/{id}/chain — full sub-tree for traceability. */
    public function chain(string $delegationId = '')
    {
        $rows = AccessControlServices::delegations()->chain($this->orgId(), $delegationId);

        // API clients get JSON; browsers get the bespoke delegation-chain view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($rows),
                lang('AccessControl.delegationChain'),
                $delegationId,
            );
        }

        return $this->respondWith(
            Result::ok($rows),
            'WBS\AccessControl\Views\delegations_chain',
            null,
            [
                'delegations'  => $rows,
                'delegationId' => $delegationId,
                // Token the global webcsrfissue filter minted this request, so the
                // inline revoke forms satisfy the webcsrf check.
                'csrf'         => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    /** GET delegations/received/{subjectId} — delegations held by a subject. */
    public function received(string $subjectId = '')
    {
        $rows = AccessControlServices::delegations()->forDelegate(
            $this->orgId(),
            $subjectId,
            (bool) $this->field('active_only', true),
        );

        // API clients get JSON; browsers get the bespoke delegations-received view.
        if ($this->wantsJson()) {
            return $this->respondAdmin(
                Result::ok($rows),
                lang('AccessControl.delegationsReceived'),
                str_replace('{0}', $subjectId, lang('AccessControl.subjectSub')),
            );
        }

        return $this->respondWith(
            Result::ok($rows),
            'WBS\AccessControl\Views\delegations_received',
            null,
            [
                'delegations' => $rows,
                'subjectId'   => $subjectId,
                // Entity-reference pickers: the inline re-delegate form references a
                // delegate (user) and an optional scope group; give the view the
                // org roster + groups so those are chosen, not typed as raw ids.
                'delegates'   => IdentityServices::accounts()->listMembers($this->orgId(), 'active'),
                'groups'      => GroupServices::groups()->listForOrg($this->orgId()),
                // Token the global webcsrfissue filter minted this request, so the
                // inline re-delegate/revoke forms satisfy the webcsrf check.
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }
}
