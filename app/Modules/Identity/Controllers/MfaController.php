<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * MFA enrolment + step-up verification (SRS adaptive/dynamic MFA).
 *
 * A real deployment resolves the acting user from the authenticated session;
 * these endpoints accept an explicit user_id for the single-org build and must
 * be protected by the auth filter + rate limits on the routes.
 */
final class MfaController extends BaseController
{
    public function enrolTotp()
    {
        $in     = $this->input();
        $userId = (string) ($in['user_id'] ?? '');
        if ($userId === '') {
            return $this->respondWith(Result::fail('USER_REQUIRED', 'identity.user_required', 422));
        }

        $result = IdentityServices::mfa()->beginTotpEnrolment(
            $userId,
            (string) ($in['account'] ?? 'member'),
            (string) ($in['issuer'] ?? 'WBS'),
        );

        return $this->respondWith($result);
    }

    public function confirmTotp()
    {
        $in = $this->input();

        return $this->respondWith(IdentityServices::mfa()->confirmTotp(
            (string) ($in['user_id'] ?? ''),
            (string) ($in['factor_id'] ?? ''),
            (string) ($in['code'] ?? ''),
        ));
    }

    public function verify()
    {
        $in     = $this->input();
        $userId = (string) ($in['user_id'] ?? '');
        $code   = (string) ($in['code'] ?? '');

        // Try TOTP first, then a recovery code as fallback.
        $result = IdentityServices::mfa()->verifyTotp($userId, $code);
        if (! $result->ok) {
            $result = IdentityServices::mfa()->verifyRecoveryCode($userId, $code);
        }

        // On success, elevate the referenced session's assurance. elevate()
        // ROTATES the session id (anti-fixation) and returns the NEW id, which
        // the client MUST use for subsequent requests — surface it in the body.
        if ($result->ok && ! empty($in['session_id'])) {
            $elevated = IdentityServices::sessions()->elevate((string) $in['session_id'], 'high');
            if ($elevated->ok) {
                $data = is_array($result->data) ? $result->data : [];
                $data['session_id'] = $elevated->data['session_id'] ?? null;
                $data['session_rotated'] = true;
                $result = Result::ok($data, $result->status);
            }
        }

        return $this->respondWith($result);
    }

    public function factors()
    {
        $userId = (string) $this->field('user_id', '');
        if ($userId === '') {
            return $this->respondWith(Result::fail('USER_REQUIRED', 'identity.user_required', 422));
        }

        return $this->respondPage(
            Result::ok(IdentityServices::mfa()->listFactors($userId)),
            'identity_mfa_factors',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['factors'] ?? [])],
        );
    }
}
