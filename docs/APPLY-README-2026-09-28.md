# WBS Platform — fixes & features (through 2026-09-28)

Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

NOTE (carried from 2026-09-27): this release still MOVES two view files. If you
extracted an archive from BEFORE 2026-09-27, DELETE the old copies after applying:
  - app/Views/shared/data_page.php      (moved -> app/Modules/Shared/Views/data_page.php)
  - app/Views/admin/console.php         (moved -> app/Modules/Shared/Views/admin_console.php)

================================================================================
FEATURE (2026-09-28) — View helpers: relative time + PII redactor
================================================================================
Two presentation-layer helpers, loaded into every controller automatically via
BaseController::$helpers (now ['url','form','time','redactor']). Both are
null-tolerant and NEVER throw — a view must render even when a value is null,
malformed, or an unexpected type. Output is plain text (still pass through esc()
as usual); the one exception, time_tag(), escapes its own output.

NEW — app/Helpers/time_helper.php
  - time_ago($value, $fallback='—', $now=null)
        Human relative label: "just now", "15 minutes ago", "3 hours ago",
        "in 1 hour". Singular/plural aware (1 minute vs 2 minutes). Past AND
        future. Accepts DateTimeInterface | ISO/any-parseable string | epoch int
        (int or numeric string). Unparseable -> $fallback.
  - format_datetime($value, $format='Y-m-d H:i', $viewerTz=null, $fallback='—')
        Absolute display, optionally converted to a viewer timezone (e.g. the
        user's user_preferences.timezone). Unknown tz falls back to the value's
        own zone; unparseable -> $fallback.
  - time_tag($value)
        A safe <time> element: machine-readable ISO-8601 in datetime="", human
        "time ago" as the visible text, full timestamp in title=". Self-escaping.
        Unparseable -> '' (renders nothing).
  - to_datetime($value, $assumeTz='UTC')
        Low-level coercion to DateTimeImmutable|null (naive strings/epochs are
        treated as UTC). Used by the above; exposed for callers that need it.

NEW — app/Helpers/redactor_helper.php  (PII masking; SRS: prospect/member data is private)
  These are a DISPLAY safeguard, not a security boundary — services must still
  avoid sending secrets to the browser.
  - mask_middle($v, $keepStart=2, $keepEnd=2, $maskChar='*')
        "4242424242424242" -> "4242********4242"; short values fully masked.
  - redact_email($email, $fallback='—')
        "jane.doe@example.com" -> "ja******@example.com"; domain kept.
  - redact_phone($phone, $keepEnd=4, $fallback='—')
        "+233241234567" -> "+••••••••4567"; preserves a leading '+', keeps last 4.
  - redact_name($name, $fallback='Member')
        "Kwame Nkrumah Mensah" -> "Kwame N. M." (first name + last initials).
  - redact_id($id, $keepEnd=6, $fallback='—')
        UUID/token -> "…456789" (trailing segment only).
  - redact($value, $type='middle')
        Convenience dispatcher: 'email' | 'phone' | 'name' | 'id' | 'middle'.

CHANGED (worked example, opt-in usage):
  - app/Modules/Shared/Http/BaseController.php — $helpers now loads 'time' and
    'redactor' so every browser view can call them with no per-view helper() call.
  - app/Modules/Contributions/Views/cause_donors.php — named donors now shown as
    "First L." via redact_name() (the service still collapses anonymous/none gifts
    to "Anonymous", which is passed through unchanged); the gift timestamp now
    renders via time_tag() ("2 days ago", with the exact time on hover).

REGRESSION TEST (new):
  - app/Modules/Shared/Http/tests/helpers_test.php (39 assertions) — covers every
    function incl. singular/plural, future, epoch, null/garbage fallbacks, tz
    conversion, the <time> element shape, all redactors + null-safety, and asserts
    BaseController registers both helpers.

REQUIREMENT: PHP mbstring extension (the redactor uses mb_* for multibyte-safe
slicing, consistent with the rest of the codebase). Enable it if not already on.

VERIFIED: php -l clean on all changed/new files; suite now 445 assertions green
(menu 216 + negotiation 55 + service-keys 135 + helpers 39). JSON path unchanged.

--------------------------------------------------------------------------------
INVESTIGATION (2026-09-28) — Are the views language-aware? Answer: NOT YET.
--------------------------------------------------------------------------------
Asked whether views support (a) a manual language switch and (b) automatic
selection by region/subregion/country/state. Current state: NO on both.
  - The layout hardcodes <html lang="en">; no bespoke/admin view uses lang().
  - Config\App: negotiateLocale=false, supportedLocales=['en'], defaultLocale='en'.
  - No app Language/ dirs exist; locale is not attached to the request (AuthFilter
    sets wbsUserId/wbsOrgId only).
The DATA foundation to enable it already exists (no new tables needed):
  users.locale, user_preferences.locale + .timezone, organizations.default_locale,
  and the Geo hierarchy (countries.translations JSON, regions/subregions/states)
  resolved by LocationService. Enabling i18n would be a presentation-layer job
  (extract locale -> Services::request()->setLocale() -> lang() keys + a manual
  switcher). Not built in this release — flagged for direction.

================================================================================
FIX (2026-09-27) — Two production errors from log-2026-09-09
================================================================================
BUG 1 — Journey TypeError: attribution()/recommendations() returned null.
  CAUSE: CodeIgniter resolves getSharedInstance($key) by dispatching $key to a
  factory METHOD of the same name via service discovery. Journey\Config\Services
  used keys 'journeyAttribution'/'journeyRecommendations' that are not method
  names, so discovery returned null -> TypeError on the declared return type.
  FIX: keys now match their method names — 'attribution' and 'recommendations'.
  File: app/Modules/Journey/Config/Services.php

BUG 2 — ViewException "Invalid file: shared/data_page.php".
  CAUSE: the fallback/console view constants used bare paths under app/Views/. The
  deployed view locator resolves module views by the NAMESPACED form, so the bare
  paths did not resolve.
  FIX: moved both views into the module Views dir and reference them the namespaced
  way used everywhere else:
    app/Views/shared/data_page.php -> app/Modules/Shared/Views/data_page.php
        FALLBACK_HTML_VIEW = 'WBS\Shared\Views\data_page'
    app/Views/admin/console.php    -> app/Modules/Shared/Views/admin_console.php
        ADMIN_CONSOLE_VIEW = 'WBS\Shared\Views\admin_console'
  Files: app/Modules/Shared/Http/BaseController.php + the two moved views.

REGRESSION TESTS (2026-09-27):
  - app/Modules/Shared/Http/tests/service_keys_test.php (135 assertions) — scans
    every module Config/Services.php and asserts each getSharedInstance() key
    equals its factory method name. Would have caught BUG 1.
  - negotiation_test.php extended — asserts the fallback/console constants use the
    resolvable namespaced form and are NOT left at the bare path. Covers BUG 2.

## Notes
- docs/ contains the design/audit records (not applied to the DB).
- Earlier release notes (admin console 2026-09-26, Part B views 2026-09-25,
  universal JSON fallback 2026-09-24, login/CSP fixes, etc.) are preserved in the
  prior APPLY-README archives.
