<?php

declare(strict_types=1);

namespace WBS\Shared\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use WBS\Identity\Config\Services as IdentityServices;

/**
 * Universal double-submit CSRF TOKEN ISSUER for server-rendered browser pages.
 *
 * WHY: several state-changing web forms live in the SHARED layout / injected
 * chrome rather than in one bespoke controller — most notably the language
 * switcher baked into app/Views/layouts/app.php, which posts to the
 * `webcsrf`-guarded POST /prefs/locale on EVERY page (authenticated and
 * anonymous). Those pages are rendered by read controllers (respondHtml /
 * respondAdmin) that never mint a token, so the switcher had no `wbs_csrf` cookie
 * to satisfy the double-submit check and every language switch 403'd. Hand-issuing
 * a token in each read controller is fragile; a single `after` filter covers them
 * all, including future pages.
 *
 * WHAT (mirrors WebCsrfFilter's contract):
 *   before(): on a safe (GET/HEAD) browser request, mint ONE opaque token and
 *             expose it as $request->wbsCsrf so the layout can render it into the
 *             hidden `_csrf` field. Minting is pure crypto (SecretBox encrypt) —
 *             NO DB access — so the hot path stays resource-light per the platform
 *             rule that mirrors the menu/locale filters.
 *   after():  set the `wbs_csrf` HttpOnly cookie to that same token on 2xx
 *             text/html responses — UNLESS a controller already issued its own
 *             `wbs_csrf` cookie (self-contained pages like Identity/me set their
 *             own token+cookie and render their own fields; they must win so their
 *             field and cookie stay byte-identical).
 *
 * SECURITY: this only ISSUES the double-submit pair; verification still happens
 * exclusively in WebCsrfFilter on the guarded POST. Non-HTML / token-API
 * responses are skipped, so pure API clients are unaffected.
 */
final class WebCsrfIssueFilter implements FilterInterface
{
    private const COOKIE      = 'wbs_csrf';
    private const SAFE_METHODS = ['GET', 'HEAD'];

    public function before(RequestInterface $request, $arguments = null)
    {
        // Only safe navigations need a fresh token to seed their forms. Unsafe
        // methods are verified (not issued) by WebCsrfFilter.
        if (! in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true)) {
            return $request;
        }

        // Pure API callers authenticate with a header, never the cookie form flow.
        if (stripos($request->getHeaderLine('Authorization'), 'Bearer ') === 0
            || $request->getHeaderLine('X-WBS-Session') !== '') {
            return $request;
        }

        // One mint per request; reuse if something already set it.
        if (! isset($request->wbsCsrf) || ! is_string($request->wbsCsrf) || $request->wbsCsrf === '') {
            $request->wbsCsrf = IdentityServices::webAuth()->issueCsrf();
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $token = $request->wbsCsrf ?? null;
        if (! is_string($token) || $token === '') {
            return $response;
        }

        // Only successful HTML documents carry forms that need the token.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return $response;
        }
        $ctype = strtolower($response->getHeaderLine('Content-Type'));
        if ($ctype !== '' && ! str_contains($ctype, 'text/html')) {
            return $response;
        }

        // A controller that minted its OWN token already set this cookie and
        // rendered a matching field — don't overwrite it or the pair breaks.
        if (method_exists($response, 'hasCookie') && $response->hasCookie(self::COOKIE)) {
            return $response;
        }

        $response->setCookie([
            'name'     => self::COOKIE,
            'value'    => $token,
            'expires'  => 7200,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $this->isHttpsRequest($request),
        ]);

        return $response;
    }

    /** True when the effective connection is HTTPS (honoring a TLS-terminating proxy). */
    private function isHttpsRequest(RequestInterface $request): bool
    {
        if (str_contains(strtolower($request->getHeaderLine('X-Forwarded-Proto')), 'https')) {
            return true;
        }

        return method_exists($request, 'isSecure') && $request->isSecure();
    }
}
