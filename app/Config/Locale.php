<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Localization policy (Phase 1 language-awareness).
 *
 * Single source of truth for: the supported-locale allowlist (the security clamp
 * for ?lang= and the intersection set for Accept-Language / geo detection), which
 * locales are right-to-left, how the visitor's country is discovered from the
 * network/edge, and the country -> locale map used for geo auto-detection.
 *
 * Keep this in sync with Config\App::$supportedLocales (LocaleFilter asserts it).
 */
class Locale extends BaseConfig
{
    /**
     * Locales the app can actually serve (has, or will have, strings for). Order
     * is significant: earlier = higher priority when scoring Accept-Language ties.
     * BCP-47 primary subtags; regional variants (fr-CA) fall back to the base.
     *
     * @var list<string>
     */
    public array $supported = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];

    /** The guaranteed fallback; must be present in $supported. */
    public string $default = 'en';

    /**
     * Right-to-left locales — drives <html dir="rtl"> and mirrored layout.
     *
     * @var list<string>
     */
    public array $rtl = ['ar', 'he', 'fa', 'ur'];

    /**
     * Cookie that carries the resolved/chosen locale across requests. Written by
     * the switcher and at login (from the DB preference) so the hot-path filter
     * never needs a query. Not HttpOnly — harmless and lets client code read it.
     */
    public string $cookieName = 'wbs_locale';

    /** Cookie lifetime in seconds (1 year). */
    public int $cookieLifetime = 31_536_000;

    /** Query parameter for a one-off manual switch (e.g. ?lang=fr). */
    public string $queryParam = 'lang';

    /**
     * Ordered list of request headers that may carry the visitor's ISO-3166
     * alpha-2 country, set by an edge/CDN or reverse proxy. First non-empty wins.
     * All configurable so the same build works behind Cloudflare, a generic
     * proxy, or nothing. Values are upper-cased and validated before use.
     *
     * @var list<string>
     */
    public array $countryHeaders = [
        'CF-IPCountry',      // Cloudflare
        'X-Geo-Country',     // generic reverse-proxy / CDN convention
        'X-Country-Code',
        'X-AppEngine-Country',
    ];

    /** Master switch for network/geo auto-detection (browser negotiation is separate). */
    public bool $geoDetection = true;

    /**
     * ISO-3166 alpha-2 country -> locale. Only entries whose locale is in
     * $supported take effect (others are ignored at resolve time), so it is safe
     * to keep a broad map. Countries not listed simply skip the geo step.
     *
     * @var array<string,string>
     */
    public array $countryLocale = [
        // English-majority / default
        'GB' => 'en', 'US' => 'en', 'GH' => 'en', 'NG' => 'en', 'KE' => 'en',
        'ZA' => 'en', 'AU' => 'en', 'CA' => 'en', 'IE' => 'en', 'NZ' => 'en',
        // French
        'FR' => 'fr', 'BE' => 'fr', 'CI' => 'fr', 'SN' => 'fr', 'CD' => 'fr',
        'CM' => 'fr', 'ML' => 'fr', 'BF' => 'fr', 'NE' => 'fr', 'TG' => 'fr',
        'BJ' => 'fr', 'HT' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
        // Spanish
        'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CO' => 'es', 'PE' => 'es',
        'CL' => 'es', 'VE' => 'es', 'EC' => 'es', 'GT' => 'es', 'CU' => 'es',
        'BO' => 'es', 'DO' => 'es', 'HN' => 'es', 'PY' => 'es', 'SV' => 'es',
        'NI' => 'es', 'CR' => 'es', 'PA' => 'es', 'UY' => 'es',
        // Portuguese
        'PT' => 'pt', 'BR' => 'pt', 'AO' => 'pt', 'MZ' => 'pt', 'CV' => 'pt',
        'GW' => 'pt', 'ST' => 'pt', 'TL' => 'pt',
        // Chinese
        'CN' => 'zh', 'TW' => 'zh', 'HK' => 'zh', 'MO' => 'zh', 'SG' => 'zh',
        // Arabic
        'SA' => 'ar', 'EG' => 'ar', 'AE' => 'ar', 'DZ' => 'ar', 'IQ' => 'ar',
        'MA' => 'ar', 'SD' => 'ar', 'SY' => 'ar', 'TN' => 'ar', 'JO' => 'ar',
        'LB' => 'ar', 'LY' => 'ar', 'OM' => 'ar', 'KW' => 'ar', 'QA' => 'ar',
        'BH' => 'ar', 'YE' => 'ar', 'PS' => 'ar',
    ];
}
