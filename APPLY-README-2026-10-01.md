# WBS Platform — fixes & features (through 2026-10-01)

Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

NOTE (carried from 2026-09-27): this line of releases MOVED two view files. If you
extracted an archive from BEFORE 2026-09-27, DELETE the old copies after applying:
  - app/Views/shared/data_page.php      (moved -> app/Modules/Shared/Views/data_page.php)
  - app/Views/admin/console.php         (moved -> app/Modules/Shared/Views/admin_console.php)

================================================================================
FEATURE (2026-10-01) — AccessControl admin pages localized (+ shared admin console)
================================================================================
AccessControl has NO bespoke views — its 7 admin controllers render through the
SHARED admin console (BaseController::respondAdmin). So localizing this module has
two parts, and part 1 benefits EVERY admin module (Gamification, Admin, Groups,
Events managerial, Contributions, Streaming, Referrals, Integrations):

PART 1 — Shared admin-console CHROME now localized.
  CHANGED app/Modules/Shared/Views/admin_console.php
    Title, "admin" badge, record/records count (PHP singular/plural), error label,
    "status {0}" line, "No records." empty state, and "Meta" heading now come from
    lang('AdminConsole.*'). A guarded $t() helper falls back to the English literal
    when a key is missing OR when lang() isn't booted (CLI/test harnesses), so the
    page always renders.
  NEW app/Language/{en,fr,es,pt,zh,ar}/AdminConsole.php  (shared chrome strings).

PART 2 — AccessControl page titles/subtitles localized.
  NEW app/Modules/AccessControl/Language/{en,fr,es,pt,zh,ar}/AccessControl.php
    Titles: Roles, Role, Rules, Rule, ABAC policies/policy, Role assignments/
    assignment, Delegation chain, Delegations received, Access request(s pending),
    Break-glass pending reviews / session. Labelled subtitles: "subject {0}",
    "facet {0}" ({0} = the identifier). Pure-identifier subtitles (a role id,
    policy id, session id, …) stay as the raw value and are intentionally NOT keyed.
  CHANGED the 7 controllers (Role, Rule, AbacPolicy, AccessRequest, BreakGlass,
    Delegation, RoleAssignment) — all 14 respondAdmin() calls now pass
    lang('AccessControl.<key>') instead of hardcoded English. CI4's locale
    fallback to 'en' guarantees resolution even for a partial translation.

DESIGN NOTES:
  - English is the guaranteed fallback everywhere (view $t() + CI4 'en' locale
    file), so a missing key never blanks a heading or leaks a raw key.
  - Number interpolation + singular/plural are done in PHP (not ICU {} placeholders),
    so it renders correctly with OR without ext-intl.
  - RTL verified: the whole console mirrors under <html dir="rtl"> for Arabic
    (title "إسنادات الأدوار", badge "إدارة", subtitle "الموضوع u-123", "1 سجل").
  - JSON path unchanged — API clients still get identical payloads; only the
    browser-facing HTML is localized. Write actions (POST/PATCH) are untouched.

REGRESSION TEST (new, +58):
  - app/Modules/AccessControl/Controllers/tests/accesscontrol_i18n_test.php —
    every locale's AdminConsole.php + AccessControl.php mirror the English keys
    (no missing, no stray; {0} placeholders preserved); the console view uses the
    localized chrome (no hardcoded English); all 7 controllers pass lang() titles;
    and the console renders localized chrome end-to-end in French (title, badge,
    record label, empty state, "statut 404").

VERIFIED: php -l clean on all 21 new/changed files; fr + ar render smoke pass; the
existing negotiation test's admin-console render still passes (English fallback via
$t() when lang() isn't booted); suite now 621 assertions green (previous 563 +
AccessControl 58).

WHAT REMAINS (say the word):
  - Remaining modules' views (Groups, Gamification, Contributions, Identity,
    Referrals, Reporting, Streaming). Note the shared admin-console chrome is now
    done once for ALL of them — each remaining admin module only needs its own
    <Module>.php title/subtitle strings.

================================================================================
FEATURE (2026-09-30) — Events module views localized
================================================================================
All three Events read views (index/show/attendance) pull copy from
app/Modules/Events/Language/<locale>/Events.php in all six locales, with enum
maps (status/mode/registration) that fall back to the raw value. Test:
events_i18n_test.php (28).

================================================================================
FEATURE (2026-09-29) — Language-awareness, Phase 1 (plumbing)
================================================================================
Locale cascade (manual ?lang=/switcher → cookie → org → network/geo header →
Accept-Language → 'en'), DB-free hot-path filter, CSP-safe no-JS switcher, dynamic
<html lang dir> (RTL), intl-optional Formatter, locale in the menu ETag. Tests:
locale_resolver (43) + formatter (14) + i18n_wiring (32).

================================================================================
FEATURE (2026-09-28) — View helpers: relative time + PII redactor
================================================================================
app/Helpers/{time,redactor}_helper.php, auto-loaded via BaseController::$helpers.
Test: helpers_test.php (39).

================================================================================
FIX (2026-09-27) — Two production errors from log-2026-09-09
================================================================================
Journey getSharedInstance() keys == factory method names; fallback/console views
moved into the module Views dir (namespaced form).

## Notes
- docs/ holds design/audit records (not applied to the DB).
- Earlier release notes are preserved in the prior APPLY-README archives.
