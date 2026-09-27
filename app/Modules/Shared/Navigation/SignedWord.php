<?php

declare(strict_types=1);

namespace WBS\Shared\Navigation;

use WBS\Shared\Security\KeyProvider;

/**
 * Tamper-evident, client-held capability word (the zero-server-burden option).
 *
 * In the "authority bundle + client-held word" model the browser/edge holds the
 * 8-byte capability word and renders/scope-switches locally, so the origin does no
 * per-navigation work. But a client-held value is untrusted input, so the word is
 * carried as a SIGNED claim: the server mints it once, the client echoes it back,
 * and any mutation is detected on the (cheap) revalidation.
 *
 * Format (compact, URL/cookie/header-safe):
 *
 *     v1.<keyId>.<payload_b64url>.<mac_b64url>
 *
 *   payload = "<word>|<sub>|<org>|<scope>|<grantVer>|<roleTblVer>|<catalogVer>|<exp>"
 *   mac     = HMAC-SHA256(payload, keyProvider.keyFor(keyId)), truncated to 128 bits
 *
 * SECURITY MODEL: this only protects DISPLAY integrity. Every route still enforces
 * its own authorize: filter, so even a perfectly forged word can at most reveal a
 * dead link, which the PDP then denies. The signature exists so a client cannot
 * silently grant itself menu entries, and so a stale word is rejected once any
 * version stamp (grant/role-table/catalog) moves or the token expires.
 *
 * Keying reuses the platform KeyProvider seam (env today, KMS/Vault switchable),
 * so rotation is a keyId change with no format break: old tokens verify against
 * their embedded keyId until they expire.
 */
final class SignedWord
{
    private const VERSION = 'v1';
    private const MAC_BYTES = 16; // 128-bit truncated HMAC-SHA256

    public function __construct(private readonly KeyProvider $keys)
    {
    }

    /**
     * Mint a signed token binding the word to its subject/scope and every version
     * stamp, with an absolute expiry (seconds from now).
     */
    public function mint(
        int $word,
        string $subjectId,
        string $orgId,
        ?string $scopeGroupId,
        int $grantVersion,
        string $roleTableVersion,
        string $catalogVersion,
        int $ttlSeconds = 3600,
        ?int $now = null,
    ): string {
        $now = $now ?? time();
        $exp = $now + max(1, $ttlSeconds);

        $keyId   = $this->keys->activeKeyId();
        $payload = self::payload(
            $word,
            $subjectId,
            $orgId,
            $scopeGroupId,
            $grantVersion,
            $roleTableVersion,
            $catalogVersion,
            $exp,
        );

        $mac = $this->mac($payload, $keyId);

        return self::VERSION . '.' . $keyId . '.'
            . self::b64url($payload) . '.' . self::b64url($mac);
    }

    /**
     * Verify a token and, if valid+fresh+matching the expected context, return the
     * word. Returns null on ANY failure (bad format, wrong key, bad MAC, expired,
     * or context mismatch) -- the caller then falls back to server-side derivation
     * (fail-closed: an untrusted word is simply not trusted, never partially).
     *
     * @return int|null the verified capability word, or null
     */
    public function verify(
        string $token,
        string $expectedSubjectId,
        string $expectedOrgId,
        ?string $expectedScopeGroupId,
        int $expectedGrantVersion,
        string $expectedRoleTableVersion,
        string $expectedCatalogVersion,
        ?int $now = null,
    ): ?int {
        $now   = $now ?? time();
        $parts = explode('.', $token);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return null;
        }
        [, $keyId, $payloadB64, $macB64] = $parts;

        $payload = self::b64urlDecode($payloadB64);
        $mac     = self::b64urlDecode($macB64);
        if ($payload === null || $mac === null) {
            return null;
        }

        // Constant-time MAC check against the embedded keyId (rotation-safe).
        $expectedMac = $this->macSafe($payload, $keyId);
        if ($expectedMac === null || ! hash_equals($expectedMac, $mac)) {
            return null;
        }

        $fields = explode('|', $payload);
        if (count($fields) !== 8) {
            return null;
        }
        [$word, $sub, $org, $scope, $gv, $rtv, $cv, $exp] = $fields;

        $scope = $scope === '' ? null : $scope;

        // Freshness + full context binding. Any drift => reject => server recompute.
        if ((int) $exp <= $now) {
            return null;
        }
        if (! hash_equals($expectedSubjectId, $sub)) {
            return null;
        }
        if (! hash_equals($expectedOrgId, $org)) {
            return null;
        }
        if (($expectedScopeGroupId ?? '') !== ($scope ?? '')) {
            return null;
        }
        if ((int) $gv !== $expectedGrantVersion) {
            return null;
        }
        if (! hash_equals($expectedRoleTableVersion, $rtv)) {
            return null;
        }
        if (! hash_equals($expectedCatalogVersion, $cv)) {
            return null;
        }

        // Constrain to known bits (defense in depth) before trusting it.
        return ((int) $word) & PermissionBits::allKnownMask();
    }

    // -- internals -----------------------------------------------------------

    private static function payload(
        int $word,
        string $subjectId,
        string $orgId,
        ?string $scopeGroupId,
        int $grantVersion,
        string $roleTableVersion,
        string $catalogVersion,
        int $exp,
    ): string {
        return implode('|', [
            $word,
            $subjectId,
            $orgId,
            $scopeGroupId ?? '',
            $grantVersion,
            $roleTableVersion,
            $catalogVersion,
            $exp,
        ]);
    }

    private function mac(string $payload, string $keyId): string
    {
        $key = $this->keys->keyFor($keyId);

        return substr(hash_hmac('sha256', $payload, $key, true), 0, self::MAC_BYTES);
    }

    /** Like mac() but returns null if the keyId is unknown (verify path). */
    private function macSafe(string $payload, string $keyId): ?string
    {
        try {
            return $this->mac($payload, $keyId);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): ?string
    {
        $b64 = strtr($s, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($b64, true);

        return $out === false ? null : $out;
    }
}
