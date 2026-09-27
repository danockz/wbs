<?php

declare(strict_types=1);

namespace WBS\Identity\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\ProblemResponder;

/**
 * Session authentication filter (SRS FR-ID session/token).
 *
 * Resolves an active session from a Bearer token (session id) or the
 * `X-WBS-Session` header, attaches the authenticated user/org/assurance to the
 * request as attributes for downstream authorization, and rejects unauthenticated
 * access with a generic 401 (no account-existence leakage).
 *
 * The 401 is content-negotiated by ProblemResponder: an API caller gets the
 * problem+json envelope, a BROWSER gets a localized page that explains the
 * expired session and offers sign-in with a return to where it was. Filters run
 * before any controller, so this is the layer that has to get it right.
 *
 * Registered as alias `auth` in Config/Filters. Apply per-route.
 */
final class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $credential = $this->extractCredential($request);
        if ($credential === null) {
            return $this->deny($request, false);
        }

        // API access token (FR-ID-006): prefixed, hashed-at-rest, scoped. These
        // authenticate machine/API callers; MFA assurance is 'token'.
        if (str_starts_with($credential, 'wbsat_')) {
            $verified = IdentityServices::tokens()->verifyAccessToken($credential);
            if (! $verified->ok) {
                return $this->deny($request, true);
            }
            $request->wbsUserId   = $verified->data['user_id'];
            $request->wbsOrgId    = $verified->data['organization_id'];
            $request->wbsMfaLevel = 'token';
            $request->wbsScopes   = $verified->data['scopes'];
            $request->wbsTokenId  = $verified->data['token_id'];

            return $request;
        }

        // Otherwise treat the credential as a server-side session reference.
        $session = IdentityServices::sessions()->active($credential);
        if ($session === null) {
            return $this->deny($request, true);
        }

        // Attach context for controllers/authorization (CI request store).
        $request->wbsUserId    = $session['user_id'];
        $request->wbsOrgId     = $session['organization_id'];
        $request->wbsMfaLevel  = $session['mfa_level'];
        $request->wbsSessionId = $credential;

        IdentityServices::sessions()->touch($credential);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    /**
     * A Bearer credential (API token OR session ref), an `X-WBS-Session` header,
     * or — for browser web sessions — the HttpOnly `wbs_session` cookie. Header
     * credentials win so API callers are never overridden by a stray cookie.
     */
    private function extractCredential(RequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $hdr = $request->getHeaderLine('X-WBS-Session');
        if ($hdr !== '') {
            return $hdr;
        }

        // Browser session cookie (set by the web sign-in flow). The runtime
        // object is a WbsIncomingRequest (extends IncomingRequest::getCookie);
        // guard for the interface type-hint.
        if (method_exists($request, 'getCookie')) {
            $cookie = $request->getCookie('wbs_session');
            if (is_string($cookie) && $cookie !== '') {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @param bool $hadCredential the caller presented a credential that no longer
     *                            works (expired/revoked) rather than none at all —
     *                            a human reads those two differently
     */
    private function deny(RequestInterface $request, bool $hadCredential): ResponseInterface
    {
        return (new ProblemResponder($request))->unauthenticated($hadCredential);
    }
}
