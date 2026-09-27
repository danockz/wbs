<?php

declare(strict_types=1);

namespace WBS\Identity\Services;

use CodeIgniter\Database\BaseConnection;
use WBS\Identity\Security\RecoveryCodeService;
use WBS\Identity\Security\TotpService;
use WBS\Shared\Security\SecretBox;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Result;
use WBS\Shared\Support\Uuid;

/**
 * MFA factor enrolment + verification (SRS adaptive MFA; passkeys/TOTP
 * preferred, SMS/email lower assurance).
 *
 *  - TOTP secrets are stored encrypted with SecretBox (AAD-bound to the factor).
 *  - A factor is not usable until confirmed with a valid code.
 *  - Recovery codes are single-use HMAC hashes (delegated to RecoveryCodeService).
 */
final class MfaService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recovery,
        private readonly SecretBox $box,
    ) {
    }

    /** Begin TOTP enrolment: returns the secret + provisioning URI (once). */
    public function beginTotpEnrolment(string $userId, string $account, string $issuer = 'WBS'): Result
    {
        $secret = $this->totp->generateSecret();
        $id     = Uuid::v7();
        $now    = $this->clock->nowUtcString();

        $this->db->table('mfa_factors')->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'type'          => 'totp',
            'assurance'     => 'high',
            'label'         => $account,
            'secret_cipher' => $this->box->encrypt($secret, 'mfa:' . $id),
            'confirmed_at'  => null,
            'created_at'    => $now,
        ]);

        return Result::created([
            'factor_id'        => $id,
            'secret'           => $secret,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $account, $issuer),
        ]);
    }

    /** Confirm a TOTP factor with a code; enables MFA on the user. */
    public function confirmTotp(string $userId, string $factorId, string $code): Result
    {
        $factor = $this->db->table('mfa_factors')
            ->where('id', $factorId)->where('user_id', $userId)->where('type', 'totp')
            ->get()->getRowArray();
        if ($factor === null) {
            return Result::notFound('identity.factor_not_found', 'FACTOR_NOT_FOUND');
        }

        $secret = $this->box->decrypt((string) $factor['secret_cipher'], 'mfa:' . $factorId);
        if (! $this->totp->verify($secret, $code)) {
            return Result::fail('MFA_INVALID', 'identity.mfa_invalid', 422);
        }

        $now = $this->clock->nowUtcString();
        $this->db->table('mfa_factors')->where('id', $factorId)->update(['confirmed_at' => $now]);
        $this->db->table('users')->where('id', $userId)->update(['mfa_enabled' => 1, 'updated_at' => $now]);

        $codes = $this->recovery->generate($userId);

        return Result::ok([
            'factor_id'      => $factorId,
            'confirmed'      => true,
            'recovery_codes' => $codes, // shown once
        ]);
    }

    /** Verify a TOTP code against any confirmed factor (a step-up challenge). */
    public function verifyTotp(string $userId, string $code): Result
    {
        $factors = $this->db->table('mfa_factors')
            ->where('user_id', $userId)->where('type', 'totp')
            ->where('confirmed_at IS NOT NULL', null, false)
            ->get()->getResultArray();

        foreach ($factors as $factor) {
            $secret = $this->box->decrypt((string) $factor['secret_cipher'], 'mfa:' . $factor['id']);
            if ($this->totp->verify($secret, $code)) {
                return Result::ok(['verified' => true, 'assurance' => 'high']);
            }
        }

        return Result::fail('MFA_INVALID', 'identity.mfa_invalid', 422);
    }

    /** Consume a recovery code as a fallback second factor. */
    public function verifyRecoveryCode(string $userId, string $code): Result
    {
        if ($this->recovery->consume($userId, $code)) {
            return Result::ok(['verified' => true, 'assurance' => 'high', 'via' => 'recovery_code']);
        }

        return Result::fail('MFA_INVALID', 'identity.mfa_invalid', 422);
    }

    public function listFactors(string $userId): array
    {
        return $this->db->table('mfa_factors')
            ->select('id, type, assurance, label, confirmed_at, created_at')
            ->where('user_id', $userId)
            ->get()->getResultArray();
    }
}
