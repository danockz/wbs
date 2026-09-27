<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;

/**
 * Set-password / accept-invite web flow (SRS FR-ID account activation).
 *
 *   GET  /set-password?token=…   validate the token, render the form
 *   POST /set-password           consume the token, set the password, activate
 *
 * Consumes a single-use CredentialSetupService token, so a passwordless member
 * created via the public group self-join (or an admin invite) can set a first
 * password and sign in. CSRF-guarded like the rest of the browser flows; the
 * token itself is the bearer secret, carried in a hidden field.
 */
final class SetPasswordController extends BaseController
{
    private const CSRF_COOKIE = 'wbs_csrf';

    public function show(): ResponseInterface
    {
        $token  = (string) $this->field('token', '');
        $result = IdentityServices::credentialSetup()->inspect($token);

        if (! $result->ok) {
            return $this->htmlResponse(400, view('WBS\Identity\Views\set_password_invalid'));
        }

        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\set_password', [
            'csrf'    => $csrf,
            'token'   => $token,
            'purpose' => (string) ($result->data['purpose'] ?? 'invite'),
            'email'   => (string) ($result->data['email'] ?? ''),
            'name'    => (string) ($result->data['display_name'] ?? ''),
            'error'   => null,
        ]);

        return $this->htmlResponse(200, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    public function submit(): ResponseInterface
    {
        $in       = $this->input();
        $token    = (string) ($in['token'] ?? '');
        $password = (string) ($in['password'] ?? '');
        $confirm  = (string) ($in['password_confirm'] ?? '');

        // Validate the token first so an invalid/expired link never shows the form.
        $inspect = IdentityServices::credentialSetup()->inspect($token);
        if (! $inspect->ok) {
            if ($this->wantsJson()) {
                return $this->respondJson($inspect);
            }

            return $this->htmlResponse(400, view('WBS\Identity\Views\set_password_invalid'));
        }

        if ($password !== $confirm) {
            return $this->reshow($token, $inspect, 'Passwords do not match.', 422);
        }

        $result = IdentityServices::credentialSetup()->consume($token, $password);

        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }
        if (! $result->ok) {
            // Surface the policy/consumption message (e.g. weak password).
            return $this->reshow($token, $inspect, $this->humanError((string) $result->message), $result->status);
        }

        return $this->htmlResponse(200, view('WBS\Identity\Views\set_password_done'))
            ->setCookie($this->forget(self::CSRF_COOKIE));
    }

    // ---- helpers ----------------------------------------------------------

    private function reshow(string $token, $inspect, string $error, int $status): ResponseInterface
    {
        $csrf = IdentityServices::webAuth()->issueCsrf();
        $body = view('WBS\Identity\Views\set_password', [
            'csrf'    => $csrf,
            'token'   => $token,
            'purpose' => (string) ($inspect->data['purpose'] ?? 'invite'),
            'email'   => (string) ($inspect->data['email'] ?? ''),
            'name'    => (string) ($inspect->data['display_name'] ?? ''),
            'error'   => $error,
        ]);

        return $this->htmlResponse($status, $body)
            ->setCookie($this->cookie(self::CSRF_COOKIE, $csrf, 7200));
    }

    /** Map the known policy message keys to friendly copy. */
    private function humanError(string $key): string
    {
        return match ($key) {
            'identity.password_too_short'  => 'Your password must be at least 12 characters.',
            'identity.password_complexity' => 'Include an uppercase letter, a lowercase letter, and a number.',
            'identity.password_breached'   => 'That password has appeared in a known data breach. Please choose a different one.',
            default                        => 'Could not set your password. Please try again.',
        };
    }

    private function cookie(string $name, string $value, int $ttl): array
    {
        return [
            'name' => $name, 'value' => $value, 'expires' => $ttl, 'path' => '/',
            'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ];
    }

    private function forget(string $name): array
    {
        return [
            'name' => $name, 'value' => '', 'expires' => -1, 'path' => '/',
            'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax',
        ];
    }

    private function isHttps(): bool
    {
        if (str_contains(strtolower($this->request->getHeaderLine('X-Forwarded-Proto')), 'https')) {
            return true;
        }

        return method_exists($this->request, 'isSecure') && $this->request->isSecure();
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
