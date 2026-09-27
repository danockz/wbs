<?php

declare(strict_types=1);

namespace WBS\Groups\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Groups\Config\Services as GroupServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * PUBLIC, unauthenticated group pages (no `auth` filter):
 *   GET  /g              -> directory of active groups
 *   GET  /g/{slug}       -> a group's public landing page
 *   GET  /g/{slug}/join  -> public self-join form
 *   POST /g/{slug}/join  -> process a self-join
 *
 * Canonical HTML/JSON: the same URL returns a rendered page to a browser and the
 * public payload to a JSON client (representation negotiated in BaseController).
 * Only public-safe data is exposed (see GroupPublicService).
 *
 * Auth-awareness: none of these routes carry the `auth` filter, but every browser
 * page still adapts to the signed-in state (resolved from the wbs_session cookie)
 * so a logged-in member sees "My dashboard / Log out" instead of "Member login".
 */
final class GroupPublicController extends BaseController
{
    /** @var array{viewer: array<string,mixed>|null, csrf: string|null}|null */
    private ?array $viewerCache = null;

    /** Directory of active groups, grouped by location. */
    public function directory()
    {
        $orgId    = $this->orgId();
        $sections = GroupServices::groupPublic()->directoryByLocation($orgId);

        if ($this->wantsJson()) {
            $total = array_sum(array_map(static fn ($s) => $s['count'], $sections));

            return $this->respondWith(Result::ok(['sections' => $sections, 'count' => $total]));
        }

        return $this->htmlWithViewer('WBS\Groups\Views\public_directory', ['sections' => $sections]);
    }

    /**
     * The public global→local drill-down map: Country ▸ State ▸ City ▸ Venue ▸
     * groups. Same URL returns a rendered nested-disclosure tree to a browser
     * and the tree payload to a JSON client.
     */
    public function geoDirectory()
    {
        $orgId = $this->orgId();
        $tree  = GroupServices::groupPublic()->geoDirectory($orgId);

        if ($this->wantsJson()) {
            $groups  = array_sum(array_map(static fn ($c) => (int) ($c['count'] ?? 0), $tree));

            return $this->respondWith(Result::ok(['tree' => $tree, 'count' => $groups]));
        }

        return $this->htmlWithViewer('WBS\Groups\Views\public_geo_directory', ['tree' => $tree]);
    }

    /** One group's public landing page, by slug. */
    public function page(string $slug = '')
    {
        $orgId  = $this->orgId();
        $result = GroupServices::groupPublic()->page($orgId, trim($slug));

        // 404 for unknown/inactive groups — render the standard error page for
        // browsers, JSON problem for API clients.
        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondWith($result);
            }

            return $this->response
                ->setStatusCode(404)
                ->setBody(view('WBS\Groups\Views\public_not_found', ['slug' => $slug]));
        }

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        return $this->htmlWithViewer('WBS\Groups\Views\public_page', ['data' => $result->data]);
    }

    /**
     * Resolve the current browser viewer (if any) from the HttpOnly wbs_session
     * cookie, for auth-aware rendering of otherwise-public pages. When a viewer is
     * present, also mints a CSRF token for the logout form. Cached per request.
     *
     * @return array{viewer: array<string,mixed>|null, csrf: string|null}
     */
    private function viewerContext(): array
    {
        if ($this->viewerCache !== null) {
            return $this->viewerCache;
        }

        // Always mint a double-submit CSRF token for the page's forms. This is
        // pure crypto (no DB), so it stays resource-light, and the PUBLIC join
        // form must carry a token even for an ANONYMOUS guest (the common case) —
        // not only for a logged-in viewer's logout form.
        $csrf = IdentityServices::webAuth()->issueCsrf();

        $sid = null;
        if (method_exists($this->request, 'getCookie')) {
            $cookie = $this->request->getCookie('wbs_session');
            $sid    = is_string($cookie) && $cookie !== '' ? $cookie : null;
        }

        if ($sid === null) {
            return $this->viewerCache = ['viewer' => null, 'csrf' => $csrf];
        }

        $session = IdentityServices::sessions()->active($sid);
        if ($session === null) {
            return $this->viewerCache = ['viewer' => null, 'csrf' => $csrf];
        }

        $user = IdentityServices::accounts()->findById((string) $session['user_id']);
        if ($user === null) {
            return $this->viewerCache = ['viewer' => null, 'csrf' => $csrf];
        }

        return $this->viewerCache = [
            'viewer' => [
                'id'           => (string) ($user['id'] ?? ''),
                'display_name' => (string) ($user['display_name'] ?? $user['email'] ?? 'My account'),
            ],
            'csrf' => $csrf,
        ];
    }

    /**
     * Render an HTML view merged with the auth-aware viewer context, attaching the
     * logout CSRF cookie when a viewer is present. `$viewData` wins over any
     * viewer/csrf defaults it happens to set.
     *
     * @param array<string,mixed> $viewData
     */
    private function htmlWithViewer(string $view, array $viewData, int $status = 200): ResponseInterface
    {
        $ctx  = $this->viewerContext();
        $body = view($view, $viewData + ['viewer' => $ctx['viewer'], 'csrf' => $ctx['csrf']]);

        $response = $this->response->setStatusCode($status)->setBody($body);

        if ($ctx['csrf'] !== null && method_exists($response, 'setCookie')) {
            $response = $response->setCookie($this->csrfCookie($ctx['csrf']));
        }

        return $response;
    }

    /**
     * Double-submit CSRF cookie for the logout form (matches WebSessionController's
     * cookie shape: HttpOnly, SameSite=Lax, Secure over HTTPS).
     *
     * @return array<string,mixed>
     */
    private function csrfCookie(string $value): array
    {
        return [
            'name'     => 'wbs_csrf',
            'value'    => $value,
            'expires'  => 7200,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function isHttps(): bool
    {
        if (str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')) {
            return true;
        }

        return method_exists($this->request, 'isSecure') && $this->request->isSecure();
    }

    /** Public join form for a group (GET). */
    public function joinForm(string $slug = '')
    {
        $orgId  = $this->orgId();
        $result = GroupServices::groupPublic()->page($orgId, trim($slug));

        if (! $result->ok) {
            if ($this->wantsJson()) {
                return $this->respondWith($result);
            }

            return $this->response
                ->setStatusCode(404)
                ->setBody(view('WBS\Groups\Views\public_not_found', ['slug' => $slug]));
        }

        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        return $this->htmlWithViewer(
            'WBS\Groups\Views\public_join',
            ['data' => $result->data, 'errors' => [], 'old' => []],
        );
    }

    /** Process a public self-join (POST). */
    public function join(string $slug = '')
    {
        $orgId = $this->orgId();
        $in    = $this->input();

        $result = GroupServices::groupPublic()->selfJoin($orgId, trim($slug), [
            'name'  => (string) ($in['name'] ?? ''),
            'email' => (string) ($in['email'] ?? ''),
            'phone' => (string) ($in['phone'] ?? ''),
        ]);

        // API clients get the canonical Result.
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }

        // Validation failure -> re-render the form with the message + old input.
        if (! $result->ok) {
            $page = GroupServices::groupPublic()->page($orgId, trim($slug));
            if (! $page->ok) {
                return $this->response->setStatusCode(404)
                    ->setBody(view('WBS\Groups\Views\public_not_found', ['slug' => $slug]));
            }

            return $this->htmlWithViewer(
                'WBS\Groups\Views\public_join',
                [
                    'data'   => $page->data,
                    'errors' => [$this->errText((string) $result->message)],
                    'old'    => ['name' => $in['name'] ?? '', 'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? ''],
                ],
                $result->status,
            );
        }

        // Success -> confirmation page (pending vs active depends on join policy).
        $data    = is_array($result->data) ? $result->data : [];
        $pending = ($data['approval_state'] ?? 'approved') === 'pending';

        // A brand-new member gets a one-time link to set their password. In
        // production this is emailed; here we also surface it on the confirmation
        // page so the flow is demonstrable end to end without a mail server.
        $setupUrl = null;
        if (! empty($data['is_new_user']) && ! empty($data['invite_token'])) {
            $setupUrl = site_url('set-password') . '?token=' . rawurlencode((string) $data['invite_token']);
        }

        return $this->htmlWithViewer('WBS\Groups\Views\public_join_done', [
            'slug'      => trim($slug),
            'pending'   => $pending,
            'setup_url' => $setupUrl,
        ]);
    }
}
