<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use WBS\Shared\Security\SecretBox;
use WBS\Shared\Support\Clock;

/**
 * Browser web-session helpers: stateless, signed, short-lived tokens for the
 * cookie-based sign-in flow. Two token kinds, both authenticated-encrypted with
 * {@see SecretBox} (tamper-evident, AAD-bound so one kind can't be replayed as
 * the other):
 *
 *   - CSRF token: server-rendered double-submit. The SAME token is embedded in
 *     the form AND set as an HttpOnly cookie; the CsrfFilter requires they match
 *     and that the token decrypts and is unexpired. Because it is HttpOnly and
 *     signed, an attacker can neither read it (XSS) nor forge a valid pair.
 *   - MFA ticket: proves the password step already succeeded while the second
 *     factor is pending — there is NO session yet at that point, so the pending
 *     principal has to travel in a signed cookie rather than server state.
 */
final class WebAuthService
{
    private const CSRF_TTL = 7200;   // 2h — form lifetime
    private const MFA_TTL  = 300;    // 5m — step-up window

    public function __construct(
        private readonly SecretBox $box,
        private readonly Clock $clock,
    ) {
    }

    // ---- CSRF -------------------------------------------------------------

    /** Mint a fresh CSRF token (opaque signed blob). */
    public function issueCsrf(): string
    {
        $payload = bin2hex(random_bytes(16)) . '|' . ($this->now() + self::CSRF_TTL);

        return $this->box->encrypt($payload, 'wbs_csrf');
    }

    /**
     * Validate a double-submit CSRF pair. The cookie and the form field must be
     * byte-identical AND the token must decrypt and be unexpired.
     */
    public function verifyCsrf(?string $cookie, ?string $field): bool
    {
        if ($cookie === null || $field === null || $cookie === '' || $field === '') {
            return false;
        }
        if (! hash_equals($cookie, $field)) {
            return false;
        }

        try {
            $plain = $this->box->decrypt($field, 'wbs_csrf');
        } catch (\Throwable) {
            return false;
        }
        $parts = explode('|', $plain, 2);

        return count($parts) === 2 && (int) $parts[1] >= $this->now();
    }

    // ---- MFA step-up ticket ----------------------------------------------

    /**
     * Encrypt the pending-second-factor principal.
     *
     * @param array{user_id:string,organization_id:string,required_assurance:string,risk_score:int} $claims
     */
    public function issueMfaTicket(array $claims): string
    {
        $claims['exp'] = $this->now() + self::MFA_TTL;

        return $this->box->encrypt(json_encode($claims, JSON_THROW_ON_ERROR), 'wbs_mfa');
    }

    /**
     * Decrypt + validate an MFA ticket. Returns the claims, or null if the token
     * is missing, tampered, or expired.
     *
     * @return array{user_id:string,organization_id:string,required_assurance:string,risk_score:int}|null
     */
    public function readMfaTicket(?string $ticket): ?array
    {
        if ($ticket === null || $ticket === '') {
            return null;
        }
        try {
            $claims = json_decode($this->box->decrypt($ticket, 'wbs_mfa'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($claims) || (int) ($claims['exp'] ?? 0) < $this->now()) {
            return null;
        }

        return [
            'user_id'            => (string) ($claims['user_id'] ?? ''),
            'organization_id'    => (string) ($claims['organization_id'] ?? ''),
            'required_assurance' => (string) ($claims['required_assurance'] ?? 'none'),
            'risk_score'         => (int) ($claims['risk_score'] ?? 0),
        ];
    }

    private function now(): int
    {
        return strtotime($this->clock->nowUtcString()) ?: time();
    }
}
