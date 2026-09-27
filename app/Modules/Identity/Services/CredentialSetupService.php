<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Credential-setup / invite tokens (SRS FR-ID account activation).
 *
 * Issues single-use, expiring tokens that let a passwordless member set their
 * first password (invite) or reset a forgotten one (password_reset), then
 * activate sign-in. Only the SHA-256 hash is stored; the plaintext is returned
 * once for the invite link. Consuming a token sets the password via
 * AccountService (policy-checked), marks the token used, and — for invites —
 * activates the account and verifies its email.
 */
final class CredentialSetupService
{
    /** Default invite/reset token lifetime (seconds) — 7 days. */
    private const DEFAULT_TTL = 604800;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AccountService $accounts,
        private readonly SessionService $sessions,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Mint a token for a user. Any prior UNUSED token of the same purpose is
     * invalidated first, so only the newest invite link works. Returns the
     * PLAINTEXT token (embed in the link) exactly once.
     *
     * @return Result data: { token, user_id, purpose, expires_at }
     */
    public function issue(string $organizationId, string $userId, string $purpose = 'invite', ?string $createdBy = null): Result
    {
        if (! in_array($purpose, ['invite', 'password_reset'], true)) {
            return Result::fail('BAD_PURPOSE', 'identity.invite_bad_purpose', 422);
        }

        $now = $this->clock->nowUtcString();

        // Retire outstanding unused tokens of this purpose for the user.
        $this->db->table('credential_setup_tokens')
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('consumed_at', null)
            ->update(['consumed_at' => $now]);

        $plain = 'wbsinv_' . bin2hex(random_bytes(32));
        $this->db->table('credential_setup_tokens')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'token_hash'      => $this->hash($plain),
            'purpose'         => $purpose,
            'expires_at'      => $this->clock->now()->modify('+' . $this->ttl . ' seconds')->format('Y-m-d H:i:s'),
            'consumed_at'     => null,
            'created_by'      => $createdBy,
            'created_at'      => $now,
        ]);

        return Result::created([
            'token'      => $plain,
            'user_id'    => $userId,
            'purpose'    => $purpose,
            'expires_at' => $this->clock->now()->modify('+' . $this->ttl . ' seconds')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Validate a token WITHOUT consuming it (for rendering the set-password form).
     *
     * @return Result data: { user_id, purpose, display_name, email }
     */
    public function inspect(string $rawToken): Result
    {
        $row = $this->lookupValid($rawToken);
        if ($row === null) {
            return Result::denied('identity.invite_invalid', 'INVITE_INVALID');
        }

        $user = $this->accounts->findById((string) $row['user_id']);

        return Result::ok([
            'user_id'      => (string) $row['user_id'],
            'purpose'      => (string) $row['purpose'],
            'display_name' => $user['display_name'] ?? null,
            'email'        => $user['email'] ?? null,
        ]);
    }

    /**
     * Consume a token: set the password (policy-checked), mark the token used,
     * and for an invite activate the account + verify the email. Single-use and
     * atomic — a second consumption of the same token fails. Existing sessions
     * are revoked so a stale/hijacked session can't survive a credential change.
     *
     * @return Result data: { user_id, activated }
     */
    public function consume(string $rawToken, string $newPassword): Result
    {
        $row = $this->lookupValid($rawToken);
        if ($row === null) {
            return Result::denied('identity.invite_invalid', 'INVITE_INVALID');
        }

        $userId = (string) $row['user_id'];
        $now    = $this->clock->nowUtcString();

        // Atomically claim the token (guards against double-submit / races).
        $this->db->table('credential_setup_tokens')
            ->where('id', $row['id'])
            ->where('consumed_at', null)
            ->update(['consumed_at' => $now]);
        if ($this->db->affectedRows() !== 1) {
            return Result::denied('identity.invite_invalid', 'INVITE_INVALID');
        }

        // Set the password (enforces the shared password policy).
        $set = $this->accounts->setPassword($userId, $newPassword);
        if (! $set->ok) {
            // Roll back the claim so the user can retry with a stronger password.
            $this->db->table('credential_setup_tokens')->where('id', $row['id'])
                ->update(['consumed_at' => null]);

            return $set;
        }

        $activated = false;
        if ((string) $row['purpose'] === 'invite') {
            $this->db->table('users')->where('id', $userId)->update([
                'status'         => 'active',
                'email_verified' => 1,
                'updated_at'     => $now,
            ]);
            $activated = true;
        }

        // A credential change invalidates all existing sessions.
        $this->sessions->revokeAllForUser($userId);

        return Result::ok(['user_id' => $userId, 'activated' => $activated]);
    }

    /** @return array<string,mixed>|null the valid, unconsumed, unexpired row */
    /**
     * Housekeeping (ID4): bound the credential-setup token table. Invite /
     * password-reset tokens lazy-expire — an expired one simply fails validation
     * (`lookupValid` rejects it) but the row is never removed, so the table grows
     * unbounded. Two passes, mirroring SessionService/TokenService::prune():
     *
     *   1. EXPIRE  — stamp `consumed_at` on still-unconsumed tokens whose expiry
     *      has passed, moving them to a terminal state so a stale invite can never
     *      later be consumed even before deletion.
     *   2. DELETE  — hard-delete tokens consumed longer than $retentionDays ago,
     *      keeping a short audit window before the row is dropped.
     *
     * Idempotent and safe to run on a schedule.
     *
     * @return array{expired:int, deleted:int}
     */
    public function prune(int $retentionDays = 30): array
    {
        $now           = $this->clock->nowUtcString();
        $retentionDays = $retentionDays > 0 ? $retentionDays : 30;
        $cutoff        = $this->clock->now()->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

        // 1. Expire still-unconsumed tokens whose expiry has passed.
        $this->db->table('credential_setup_tokens')
            ->where('consumed_at', null)
            ->where('expires_at <=', $now)
            ->update(['consumed_at' => $now]);
        $expired = (int) $this->db->affectedRows();

        // 2. Delete tokens consumed longer than the retention window ago.
        $this->db->table('credential_setup_tokens')
            ->where('consumed_at !=', null)
            ->where('consumed_at <', $cutoff)
            ->delete();
        $deleted = (int) $this->db->affectedRows();

        return ['expired' => $expired, 'deleted' => $deleted];
    }

    private function lookupValid(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }
        $row = $this->db->table('credential_setup_tokens')
            ->where('token_hash', $this->hash($rawToken))
            ->where('consumed_at', null)
            ->get()->getRowArray() ?: null;
        if ($row === null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) <= $this->clock->now()->getTimestamp()) {
            return null;
        }

        return $row;
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
