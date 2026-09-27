<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Social sign-in callback + linking (SRS FR-ID-005).
 *
 * The OIDC handshake (PKCE, signed state, nonce, issuer/audience/signature/
 * expiry validation) is performed by the provider adapter before this
 * controller receives VALIDATED claims. This endpoint only maps claims to an
 * account and never performs a silent takeover.
 */
final class SocialAuthController extends BaseController
{
    public function callback(string $provider = '')
    {
        $in    = $this->input();
        $orgId = $this->orgId();

        $claims = [
            'sub'            => $in['sub'] ?? '',
            'email'          => $in['email'] ?? null,
            'email_verified' => (bool) ($in['email_verified'] ?? false),
            'name'           => $in['name'] ?? null,
        ];

        if (! in_array($provider, ['google', 'microsoft'], true)) {
            return $this->respondWith(Result::fail('UNSUPPORTED_PROVIDER', 'identity.unsupported_provider', 422));
        }

        return $this->respondWith(IdentityServices::socialAuth()->signInWithClaims($orgId, $provider, $claims));
    }

    public function link(string $provider = '')
    {
        $in = $this->input();

        // Requires an already-authenticated user (enforced by auth filter).
        return $this->respondWith(IdentityServices::socialAuth()->linkToUser(
            (string) ($in['user_id'] ?? ''),
            $provider,
            (string) ($in['sub'] ?? ''),
            $in['email'] ?? null,
        ));
    }

    public function unlink(string $provider = '')
    {
        $userId = (string) $this->field('user_id', '');

        return $this->respondWith(IdentityServices::socialAuth()->unlink($userId, $provider));
    }
}
