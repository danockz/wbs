<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;

/**
 * Facebook Live adapter (Graph API — live_videos).
 *
 * Uses a long-lived Page access token stored in CredentialVault slot
 * 'facebook_page_token'; the target Page id is a non-secret stored in the
 * destination capabilities. The RTMP stream URL Facebook returns embeds a
 * secret key, so it is stored via the vault, not returned.
 */
final class FacebookProvider implements StreamProvider
{
    private const API = 'https://graph.facebook.com/v18.0';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
    ) {
    }

    public function code(): string
    {
        return 'facebook';
    }

    public function createBroadcast(array $stream, array $destination): BroadcastResult
    {
        $connId = (string) ($destination['connection_id'] ?? '');
        $pageId = $this->pageId($destination);

        return $this->withToken($connId, function (string $token) use ($stream, $pageId, $connId): BroadcastResult {
            $resp = $this->http->request('POST', self::API . '/' . $pageId . '/live_videos', [
                'query' => ['access_token' => $token],
                'json'  => [
                    'status'      => 'UNPUBLISHED',
                    'title'       => (string) ($stream['title'] ?? 'Live'),
                    'description' => (string) ($stream['description'] ?? ''),
                ],
            ]);

            $videoId    = (string) ($resp['id'] ?? '');
            $streamUrl  = (string) ($resp['stream_url'] ?? ''); // contains a secret key
            if ($streamUrl !== '' && $connId !== '') {
                $this->vault->put($connId, 'facebook_stream_url', $streamUrl);
            }

            return new BroadcastResult(
                externalRef: $videoId,
                ingestUrl: null, // secret ingest stored in the vault
                watchUrl: $videoId !== '' ? 'https://www.facebook.com/' . $videoId : null,
                meta: ['ingest_stored' => $streamUrl !== ''],
            );
        });
    }

    public function startBroadcast(array $destination): BroadcastResult
    {
        return $this->setStatus($destination, 'LIVE_NOW');
    }

    public function endBroadcast(array $destination): BroadcastResult
    {
        return $this->setStatus($destination, 'STOP');
    }

    public function fetchAnalytics(array $destination): array
    {
        $connId  = (string) ($destination['connection_id'] ?? '');
        $videoId = (string) ($destination['external_ref'] ?? '');
        if ($videoId === '') {
            return [['metric' => 'viewers', 'value' => null, 'exactness' => 'unavailable']];
        }

        return $this->withToken($connId, function (string $token) use ($videoId): array {
            $resp    = $this->http->request('GET', self::API . '/' . $videoId, [
                'query' => ['fields' => 'live_views', 'access_token' => $token],
            ]);
            $viewers = $resp['live_views'] ?? null;

            return [[
                'metric'    => 'concurrent_viewers',
                'value'     => $viewers !== null ? (float) $viewers : null,
                'exactness' => $viewers !== null ? 'estimated' : 'unavailable',
            ]];
        });
    }

    private function setStatus(array $destination, string $status): BroadcastResult
    {
        $connId  = (string) ($destination['connection_id'] ?? '');
        $videoId = (string) ($destination['external_ref'] ?? '');
        if ($videoId === '') {
            throw new ProviderException('missing live video external_ref', 0, $this->code());
        }

        return $this->withToken($connId, function (string $token) use ($videoId, $status): BroadcastResult {
            $this->http->request('POST', self::API . '/' . $videoId, [
                'query' => ['access_token' => $token],
                'json'  => ['status' => $status],
            ]);

            return new BroadcastResult(externalRef: $videoId, meta: ['status' => $status]);
        });
    }

    private function pageId(array $destination): string
    {
        $caps = $destination['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }
        $id = is_array($caps) ? (string) ($caps['page_id'] ?? '') : '';
        if ($id === '') {
            throw new ProviderException('facebook page_id missing from destination capabilities', 0, $this->code());
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
            throw new ProviderException('facebook connection not configured', 0, $this->code());
        }

        $result = $this->vault->useSecret($connectionId, 'facebook_page_token', static fn (string $token) => $op($token));

        if ($result === null) {
            throw new ProviderException('facebook page token not found', 0, $this->code());
        }

        return $result;
    }
}
