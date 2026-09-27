# WBS Platform — fixes & features (through 2026-09-30)

Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

NOTE (carried from 2026-09-27): this line of releases MOVED two view files. If you
extracted an archive from BEFORE 2026-09-27, DELETE the old copies after applying:
  - app/Views/shared/data_page.php      (moved -> app/Modules/Shared/Views/data_page.php)
  - app/Views/admin/console.php         (moved -> app/Modules/Shared/Views/admin_console.php)

================================================================================
FEATURE (2026-09-30) — Events module views localized (first module on i18n)
================================================================================
The first bespoke module wired onto the Phase 1 language foundation (2026-09-29).
All three Events read views now pull their copy from module language files instead
of hardcoded English, in all six supported locales (en, fr, es, pt, zh, ar).

NEW — app/Modules/Events/Language/{en,fr,es,pt,zh,ar}/Events.php
  Module-namespaced strings resolved as lang('Events.<key>'). English is the
  guaranteed fallback (every locale mirrors all English keys — enforced by test).
  Includes nested enum groups: status, mode, regPolicy — used to translate the
  stored enum values for display.

CHANGED — app/Modules/Events/Views/{index,show,attendance}.php
  - index.php       Events list: heading, count line (PHP singular/plural), empty
                    state, per-card status/mode labels, "starts … UTC", capacity.
  - show.php        Event overview: status/mode/registration enums, at-a-glance
                    labels, description, attendance section, meta line.
  - attendance.php  Expected-attendance report: funnel labels, expected-attendees
                    band, range/show-rate, logistics estimates.

DESIGN NOTES (why it renders correctly everywhere):
  - Numbers are interpolated in PHP and singular/plural is chosen in the view (not
    via ICU {} placeholders), so the copy is correct WITH OR WITHOUT ext-intl.
  - Enum labels (status/mode/registration) fall back to the RAW stored value when
    a translation key is missing, so an unknown/new enum value never breaks a page
    or leaks a raw i18n key.
  - RTL: Arabic renders inside the layout's <html dir="rtl"> (from Phase 1), so the
    whole page mirrors; verified all three views render full Arabic copy.
  - JSON path is unchanged — API clients still get the same payloads; only the
    browser-facing HTML is localized.

REGRESSION TEST (new, +28):
  - app/Modules/Events/Views/tests/events_i18n_test.php — asserts every locale
    mirrors the English keys (incl. nested enums) with no stray keys, that each
    view calls lang('Events.*') and no longer hardcodes English headings/strings,
    and renders the fr index end-to-end (translated status/mode, unknown-enum
    fallback, ∞ for null capacity, value interpolation).

VERIFIED: php -l clean on all 9 new/changed files; fr + ar render smoke shows fully
translated views; suite now 563 assertions green (previous 535 + Events 28). No
other module touched.

WHAT REMAINS (say the word):
  - The remaining modules' bespoke views (Groups, Gamification, Contributions,
    Identity, Referrals, Reporting, Streaming, AccessControl admin pages). Each is
    the same pattern: add Language/<locale>/<Module>.php + swap hardcoded strings
    for lang() calls. Everything stays English until its keys land; nothing breaks.

================================================================================
FEATURE (2026-09-29) — Language-awareness, Phase 1 (plumbing)
================================================================================
Locale resolution cascade (manual ?lang= / switcher → cookie → org default →
network/geo edge header → Accept-Language → 'en'), all clamped to the supported
allowlist. DB-free hot-path filter; preference synced to the wbs_locale cookie at
login. CSP-safe no-JS language switcher in the layout; dynamic <html lang dir>
(RTL for Arabic). intl-OPTIONAL Formatter (ICU when present, graceful fallback).
Menu category labels localized + locale folded into the menu ETag. Files:
Config/Locale.php, Shared/I18n/{LocaleResolver,Formatter}, Shared/Filters/
LocaleFilter, Shared/Controllers/LocaleController, Identity/UserPreferenceService,
app/Language/<locale>/App.php. Tests: locale_resolver (43) + formatter (14) +
i18n_wiring (32).

================================================================================
FEATURE (2026-09-28) — View helpers: relative time + PII redactor
================================================================================
app/Helpers/time_helper.php + app/Helpers/redactor_helper.php, auto-loaded via
BaseController::$helpers. Test: helpers_test.php (39).

================================================================================
FIX (2026-09-27) — Two production errors from log-2026-09-09
================================================================================
Journey getSharedInstance() keys == factory method names; fallback/console views
moved into the module Views dir (namespaced form). Enforced by service_keys_test +
negotiation_test.

## Notes
- docs/ holds design/audit records (not applied to the DB).
- Earlier release notes are preserved in the prior APPLY-README archives.
