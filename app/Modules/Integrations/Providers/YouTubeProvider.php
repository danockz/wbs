<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;

/**
 * YouTube Live adapter (YouTube Data API v3 — liveBroadcasts / liveStreams).
 *
 * Adapted from the source spec's YouTube methods to WBS conventions:
 *  - The OAuth refresh token lives in CredentialVault slot 'youtube_refresh';
 *    an access token is obtained per operation and used only within the
 *    useSecret() closure — it is never returned or stored on a row.
 *  - App client id/secret come from platform env (app-level, not per-group).
 *  - All HTTP goes through ProviderHttp, which surfaces YouTube's own error.
 *
 * NOTE: real API calls require network + valid credentials, which this sandbox
 * lacks; the adapter is structurally validated here and exercised in CI/staging.
 */
final class YouTubeProvider implements StreamProvider
{
    private const API   = 'https://www.googleapis.com/youtube/v3';
    private const TOKEN = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function code(): string
    {
        return 'youtube';
    }

    public function createBroadcast(array $stream, array $destination): BroadcastResult
    {
        $connId = (string) ($destination['connection_id'] ?? '');

        return $this->withToken($connId, function (string $token) use ($stream): BroadcastResult {
            $headers = ['Authorization' => 'Bearer ' . $token];

            // 1. Create the liveBroadcast (the "event").
            $broadcast = $this->http->request('POST', self::API . '/liveBroadcasts', [
                'headers' => $headers,
                'query'   => ['part' => 'snippet,status,contentDetails'],
                'json'    => [
                    'snippet' => [
                        'title'              => (string) ($stream['title'] ?? 'Live'),
                        'scheduledStartTime' => $this->scheduledStart($stream),
                    ],
                    'status' => [
                        'privacyStatus'          => 'unlisted',
                        'selfDeclaredMadeForKids' => false,
                    ],
                    'contentDetails' => ['enableAutoStart' => true, 'enableAutoStop' => true],
                ],
            ]);

            // 2. Create the liveStream (the ingest endpoint).
            $liveStream = $this->http->request('POST', self::API . '/liveStreams', [
                'headers' => $headers,
                'query'   => ['part' => 'snippet,cdn'],
                'json'    => [
                    'snippet' => ['title' => (string) ($stream['title'] ?? 'Live') . ' ingest'],
                    'cdn'     => ['frameRate' => 'variable', 'ingestionType' => 'rtmp', 'resolution' => 'variable'],
                ],
            ]);

            // 3. Bind them.
            $broadcastId = (string) ($broadcast['id'] ?? '');
            $streamId    = (string) ($liveStream['id'] ?? '');
            $this->http->request('POST', self::API . '/liveBroadcasts/bind', [
                'headers' => $headers,
                'query'   => ['id' => $broadcastId, 'part' => 'id,contentDetails', 'streamId' => $streamId],
            ]);

            $ingestion = $liveStream['cdn']['ingestionInfo'] ?? [];
            $ingestUrl = isset($ingestion['ingestionAddress']) ? (string) $ingestion['ingestionAddress'] : null;

            // The ingest stream NAME is a secret → store, don't return.
            if (isset($ingestion['streamName']) && $destination['connection_id'] !== null) {
                $this->vault->put((string) $destination['connection_id'], 'youtube_stream_name', (string) $ingestion['streamName']);
            }

            return new BroadcastResult(
                externalRef: $broadcastId,
                ingestUrl: $ingestUrl,
                watchUrl: $broadcastId !== '' ? 'https://www.youtube.com/watch?v=' . $broadcastId : null,
                meta: ['live_stream_id' => $streamId],
            );
        });
    }

    public function startBroadcast(array $destination): BroadcastResult
    {
        return $this->transition($destination, 'live');
    }

    public function endBroadcast(array $destination): BroadcastResult
    {
        return $this->transition($destination, 'complete');
    }

    public function fetchAnalytics(array $destination): array
    {
        $connId      = (string) ($destination['connection_id'] ?? '');
        $broadcastId = (string) ($destination['external_ref'] ?? '');
        if ($broadcastId === '') {
            return [['metric' => 'viewers', 'value' => null, 'exactness' => 'unavailable']];
        }

        return $this->withToken($connId, function (string $token) use ($broadcastId): array {
            $resp = $this->http->request('GET', self::API . '/videos', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['part' => 'liveStreamingDetails', 'id' => $broadcastId],
            ]);
            $details = $resp['items'][0]['liveStreamingDetails'] ?? [];
            $current = $details['concurrentViewers'] ?? null;

            return [[
                'metric'    => 'concurrent_viewers',
                'value'     => $current !== null ? (float) $current : null,
                'exactness' => $current !== null ? 'exact' : 'unavailable',
            ]];
        });
    }

    private function transition(array $destination, string $status): BroadcastResult
    {
        $connId      = (string) ($destination['connection_id'] ?? '');
        $broadcastId = (string) ($destination['external_ref'] ?? '');
        if ($broadcastId === '') {
            throw new ProviderException('missing broadcast external_ref', 0, $this->code());
        }

        return $this->withToken($connId, function (string $token) use ($broadcastId, $status): BroadcastResult {
            $this->http->request('POST', self::API . '/liveBroadcasts/transition', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query'   => ['broadcastStatus' => $status, 'id' => $broadcastId, 'part' => 'id,status'],
            ]);

            return new BroadcastResult(externalRef: $broadcastId, meta: ['status' => $status]);
        });
    }

    /**
     * Obtain a fresh access token from the stored refresh token and hand it to
     * $op. The refresh token never leaves the vault closure.
     *
     * @template T
     * @param callable(string):T $op
     * @return T
     */
    private function withToken(string $connectionId, callable $op): mixed
    {
        if ($connectionId === '') {
            throw new ProviderException('youtube connection not configured', 0, $this->code());
        }

        $result = $this->vault->useSecret($connectionId, 'youtube_refresh', function (string $refresh) use ($op) {
            $token = $this->http->request('POST', self::TOKEN, [
                'form' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ],
            ]);
            $access = (string) ($token['access_token'] ?? '');
            if ($access === '') {
                throw new ProviderException('youtube token refresh returned no access_token', 0, $this->code());
            }

            return $op($access);
        });

        if ($result === null) {
            throw new ProviderException('youtube refresh credential not found', 0, $this->code());
        }

        return $result;
    }

    /** @param array<string,mixed> $stream */
    private function scheduledStart(array $stream): string
    {
        $at = $stream['scheduled_at'] ?? null;
        if (is_string($at) && $at !== '') {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($at));
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
