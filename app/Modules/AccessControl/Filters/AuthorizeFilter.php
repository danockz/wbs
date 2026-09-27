<?php

declare(strict_types=1);

namespace WBS\AccessControl\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\Shared\Http\ProblemResponder;

/**
 * Authorization filter — invokes the Policy Decision Point (SRS FR-ACL-*).
 *
 * Usage on a route: ['filter' => 'authorize:event.create']. The permission code
 * is passed as the filter argument and evaluated by the default-deny PDP
 * (MAC -> SoD -> ABAC -> RBAC). Requires the AuthFilter to have run first so the
 * subject/org are on the request; otherwise it denies.
 *
 * Registered as alias `authorize` in Config/Filters.
 */
final class AuthorizeFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $args   = is_array($arguments) ? $arguments : [(string) $arguments];
        $action = $args[0] ?? '';
        if ($action === '') {
            return $this->deny($request, 'access.no_action');
        }

        // Optional second arg `any` = coarse capability gate for endpoints whose
        // resource target group is not known at routing time: admit the subject
        // if they hold the permission in ANY scope, then let the service do the
        // authoritative per-group check (StreamService pattern). Without it the
        // gate is org-wide (a group-scoped grant does not pass).
        $scopeCheck = ($args[1] ?? '') === 'any' ? 'any' : 'exact';

        $subjectId = $request->wbsUserId ?? null;
        $orgId     = $request->wbsOrgId ?? null;
        if ($subjectId === null || $orgId === null) {
            return $this->deny($request, 'access.unauthenticated', 401);
        }

        $decision = AccessControlServices::authorization()->decide(new AccessRequest(
            organizationId: (string) $orgId,
            subjectId: (string) $subjectId,
            action: $action,
            attributes: [
                'mfa_level'   => $request->wbsMfaLevel ?? 'none',
                'ip'          => $request->getIPAddress(),
                'scope_check' => $scopeCheck,
            ],
        ));

        if (! $decision->isPermitted()) {
            return $this->deny($request, $decision->reason);
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    /**
     * A 401 here means no principal was attached at all (the auth filter did not
     * run or the session is gone), so the browser is sent to sign in; a 403 is a
     * real permission refusal and says so, with the PDP's reason as a reference
     * code. JSON clients get exactly the envelope they got before.
     */
    private function deny(RequestInterface $request, string $reason, int $status = 403): ResponseInterface
    {
        $responder = new ProblemResponder($request);

        return $status === 401
            ? $responder->unauthenticated(null, $reason)
            : $responder->forbidden($reason);
    }
}
