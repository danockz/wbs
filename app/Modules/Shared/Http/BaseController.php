<?php

declare(strict_types=1);

namespace WBS\Shared\Http;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use WBS\AccessControl\Config\Services as AccessControlServices;
use WBS\AccessControl\Policy\AccessRequest;
use WBS\Shared\Support\Messages;
use WBS\Shared\Support\Result;

/**
 * Extended BaseController — the single place representation is negotiated
 * (SRS FR-ARC-001/002). Concrete controllers return a Result and choose an
 * optional HTML view; this base renders JSON or HTML from the same call.
 *
 * Controllers never decide permissions inline beyond invoking the PDP/services;
 * they translate service Results into HTTP responses uniformly.
 */
abstract class BaseController extends Controller
{
    /**
     * @var RequestInterface
     *
     * NOTE: no property TYPE here — the parent CodeIgniter\Controller::$request is
     * untyped, and PHP 8 forbids a subclass from adding a type to an inherited
     * property. Type is documented for IDE/static analysis only.
     */
    protected $request;
    protected RequestNegotiator $negotiator;

    /**
     * Generic HTML template used when a browser hits an endpoint that supplied
     * no bespoke view — the universal "raw JSON in the browser" safety net.
     * Resolves to app/Modules/Shared/Views/data_page.php.
     */
    protected const FALLBACK_HTML_VIEW = 'WBS\Shared\Views\data_page';

    /**
     * Reusable admin-console template for back-office read endpoints whose record
     * shapes are configuration-defined. Resolves to app/Modules/Shared/Views/admin_console.php.
     */
    protected const ADMIN_CONSOLE_VIEW = 'WBS\Shared\Views\admin_console';

    /**
     * Shared locale-aware page presenter for endpoints given a declarative spec
     * in WBS\Shared\Navigation\PageSpecs. Resolves to
     * app/Modules/Shared/Views/presenter/page.php.
     */
    protected const PRESENTER_VIEW = 'WBS\Shared\Views\presenter\page';

    /** @var list<string> */
    protected $helpers = ['url', 'form', 'time', 'redactor'];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->negotiator = new RequestNegotiator($this->request);
    }

    // -------------------------------------------------------------------------
    // Request-context helpers (single source of truth for org/user resolution)
    // -------------------------------------------------------------------------
    //
    // AuthFilter attaches the authenticated principal to the request as
    // `wbsOrgId` / `wbsUserId` (plus mfa level, scopes). These helpers resolve
    // that context uniformly so controllers stop copy-pasting the
    // `wbsOrgId ?? body ?? getenv` fallback chain. The authenticated request
    // attribute ALWAYS takes precedence over a body/query param, so a caller
    // cannot spoof another organization by posting `organization_id`.

    /**
     * Resolve the acting organization id: authenticated org > explicit
     * organization_id input > single-tenant env default.
     */
    protected function orgId(): string
    {
        return (string) (
            $this->request->wbsOrgId
            ?? $this->field('organization_id')
            ?? getenv('wbs.organizationId')
            ?: ''
        );
    }

    /**
     * Resolve the acting user id: authenticated user > optional body-field
     * fallback (for internal/CLI calls that pass it explicitly) > default.
     *
     * @param string|null $fallbackKey input key to fall back to (e.g. 'author_id')
     */
    protected function currentUserId(?string $fallbackKey = null, string $default = ''): string
    {
        $id = $this->request->wbsUserId
            ?? ($fallbackKey !== null ? $this->field($fallbackKey) : null);

        return (string) ($id ?? $default);
    }

    /** Nullable acting user id (null when unauthenticated), for optional-actor call sites. */
    protected function actorId(?string $fallbackKey = null): ?string
    {
        $id = $this->request->wbsUserId
            ?? ($fallbackKey !== null ? $this->field($fallbackKey) : null);

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** MFA assurance level attached by AuthFilter, if any. */
    protected function mfaLevel(): ?string
    {
        return isset($this->request->wbsMfaLevel) ? (string) $this->request->wbsMfaLevel : null;
    }

    /**
     * Authoritative per-group authorization check for group-scoped write/read
     * surfaces (StreamService pattern). The route filter is only a coarse
     * capability gate (`authorize:<perm>,any`); this confirms the authenticated
     * subject's grant actually COVERS the resource's target group, honouring
     * scope_group_id + include_descendants via the hierarchy-aware PDP.
     *
     * $targetGroupId null means an org-wide resource — only an org-wide grant
     * passes. Returns null when permitted, or a denied Result to return as-is.
     */
    protected function authorizeGroupScope(string $action, ?string $targetGroupId): ?Result
    {
        $subjectId = $this->actorId();
        $orgId     = $this->orgId();
        if ($subjectId === null || $orgId === '') {
            return Result::denied('access.unauthenticated', 'UNAUTHENTICATED');
        }

        $permitted = AccessControlServices::authorization()->isAllowed(new AccessRequest(
            organizationId: $orgId,
            subjectId: $subjectId,
            action: $action,
            attributes: [
                'mfa_level' => $this->mfaLevel() ?? 'none',
                'ip'        => $this->clientIp(),
                'group_id'  => $targetGroupId,
            ],
        ));

        return $permitted ? null : Result::denied('access.out_of_scope', 'ACCESS_OUT_OF_SCOPE');
    }

    /**
     * Boolean form of authorizeGroupScope for filtering collections (e.g. an
     * approval queue) where each row's target group differs. True when the
     * authenticated subject's grant covers $targetGroupId (null = org-wide).
     */
    protected function canManageGroupScope(string $action, ?string $targetGroupId): bool
    {
        $subjectId = $this->actorId();
        $orgId     = $this->orgId();
        if ($subjectId === null || $orgId === '') {
            return false;
        }

        return AccessControlServices::authorization()->isAllowed(new AccessRequest(
            organizationId: $orgId,
            subjectId: $subjectId,
            action: $action,
            attributes: [
                'mfa_level' => $this->mfaLevel() ?? 'none',
                'ip'        => $this->clientIp(),
                'group_id'  => $targetGroupId,
            ],
        ));
    }

    protected function clientIp(): ?string
    {
        return $this->request->getIPAddress() ?: null;
    }

    protected function userAgent(): ?string
    {
        $ua = $this->request->getUserAgent();

        return $ua !== null ? ($ua->getAgentString() ?: null) : null;
    }

    protected function wantsJson(): bool
    {
        return $this->negotiator->wantsJson();
    }

    /**
     * Unified request-input reader. Merges a JSON body (when present) with
     * form/query params so controllers read input the same way for web and API.
     * Uses json_decode on the raw body — $this->request->getJSON() is known to
     * throw a 500 on some edge bodies.
     *
     * @return array<string,mixed>
     */
    protected function input(): array
    {
        $data = [];
        $body = (string) $this->request->getBody();
        if ($body !== '' && str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        // Form + query fall back / augment.
        $post = $this->request->getPost() ?? [];
        $get  = $this->request->getGet() ?? [];

        return array_merge($get, $post, $data);
    }

    /** Convenience: a single input field with a default. */
    protected function field(string $key, mixed $default = null): mixed
    {
        return $this->input()[$key] ?? $default;
    }

    /**
     * Render a Result as the negotiated representation.
     *
     * @param array<string,mixed> $viewData
     */
    protected function respondWith(
        Result $result,
        ?string $htmlView = null,
        ?string $redirectTo = null,
        array $viewData = [],
    ): ResponseInterface {
        // API clients (Accept: application/json, ?format=json, XHR) always get JSON.
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        // A browser with a bespoke view gets that view.
        if ($htmlView !== null) {
            return $this->respondHtml($result, $htmlView, $redirectTo, $viewData);
        }

        // A browser hitting an endpoint that supplied NO view would otherwise be
        // shown raw JSON. Render the service Result through the generic data-page
        // template instead (SRS FR-ARC-002 safety net). Failure redirects still
        // win when a redirect target was given — with a HUMAN message, never the
        // raw machine key the service returned.
        if (! $result->ok && $redirectTo !== null) {
            return redirect()->to($redirectTo)->with('error', $this->errText($result->message));
        }

        return $this->respondHtml(
            $result,
            self::FALLBACK_HTML_VIEW,
            null,
            $viewData + [
                'result' => $result->toArray(),
                'ok'     => $result->ok,
                'title'  => $viewData['title'] ?? $this->fallbackTitle(),
            ],
        );
    }

    /**
     * Default HTML title for the generic fallback page, derived from the current
     * controller/method so the page isn't anonymous. Best-effort and session-free.
     */
    protected function fallbackTitle(): string
    {
        try {
            $router = service('router');
            $method = $router !== null && method_exists($router, 'methodName')
                ? (string) $router->methodName()
                : '';
            if ($method !== '') {
                $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $method) ?? $method;

                return ucfirst(trim($words));
            }
        } catch (\Throwable) {
            // Router unavailable (e.g. CLI/tests) — fall through to the default.
        }

        return 'Result';
    }

    /**
     * Render an admin read Result as the negotiated representation: JSON for API
     * clients, otherwise the branded admin-console page (list -> table, record ->
     * key/value, problem -> message). One call covers the many back-office read
     * endpoints whose payload shapes are configuration-defined.
     */
    protected function respondAdmin(Result $result, string $title, string $subtitle = ''): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        return $this->respondHtml($result, self::ADMIN_CONSOLE_VIEW, null, [
            'result'   => $result->toArray(),
            'ok'       => $result->ok,
            'title'    => $title,
            'subtitle' => $subtitle,
        ]);
    }

    /**
     * The shared locale-aware page presenter. API clients still get JSON; a
     * browser gets a bespoke, fully-translated HTML page driven by the
     * declarative spec registered under $pageId in
     * WBS\Shared\Navigation\PageSpecs (title/columns/forms live under
     * lang('Pages.views.<id>.*')). $extract maps the service Result payload onto
     * the presenter keys (rows/record/count/facts/forms/back) at request time;
     * static presentation comes from the spec. On a failed Result the page still
     * renders (empty state + the problem is available to JSON clients), keeping
     * behaviour identical to the previous data_page fallback for browsers.
     *
     * @param callable(array<string,mixed>):array<string,mixed>|null $extract
     */
    protected function respondPage(
        Result $result,
        string $pageId,
        ?callable $extract = null,
    ): ResponseInterface {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $spec = \WBS\Shared\Navigation\PageSpecs::get($pageId);
        if ($spec === null) {
            // Unknown id — fall back to the generic data page rather than error.
            return $this->respondWith($result);
        }

        $payload = $result->toArray();
        $data    = $payload['data'] ?? [];
        $data    = is_array($data) ? $data : ['value' => $data];

        $dynamic = $extract !== null ? $extract($data) : [];

        $page = array_merge(
            ['id' => $pageId],
            $spec,
            $dynamic,
        );

        return $this->respondHtml(
            $result,
            self::PRESENTER_VIEW,
            null,
            [
                'page' => $page,
                'csrf' => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    protected function respondJson(Result $result): ResponseInterface
    {
        return $this->cacheSafe()
            ->setStatusCode($result->status)
            ->setJSON($result->toArray());
    }

    /**
     * Cache-safety headers for a single canonical URL that serves BOTH JSON and
     * HTML. `Vary` tells any shared cache (browser, CDN, reverse proxy) that the
     * body depends on how representation was negotiated — without it a cache
     * keyed on URL alone could hand a cached JSON payload to an HTML client (or
     * vice-versa). `nosniff` stops a browser reinterpreting one representation
     * as the other. See RequestNegotiator for the negotiation inputs.
     */
    protected function cacheSafe(): ResponseInterface
    {
        return $this->response
            ->setHeader('Vary', 'Accept, X-Requested-With, Authorization')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param array<string,mixed> $viewData
     */
    protected function respondHtml(
        Result $result,
        string $htmlView,
        ?string $redirectTo = null,
        array $viewData = [],
    ): ResponseInterface {
        if (! $result->ok && $redirectTo !== null) {
            return redirect()->to($redirectTo)->with('error', $this->errText($result->message));
        }

        $html = view($htmlView, $viewData);

        return $this->cacheSafe()->setStatusCode($result->status)->setBody($html);
    }

    /**
     * Human-facing text for a service Result message, for use in browser
     * flashes: translates existing catalog keys and humanizes raw machine
     * keys/codes so a view NEVER renders `contact.name_required`-style keys.
     * API/JSON paths must keep sending `$result->message` unchanged.
     */
    protected function errText(?string $message): string
    {
        return Messages::humanize($message);
    }

    /**
     * The CSRF cookie name the WebCsrfFilter double-submit check reads. Kept here
     * so every browser FORM page (the GET side of a webcsrf-guarded POST) issues
     * the token the exact same way WebSessionController does.
     */
    protected const WEB_CSRF_COOKIE = 'wbs_csrf';

    /**
     * Render a SELF-CONTAINED browser FORM page (the GET side of a
     * webcsrf-guarded POST). Mints a fresh double-submit CSRF token, sets the
     * `wbs_csrf` HttpOnly cookie on the response, and passes the same token to the
     * view as `$csrf` so the form's hidden `_csrf` field matches. API clients
     * (Accept: application/json etc.) get a small JSON descriptor instead of HTML.
     *
     * @param array<string,mixed> $viewData
     */
    protected function renderForm(string $htmlView, array $viewData = []): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson(Result::ok([
                'form' => $htmlView,
            ] + $viewData));
        }

        $csrf = \WBS\Identity\Config\Services::webAuth()->issueCsrf();
        $html = view($htmlView, $viewData + ['csrf' => $csrf]);

        return $this->cacheSafe()
            ->setBody($html)
            ->setCookie([
                'name'     => self::WEB_CSRF_COOKIE,
                'value'    => $csrf,
                'expires'  => 7200,
                'path'     => '/',
                'secure'   => $this->isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
    }

    /** True when the effective connection is HTTPS (honoring a TLS-terminating proxy). */
    private function isHttpsRequest(): bool
    {
        if (str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')) {
            return true;
        }

        return method_exists($this->request, 'isSecure') && $this->request->isSecure();
    }
}
