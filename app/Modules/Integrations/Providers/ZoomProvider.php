<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;

/**
 * Zoom adapter using Server-to-Server OAuth (account_credentials grant) — no
 * per-group consent step, per the source spec's scope note.
 *
 * Secrets in the vault (per connection): 'zoom_account_id', 'zoom_client_id',
 * 'zoom_client_secret'. The host user's Zoom id/email is a non-secret in the
 * meeting capabilities bag. A returned meeting password is stored via the vault.
 */
final class ZoomProvider implements MeetingProvider
{
    private const TOKEN = 'https://zoom.us/oauth/token';
    private const API   = 'https://api.zoom.us/v2';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
    ) {
    }

    public function code(): string
    {
        return 'zoom';
    }

    public function createMeeting(array $meeting): MeetingResult
    {
        $connId = (string) ($meeting['connection_id'] ?? '');
        if ($connId === '') {
            throw new ProviderException('zoom connection not configured', 0, $this->code());
        }
        $host = $this->host($meeting);

        $token = $this->accessToken($connId);

        $type = ($meeting['mode'] ?? 'meeting') === 'webinar' ? 'webinars' : 'meetings';
        $resp = $this->http->request('POST', self::API . '/users/' . rawurlencode($host) . '/' . $type, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'json'    => [
                'topic'      => (string) ($meeting['title'] ?? 'Meeting'),
                'type'       => 2, // scheduled
                'start_time' => $this->iso8601($meeting['starts_at'] ?? null),
                'settings'   => ['join_before_host' => false, 'waiting_room' => true],
            ],
        ]);

        $meetingId = (string) ($resp['id'] ?? '');
        $joinUrl   = (string) ($resp['join_url'] ?? '');
        $password  = (string) ($resp['password'] ?? '');
        if ($password !== '') {
            $this->vault->put($connId, 'zoom_meeting_password:' . $meetingId, $password);
        }

        return new MeetingResult(
            externalRef: $meetingId,
            joinUrl: $joinUrl !== '' ? $joinUrl : null,
            meta: ['password_stored' => $password !== '', 'type' => $type],
        );
    }

    private function accessToken(string $connId): string
    {
        $accountId = $this->vault->useSecret($connId, 'zoom_account_id', static fn (string $v) => $v);
        $clientId  = $this->vault->useSecret($connId, 'zoom_client_id', static fn (string $v) => $v);
        if ($accountId === null || $clientId === null) {
            throw new ProviderException('zoom S2S credentials not found', 0, $this->code());
        }

        $token = $this->vault->useSecret($connId, 'zoom_client_secret', function (string $secret) use ($accountId, $clientId): array {
            return $this->http->request('POST', self::TOKEN, [
                'headers' => ['Authorization' => 'Basic ' . base64_encode($clientId . ':' . $secret)],
                'query'   => ['grant_type' => 'account_credentials', 'account_id' => $accountId],
            ]);
        });
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new ProviderException('zoom token request returned no access_token', 0, $this->code());
        }

        return $access;
    }

    private function host(array $meeting): string
    {
        $caps = $meeting['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }
        $host = is_array($caps) ? (string) ($caps['host'] ?? '') : '';

        return $host !== '' ? $host : 'me';
    }

    private function iso8601(mixed $at): string
    {
        if (is_string($at) && $at !== '') {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($at));
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
