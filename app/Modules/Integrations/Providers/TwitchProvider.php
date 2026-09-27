<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;

/**
 * Twitch (Helix API) adapter.
 *
 * Twitch has no "create broadcast" call — a channel goes live when the encoder
 * pushes to the ingest server using the channel's stream key. So this adapter:
 *  - createBroadcast: reads the channel's stream key (Helix) and stores it as a
 *    secret; external_ref is the broadcaster user id (non-secret).
 *  - start/end: no-ops (the encoder controls liveness).
 *  - fetchAnalytics: reads current viewer count from GET /streams.
 *
 * Token model: an OAuth user access token (slot 'twitch_access') plus the app
 * client id are required for Helix; refresh (slot 'twitch_refresh') mints a new
 * access token when needed. All tokens are used inside vault closures.
 */
final class TwitchProvider implements StreamProvider
{
    private const API   = 'https://api.twitch.tv/helix';
    private const TOKEN = 'https://id.twitch.tv/oauth2/token';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function code(): string
    {
        return 'twitch';
    }

    public function createBroadcast(array $stream, array $destination): BroadcastResult
    {
        $connId = (string) ($destination['connection_id'] ?? '');

        return $this->withToken($connId, function (string $token) use ($connId, $destination): BroadcastResult {
            $broadcasterId = $this->broadcasterId($destination);
            $headers       = $this->helixHeaders($token);

            $resp = $this->http->request('GET', self::API . '/streams/key', [
                'headers' => $headers,
                'query'   => ['broadcaster_id' => $broadcasterId],
            ]);
            $key = (string) ($resp['data'][0]['stream_key'] ?? '');
            if ($key !== '' && $connId !== '') {
                $this->vault->put($connId, 'twitch_stream_key', $key);
            }

            return new BroadcastResult(
                externalRef: $broadcasterId,
                ingestUrl: 'rtmp://live.twitch.tv/app',
                watchUrl: null,
                meta: ['key_stored' => $key !== ''],
            );
        });
    }

    public function startBroadcast(array $destination): BroadcastResult
    {
        return new BroadcastResult(
            externalRef: (string) ($destination['external_ref'] ?? ''),
            meta: ['note' => 'twitch is live when the encoder connects'],
        );
    }

    public function endBroadcast(array $destination): BroadcastResult
    {
        return new BroadcastResult(
            externalRef: (string) ($destination['external_ref'] ?? ''),
            meta: ['note' => 'twitch ends when the encoder disconnects'],
        );
    }

    public function fetchAnalytics(array $destination): array
    {
        $connId        = (string) ($destination['connection_id'] ?? '');
        $broadcasterId = (string) ($destination['external_ref'] ?? '');
        if ($broadcasterId === '') {
            return [['metric' => 'viewers', 'value' => null, 'exactness' => 'unavailable']];
        }

        return $this->withToken($connId, function (string $token) use ($broadcasterId): array {
            $resp    = $this->http->request('GET', self::API . '/streams', [
                'headers' => $this->helixHeaders($token),
                'query'   => ['user_id' => $broadcasterId],
            ]);
            $live    = $resp['data'][0] ?? null;
            $viewers = $live['viewer_count'] ?? null;

            return [[
                'metric'    => 'concurrent_viewers',
                'value'     => $viewers !== null ? (float) $viewers : null,
                'exactness' => $viewers !== null ? 'exact' : 'unavailable',
            ]];
        });
    }

    /** @return array<string,string> */
    private function helixHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Client-Id'     => $this->clientId,
        ];
    }

    private function broadcasterId(array $destination): string
    {
        $caps = $destination['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }
        $id = is_array($caps) ? (string) ($caps['broadcaster_id'] ?? '') : '';
        if ($id === '') {
            throw new ProviderException('twitch broadcaster_id missing from destination capabilities', 0, $this->code());
        }

        return $id;
    }

    /**
     * @template T
     * @param callable(string):T $op
     * @return T
     */
    private function withToken(string $connectionId, callable $op): mixed
    {
        if ($connectionId === '') {
            throw new ProviderException('twitch connection not configured', 0, $this->code());
        }

        $result = $this->vault->useSecret($connectionId, 'twitch_refresh', function (string $refresh) use ($op) {
            $token  = $this->http->request('POST', self::TOKEN, [
                'form' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ],
            ]);
            $access = (string) ($token['access_token'] ?? '');
            if ($access === '') {
                throw new ProviderException('twitch token refresh returned no access_token', 0, $this->code());
            }

            return $op($access);
        });

        if ($result === null) {
            throw new ProviderException('twitch refresh credential not found', 0, $this->code());
        }

        return $result;
    }
}
