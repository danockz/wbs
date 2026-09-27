<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Social sign-in / account linking (SRS FR-ID-005).
 *
 *  - Identity is keyed by (provider, subject) — the immutable OIDC `sub` — never
 *    by mutable email.
 *  - An email claim is trusted only when provider-verified AND policy accepts it.
 *  - An existing-account email collision requires authenticated linking or a
 *    safe verification/merge workflow — NEVER a silent takeover.
 *
 * This service assumes the OIDC handshake (PKCE, state, nonce, issuer/audience/
 * signature/expiry validation) has already been performed by the caller/adapter;
 * it receives the validated claims.
 */
final class SocialAuthService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly AccountService $accounts,
    ) {
    }

    /**
     * Resolve validated OIDC claims to an account.
     *
     * @param array<string,mixed> $claims sub (req), email, email_verified, name
     *
     * @return Result outcomes:
     *   - existing linked identity  -> {action: signed_in, user_id}
     *   - new user (no collision)   -> {action: registered, user_id}
     *   - email collision           -> {action: link_required} (no session issued)
     */
    public function signInWithClaims(string $organizationId, string $provider, array $claims): Result
    {
        $subject = (string) ($claims['sub'] ?? '');
        if ($subject === '') {
            return Result::fail('MISSING_SUBJECT', 'identity.social_missing_subject', 422);
        }

        // 1. Already-linked identity -> straight sign-in.
        $identity = $this->db->table('user_identities')
            ->where('provider', $provider)->where('subject', $subject)
            ->get()->getRowArray();
        if ($identity !== null) {
            return Result::ok(['action' => 'signed_in', 'user_id' => $identity['user_id']]);
        }

        $email         = isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        // 2. Verified email that matches an existing account -> require explicit
        //    linking (authenticated), never silent takeover.
        if ($email !== null && $emailVerified) {
            $existing = $this->db->table('users')
                ->where('organization_id', $organizationId)->where('email', $email)
                ->get()->getRowArray();
            if ($existing !== null) {
                return Result::ok([
                    'action'    => 'link_required',
                    'user_id'   => $existing['id'],
                    'provider'  => $provider,
                    'subject'   => $subject,
                ], 200, ['requires_authenticated_link' => true]);
            }
        }

        // 3. No collision -> provision a new social account and link it.
        $reg = $this->accounts->register($organizationId, [
            'email'        => $emailVerified ? $email : null,
            'display_name' => $claims['name'] ?? null,
            'social'       => true,
        ]);
        if (! $reg->ok) {
            return $reg;
        }
        $userId = $reg->data['user_id'];

        $this->linkInternal($userId, $provider, $subject, $email);
        if ($emailVerified && $email !== null) {
            $this->accounts->markEmailVerified($userId);
        }

        return Result::created(['action' => 'registered', 'user_id' => $userId]);
    }

    /**
     * Link a social identity to an already-authenticated user (FR-ID-005).
     * The caller MUST have confirmed recent authentication (+ MFA if risk).
     */
    public function linkToUser(string $userId, string $provider, string $subject, ?string $email = null): Result
    {
        $taken = $this->db->table('user_identities')
            ->where('provider', $provider)->where('subject', $subject)
            ->get()->getRowArray();
        if ($taken !== null) {
            if ((string) $taken['user_id'] === $userId) {
                return Result::ok(['action' => 'already_linked', 'user_id' => $userId]);
            }

            return Result::fail('IDENTITY_TAKEN', 'identity.social_identity_taken', 409);
        }

        $this->linkInternal($userId, $provider, $subject, $email);

        return Result::created(['action' => 'linked', 'user_id' => $userId, 'provider' => $provider]);
    }

    /**
     * Unlink a social identity. Refuses to remove the final usable login method
     * (FR-ID-005: last usable method cannot be removed without a recovery path).
     */
    public function unlink(string $userId, string $provider): Result
    {
        $user   = $this->accounts->findById($userId);
        $others = $this->db->table('user_identities')->where('user_id', $userId)->where('provider !=', $provider)->countAllResults();
        $hasPassword = $user !== null && ! empty($user['password_hash']);

        if (! $hasPassword && $others === 0) {
            return Result::fail('LAST_LOGIN_METHOD', 'identity.last_login_method', 409);
        }

        $this->db->table('user_identities')->where('user_id', $userId)->where('provider', $provider)->delete();

        return Result::ok(['action' => 'unlinked', 'user_id' => $userId, 'provider' => $provider]);
    }

    private function linkInternal(string $userId, string $provider, string $subject, ?string $email): void
    {
        $this->db->table('user_identities')->insert([
            'id'        => Uuid::v7(),
            'user_id'   => $userId,
            'provider'  => $provider,
            'subject'   => $subject,
            'email'     => $email,
            'linked_at' => $this->clock->nowUtcString(),
        ]);
    }
}
