<?php

declare(strict_types=1);

namespace WBS\Shared\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Locale as LocaleConfig;
use WBS\Shared\I18n\LocaleResolver;

/**
 * Resolves and applies the request locale (Phase 1 language-awareness).
 *
 * Registered as a GLOBAL `before` filter, so it runs before the per-route
 * AuthFilter — which means `wbsUserId` is NOT available here. To honor the
 * platform's resource-light rule the hot path is DB-FREE: it reads only the
 * `wbs_locale` cookie, the `?lang=` switch, an edge country header, and
 * Accept-Language. The signed-in preference (user_preferences.locale) and the
 * org default are synced INTO the cookie at login and in the switcher endpoint,
 * so they still take effect without a per-request query.
 *
 * Effects:
 *   - service('request')->setLocale($locale)      (drives lang() + CI4 fallback)
 *   - $request->wbsLocale / $request->wbsLocaleDir (for views / layout)
 *   - a refreshed wbs_locale cookie when ?lang= is used or the cookie is stale
 */
final class LocaleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $cfg      = config(LocaleConfig::class);
        $resolver = new LocaleResolver($cfg->supported, $cfg->default, $cfg->countryLocale);

        $explicit = null;
        $get      = $request->getGet($cfg->queryParam);
        if (is_string($get) && $get !== '') {
            $explicit = $get;
        }

        $cookie = null;
        if (method_exists($request, 'getCookie')) {
            $c = $request->getCookie($cfg->cookieName);
            if (is_string($c) && $c !== '') {
                $cookie = $c;
            }
        }

        $country = null;
        if ($cfg->geoDetection) {
            foreach ($cfg->countryHeaders as $h) {
                $v = $request->getHeaderLine($h);
                if ($v !== '') {
                    $country = $v;
                    break;
                }
            }
        }

        $result = $resolver->resolveWithSource([
            'explicit'       => $explicit,
            'cookie'         => $cookie,
            'orgDefault'     => null, // synced into the cookie at login
            'country'        => $country,
            'acceptLanguage' => $request->getHeaderLine('Accept-Language') ?: null,
        ]);
        $locale = $result['locale'];

        // Apply to the framework so lang() and view negotiation use it.
        service('request')->setLocale($locale);

        // Expose to controllers/views (layout reads these).
        $request->wbsLocale    = $locale;
        $request->wbsLocaleDir = in_array($locale, $cfg->rtl, true) ? 'rtl' : 'ltr';

        // Remember the decision so the next request is a pure cookie hit — and so
        // an explicit ?lang switch persists. We only need to (re)write when the
        // manual switch was used or the stored cookie disagrees with the result.
        if ($explicit !== null || $cookie !== $locale) {
            $request->wbsLocaleWrite = $locale; // signal to after()
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $cfg = config(LocaleConfig::class);

        // Always advertise the served language + vary for correct caching.
        if (isset($request->wbsLocale)) {
            $response->setHeader('Content-Language', (string) $request->wbsLocale);
        }
        $response->appendHeader('Vary', 'Accept-Language');
        $response->appendHeader('Vary', 'Cookie');

        // Persist the resolved locale to the cookie when needed (see before()).
        if (isset($request->wbsLocaleWrite)) {
            $response->setCookie([
                'name'     => $cfg->cookieName,
                'value'    => (string) $request->wbsLocaleWrite,
                'expires'  => $cfg->cookieLifetime,
                'path'     => '/',
                'httponly' => false,
                'samesite' => 'Lax',
                'secure'   => $request->isSecure(),
            ]);
        }

        return $response;
    }
}
