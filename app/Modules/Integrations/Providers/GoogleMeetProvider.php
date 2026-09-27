<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

use WBS\Integrations\Services\CredentialVault;

/**
 * Google Meet adapter. A Meet link is created by inserting a Calendar event with
 * a conferenceData request (same Google OAuth as YouTube; scope
 * calendar.events). Uses the OAuth refresh token in slot 'googlemeet_refresh';
 * the target calendar id is a non-secret in the capabilities bag.
 */
final class GoogleMeetProvider implements MeetingProvider
{
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const API   = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly ProviderHttp $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function code(): string
    {
        return 'meet';
    }

    public function createMeeting(array $meeting): MeetingResult
    {
        $connId = (string) ($meeting['connection_id'] ?? '');
        if ($connId === '') {
            throw new ProviderException('google meet connection not configured', 0, $this->code());
        }
        $calendarId = $this->calendarId($meeting);

        $result = $this->vault->useSecret($connId, 'googlemeet_refresh', function (string $refresh) use ($meeting, $calendarId): MeetingResult {
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
                throw new ProviderException('google token refresh returned no access_token', 0, $this->code());
            }

            $start = $this->iso8601($meeting['starts_at'] ?? null);
            $end   = $this->iso8601($meeting['ends_at'] ?? null, '+1 hour');
            $resp  = $this->http->request('POST', self::API . '/calendars/' . rawurlencode($calendarId) . '/events', [
                'headers' => ['Authorization' => 'Bearer ' . $access],
                'query'   => ['conferenceDataVersion' => '1'],
                'json'    => [
                    'summary'        => (string) ($meeting['title'] ?? 'Meeting'),
                    'start'          => ['dateTime' => $start],
                    'end'            => ['dateTime' => $end],
                    'conferenceData' => [
                        'createRequest' => [
                            'requestId'             => bin2hex(random_bytes(8)),
                            'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                        ],
                    ],
                ],
            ]);

            $eventId = (string) ($resp['id'] ?? '');
            $joinUrl = (string) ($resp['hangoutLink'] ?? '');

            return new MeetingResult(
                externalRef: $eventId,
                joinUrl: $joinUrl !== '' ? $joinUrl : null,
                meta: ['calendar_id' => $calendarId],
            );
        });

        if ($result === null) {
            throw new ProviderException('google meet refresh credential not found', 0, $this->code());
        }

        return $result;
    }

    private function calendarId(array $meeting): string
    {
        $caps = $meeting['capabilities'] ?? null;
        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }
        $id = is_array($caps) ? (string) ($caps['calendar_id'] ?? '') : '';

        return $id !== '' ? $id : 'primary';
    }

    private function iso8601(mixed $at, string $fallbackModifier = 'now'): string
    {
        if (is_string($at) && $at !== '') {
            return gmdate('Y-m-d\TH:i:s\Z', strtotime($at));
        }

        return gmdate('Y-m-d\TH:i:s\Z', strtotime($fallbackModifier));
    }
}
