<?php

declare(strict_types=1);

namespace WBS\Integrations\Services;

use WBS\Integrations\Providers\OAuthProviderConfig;
use WBS\Integrations\Providers\ProviderException;
use WBS\Integrations\Providers\ProviderHttp;
use WBS\Shared\Support\Result;

/**
 * Server side of the streaming-provider OAuth CONSENT flow (S7) — the
 * browser-redirect half the source library explicitly did NOT implement.
 *
 *  - beginAuthorization(): builds the provider authorize URL and a CSRF-strong,
 *    single-use `state` that binds the redirect to the initiating connection.
 *    The caller stores {state => connectionId/provider} in the session.
 *  - completeAuthorization(): validates state, exchanges the code for tokens,
 *    and persists the refresh token via CredentialVault (write-only). The
 *    plaintext token is never returned to the caller.
 *
 * Client id/secret come from env; only NON-secret metadata lives in code.
 */
final class StreamingOAuthService
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
    ) {
    }

    /**
     * Build the authorize URL + opaque state for a provider consent redirect.
     */
    public function beginAuthorization(string $provider, string $connectionId, string $redirectUri): Result
    {
        $cfg = OAuthProviderConfig::for($provider);
        if ($cfg === null) {
            return Result::fail('BAD_PROVIDER', 'oauth.bad_provider', 422);
        }
        [$idEnv] = OAuthProviderConfig::clientEnv($provider);
        $clientId = (string) (getenv($idEnv) ?: '');
        if ($clientId === '') {
            return Result::fail('NOT_CONFIGURED', 'oauth.client_not_configured', 409);
        }

        $state = bin2hex(random_bytes(24));
        $query = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => $cfg['scope'],
            'state'         => $state,
        ];
        if (isset($cfg['access_type'])) {
            $query['access_type'] = $cfg['access_type'];
        }
        foreach ($cfg['extra'] ?? [] as $k => $v) {
            $query[$k] = $v;
        }

        return Result::ok([
            'authorize_url' => $cfg['authorize'] . '?' . http_build_query($query),
            'state'         => $state,
            'connection_id' => $connectionId,
            'provider'      => strtolower($provider),
        ]);
    }

    /**
     * Exchange an authorization code for tokens and store the refresh token.
     * `state` and `connectionId`/`provider` must have been validated against the
     * session BEFORE calling this (the controller does that).
     */
    public function completeAuthorization(
        string $provider,
        string $connectionId,
        string $code,
        string $redirectUri,
    ): Result {
        $cfg = OAuthProviderConfig::for($provider);
        if ($cfg === null) {
            return Result::fail('BAD_PROVIDER', 'oauth.bad_provider', 422);
        }
        [$idEnv, $secretEnv] = OAuthProviderConfig::clientEnv($provider);
        $clientId     = (string) (getenv($idEnv) ?: '');
        $clientSecret = (string) (getenv($secretEnv) ?: '');
        if ($clientId === '' || $clientSecret === '') {
            return Result::fail('NOT_CONFIGURED', 'oauth.client_not_configured', 409);
        }

        try {
            $token = $this->http->request('POST', $cfg['token'], [
                'form' => [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirectUri,
                ],
            ]);
        } catch (ProviderException $e) {
            return Result::fail('TOKEN_EXCHANGE_FAILED', $e->getMessage(), 502);
        }

        // Prefer a refresh token; some providers (Facebook) return a long-lived
        // access token instead, which we store in the same slot.
        $secret = (string) ($token['refresh_token'] ?? $token['access_token'] ?? '');
        if ($secret === '') {
            return Result::fail('NO_TOKEN', 'oauth.no_token_returned', 502);
        }

        $store = $this->vault->put($connectionId, $cfg['refresh_slot'], $secret);
        if ($store->failed()) {
            return $store;
        }

        return Result::ok([
            'connection_id' => $connectionId,
            'provider'      => strtolower($provider),
            'slot'          => $cfg['refresh_slot'],
            'stored'        => true,
        ]);
    }
}
