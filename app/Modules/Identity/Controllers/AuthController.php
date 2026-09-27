<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Authentication endpoints (SRS FR-ID-001/005, adaptive MFA).
 *
 * Canonical endpoints serving both web and API (representation negotiated by the
 * BaseController). Auth flows use GENERIC responses that never reveal account
 * existence. Organization context comes from a resolved header/subdomain; here
 * we read an explicit organization_id for the single-org deployment.
 */
final class AuthController extends BaseController
{
    public function register()
    {
        $in    = $this->input();
        $orgId = (string) ($in['organization_id'] ?? $this->defaultOrg());

        $result = IdentityServices::accounts()->register($orgId, [
            'email'         => $in['email'] ?? null,
            'phone'         => $in['phone'] ?? null,
            'phone_region'  => $in['phone_region'] ?? null,
            'password'      => $in['password'] ?? null,
            'display_name'  => $in['display_name'] ?? null,
            // null → the service falls back to the ORG's defaults (field-sync).
            'locale'        => $in['locale'] ?? null,
            'timezone'      => $in['timezone'] ?? null,
            'date_of_birth' => $in['date_of_birth'] ?? null,
            'country_code'  => $in['country_code'] ?? null,
            'consents'      => $in['consents'] ?? [],
            // Upline: an explicit sponsor (referral referrer / ?sponsor=) and/or
            // the group being joined. When neither yields a sponsor, the service
            // falls back to the hierarchical group leader chain (FR-MEM-001).
            'sponsor_id'    => $in['sponsor_id'] ?? $this->request->getGet('sponsor') ?? null,
            'group_id'      => $in['group_id'] ?? $this->request->getGet('group') ?? null,
        ]);

        // Do not leak existence on public registration — a uniqueness collision
        // returns the same generic "pending verification" response as success.
        if (! $result->ok && in_array($result->code, ['EMAIL_TAKEN', 'PHONE_TAKEN'], true)) {
            $result = Result::created(['status' => 'pending_verification']);
        }

        return $this->respondWith($result, htmlView: null);
    }

    public function login()
    {
        $in    = $this->input();
        $orgId = (string) ($in['organization_id'] ?? $this->defaultOrg());

        $result = IdentityServices::authentication()->login(
            $orgId,
            (string) ($in['email'] ?? ''),
            (string) ($in['password'] ?? ''),
            [
                'ip'                  => $this->request->getIPAddress(),
                'user_agent'          => $this->request->getUserAgent()->getAgentString(),
                'new_device'          => (bool) ($in['new_device'] ?? false),
                'new_location'        => (bool) ($in['new_location'] ?? false),
                'impossible_travel'   => (bool) ($in['impossible_travel'] ?? false),
                'bad_ip'              => (bool) ($in['bad_ip'] ?? false),
                'achieved_mfa'        => $in['achieved_mfa'] ?? 'none',
            ],
        );

        return $this->respondWith($result, htmlView: null);
    }

    public function logout()
    {
        $sessionId = (string) $this->field('session_id', '');
        if ($sessionId === '') {
            return $this->respondWith(Result::fail('NO_SESSION', 'identity.no_session', 422));
        }

        return $this->respondWith(IdentityServices::sessions()->revoke($sessionId));
    }

    private function defaultOrg(): string
    {
        return $this->orgId();
    }
}
