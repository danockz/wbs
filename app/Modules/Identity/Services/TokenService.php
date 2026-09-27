<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * API access + refresh token lifecycle (SRS FR-ID-006).
 *
 * Design invariants:
 *  - Tokens are high-entropy random strings; only their SHA-256 hash is stored.
 *    The plaintext is returned to the caller exactly ONCE at mint/rotate time.
 *  - Access tokens are short-lived and audience/scope-bound. No broad wildcard
 *    scope is granted by default — an empty scope list means "no scope".
 *  - Refresh tokens rotate on every use and carry a family id. Presenting an
 *    already-rotated or revoked refresh token (replay) revokes the ENTIRE family,
 *    defeating stolen-token reuse.
 *  - verifyAccessToken is constant-time (hash lookup + status/expiry checks) and
 *    never reveals why a token is invalid beyond a generic failure.
 *
 * Token string format: "<prefix>_<43+ base64url chars>". The prefix helps clients
 * and secret scanners recognize the token type; it is NOT a secret by itself.
 */
final class TokenService
{
    private const ACCESS_PREFIX  = 'wbsat';
    private const REFRESH_PREFIX = 'wbsrt';

    /** Default access-token lifetime (seconds) — short-lived per FR-ID-006. */
    private const ACCESS_TTL  = 900;        // 15 minutes
    /** Default refresh-token lifetime (seconds). */
    private const REFRESH_TTL = 1209600;    // 14 days

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Issue a fresh access+refresh pair for a user (e.g. after login). Establishes
     * a NEW refresh-token family.
     *
     * @param list<string> $scopes explicit scopes; empty = no scope (never wildcard)
     * @return Result data: {access_token, refresh_token, token_type, expires_in, scopes, ...}
     */
    public function issue(string $userId, string $organizationId, array $scopes = [], ?string $name = null, ?int $accessTtl = null, ?int $refreshTtl = null): Result
    {
        $scopes   = $this->normalizeScopes($scopes);
        $familyId = Uuid::v7();

        return $this->mintPair($userId, $organizationId, $scopes, $familyId, $name, $accessTtl, $refreshTtl);
    }

    /**
     * Rotate a refresh token: validate it, mint a new pair in the SAME family, and
     * mark the presented token as rotated. If the presented token was already
     * rotated or revoked (replay), the whole family is revoked and this fails.
     */
    public function refresh(string $rawRefreshToken, ?int $accessTtl = null, ?int $refreshTtl = null): Result
    {
        $hash = $this->hash($rawRefreshToken);
        $row  = $this->db->table('refresh_tokens')->where('token_hash', $hash)->get()->getRowArray();

        if ($row === null) {
            return Result::denied('token.invalid', 'TOKEN_INVALID');
        }

        // Replay detection: a token that is not active means it was already used
        // (rotated) or revoked. Any presentation of such a token compromises the
        // family, so revoke everything derived from that original grant.
        if ($row['status'] !== 'active') {
            $this->revokeFamily($row['family_id'], 'replay_detected');

            return Result::denied('token.replay_detected', 'TOKEN_REPLAY');
        }

        if ($row['expires_at'] < $this->clock->nowUtcString()) {
            $this->db->table('refresh_tokens')->where('id', $row['id'])
                ->update(['status' => 'revoked', 'used_at' => $this->clock->nowUtcString()]);

            return Result::denied('token.expired', 'TOKEN_EXPIRED');
        }

        $scopes = $this->normalizeScopes($row['scopes'] !== null ? (json_decode((string) $row['scopes'], true) ?: []) : []);

        // Mint the successor pair in the same family.
        $minted = $this->mintPair(
            $row['user_id'],
            $row['organization_id'],
            $scopes,
            $row['family_id'],
            null,
            $accessTtl,
            $refreshTtl,
        );
        if (! $minted->ok) {
            return $minted;
        }

        // Mark the presented token rotated and link to its successor.
        $this->db->table('refresh_tokens')->where('id', $row['id'])->update([
            'status'      => 'rotated',
            'replaced_by' => $minted->data['refresh_token_id'],
            'used_at'     => $this->clock->nowUtcString(),
        ]);

        return $minted;
    }

    /**
     * Verify a raw access token. Returns the token principal (user/org/scopes) on
     * success. Updates last_used_at. Generic failure — no reason leakage.
     *
     * @return Result data: {user_id, organization_id, scopes, token_id}
     */
    public function verifyAccessToken(string $rawAccessToken, ?string $requiredScope = null): Result
    {
        $hash = $this->hash($rawAccessToken);
        $row  = $this->db->table('access_tokens')->where('token_hash', $hash)->get()->getRowArray();

        if ($row === null || $row['revoked_at'] !== null) {
            return Result::denied('token.invalid', 'TOKEN_INVALID');
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < $this->clock->nowUtcString()) {
            return Result::denied('token.expired', 'TOKEN_EXPIRED');
        }

        $scopes = $row['scopes'] !== null ? (json_decode((string) $row['scopes'], true) ?: []) : [];
        if ($requiredScope !== null && ! in_array($requiredScope, $scopes, true)) {
            return Result::denied('token.insufficient_scope', 'INSUFFICIENT_SCOPE');
        }

        $this->db->table('access_tokens')->where('id', $row['id'])
            ->update(['last_used_at' => $this->clock->nowUtcString()]);

        return Result::ok([
            'user_id'         => $row['user_id'],
            'organization_id' => $row['organization_id'],
            'scopes'          => $scopes,
            'token_id'        => $row['id'],
        ]);
    }

    /** Revoke a single access token by id. */
    public function revokeAccessToken(string $tokenId): Result
    {
        $this->db->table('access_tokens')->where('id', $tokenId)->where('revoked_at', null)
            ->update(['revoked_at' => $this->clock->nowUtcString()]);

        return Result::ok(['token_id' => $tokenId, 'revoked' => true]);
    }

    /** Revoke an entire refresh-token family (e.g. logout-all / compromise). */
    public function revokeFamily(string $familyId, string $reason = 'revoked'): Result
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('refresh_tokens')->where('family_id', $familyId)
            ->whereIn('status', ['active', 'rotated'])
            ->update(['status' => 'revoked', 'used_at' => $now]);

        // Also revoke access tokens minted for this family's members.
        $ids = array_column(
            $this->db->table('refresh_tokens')->select('access_token_id')
                ->where('family_id', $familyId)->where('access_token_id !=', null)->get()->getResultArray(),
            'access_token_id',
        );
        if ($ids !== []) {
            $this->db->table('access_tokens')->whereIn('id', $ids)->where('revoked_at', null)
                ->update(['revoked_at' => $now]);
        }

        return Result::ok(['family_id' => $familyId, 'reason' => $reason, 'revoked' => true]);
    }

    /** Revoke every active token for a user (all families + access tokens). */
    public function revokeAllForUser(string $userId): Result
    {
        $now = $this->clock->nowUtcString();
        $this->db->table('refresh_tokens')->where('user_id', $userId)
            ->whereIn('status', ['active', 'rotated'])
            ->update(['status' => 'revoked', 'used_at' => $now]);
        $this->db->table('access_tokens')->where('user_id', $userId)->where('revoked_at', null)
            ->update(['revoked_at' => $now]);

        return Result::ok(['user_id' => $userId, 'revoked' => true]);
    }

    /** List a user's non-revoked access tokens (metadata only, never the secret). */
    public function listAccessTokens(string $userId): array
    {
        return $this->db->table('access_tokens')
            ->select('id, name, scopes, last_used_at, expires_at, created_at')
            ->where('user_id', $userId)->where('revoked_at', null)
            ->orderBy('created_at', 'DESC')->get()->getResultArray();
    }

    /**
     * Housekeeping (ID4): bound the token tables. Access + refresh tokens
     * lazy-expire — a caller presenting an expired token is rejected, but the row
     * is never removed, so both tables grow without limit. This does two passes,
     * mirroring SessionService::prune():
     *
     *   1. EXPIRE  — flip still-"live" rows whose absolute expiry has passed to a
     *      terminal state (access: set revoked_at; refresh: status='expired'), so
     *      a stale token can never verify/rotate even before deletion.
     *   2. DELETE  — hard-delete tokens that reached a terminal state (revoked /
     *      rotated / expired) longer than $retentionDays ago, keeping an audit
     *      window for replay forensics before the row is dropped.
     *
     * Idempotent and safe to run on a schedule; a second pass in the same window
     * finds nothing new.
     *
     * @return array{access_expired:int, refresh_expired:int, access_deleted:int, refresh_deleted:int}
     */
    public function prune(int $retentionDays = 30): array
    {
        $now             = $this->clock->nowUtcString();
        $retentionDays   = $retentionDays > 0 ? $retentionDays : 30;
        $cutoff          = $this->clock->now()->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

        // 1a. Expire still-active access tokens whose absolute expiry has passed.
        $this->db->table('access_tokens')
            ->where('revoked_at', null)
            ->where('expires_at !=', null)
            ->where('expires_at <=', $now)
            ->update(['revoked_at' => $now]);
        $accessExpired = (int) $this->db->affectedRows();

        // 1b. Expire still-active refresh tokens whose absolute expiry has passed.
        $this->db->table('refresh_tokens')
            ->where('status', 'active')
            ->where('expires_at <=', $now)
            ->update(['status' => 'expired', 'used_at' => $now]);
        $refreshExpired = (int) $this->db->affectedRows();

        // 2a. Delete access tokens revoked longer than the retention window ago.
        $this->db->table('access_tokens')
            ->where('revoked_at !=', null)
            ->where('revoked_at <', $cutoff)
            ->delete();
        $accessDeleted = (int) $this->db->affectedRows();

        // 2b. Delete terminal refresh tokens whose last-touch is past the window.
        //     rotated/revoked/expired rows are dead; used_at (or created_at when a
        //     row was never touched) is the age reference.
        $this->db->table('refresh_tokens')
            ->whereIn('status', ['rotated', 'revoked', 'expired'])
            ->where('COALESCE(used_at, created_at) <', $cutoff)
            ->delete();
        $refreshDeleted = (int) $this->db->affectedRows();

        return [
            'access_expired'  => $accessExpired,
            'refresh_expired' => $refreshExpired,
            'access_deleted'  => $accessDeleted,
            'refresh_deleted' => $refreshDeleted,
        ];
    }

    // -- internals ----------------------------------------------------------

    /**
     * Mint an access token + refresh token pair (shared family).
     *
     * @param list<string> $scopes
     */
    private function mintPair(string $userId, string $organizationId, array $scopes, string $familyId, ?string $name, ?int $accessTtl, ?int $refreshTtl): Result
    {
        $now       = $this->clock->nowUtcString();
        $accessTtl = $accessTtl ?? self::ACCESS_TTL;
        $refreshTtl = $refreshTtl ?? self::REFRESH_TTL;

        $accessRaw   = $this->randomToken(self::ACCESS_PREFIX);
        $refreshRaw  = $this->randomToken(self::REFRESH_PREFIX);
        $accessId    = Uuid::v7();
        $refreshId   = Uuid::v7();
        $accessExp   = $this->clock->now()->modify("+{$accessTtl} seconds")->format('Y-m-d H:i:s');
        $refreshExp  = $this->clock->now()->modify("+{$refreshTtl} seconds")->format('Y-m-d H:i:s');
        $scopesJson  = json_encode($scopes);

        $this->db->transStart();
        $this->db->table('access_tokens')->insert([
            'id'              => $accessId,
            'user_id'         => $userId,
            'organization_id' => $organizationId,
            'name'            => $name,
            'token_hash'      => $this->hash($accessRaw),
            'scopes'          => $scopesJson,
            'expires_at'      => $accessExp,
            'created_at'      => $now,
        ]);
        $this->db->table('refresh_tokens')->insert([
            'id'              => $refreshId,
            'family_id'       => $familyId,
            'user_id'         => $userId,
            'organization_id' => $organizationId,
            'token_hash'      => $this->hash($refreshRaw),
            'access_token_id' => $accessId,
            'scopes'          => $scopesJson,
            'status'          => 'active',
            'expires_at'      => $refreshExp,
            'created_at'      => $now,
        ]);
        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return Result::fail('TOKEN_MINT_FAILED', 'token.mint_failed', 500);
        }

        return Result::created([
            'token_type'        => 'Bearer',
            'access_token'      => $accessRaw,      // returned once
            'refresh_token'    => $refreshRaw,      // returned once
            'access_token_id'  => $accessId,
            'refresh_token_id' => $refreshId,
            'family_id'        => $familyId,
            'expires_in'       => $accessTtl,
            'scopes'           => $scopes,
        ]);
    }

    /** High-entropy URL-safe token with a type prefix. */
    private function randomToken(string $prefix): string
    {
        $rand = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return $prefix . '_' . $rand;
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /**
     * Normalize/deduplicate scopes. An empty list stays empty — never expanded to
     * a wildcard (FR-ID-006: no broad wildcard scope by default).
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $s) {
            $s = trim((string) $s);
            if ($s !== '' && $s !== '*') { // reject wildcard explicitly
                $clean[$s] = true;
            }
        }

        return array_keys($clean);
    }
}
