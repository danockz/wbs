<?php

declare(strict_types=1);

namespace WBS\Identity\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\ProblemResponder;

/**
 * Double-submit CSRF protection for BROWSER (cookie-authenticated) state-changing
 * requests. The platform's JSON API authenticates with Bearer tokens / the
 * `X-WBS-Session` header and is CSRF-exempt (a cross-site page cannot set those
 * headers); this filter guards ONLY the HTML web flows that rely on the ambient
 * `wbs_session` cookie.
 *
 * A request passes when the `wbs_csrf` cookie and the submitted `_csrf` field
 * match and the token is a valid, unexpired signed blob. Safe methods (GET/HEAD/
 * OPTIONS) and pure-API callers (Bearer / X-WBS-Session present) bypass the check.
 *
 * Registered as alias `webcsrf`; apply per-route on cookie-auth POSTs.
 */
final class WebCsrfFilter implements FilterInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function before(RequestInterface $request, $arguments = null)
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $request;
        }

        // Pure API callers carry their credential in a header, not the cookie,
        // so they are not susceptible to CSRF and are exempt.
        if (stripos($request->getHeaderLine('Authorization'), 'Bearer ') === 0
            || $request->getHeaderLine('X-WBS-Session') !== '') {
            return $request;
        }

        $cookie = method_exists($request, 'getCookie') ? $request->getCookie('wbs_csrf') : null;
        $field  = null;
        if (method_exists($request, 'getPost')) {
            $field = $request->getPost('_csrf');
        }

        if (! IdentityServices::webAuth()->verifyCsrf(
            is_string($cookie) ? $cookie : null,
            is_string($field) ? $field : null,
        )) {
            // A browser that lands here almost always submitted a stale tab, so
            // it gets a page saying that plus a way back; an API client gets the
            // problem+json it has always had.
            return (new ProblemResponder($request))->csrfFailed();
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}
