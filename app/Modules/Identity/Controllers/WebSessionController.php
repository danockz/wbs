<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Admin\Config\Services as AdminServices;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Config\Services as SharedServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Avatar;
use WBS\Shared\Support\Result;

/**
 * Browser web-session flow (SRS FR-ID): a cookie-based sign-in experience layered
 * on the existing JSON auth services — NO parallel auth logic. Steps:
 *
 *   GET  /login            render the sign-in form (issues a CSRF token)
 *   POST /login            verify credentials via AuthenticationService, then
 *                          either set the session cookie OR, if step-up is
 *                          required, stash a signed MFA ticket + redirect to /mfa
 *   GET  /mfa              TOTP challenge screen (requires a valid MFA ticket)
 *   POST /mfa              verify TOTP -> create an elevated session + cookie
 *   GET  /me               simple signed-in home (auth-filtered)
 *   POST /logout           revoke the session + clear cookies
 *
 * Cookies: `wbs_session` (HttpOnly session ref, read by AuthFilter), `wbs_csrf`
 * (HttpOnly double-submit token, checked by WebCsrfFilter), `wbs_mfa` (HttpOnly,
 * short-lived, holds the pending-second-factor principal — there is no session
 * yet between the password and TOTP steps).
 */
final class WebSessionController extends BaseController
{
    private const SESSION_COOKIE = 'wbs_session';
    private const CSRF_COOKIE    = 'wbs_csrf';
    private const MFA_COOKIE     = 'wbs_mfa';
    private const SESSION_TTL    = 86400 * 14; // 14 days

    // ---- Sign-in form -----------------------------------------------------

    public function showLogin(): ResponseInterface
    {
        $csrf   = IdentityServices::webAuth()->issueCsrf();
        $return = $this->safeReturn((string) $this->field('return', '/me'));

        $body = view('WBS\Identity\Views\login', [
            'csrf'   => $csrf,
            'return' => $return,
            'error'  => null,
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    public function login(): ResponseInterface
    {
        $in     = $this->input();
        $return = $this->safeReturn((string) ($in['return'] ?? '/me'));
        $orgId  = (string) ($in['organization_id'] ?? $this->orgId());

        $result = IdentityServices::authentication()->login(
            $orgId,
            (string) ($in['email'] ?? ''),
            (string) ($in['password'] ?? ''),
            [
                'ip'         => $this->clientIp(),
                'user_agent' => $this->userAgent(),
            ],
        );

        if (! $result->ok) {
            return $this->reloginWithError('Invalid email or password.', $return, 401);
        }

        // Step-up required: no session yet — carry the pending principal in a
        // signed, short-lived MFA cookie and send the user to the TOTP screen.
        if (! empty($result->data['mfa_required'])) {
            $ticket = IdentityServices::webAuth()->issueMfaTicket([
                'user_id'            => (string) $result->data['user_id'],
                'organization_id'    => $orgId,
                'required_assurance' => (string) $result->data['required_assurance'],
                'risk_score'         => (int) $result->data['risk_score'],
            ]);

            return redirect()->to('/mfa?return=' . rawurlencode($return))
                ->setCookie($this->cookie(self::MFA_COOKIE, $ticket, 300));
        }

        // No step-up: session already created by the service.
        return $this->establishSession((string) $result->data['session_id'], $return);
    }

    // ---- MFA step-up ------------------------------------------------------

    public function showMfa(): ResponseInterface
    {
        $ticket = IdentityServices::webAuth()->readMfaTicket($this->cookieValue(self::MFA_COOKIE));
        if ($ticket === null) {
            return redirect()->to('/login')->setCookie($this->forget(self::MFA_COOKIE));
        }

        // Optional protection for the challenge PAGE itself — enforced ONLY when a
        // group in the signing-in user's ancestry enables it in the hierarchical
        // group configuration (capability 'security.mfa_challenge_ratelimit',
        // inheritance-aware, default OFF). Off => this is a no-op.
        if ($this->mfaChallengeRateLimitEnabled($ticket['organization_id'], $ticket['user_id'])) {
            $decision = SharedServices::rateLimiter()->check('auth.mfa_challenge', [
                'route' => 'auth.mfa_challenge',
                'user'  => $ticket['user_id'],
                'ip'    => (string) $this->clientIp(),
            ]);
            if (! $decision->allowed) {
                return $this->mfaError(
                    $ticket,
                    $this->safeReturn((string) $this->field('return', '/me')),
                    'Too many attempts. Please wait a few minutes and try again.',
                    429,
                    $decision->retryAfter,
                );
            }
        }

        $csrf   = IdentityServices::webAuth()->issueCsrf();
        $return = $this->safeReturn((string) $this->field('return', '/me'));

        $body = view('WBS\Identity\Views\mfa', [
            'csrf'     => $csrf,
            'return'   => $return,
            'error'    => null,
            'required' => (string) $ticket['required_assurance'],
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    public function verifyMfa(): ResponseInterface
    {
        $ticket = IdentityServices::webAuth()->readMfaTicket($this->cookieValue(self::MFA_COOKIE));
        if ($ticket === null) {
            // Ticket missing/expired/tampered -> restart from the password step.
            return redirect()->to('/login')->setCookie($this->forget(self::MFA_COOKIE));
        }

        $in     = $this->input();
        $return = $this->safeReturn((string) ($in['return'] ?? '/me'));
        $code   = trim((string) ($in['code'] ?? ''));

        // Rate-limit per pending PRINCIPAL, not per session (there is none yet).
        // The standard ratelimit filter keys on the CI session user, which this
        // platform never populates, so at /mfa it would degrade to one global
        // bucket — an attacker could lock every user out. Key on the ticket's
        // user_id instead, so each account gets its own auth.mfa_verify budget.
        $decision = SharedServices::rateLimiter()->check('auth.mfa_verify', [
            'route' => 'auth.mfa_verify',
            'user'  => $ticket['user_id'],
            'ip'    => (string) $this->clientIp(),
        ]);
        if (! $decision->allowed) {
            return $this->mfaError(
                $ticket,
                $return,
                'Too many attempts. Please wait a few minutes and try again.',
                429,
                $decision->retryAfter,
            );
        }

        // Adaptive/dynamic MFA: accept EITHER an authenticator (TOTP) code OR a
        // recovery code — both are "high" assurance — so a user without their
        // device can still satisfy a high-risk challenge. verifyTotp reports the
        // achieved assurance; recovery codes are consumed once.
        $achieved = 'none';
        $verified = IdentityServices::mfa()->verifyTotp($ticket['user_id'], $code);
        if ($verified->ok) {
            $achieved = (string) ($verified->data['assurance'] ?? 'high');
        } else {
            $recovery = IdentityServices::mfa()->verifyRecoveryCode($ticket['user_id'], $code);
            if ($recovery->ok) {
                $achieved = (string) ($recovery->data['assurance'] ?? 'high');
            }
        }

        if ($achieved === 'none') {
            return $this->mfaError($ticket, $return, 'That code was not valid. Try again.', 422);
        }

        // Enforce the DYNAMIC step-up requirement: the assurance just achieved
        // must satisfy what the risk score demanded at login. This is the same
        // StepUpPolicy the login path uses, so the web flow can never mint a
        // session weaker than the adaptive policy requires.
        if (! IdentityServices::stepUpPolicy()->isSatisfied($achieved, $ticket['risk_score'])) {
            return $this->mfaError(
                $ticket,
                $return,
                'A stronger verification method is required for this sign-in.',
                422,
            );
        }

        // Requirement met -> create a session recording the ACTUAL achieved
        // assurance (not a hardcoded level), so downstream step-up checks and
        // the /me badge reflect reality.
        $session = IdentityServices::sessions()->create(
            $ticket['user_id'],
            $ticket['organization_id'],
            [
                'ip'         => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'mfa_level'  => $achieved,
                'risk_score' => $ticket['risk_score'],
            ],
        );

        return $this->establishSession((string) $session->data['session_id'], $return)
            ->setCookie($this->forget(self::MFA_COOKIE));
    }

    /**
     * Whether the MFA-challenge-page rate limit is switched on for THIS user,
     * per the hierarchical group configuration. Resolves the capability against
     * the member's primary/highest group with the inheritance-aware resolver, so
     * a parent group can enable it for a whole subtree. Fails SAFE (off) if the
     * user has no group or the value cannot be resolved — the feature only ever
     * takes effect when explicitly enabled somewhere in the ancestry.
     */
    private function mfaChallengeRateLimitEnabled(string $organizationId, string $userId): bool
    {
        $groupId = SharedServices::groupScope()->primaryMembershipGroup($organizationId, $userId);
        if ($groupId === null) {
            return false;
        }

        $resolved = AdminServices::effectiveConfig()->resolve($groupId, 'security.mfa_challenge_ratelimit');
        if (! $resolved->ok) {
            return false;
        }

        return $this->isTruthy($resolved->data['value'] ?? null);
    }

    /** Interpret a group-config value (bool, 1/0, "true"/"on"/"yes") as a flag. */
    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'enabled'], true);
        }
        if (is_array($value)) {
            // Allow { "enabled": true } shape too.
            return $this->isTruthy($value['enabled'] ?? null);
        }

        return false;
    }

    /** Re-render the MFA challenge with an error (preserves the pending ticket). */
    private function mfaError(array $ticket, string $return, string $msg, int $status, int $retryAfter = 0): ResponseInterface
    {
        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\mfa', [
            'csrf'     => $csrf,
            'return'   => $return,
            'error'    => $msg,
            'required' => (string) $ticket['required_assurance'],
        ]);

        $resp = $this->htmlResponse($status, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
        if ($retryAfter > 0) {
            $resp->setHeader('Retry-After', (string) $retryAfter);
        }

        return $resp;
    }

    // ---- Signed-in home ---------------------------------------------------

    public function me(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $user   = $userId !== '' ? IdentityServices::accounts()->findById($userId) : null;

        // /me is behind the auth filter; issue a CSRF token for the logout form.
        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\me', [
            'user'      => $user,
            'mfa_level' => $this->mfaLevel(),
            'csrf'      => $csrf,
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    // ---- Profile photo (self-service) -------------------------------------

    /**
     * GET /me/profile — the member SELF-SERVICE profile editor: edit display
     * name + language + time-zone (wired to the previously-orphaned
     * AccountService::updateProfile), plus the photo set/remove controls. No-JS,
     * CSP-safe, PRG. API clients (Bearer / X-WBS-Session) get JSON.
     */
    public function profile(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $user   = $userId !== '' ? IdentityServices::accounts()->findById($userId) : null;
        if ($user === null) {
            return $this->respondWith(Result::fail('NO_SESSION', 'identity.no_session', 401));
        }

        if ($this->wantsJson()) {
            return $this->respondWith(Result::ok([
                'user_id'       => $userId,
                'display_name'  => $user['display_name'] ?? null,
                'email'         => $user['email'] ?? null,
                'locale'        => $user['locale'] ?? 'en',
                'timezone'      => $user['timezone'] ?? 'UTC',
                'photo_url'     => $user['profile_photo_url'] ?? null,
                // Verified identity fields — READ-ONLY self-service (an admin /
                // re-verification flow changes them); exposed for display only.
                'phone'         => $user['phone'] ?? null,
                'date_of_birth' => $user['date_of_birth'] ?? null,
                'country_code'  => $user['country_code'] ?? null,
            ]));
        }

        $csrf   = IdentityServices::webAuth()->issueCsrf();
        $locales = (config(\Config\Locale::class)->supported ?? ['en']);
        $body   = view('WBS\\Identity\\Views\\profile', [
            'user'      => $user,
            'avatarSrc' => Avatar::resolveUrl($user, 96),
            'locales'   => $locales,
            'csrf'      => $csrf,
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    /**
     * POST /me/profile — save the member's display name / language / time zone.
     * Browsers PRG back to the profile page with a flash; API keeps raw JSON.
     */
    public function updateProfile(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === '') {
            return $this->respondWith(Result::fail('NO_SESSION', 'identity.no_session', 401));
        }
        $in     = $this->input();
        $locales = (config(\Config\Locale::class)->supported ?? ['en']);
        $locale  = (string) ($in['locale'] ?? '');

        $result = IdentityServices::accounts()->updateProfile($userId, [
            'display_name' => isset($in['display_name']) ? trim((string) $in['display_name']) : null,
            'locale'       => in_array($locale, $locales, true) ? $locale : null,
            'timezone'     => isset($in['timezone']) ? trim((string) $in['timezone']) : null,
        ]);

        return $this->profilePrg($result, 'savedFlash');
    }

    // ---- Profile photo (self-service) -------------------------------------

    /**
     * Set the signed-in member's profile photo URL (self-service, CSRF-guarded).
     * Body: { photo_url }. An already-hosted image URL or a `data:` image URI.
     */
    public function setPhoto(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === '') {
            return $this->respondWith(Result::fail('NO_SESSION', 'identity.no_session', 401));
        }
        $url = (string) $this->field('photo_url', '');

        return $this->profilePrg(IdentityServices::accounts()->setProfilePhoto($userId, $url), 'photoSavedFlash');
    }

    /** Remove the signed-in member's photo; renders fall back to the initials avatar. */
    public function removePhoto(): ResponseInterface
    {
        $userId = $this->currentUserId();
        if ($userId === '') {
            return $this->respondWith(Result::fail('NO_SESSION', 'identity.no_session', 401));
        }

        return $this->profilePrg(IdentityServices::accounts()->removeProfilePhoto($userId), 'photoRemovedFlash');
    }

    /**
     * PRG for a browser profile write: API callers (Bearer / X-WBS-Session) keep
     * the raw Result (JSON); browsers redirect back to /me/profile with a
     * localized success flash, or the failing Result's message as an error flash.
     */
    private function profilePrg(Result $result, string $okKey): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondWith($result);
        }
        if (! $result->ok) {
            return redirect()->to('/me/profile')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to('/me/profile')->with('success', lang('Identity.profile.' . $okKey));
    }

    /**
     * Serve the signed-in member's AVATAR as an image. When they have a photo we
     * redirect to it; otherwise we render the deterministic inline SVG initials
     * avatar directly (no external calls, cacheable). This gives clients one
     * stable <img src="/me/avatar"> regardless of whether a photo is set.
     */
    public function avatar(): ResponseInterface
    {
        $userId = $this->currentUserId();
        $user   = $userId !== '' ? IdentityServices::accounts()->findById($userId) : null;
        if ($user === null) {
            $user = ['display_name' => 'Guest'];
        }

        if (Avatar::hasPhoto($user)) {
            return $this->response->redirect((string) $user['profile_photo_url']);
        }

        $svg = Avatar::svg(Avatar::seedFrom($user), Avatar::initialsFrom($user), 128);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->setHeader('Cache-Control', 'private, max-age=300')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($svg);
    }

    // ---- Active-session review / revoke ------------------------------------

    /**
     * List the current user's active sessions. JSON for API clients; an HTML
     * management page otherwise. The session the request is riding on is flagged
     * `current` so the UI can label it and avoid a surprise self-logout. No
     * hashes/PII are exposed (SessionService::listForUser is metadata-only).
     */
    public function sessions(): ResponseInterface
    {
        $userId   = $this->currentUserId();
        $current  = $this->cookieValue(self::SESSION_COOKIE);
        $sessions = $userId !== '' ? IdentityServices::sessions()->listForUser($userId) : [];

        foreach ($sessions as &$s) {
            $s['current'] = isset($s['id']) && $current !== null && hash_equals((string) $s['id'], $current);
        }
        unset($s);

        if ($this->wantsJson()) {
            return $this->respondJson(Result::ok(['sessions' => $sessions]));
        }

        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\sessions', [
            'sessions' => $sessions,
            'csrf'     => $csrf,
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    /**
     * Revoke one of the current user's sessions by id. Ownership is enforced by
     * scoping to the caller's own active sessions — a user can never revoke
     * another account's session. Revoking the CURRENT session also clears the
     * cookies (an explicit sign-out of this device).
     */
    public function revokeSession(string $sessionId = ''): ResponseInterface
    {
        $userId    = $this->currentUserId();
        $sessionId = trim($sessionId);

        // Confirm the target belongs to the caller before revoking.
        $owned = false;
        foreach (IdentityServices::sessions()->listForUser($userId) as $s) {
            if (($s['id'] ?? null) === $sessionId) {
                $owned = true;
                break;
            }
        }
        if (! $owned) {
            $result = Result::notFound('identity.session_not_found', 'SESSION_NOT_FOUND');

            return $this->wantsJson()
                ? $this->respondJson($result)
                : redirect()->to('/me/sessions');
        }

        IdentityServices::sessions()->revoke($sessionId);

        $isCurrent = ($cur = $this->cookieValue(self::SESSION_COOKIE)) !== null
            && hash_equals($sessionId, $cur);

        if ($this->wantsJson()) {
            return $this->respondJson(Result::ok([
                'session_id' => $sessionId,
                'revoked'    => true,
                'current'    => $isCurrent,
            ]));
        }

        if ($isCurrent) {
            // Revoked our own session -> clear cookies and land on login.
            return redirect()->to('/login')
                ->setCookie($this->forget(self::SESSION_COOKIE))
                ->setCookie($this->forget(self::CSRF_COOKIE));
        }

        return redirect()->to('/me/sessions');
    }

    // ---- Logout -----------------------------------------------------------

    public function logout(): ResponseInterface
    {
        $sid = $this->cookieValue(self::SESSION_COOKIE);
        if ($sid !== null && $sid !== '') {
            IdentityServices::sessions()->revoke($sid);
        }

        return redirect()->to('/login')
            ->setCookie($this->forget(self::SESSION_COOKIE))
            ->setCookie($this->forget(self::CSRF_COOKIE))
            ->setCookie($this->forget(self::MFA_COOKIE));
    }

    // ---- helpers ----------------------------------------------------------

    private function establishSession(string $sessionId, string $return): ResponseInterface
    {
        $response = redirect()->to($return)
            ->setCookie($this->cookie(self::SESSION_COOKIE, $sessionId, self::SESSION_TTL))
            ->setCookie($this->forget(self::CSRF_COOKIE));

        // Sync the user's stored language into the wbs_locale cookie so the
        // (DB-free) LocaleFilter serves their preference from the very next
        // request. Login is not a hot path, so this lookup is acceptable here.
        $localeCookie = $this->localeCookieForSession($sessionId);
        if ($localeCookie !== null) {
            $response->setCookie($localeCookie);
        }

        return $response;
    }

    /**
     * Build a wbs_locale cookie array from the signed-in user's stored preference
     * (user_preferences.locale, else users.locale), clamped to the supported
     * allowlist. Returns null when there is no usable preference.
     *
     * @return array<string,mixed>|null
     */
    private function localeCookieForSession(string $sessionId): ?array
    {
        try {
            $session = IdentityServices::sessions()->active($sessionId);
            $userId  = is_array($session) ? (string) ($session['user_id'] ?? '') : '';
            if ($userId === '') {
                return null;
            }

            $stored = IdentityServices::preferences()->localeFor($userId);
            if ($stored === null) {
                $row    = \Config\Database::connect()->table('users')
                    ->select('locale')->where('id', $userId)->get()->getRowArray();
                $stored = is_array($row) ? ($row['locale'] ?? null) : null;
            }

            $cfg      = config(\Config\Locale::class);
            $resolver = new \WBS\Shared\I18n\LocaleResolver($cfg->supported, $cfg->default, $cfg->countryLocale);
            $locale   = $resolver->canonicalize(is_string($stored) ? $stored : null);
            if ($locale === null) {
                return null;
            }

            return [
                'name'     => $cfg->cookieName,
                'value'    => $locale,
                'expires'  => $cfg->cookieLifetime,
                'path'     => '/',
                'secure'   => $this->isHttps(),
                'httponly' => false,
                'samesite' => 'Lax',
            ];
        } catch (\Throwable) {
            return null; // never block login on a locale sync
        }
    }

    private function reloginWithError(string $msg, string $return, int $status): ResponseInterface
    {
        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\login', [
            'csrf'   => $csrf,
            'return' => $return,
            'error'  => $msg,
        ]);

        return $this->htmlResponse($status, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    /** Build a hardened cookie array for Response::setCookie(). */
    private function cookie(string $name, string $value, int $ttl): array
    {
        return [
            'name'     => $name,
            'value'    => $value,
            'expires'  => $ttl,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /** An expired cookie of the same name (clears it in the browser). */
    private function forget(string $name): array
    {
        return [
            'name'     => $name,
            'value'    => '',
            'expires'  => -1,
            'path'     => '/',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private function cookieValue(string $name): ?string
    {
        $v = method_exists($this->request, 'getCookie') ? $this->request->getCookie($name) : null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** True when the effective connection is HTTPS (honoring a TLS-terminating proxy). */
    private function isHttps(): bool
    {
        if (str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')) {
            return true;
        }

        return method_exists($this->request, 'isSecure') && $this->request->isSecure();
    }

    /** Only allow same-site relative return targets (prevents open redirects). */
    private function safeReturn(string $return): string
    {
        if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            return '/me';
        }

        return $return;
    }

    private function htmlResponse(int $status, string $body): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($body);
    }
}
