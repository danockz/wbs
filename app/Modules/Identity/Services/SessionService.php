<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Server-side session references (SRS FR-ID session/token).
 *
 * Sessions carry the achieved MFA assurance level and the risk score used when
 * they were created, so downstream step-up checks can compare current assurance
 * against the risk of a requested action. IP/UA are stored only as hashes.
 *
 * Lifetime is bounded THREE ways and a session is valid only until the first of
 * them trips (fail-closed):
 *   - absolute TTL  — `expires_at`, fixed at creation (default 14 days);
 *   - idle timeout  — no activity (`last_seen_at`) for `idleTtl` (default 8h);
 *   - revocation    — `revoked_at` set (logout, password reset, admin, replay).
 * `active()` enforces all three, so a leaked session id can no longer be
 * replayed indefinitely.
 */
final class SessionService
{
    /** Default absolute lifetime (seconds) — 14 days. */
    private const DEFAULT_ABSOLUTE_TTL = 1209600;

    /** Default idle timeout (seconds) — 8 hours. */
    private const DEFAULT_IDLE_TTL = 28800;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly string $hashSalt = 'wbs-session-salt',
        private readonly int $absoluteTtl = self::DEFAULT_ABSOLUTE_TTL,
        private readonly int $idleTtl = self::DEFAULT_IDLE_TTL,
    ) {
    }

    /**
     * @param array<string,mixed> $ctx ip, user_agent, mfa_level, risk_score
     */
    public function create(string $userId, string $organizationId, array $ctx = []): Result
    {
        $id  = Uuid::v7();
        $now = $this->clock->nowUtcString();
        $this->db->table('sessions')->insert([
            'id'              => $id,
            'user_id'         => $userId,
            'organization_id' => $organizationId,
            'ip_hash'         => isset($ctx['ip']) ? $this->hash((string) $ctx['ip']) : null,
            'user_agent_hash' => isset($ctx['user_agent']) ? $this->hash((string) $ctx['user_agent']) : null,
            'mfa_level'       => $ctx['mfa_level'] ?? 'none',
            'risk_score'      => (int) ($ctx['risk_score'] ?? 0),
            'created_at'      => $now,
            'last_seen_at'    => $now,
            'expires_at'      => $this->absoluteExpiry(),
        ]);

        return Result::created([
            'session_id' => $id,
            'mfa_level'  => $ctx['mfa_level'] ?? 'none',
            'expires_at' => $this->absoluteExpiry(),
        ]);
    }

    /**
     * Raise the MFA assurance recorded on a session after a step-up AND rotate
     * the session id (anti session-fixation: a privilege change must issue a new
     * identifier so any pre-elevation id — possibly known to an attacker — cannot
     * ride the newly elevated session). Returns the NEW session id; callers MUST
     * replace the client's stored id/cookie with it.
     */
    public function elevate(string $sessionId, string $mfaLevel): Result
    {
        $session = $this->active($sessionId);
        if ($session === null) {
            return Result::denied('identity.session_invalid', 'SESSION_INVALID');
        }

        $newId = Uuid::v7();
        $now   = $this->clock->nowUtcString();

        $this->db->transStart();
        // New elevated session inherits the original's context + absolute cap.
        $this->db->table('sessions')->insert([
            'id'              => $newId,
            'user_id'         => $session['user_id'],
            'organization_id' => $session['organization_id'],
            'ip_hash'         => $session['ip_hash'],
            'user_agent_hash' => $session['user_agent_hash'],
            'mfa_level'       => $mfaLevel,
            'risk_score'      => (int) $session['risk_score'],
            'created_at'      => $now,
            'last_seen_at'    => $now,
            'expires_at'      => $session['expires_at'],
        ]);
        // Retire the old id.
        $this->db->table('sessions')->where('id', $sessionId)->update(['revoked_at' => $now]);
        $this->db->transComplete();

        return Result::ok([
            'session_id'  => $newId,
            'previous_id' => $sessionId,
            'mfa_level'   => $mfaLevel,
            'rotated'     => true,
        ]);
    }

    public function touch(string $sessionId): void
    {
        $this->db->table('sessions')->where('id', $sessionId)->where('revoked_at', null)
            ->update(['last_seen_at' => $this->clock->nowUtcString()]);
    }

    public function revoke(string $sessionId): Result
    {
        $this->db->table('sessions')->where('id', $sessionId)->update([
            'revoked_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['session_id' => $sessionId, 'revoked' => true]);
    }

    /** Revoke every active session for a user (e.g. after password reset). */
    public function revokeAllForUser(string $userId): Result
    {
        $this->db->table('sessions')->where('user_id', $userId)->where('revoked_at', null)->update([
            'revoked_at' => $this->clock->nowUtcString(),
        ]);

        return Result::ok(['user_id' => $userId, 'revoked' => true]);
    }

    /**
     * Return the session row iff it is currently valid: not revoked, not past its
     * absolute `expires_at`, and not idle beyond `idleTtl`. An expired/idle
     * session is lazily revoked so it cannot be probed again.
     *
     * @return array<string,mixed>|null
     */
    public function active(string $sessionId): ?array
    {
        $row = $this->db->table('sessions')
            ->where('id', $sessionId)->where('revoked_at', null)
            ->get()->getRowArray() ?: null;
        if ($row === null) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();

        // Absolute cap. (Rows created before 000049 backfill are covered too.)
        if (! empty($row['expires_at']) && strtotime((string) $row['expires_at']) <= $now) {
            $this->revoke($sessionId);

            return null;
        }

        // Idle timeout since last activity.
        $lastSeen = strtotime((string) ($row['last_seen_at'] ?? $row['created_at']));
        if ($lastSeen !== false && ($now - $lastSeen) > $this->idleTtl) {
            $this->revoke($sessionId);

            return null;
        }

        return $row;
    }

    /**
     * List a user's currently-active sessions (metadata only, no hashes/PII), so
     * the user can review and selectively revoke them.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId): array
    {
        $now = $this->clock->nowUtcString();

        return $this->db->table('sessions')
            ->select('id, mfa_level, risk_score, created_at, last_seen_at, expires_at')
            ->where('user_id', $userId)
            ->where('revoked_at', null)
            ->where('expires_at >', $now)
            ->orderBy('last_seen_at', 'DESC')
            ->get()->getResultArray();
    }

    /**
     * Housekeeping: revoke sessions past their absolute cap that are still marked
     * active, and hard-delete rows revoked/expired longer than $retentionDays ago
     * so the table stays bounded. Idempotent; safe to run on a schedule.
     *
     * @return array{expired:int,deleted:int}
     */
    public function prune(int $retentionDays = 30): array
    {
        $now = $this->clock->nowUtcString();

        $this->db->table('sessions')
            ->where('revoked_at', null)
            ->where('expires_at <=', $now)
            ->update(['revoked_at' => $now]);
        $expired = $this->db->affectedRows();

        $cutoff = $this->clock->now()->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');
        $this->db->table('sessions')->where('revoked_at <', $cutoff)->delete();
        $deleted = $this->db->affectedRows();

        return ['expired' => $expired, 'deleted' => $deleted];
    }

    /** Absolute expiry timestamp for a session created now. */
    private function absoluteExpiry(): string
    {
        return $this->clock->now()->modify('+' . $this->absoluteTtl . ' seconds')->format('Y-m-d H:i:s');
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashSalt);
    }
}
