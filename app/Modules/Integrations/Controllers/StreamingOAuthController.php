<?php

declare(strict_types=1);

namespace WBS\Integrations\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use WBS\Integrations\Config\Services as IntegrationServices;
use WBS\Integrations\Providers\OAuthProviderConfig;
use WBS\Shared\Http\BaseController;
use WBS\Shared\Support\Result;

/**
 * Browser-redirect half of the streaming-provider OAuth consent flow (S7).
 *
 * The library the platform adapted deliberately left this to a controller,
 * because it needs a route and a session-bound `state`. Flow:
 *
 *   GET  /integrations/oauth
 *        -> the consent dashboard: every stream/meeting connection whose adapter
 *           supports OAuth, with a "grant access" control (and whether the
 *           provider's client credentials are configured in this environment)
 *   POST /integrations/oauth/{provider}/authorize?connection_id=…
 *        -> stashes state in the session; a browser is REDIRECTED to the provider
 *           consent screen, an API client gets { authorize_url }
 *   GET  /integrations/oauth/{provider}/callback?code=…&state=…
 *        -> validates state, exchanges code, stores refresh token in the vault;
 *           a browser is PRG-redirected back to the dashboard with a flash
 *
 * The redirect URI registered with each provider must point at the callback.
 * No token or secret is ever rendered — the vault write is confirmed by a flash.
 */
final class StreamingOAuthController extends BaseController
{
    /** GET oauth — the consent dashboard. */
    public function index()
    {
        $connections = IntegrationServices::connections()->listForOrg($this->orgId());
        // Only stream/meeting connections whose adapter supports the OAuth flow
        // are consent candidates; annotate each with provider config readiness.
        $candidates = [];
        foreach ($connections as $c) {
            $provider = strtolower((string) ($c['adapter_code'] ?? ''));
            if (! OAuthProviderConfig::supports($provider)) {
                continue;
            }
            $c['oauth_provider'] = $provider;
            $c['oauth_ready']    = OAuthProviderConfig::isConfigured($provider);
            $candidates[]        = $c;
        }

        if ($this->wantsJson()) {
            return $this->respondJson(Result::ok([
                'connections' => $candidates,
                'providers'   => OAuthProviderConfig::providers(),
            ]));
        }

        return $this->respondWith(
            Result::ok(['connections' => $candidates]),
            'WBS\Integrations\Views\oauth',
            null,
            [
                'connections' => $candidates,
                'csrf'        => (string) ($this->request->wbsCsrf ?? ''),
            ],
        );
    }

    public function authorize(string $provider = '')
    {
        $in           = $this->input();
        $connectionId = (string) ($in['connection_id'] ?? '');
        if ($connectionId === '') {
            return $this->respondAuthorize(
                Result::fail('CONNECTION_REQUIRED', 'oauth.connection_required', 422),
            );
        }

        $result = IntegrationServices::streamingOAuth()->beginAuthorization(
            $provider,
            $connectionId,
            $this->callbackUri($provider),
        );

        if ($result->ok) {
            // Bind the state to this connection/provider for the callback.
            session()->set('oauth_stream', [
                'state'         => $result->data['state'],
                'connection_id' => $connectionId,
                'provider'      => strtolower($provider),
            ]);
        }

        return $this->respondAuthorize($result);
    }

    public function callback(string $provider = '')
    {
        $in    = $this->input();
        $code  = (string) ($in['code'] ?? '');
        $state = (string) ($in['state'] ?? '');
        $bound = session()->get('oauth_stream');
        session()->remove('oauth_stream'); // single-use

        if ($code === '' || $state === '') {
            return $this->respondCallback(Result::fail('BAD_CALLBACK', 'oauth.bad_callback', 422));
        }
        if (! is_array($bound)
            || ! hash_equals((string) ($bound['state'] ?? ''), $state)
            || strtolower($provider) !== (string) ($bound['provider'] ?? '')) {
            return $this->respondCallback(Result::denied('oauth.state_mismatch', 'STATE_MISMATCH'));
        }

        return $this->respondCallback(IntegrationServices::streamingOAuth()->completeAuthorization(
            $provider,
            (string) $bound['connection_id'],
            $code,
            $this->callbackUri($provider),
        ));
    }

    /**
     * A browser authorize is a REDIRECT to the provider consent screen (or back to
     * the dashboard with the failure message); API clients keep the JSON payload
     * (including `authorize_url`).
     */
    private function respondAuthorize(Result $result): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        if (! $result->ok) {
            return redirect()->to('/integrations/oauth')->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to((string) ($result->data['authorize_url'] ?? '/integrations/oauth'));
    }

    /**
     * The callback is a PRG back to the consent dashboard for browsers (a localized
     * success/failure flash), and the raw Result for API clients.
     */
    private function respondCallback(Result $result): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->respondJson($result);
        }

        $to = '/integrations/oauth';
        if (! $result->ok) {
            return redirect()->to($to)->with('error', $this->errText((string) $result->message));
        }

        return redirect()->to($to)->with('success', lang('Integrations.oauth.connectedFlash'));
    }

    private function callbackUri(string $provider): string
    {
        return base_url('integrations/oauth/' . strtolower($provider) . '/callback');
    }
}
