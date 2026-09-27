<?php

declare(strict_types=1);

namespace WBS\Identity\Controllers;

use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * API token endpoints (SRS FR-ID-006).
 *
 * `issue` mints an access+refresh pair for the authenticated caller (behind the
 * auth filter). `refresh` exchanges a rotating refresh token for a new pair and
 * performs replay detection. Tokens are returned exactly once; only hashes are
 * stored. Sensitive routes carry rate limits.
 */
final class TokenController extends BaseController
{
    public function issue()
    {
        $in     = $this->input();
        $userId = $this->currentUserId('user_id');
        $orgId  = $this->orgId();
        if ($userId === '') {
            return $this->respondWith(Result::denied('identity.unauthenticated', 'UNAUTHENTICATED'));
        }

        return $this->respondWith(IdentityServices::tokens()->issue(
            $userId,
            $orgId,
            (array) ($in['scopes'] ?? []),
            $in['name'] ?? null,
        ));
    }

    public function refresh()
    {
        $in    = $this->input();
        $token = (string) ($in['refresh_token'] ?? '');
        if ($token === '') {
            return $this->respondWith(Result::fail('MISSING_TOKEN', 'token.missing', 400));
        }

        return $this->respondWith(IdentityServices::tokens()->refresh($token));
    }

    public function revoke(string $tokenId = '')
    {
        return $this->respondWith(IdentityServices::tokens()->revokeAccessToken($tokenId));
    }

    public function revokeAll()
    {
        $userId = $this->currentUserId('user_id');
        if ($userId === '') {
            return $this->respondWith(Result::denied('identity.unauthenticated', 'UNAUTHENTICATED'));
        }

        return $this->respondWith(IdentityServices::tokens()->revokeAllForUser($userId));
    }

    public function list()
    {
        $userId = $this->currentUserId('user_id');
        if ($userId === '') {
            return $this->respondWith(Result::denied('identity.unauthenticated', 'UNAUTHENTICATED'));
        }

        return $this->respondPage(
            Result::ok(IdentityServices::tokens()->listAccessTokens($userId)),
            'identity_tokens',
            static fn (array $d): array => ['rows' => array_is_list($d) ? $d : ($d['tokens'] ?? [])],
        );
    }
}
