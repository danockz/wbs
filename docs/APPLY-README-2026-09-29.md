# WBS Platform — fixes & features (through 2026-09-29)

Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

NOTE (carried from 2026-09-27): this line of releases MOVED two view files. If you
extracted an archive from BEFORE 2026-09-27, DELETE the old copies after applying:
  - app/Views/shared/data_page.php      (moved -> app/Modules/Shared/Views/data_page.php)
  - app/Views/admin/console.php         (moved -> app/Modules/Shared/Views/admin_console.php)

================================================================================
FEATURE (2026-09-29) — Language-awareness, Phase 1 (plumbing + manual switch +
                       browser & network auto-detect + RTL)
================================================================================
Answers the earlier finding ("views are NOT language-aware") by building the whole
presentation-layer foundation. Views become locale-aware WITHOUT touching each
view: one resolution choke point, native CI4 language files, and an intl-optional
formatter. Supported locales this phase: en (default), fr, es, pt, zh, ar.
Arabic is RTL — the layout now emits <html lang dir> accordingly.

HOW A LOCALE IS CHOSEN (deterministic cascade, highest wins) —
WBS\Shared\I18n\LocaleResolver (pure, framework-free, unit-tested):
  1. explicit   ?lang=xx or the switcher this request     (MANUAL SWITCH)
  2. cookie     wbs_locale (last choice / synced preference)
  3. org        organizations.default_locale
  4. geo        visitor country (edge/CDN header) -> country->locale map  (NETWORK)
  5. browser    Accept-Language, q-weighted, intersected with the allowlist
  6. default    'en'
Every candidate is CLAMPED to the supported allowlist by canonicalize(), so a
hostile ?lang=../../etc/passwd or any unsupported tag can never escape; regional
variants collapse to base (fr-CA -> fr, zh_Hans_CN -> zh).

NETWORK/GEO is configurable (works behind Cloudflare, a generic proxy, or nothing):
  Config\Locale::$countryHeaders = ['CF-IPCountry','X-Geo-Country','X-Country-Code',
  'X-AppEngine-Country'] (first non-empty wins; values validated; 'XX'/'T1' ignored).
  Config\Locale::$countryLocale maps ISO-3166 alpha-2 -> locale (only entries whose
  locale is supported take effect). Country-level ONLY and NEVER stored — it does
  not touch the private-location / GPS consent gate. Master switch: $geoDetection.

RESOURCE-LIGHT: the hot-path filter is DB-FREE. It reads only the cookie, ?lang=,
an edge header, and Accept-Language. The signed-in preference and org default are
synced INTO the wbs_locale cookie at LOGIN (WebSessionController::establishSession)
and by the switcher — so they still win without a per-request DB read.

NEW FILES:
  - app/Config/Locale.php                         allowlist, RTL set, cookie/query
      names, country headers, country->locale map. (Keep $supported in sync with
      Config\App::$supportedLocales — the wiring test enforces this.)
  - app/Modules/Shared/I18n/LocaleResolver.php     the pure cascade + clamp + q-parse.
  - app/Modules/Shared/I18n/Formatter.php          intl-OPTIONAL number/currency/date.
      Uses IntlDateFormatter/NumberFormatter when ext-intl is present; degrades to
      an EN-style fallback otherwise. Currency takes MINOR units (platform-wide).
  - app/Modules/Shared/Filters/LocaleFilter.php    global before/after filter:
      resolves + service('request')->setLocale(), sets $request->wbsLocale /
      wbsLocaleDir, writes wbs_locale when switched/stale, and adds
      Content-Language + Vary: Accept-Language, Cookie for correct caching.
  - app/Modules/Shared/Controllers/LocaleController.php  POST /prefs/locale — the
      CSP-safe manual switch (plain form, no JS). Persists to the cookie and, when
      signed in, to user_preferences.locale (switch WINS and is REMEMBERED).
  - app/Modules/Identity/Services/UserPreferenceService.php  minimal
      user_preferences upsert (locale now; timezone/settings later). Registered as
      IdentityServices::preferences().
  - app/Language/{en,fr,es,pt,zh,ar}/App.php       shared UI strings: menu category
      labels, language switcher, common actions. English is the guaranteed fallback;
      every locale mirrors all English keys (wiring test enforces completeness).

CHANGED:
  - app/Config/App.php        supportedLocales = ['en','fr','es','pt','zh','ar']
      (IncomingRequest::setLocale() rejects anything not in this list).
      negotiateLocale stays false — WBS does its own richer negotiation.
  - app/Config/Filters.php    'locale' alias + added to globals before AND after.
      Registered as a GLOBAL before, so it runs ahead of the per-route AuthFilter
      (hence the DB-free design; identity isn't known yet at that point).
  - app/Config/Routes.php     POST prefs/locale -> LocaleController::set (webcsrf).
  - app/Views/layouts/app.php <html lang dir> now dynamic; adds a no-JS language
      switcher (native <details> + form buttons, CSP-safe). Guards service()/config()
      so it still renders in CLI/test harnesses.
  - app/Modules/Shared/Navigation/MenuCategory.php  new label() localizes category
      names via lang('App.menu.<key>') with hardcoded-English fallback.
  - MenuService.php / MenuBundle.php  category labels localized at render; the menu
      ETag (etag()/etagFor()) now carries a LOCALE term so a French menu never
      shares a cache entry with an English one (honours the version-stamped,
      edge-cacheable menu design). New optional $locale arg defaults to the active
      request locale — existing callers are unaffected.
  - app/Modules/Identity/Config/Services.php  + preferences() factory.
  - app/Modules/Identity/Controllers/WebSessionController.php  syncs the user's
      stored locale into wbs_locale at session establishment (best-effort; never
      blocks login).

REQUIREMENT: ext-intl is RECOMMENDED for correct ICU dates/numbers/currency but is
OPTIONAL — the Formatter degrades gracefully without it. ext-mbstring is already
required by the redactor helper.

REGRESSION TESTS (new, +89):
  - app/Modules/Shared/I18n/tests/locale_resolver_test.php (43) — canonicalize/clamp
    (incl. path-traversal + unsupported), geo map, Accept-Language q-weighting, the
    full precedence cascade, resolveWithSource, constructor robustness.
  - app/Modules/Shared/I18n/tests/formatter_test.php (14) — intl AND fallback paths
    for number/currency(minor units)/date, null/garbage safety, tz conversion.
  - app/Modules/Shared/I18n/tests/i18n_wiring_test.php (32) — filter globals, App vs
    Locale allowlist alignment, switch route, dynamic layout lang/dir, EVERY locale
    file mirrors English keys, and locale-aware menu labels + ETag.

VERIFIED: php -l clean on all new/changed files; layout renders <html lang="ar"
dir="rtl"> for Arabic and lang/dir correctly for en/fr/zh, with the CSP-safe
switcher (CSRF + return + aria-current) present; MenuCategory::label() returns
translated strings when lang() is available and falls back to English otherwise;
suite now 535 assertions green (menu 216 + negotiation 55 + service-keys 136 +
helpers 39 + i18n 89). JSON path unchanged for API clients.

WHAT PHASE 1 DELIBERATELY DEFERS (say the word to proceed):
  - Extracting each MODULE's bespoke view copy into Language/<locale>/*.php (only
    the shared layout + menu are translated so far — you asked to do module views
    later). Views stay English until their keys land; nothing breaks meanwhile.
  - Optional countries.default_locale column (the config map is the source now).
  - Locale-aware upgrade of the time_ago()/currency helpers' user-facing strings.

================================================================================
FEATURE (2026-09-28) — View helpers: relative time + PII redactor
================================================================================
app/Helpers/time_helper.php (time_ago/format_datetime/time_tag/to_datetime) and
app/Helpers/redactor_helper.php (mask_middle/redact_email/phone/name/id + redact
dispatcher), auto-loaded via BaseController::$helpers. Null-tolerant, never throw.
Demonstrated in Contributions/Views/cause_donors.php. Test: helpers_test.php (39).

================================================================================
FIX (2026-09-27) — Two production errors from log-2026-09-09
================================================================================
BUG 1 — Journey getSharedInstance() keys now equal their factory method names
  ('attribution'/'recommendations'). BUG 2 — fallback/console views moved into the
  module Views dir and referenced by the resolvable namespaced form. Enforced by
  service_keys_test.php + negotiation_test.php.

## Notes
- docs/ holds design/audit records (not applied to the DB).
- Earlier release notes (admin console 09-26, Part B views 09-25, universal JSON
  fallback 09-24, login/CSP fixes) are preserved in the prior APPLY-README archives.
