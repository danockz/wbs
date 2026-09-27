<?php

declare(strict_types=1);

namespace WBS\Events\Services;

/**
 * Signed, cookie-less feed tokens for PERSONAL iCalendar subscriptions.
 *
 * A member's calendar app (Google/Apple/Outlook) fetches a subscription URL on
 * its own schedule with NO session cookie, so the URL itself must carry proof of
 * who it belongs to. This mints an opaque, stable token that identifies the user
 * without exposing a raw user id or requiring server-side storage:
 *
 *     token = base64url(userId) . '.' . base64url(HMAC_SHA256(userId, key))
 *
 * PURE + storage-free (mirrors CheckinService's HMAC pattern): trivially
 * unit-testable and safe to call on the hot path. Verification is constant-time
 * (hash_equals). The token is STABLE for a user (so a subscription keeps working)
 * and REVOCABLE by rotating the signing key. It authorizes ONLY read access to
 * that user's own event feed — no write capability, no other scope.
 */
final class FeedTokenService
{
    public function __construct(private readonly string $signingKey = 'wbs-feed-key')
    {
    }

    /** Mint the stable feed token for a user id. */
    public function issue(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            return '';
        }

        return $this->b64($userId) . '.' . $this->b64($this->mac($userId));
    }

    /**
     * Verify a token and return the user id it authorizes, or null when the
     * token is malformed or its signature does not match. Constant-time.
     */
    public function verify(string $token): ?string
    {
        $token = trim($token);
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }
        [$idPart, $sigPart] = explode('.', $token, 2);
        $userId = $this->unb64($idPart);
        $sig    = $this->unb64($sigPart);
        if ($userId === '' || $sig === '') {
            return null;
        }

        return hash_equals($this->mac($userId), $sig) ? $userId : null;
    }

    private function mac(string $userId): string
    {
        return hash_hmac('sha256', $userId, $this->signingKey, true);
    }

    /** URL-safe base64 (no padding). */
    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $enc): string
    {
        $dec = base64_decode(strtr($enc, '-_', '+/'), true);

        return $dec === false ? '' : $dec;
    }
}
