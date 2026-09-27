<?php

declare(strict_types=1);

namespace WBS\Integrations\Providers;

/**
 * Static, non-secret OAuth endpoint/scope metadata per streaming provider.
 *
 * This holds ONLY public constants (authorize/token URLs, scopes, the vault slot
 * a refresh token lands in). Client ids/secrets are NOT here — they come from
 * env at call time. Mirrors the scope note documented in the source spec.
 */
final class OAuthProviderConfig
{
    /**
     * @return array{
     *   authorize:string, token:string, scope:string,
     *   refresh_slot:string, access_type?:string, extra?:array<string,string>
     * }|null
     */
    public static function for(string $provider): ?array
    {
        return match (strtolower($provider)) {
            'youtube' => [
                'authorize'    => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token'        => 'https://oauth2.googleapis.com/token',
                'scope'        => 'https://www.googleapis.com/auth/youtube',
                'refresh_slot' => 'youtube_refresh',
                'access_type'  => 'offline',
                'extra'        => ['prompt' => 'consent'],
            ],
            'googlemeet' => [
                'authorize'    => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token'        => 'https://oauth2.googleapis.com/token',
                'scope'        => 'https://www.googleapis.com/auth/calendar.events',
                'refresh_slot' => 'googlemeet_refresh',
                'access_type'  => 'offline',
                'extra'        => ['prompt' => 'consent'],
            ],
            'twitch' => [
                'authorize'    => 'https://id.twitch.tv/oauth2/authorize',
                'token'        => 'https://id.twitch.tv/oauth2/token',
                'scope'        => 'channel:manage:broadcast channel:read:stream_key',
                'refresh_slot' => 'twitch_refresh',
            ],
            'facebook' => [
                'authorize'    => 'https://www.facebook.com/v18.0/dialog/oauth',
                'token'        => 'https://graph.facebook.com/v18.0/oauth/access_token',
                'scope'        => 'pages_manage_posts pages_show_list publish_video',
                'refresh_slot' => 'facebook_page_token',
            ],
            'gotowebinar' => [
                'authorize'    => 'https://api.getgo.com/oauth/v2/authorize',
                'token'        => 'https://api.getgo.com/oauth/v2/token',
                'scope'        => '',
                'refresh_slot' => 'gotowebinar_refresh',
            ],
            default => null,
        };
    }

    /**
     * The set of streaming/meeting providers that support the OAuth consent
     * flow. Kept beside {@see self::for()} so the two never drift.
     *
     * @return list<string>
     */
    public static function providers(): array
    {
        return ['youtube', 'googlemeet', 'twitch', 'facebook', 'gotowebinar'];
    }

    /** Whether a provider supports the OAuth consent flow. */
    public static function supports(string $provider): bool
    {
        return self::for($provider) !== null;
    }

    /** True when both client id and secret env vars are populated for a provider. */
    public static function isConfigured(string $provider): bool
    {
        [$idEnv, $secretEnv] = self::clientEnv($provider);

        return (string) (getenv($idEnv) ?: '') !== '' && (string) (getenv($secretEnv) ?: '') !== '';
    }

    /** Env var names for a provider's client id/secret. */
    public static function clientEnv(string $provider): array
    {
        $p = strtoupper($provider);

        return ["{$p}_CLIENT_ID", "{$p}_CLIENT_SECRET"];
    }
}
