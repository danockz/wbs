<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Identity\Security\PasswordHasher;
use WBS\Identity\Security\RiskEngine;
use WBS\Identity\Security\RiskSignals;
use WBS\Identity\Security\StepUpPolicy;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * Password authentication with adaptive/dynamic MFA (SRS FR-ID adaptive MFA).
 *
 *  - Verifies credentials with constant-effort behavior and GENERIC failures so
 *    account existence is never revealed.
 *  - Computes a risk score from context signals and asks the StepUpPolicy what
 *    assurance is required. If the achieved assurance is insufficient it returns
 *    a `mfa_required` challenge instead of a session.
 *  - Records privacy-minimized login attempts + security events for lockout and
 *    credential-stuffing detection.
 */
final class AuthenticationService
{
    /** Failed attempts within the window that trip lockout. */
    private const LOCKOUT_THRESHOLD = 5;
    private const LOCKOUT_WINDOW_MIN = 15;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly PasswordHasher $hasher,
        private readonly RiskEngine $riskEngine,
        private readonly StepUpPolicy $stepUp,
        private readonly SessionService $sessions,
        private readonly string $hashSalt = 'wbs-auth-salt',
    ) {
    }

    /**
     * Attempt a password login.
     *
     * @param array<string,mixed> $ctx ip, user_agent, new_device, new_location,
     *                                  impossible_travel, bad_ip, achieved_mfa
     *
     * @return Result on success data has session_id; on step-up data has
     *                mfa_required=true + required_assurance.
     */
    public function login(string $organizationId, string $email, string $password, array $ctx = []): Result
    {
        $email     = strtolower(trim($email));
        $emailHash = $this->hash($email);
        $ipHash    = isset($ctx['ip']) ? $this->hash((string) $ctx['ip']) : null;

        // Lockout / stuffing gate BEFORE expensive hash verification.
        $recentFailures = $this->recentFailures($emailHash);
        if ($recentFailures >= self::LOCKOUT_THRESHOLD) {
            $this->recordSecurityEvent($organizationId, null, 'login.locked_out', $ipHash, $ctx);

            return Result::fail('AUTH_FAILED', 'identity.auth_failed', 401); // generic
        }

        $user = $this->db->table('users')
            ->where('organization_id', $organizationId)->where('email', $email)
            ->get()->getRowArray();

        // Constant-ish work even when the user is absent (mitigate enumeration).
        $hash  = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $valid = $this->hasher->verify($password, (string) $hash) && $user !== null && $user['status'] === 'active';

        if (! $valid) {
            $this->recordAttempt($organizationId, $emailHash, $ipHash, 'failure');
            $this->recordSecurityEvent($organizationId, $user['id'] ?? null, 'login.failure', $ipHash, $ctx);

            return Result::fail('AUTH_FAILED', 'identity.auth_failed', 401); // generic
        }

        // Compute risk and required assurance.
        $signals = new RiskSignals(
            newDevice: (bool) ($ctx['new_device'] ?? false),
            newLocation: (bool) ($ctx['new_location'] ?? false),
            impossibleTravel: (bool) ($ctx['impossible_travel'] ?? false),
            recentFailedLogins: $recentFailures,
            knownIpReputationBad: (bool) ($ctx['bad_ip'] ?? false),
            sensitiveActionRequested: false,
            credentialStuffingPattern: (bool) ($ctx['credential_stuffing'] ?? false),
        );
        $riskScore = $this->riskEngine->score($signals);
        $achieved  = (string) ($ctx['achieved_mfa'] ?? 'none');

        $this->recordAttempt($organizationId, $emailHash, $ipHash, 'success');

        // Transparent password upgrade: if the stored hash uses weaker params
        // than the current policy (e.g. a bcrypt legacy hash on an Argon2id
        // platform), re-hash the just-verified plaintext and persist it. Cheap,
        // one-time per credential, never blocks the login.
        if ($this->hasher->needsRehash((string) $hash)) {
            $this->db->table('users')->where('id', $user['id'])->update([
                'password_hash' => $this->hasher->hash($password),
                'updated_at'    => $this->clock->nowUtcString(),
            ]);
        }

        if (! $this->stepUp->isSatisfied($achieved, $riskScore)) {
            $this->recordSecurityEvent($organizationId, $user['id'], 'mfa.required', $ipHash, $ctx, $riskScore);

            return Result::ok([
                'mfa_required'       => true,
                'user_id'            => $user['id'],
                'risk_score'         => $riskScore,
                'required_assurance' => $this->stepUp->requiredAssurance($riskScore),
                'mfa_enabled'        => (bool) $user['mfa_enabled'],
            ], 200, ['step_up' => true]);
        }

        // Assurance satisfied -> issue a session.
        $this->db->table('users')->where('id', $user['id'])->update([
            'last_login_at' => $this->clock->nowUtcString(),
            'updated_at'    => $this->clock->nowUtcString(),
        ]);
        $this->recordSecurityEvent($organizationId, $user['id'], 'login.success', $ipHash, $ctx, $riskScore);

        $session = $this->sessions->create($user['id'], $organizationId, [
            'ip'         => $ctx['ip'] ?? null,
            'user_agent' => $ctx['user_agent'] ?? null,
            'mfa_level'  => $achieved,
            'risk_score' => $riskScore,
        ]);

        return Result::ok([
            'mfa_required' => false,
            'user_id'      => $user['id'],
            'session_id'   => $session->data['session_id'] ?? null,
            'risk_score'   => $riskScore,
        ]);
    }

    private function recentFailures(string $emailHash): int
    {
        $since = $this->clock->now()->modify('-' . self::LOCKOUT_WINDOW_MIN . ' minutes')->format('Y-m-d H:i:s');

        return $this->db->table('login_attempts')
            ->where('email_hash', $emailHash)
            ->where('outcome', 'failure')
            ->where('created_at >=', $since)
            ->countAllResults();
    }

    private function recordAttempt(string $organizationId, string $emailHash, ?string $ipHash, string $outcome): void
    {
        $this->db->table('login_attempts')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'email_hash'      => $emailHash,
            'ip_hash'         => $ipHash,
            'outcome'         => $outcome,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);
    }

    private function recordSecurityEvent(?string $organizationId, ?string $userId, string $type, ?string $ipHash, array $ctx, ?int $riskScore = null): void
    {
        $this->db->table('security_events')->insert([
            'id'              => Uuid::v7(),
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'type'            => $type,
            'ip_hash'         => $ipHash,
            'user_agent_hash' => isset($ctx['user_agent']) ? $this->hash((string) $ctx['user_agent']) : null,
            'risk_score'      => $riskScore,
            'created_at'      => $this->clock->nowUtcMicro(),
        ]);
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashSalt);
    }
}
