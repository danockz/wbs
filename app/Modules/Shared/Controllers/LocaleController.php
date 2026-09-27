<?php

declare(strict_types=1);

namespace WBS\Shared\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Locale as LocaleConfig;
use WBS\Identity\Config\Services as IdentityServices;
use WBS\Shared\Http\BaseController;
use WBS\Shared\I18n\LocaleResolver;

/**
 * Manual language switch (Phase 1 language-awareness).
 *
 * POST /prefs/locale  { locale: "fr", return: "/me" }
 *
 * CSP-safe: driven by a plain <form> (no JS). Writes the wbs_locale cookie so the
 * choice persists for anonymous users, and — when signed in — also persists it to
 * user_preferences.locale (switch wins AND is remembered). The DB write is the
 * only place this endpoint touches persistence; the hot-path filter stays
 * DB-free. Always clamps to the supported allowlist; an unsupported/hostile value
 * simply keeps the current locale.
 */
final class LocaleController extends BaseController
{
    public function set(): ResponseInterface
    {
        $cfg      = config(LocaleConfig::class);
        $resolver = new LocaleResolver($cfg->supported, $cfg->default, $cfg->countryLocale);

        $requested = (string) ($this->request->getPost('locale') ?? '');
        $locale    = $resolver->canonicalize($requested);

        // Safe, same-origin return target only.
        $return = (string) ($this->request->getPost('return') ?? '/me');
        if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            $return = '/me';
        }

        $response = redirect()->to($return);

        if ($locale === null) {
            return $response; // ignore invalid choice, keep current
        }

        // Persist to the signed-in user's stored preference (best-effort).
        $userId = $this->currentUserId();
        if ($userId !== '') {
            try {
                IdentityServices::preferences()->setLocale($userId, $locale);
            } catch (\Throwable) {
                // Non-fatal: the cookie below still carries the choice.
            }
        }

        return $response->setCookie([
            'name'     => $cfg->cookieName,
            'value'    => $locale,
            'expires'  => $cfg->cookieLifetime,
            'path'     => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure'   => $this->request->isSecure(),
        ]);
    }
}
