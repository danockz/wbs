<?php

declare(strict_types=1);

namespace WBS\Shared\Http;

use Closure;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Filter-layer representation negotiation for HTTP problems.
 *
 * `BaseController::respondWith()` already refuses to dump raw JSON in a browser,
 * but a request that dies in a FILTER never reaches a controller — so the four
 * request filters (auth, authorize, webcsrf, ratelimit) each used to answer every
 * client with `application/problem+json`. That is correct for an API caller and
 * wrong for a person: the classic symptom is a member whose session expired
 * mid-browsing being shown
 * `{"type":"about:blank","title":"UNAUTHENTICATED","status":401,…}` instead of a
 * page that says so and offers a way back.
 *
 * This class is the single place that decision is made below the controller
 * layer:
 *
 *  - **JSON clients** (Accept: application/json, ?format=json, XHR, or a
 *    non-navigation Fetch) get the SAME problem+json envelope, keys and status as
 *    before — the wire contract is unchanged, and `detail` stays a stable message
 *    key rather than localized prose (that is what `Result` does everywhere else);
 *  - **browsers** get `WBS\Shared\Views\error_page`: localized in all six
 *    locales, RTL-correct, self-contained/CSP-safe, no JS, with an action that
 *    makes sense for the failure (sign in again → `/login?return=<here>`, back and
 *    retry for a stale CSRF token, wait N seconds for a rate limit).
 *
 * Redirect targets are built here, not in the view, and are held to the same
 * same-site rule `WebSessionController::safeReturn()` applies, so an error page
 * can never be turned into an open redirect (via the query string or a Referer).
 */
final class ProblemResponder
{
    /** Self-contained browser page for a request that died in a filter. */
    public const VIEW = 'WBS\Shared\Views\error_page';

    /** Where a person goes to get a session back. */
    private const LOGIN = '/login';

    /** Fallback action target when there is nowhere sensible to return to. */
    private const HOME = '/me';

    /** Cap on a return target's length, so a huge query string can't be reflected. */
    private const MAX_TARGET = 2000;

    /**
     * @param RequestInterface                $request      the request being refused
     * @param (Closure(array<string,mixed>):string)|null $htmlRenderer test seam:
     *        renders the page body instead of the view service (PHP forbids a
     *        `callable` property type, so this is typed as a Closure)
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ?Closure $htmlRenderer = null,
    ) {
    }

    /**
     * 401 — no session, or one that expired.
     *
     * The two read differently to a human, so the copy differs: arriving without
     * any credential means "sign in to continue", arriving with a cookie the
     * server no longer recognises means "your session expired" (and nothing was
     * lost). Both send the browser to the sign-in page with a return target.
     */
    public function unauthenticated(?bool $hadCredential = null, string $detail = 'identity.unauthenticated'): ResponseInterface
    {
        $key = ($hadCredential ?? $this->presentedCredential()) ? 'sessionExpired' : 'signInRequired';

        return $this->respond(401, 'UNAUTHENTICATED', $detail, [
            'heading'     => (string) lang('App.err.' . $key),
            'explanation' => (string) lang('App.err.' . $key . 'Body'),
            'actionLabel' => (string) lang('App.err.signInAction'),
            'actionHref'  => self::LOGIN . '?return=' . rawurlencode($this->returnTarget()),
            'accent'      => '#f7b84b',
        ]);
    }

    /**
     * 403 — authenticated, but the PDP said no. The reason code is shown as a
     * muted reference so a leader can quote it when asking for the grant; it is a
     * stable machine token, not account or policy detail.
     */
    public function forbidden(string $reason = 'access.denied'): ResponseInterface
    {
        return $this->respond(403, 'ACCESS_DENIED', $reason, [
            'heading'     => (string) lang('App.err.denied'),
            'explanation' => (string) lang('App.err.deniedBody'),
            'actionLabel' => (string) lang('App.err.dashboardAction'),
            'actionHref'  => self::HOME,
            'reference'   => $reason,
            'accent'      => '#f06548',
        ]);
    }

    /**
     * 403 — the double-submit CSRF check failed, which for a browser almost always
     * means a stale tab. The way out is back to the page the form came from, so
     * the action is the same-site Referer when there is one.
     */
    public function csrfFailed(string $detail = 'identity.csrf_failed'): ResponseInterface
    {
        $back = $this->safeTarget($this->refererPath());

        return $this->respond(403, 'CSRF_FAILED', $detail, [
            'heading'        => (string) lang('App.err.csrf'),
            'explanation'    => (string) lang('App.err.csrfBody'),
            'actionLabel'    => $back !== null ? (string) lang('App.err.retryAction') : null,
            'actionHref'     => $back,
            'secondaryLabel' => (string) lang('App.err.dashboardAction'),
            'secondaryHref'  => self::HOME,
            'accent'         => '#f7b84b',
        ]);
    }

    /**
     * 429 — rate limited. `Retry-After` is set for BOTH representations (it is a
     * header an API client needs and a number a human can read), and the page says
     * how long to wait in the member's own language.
     */
    public function tooManyRequests(int $retryAfter, string $detail = 'rate.limited'): ResponseInterface
    {
        $wait = max(1, $retryAfter);

        return $this->respond(429, 'TOO_MANY_REQUESTS', $detail, [
            'heading'     => (string) lang('App.err.rateLimited'),
            'explanation' => (string) lang('App.err.rateLimitedBody', [(string) $wait]),
            'actionLabel' => (string) lang('App.err.retryAction'),
            'actionHref'  => $this->safeTarget($this->refererPath()) ?? self::HOME,
            'reference'   => 'Retry-After: ' . $wait,
            'retryAfter'  => $wait,
            'accent'      => '#299cdb',
        ], ['Retry-After' => (string) $retryAfter]);
    }

    /**
     * Emit one problem in the representation this caller asked for.
     *
     * @param array<string,mixed> $page    view data for the browser representation
     * @param array<string,string> $headers set on BOTH representations
     */
    public function respond(int $status, string $title, string $detail, array $page = [], array $headers = []): ResponseInterface
    {
        $response = service('response')->setStatusCode($status);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, (string) $value);
        }

        if ((new RequestNegotiator($this->request))->wantsJson()) {
            return $response->setJSON([
                'type'   => 'about:blank',
                'title'  => $title,
                'status' => $status,
                'detail' => $detail,
            ]);
        }

        $data = $page + [
            'status'         => $status,
            'title'          => $title,
            'detail'         => $detail,
            'heading'        => (string) lang('App.err.generic'),
            'explanation'    => '',
            'actionLabel'    => null,
            'actionHref'     => null,
            'secondaryLabel' => null,
            'secondaryHref'  => null,
            'reference'      => null,
            'retryAfter'     => null,
            'accent'         => '#f06548',
        ];

        $html = $this->htmlRenderer !== null
            ? (string) ($this->htmlRenderer)($data)
            : view(self::VIEW, $data);

        return $response
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            // One URL, two representations: caches must key on the negotiation.
            ->setHeader('Vary', 'Accept, X-Requested-With, Authorization')
            ->setBody($html);
    }

    // -------------------------------------------------------------------------
    // Return targets — built here so the view never composes a URL
    // -------------------------------------------------------------------------

    /**
     * True when the caller presented a credential that simply no longer works (an
     * expired session cookie, a revoked token) rather than presenting none at all.
     * Used when a filter cannot say which case it is — the two read very
     * differently to a person, and only one of them is a "session timeout".
     */
    private function presentedCredential(): bool
    {
        if (stripos((string) $this->request->getHeaderLine('Authorization'), 'Bearer ') === 0) {
            return true;
        }
        if ((string) $this->request->getHeaderLine('X-WBS-Session') !== '') {
            return true;
        }

        return method_exists($this->request, 'getCookie')
            && (string) $this->request->getCookie('wbs_session') !== '';
    }

    /** Where the browser should be sent after it fixes the problem. */
    private function returnTarget(): string
    {
        return $this->safeTarget($this->currentPath()) ?? self::HOME;
    }

    /**
     * This request's path + query, or null when it should not be reflected.
     *
     * The raw request target is preferred because it is the only form that still
     * shows a leading `//` — `URI::getPath()` has already normalized it away, and
     * a `//host/x` reflected into a `return=` URL is an open redirect (browsers
     * resolve `//host` against the current scheme).
     */
    private function currentPath(): ?string
    {
        $raw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($raw !== '') {
            if ($raw[0] !== '/' || str_starts_with($raw, '//') || strlen($raw) > self::MAX_TARGET) {
                return null;
            }

            return $raw;
        }

        // No raw target (CLI, or an SAPI that does not set one): rebuild from the
        // URI object. A first segment carrying a dot or a colon is what a stripped
        // `//host/x` or `scheme://host/x` looks like, so refuse it rather than
        // reflect it — the cost is only that the member lands on their dashboard.
        if (! method_exists($this->request, 'getUri')) {
            return null;
        }
        $uri = $this->request->getUri();
        if ($uri === null) {
            return null;
        }

        $path  = '/' . ltrim(method_exists($uri, 'getPath') ? (string) $uri->getPath() : '', '/');
        $query = method_exists($uri, 'getQuery') ? (string) $uri->getQuery() : '';
        $first = explode('/', ltrim($path, '/'), 2)[0];
        if ($first === '' || str_contains($first, '.') || str_contains($first, ':')) {
            return null;
        }

        return $query === '' ? $path : $path . '?' . $query;
    }

    /**
     * The same-site part of the Referer, or null. A cross-site Referer is ignored
     * rather than followed: the error page must not become a way to bounce a
     * member to another origin.
     */
    private function refererPath(): ?string
    {
        $referer = (string) $this->request->getHeaderLine('Referer');
        if ($referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        if (! is_array($parts) || ($parts['path'] ?? '') === '') {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host !== '') {
            $own = $this->host();
            if ($own === '' || strcasecmp($host, $own) !== 0) {
                return null;
            }
        }

        $path  = '/' . ltrim((string) $parts['path'], '/');
        $query = (string) ($parts['query'] ?? '');

        return $query === '' ? $path : $path . '?' . $query;
    }

    private function host(): string
    {
        $header = (string) $this->request->getHeaderLine('Host');
        if ($header !== '') {
            // Strip an optional :port.
            return strtolower(preg_replace('/:\d+$/', '', $header) ?? $header);
        }
        if (method_exists($this->request, 'getUri')) {
            $uri = $this->request->getUri();
            if ($uri !== null && method_exists($uri, 'getHost')) {
                return strtolower((string) $uri->getHost());
            }
        }

        return '';
    }

    /**
     * Same-site relative target guard — the rule `WebSessionController::safeReturn()`
     * applies to a login `return`, applied to every target an error page emits.
     * Rejects absolute URLs, protocol-relative `//host`, the backslash variant
     * `/\host` (which browsers normalize to `//host`), control characters, and
     * anything overlong; never returns the sign-in page itself, which would loop.
     */
    private function safeTarget(?string $target): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }
        if (strlen($target) > self::MAX_TARGET) {
            return null;
        }
        if ($target[0] !== '/' || str_starts_with($target, '//') || str_contains($target, '\\')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }
        $path = explode('?', $target, 2)[0];
        if ($path === self::LOGIN || str_starts_with($path, self::LOGIN . '/')) {
            return null;
        }

        return $target;
    }
}
