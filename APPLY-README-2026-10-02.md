# WBS Platform — Fix Pack 2026-10-02

**Scope:** Internationalize the Identity web-flow views (the self-contained
account pages users see when signing in, verifying MFA, setting a password, and
managing their account/sessions). Adds `en, fr, es, pt, zh, ar` translations,
per-page dynamic `<html lang dir>` (including **RTL for Arabic**), and a
regression test. Also stabilizes one pre-existing time-dependent helper test.

Builds on `wbs-fixes-2026-10-01.zip` (AccessControl i18n). No schema changes.
No new dependencies. No config changes required.

---

## Why

The Identity pages are **self-contained** — unlike admin modules, they render
their own `<html>…</html>` and are **not** wrapped by the shared admin-console
layout. So localizing them requires two things done *inside each view*:

1. Translating all body copy via `lang('Identity.*')`.
2. Emitting a **dynamic** `<html lang="…" dir="…">` so the document language and
   text direction follow the negotiated locale (Arabic renders right-to-left).

This matches the locked i18n Phase 1 model already shipped for the rest of the
platform (precedence, allowlist, RTL set, `{0}` positional interpolation).

---

## What changed

### New — language files (8 new files)
```
app/Modules/Identity/Language/en/Identity.php   (English — guaranteed fallback, every key present)
app/Modules/Identity/Language/fr/Identity.php
app/Modules/Identity/Language/es/Identity.php
app/Modules/Identity/Language/pt/Identity.php
app/Modules/Identity/Language/zh/Identity.php   (Chinese — code `zh`)
app/Modules/Identity/Language/ar/Identity.php   (Arabic — RTL)
app/Modules/Identity/Views/_locale.php          (shared in-view locale/dir + $li() interpolation helper)
app/Modules/Identity/Views/tests/identity_i18n_test.php   (65 assertions)
```

Key groups in `Identity.php`: `brand`, `login.*`, `mfa.*` (high/low subtitles),
`setpw.*` (reset vs invite titles/intros, `introInviteNamed` with `{0}`,
submit labels), `done.*`, `invalid.*` (body split `bodyPre`/`bodyLink`/`bodyPost`
for an inline link), `me.*` (`greeting` with `{0}`), `sessions.*`, shared
`assurance.{high,low,token,none}` badge labels, and `friend` name fallback.

### Changed — 7 Identity views (localized in place)
```
app/Modules/Identity/Views/login.php
app/Modules/Identity/Views/mfa.php
app/Modules/Identity/Views/set_password.php
app/Modules/Identity/Views/set_password_done.php
app/Modules/Identity/Views/set_password_invalid.php
app/Modules/Identity/Views/me.php
app/Modules/Identity/Views/sessions.php
```
Each view now:
- `include __DIR__ . '/_locale.php';` at the top (resolves `$wbsLocale`,
  `$wbsDir`, and the `$li(key, ...args)` closure for `{0}` interpolation);
- emits `<html lang="<?= esc($wbsLocale,'attr') ?>" dir="<?= esc($wbsDir,'attr') ?>">`;
- uses a dynamic `<title>` and routes all body copy through `lang('Identity.*')`;
- splits enum **badge color** (kept in a `match`) from **badge label**
  (`lang('Identity.assurance.'.$key)`, key clamped to high/low/token/none), so
  translations never affect styling logic.

`_locale.php` is framework-guarded (`function_exists('service')`,
`function_exists('config')`) so it is safe under the CLI test harness and
degrades to `en` / `ltr` when the framework isn't booted.

### Changed — test stabilization (1 file)
```
app/Modules/Shared/Http/tests/helpers_test.php
```
The `time_tag shows human text` assertion hardcoded `"2 days ago"` against a
fixed date; `time_tag()` uses the real clock (no `$now` param), so it drifted
to "3 days ago" over time. Now asserts the **shape** of the relative string
(`/\d+ (day|days|hour|hours|week|weeks|month|months) ago/` or `just now`)
instead of an exact day count. Pure test-robustness fix; no behavior change.

---

## How to apply

Overlay this archive onto your project root (it mirrors the same paths as prior
fix packs). Existing files are overwritten; new files are added.

```bash
unzip -o wbs-fixes-2026-10-02.zip -d /path/to/project-root
```

Then, per your standard deploy: re-apply into the running container and restart
PHP so the new files/opcache are picked up. No migrations, no `composer`
changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Identity -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test (self-contained; no framework boot needed)
php app/Modules/Identity/Views/tests/identity_i18n_test.php   # 65 passed, 0 failed

# full suite
# 686 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set (no missing/stray
keys), `{0}` placeholders preserved in `me.greeting` and
`setpw.introInviteNamed`, every view calls `lang('Identity.*')` and dropped the
hardcoded `lang="en"`, and end-to-end renders of login/mfa/me/sessions/set_password
in **fr** (LTR) and **ar** (RTL) with correct `lang`/`dir`, translated copy,
name interpolation, assurance badge labels, and a preserved numeric risk score.

---

## Notes / non-goals

- Locale negotiation itself is unchanged — this pack only consumes the already
  shipped Phase 1 resolver (`?lang=` → cookie → org default → geo country header
  → Accept-Language → `en`, allowlist-clamped). Manual switch still wins and
  persists to `user_preferences.locale`.
- English remains the guaranteed fallback: any key missing from a locale falls
  back to the English string via CI4's `lang()` resolution.
- Translations for `fr/es/pt/zh/ar` are complete for every key; refine wording
  with a native reviewer as desired — no code change needed, values only.
