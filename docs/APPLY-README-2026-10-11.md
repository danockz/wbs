# WBS Platform — Fix Pack 2026-10-11

**Scope:** Internationalize the **Referrals** module's 1 view — the member/staff
contacts address book (`contacts_index`). This is the **final** view i18n task:
every view-bearing module is now localized. Adds `en, fr, es, pt, zh, ar`
translations, a shared `_locale.php` include, and a regression test.

Builds on `wbs-fixes-2026-10-10.zip` (Community i18n). No schema changes, no new
dependencies, no config changes.

---

## Why

Unlike every prior i18n pack, `contacts_index` is a **SELF-CONTAINED** page: it
renders its own `<html>` document (inline styles, sandbox-safe) instead of
extending `layouts/app`. So — exactly like the Groups public pages — it needs
its own locale-aware `<html lang dir>`. This pack:

- adds `app/Modules/Referrals/Views/_locale.php` (a copy of the Groups
  `_locale.php` pattern) that resolves `$wbsLocale` / `$wbsDir` (RTL for Arabic)
  with `function_exists()` guards so the view still renders in CLI/test harnesses,
  and exposes a `$li()` `{0}`-interpolation helper;
- replaces the hardcoded `<html lang="en">` with dynamic
  `lang="<?= $wbsLocale ?>" dir="<?= $wbsDir ?>"`;
- localizes all UI copy via `lang('Referrals.*')` with English fallback; the
  contact count singular/plural is chosen in PHP (no ICU `{}` runtime).

**Discipline preserved:**

- the fixed-vocabulary **temperature** (hot/warm/cold) IS localized, falling
  back to the raw humanized value for any unknown warmth so a page never breaks;
  the warmth **colour** stays code-driven;
- free-form server vocabularies — `journey_stage` and `decision_type` slugs — are
  arbitrary lists supplied by the service, so they stay **verbatim**, humanized
  in-view via `ucwords()` (data, not translatable UI copy);
- all contact data (names, phone, dates, CSRF token, GPS placeholders) is escaped
  and rendered verbatim; the **two-flag GPS consent gate** wording is translated
  but its behaviour (coords saved only when BOTH boxes are ticked) is unchanged.

---

## What changed

### New — language files (6 files)
```
app/Modules/Referrals/Language/en/Referrals.php   (English — guaranteed fallback, every key present)
app/Modules/Referrals/Language/fr/Referrals.php
app/Modules/Referrals/Language/es/Referrals.php
app/Modules/Referrals/Language/pt/Referrals.php
app/Modules/Referrals/Language/zh/Referrals.php   (Chinese — code `zh`)
app/Modules/Referrals/Language/ar/Referrals.php   (Arabic — RTL via _locale.php)
```
55 keys per locale: document/header, summary tiles, nested `temperature.*`,
filters, contacts table columns + empty/fallback, the three inline action forms
(follow-up / decision / attendance), the add-a-contact form, and the GPS consent
block.

### New — locale include + test (2 files)
```
app/Modules/Referrals/Views/_locale.php                         (self-contained lang/dir resolver)
app/Modules/Referrals/Views/tests/referrals_i18n_test.php       (46 assertions)
```

### Changed — 1 Referrals view (copy localized, dynamic lang/dir)
```
app/Modules/Referrals/Views/contacts_index.php
```

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-11.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Referrals -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Referrals/Views/tests/referrals_i18n_test.php   # 46 passed, 0 failed

# full suite
# 1183 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`temperature.*` (no missing/stray keys), the view calls `lang('Referrals.*')`,
dropped its hardcoded `lang="en"`, includes `_locale.php`, and emits a dynamic
`<html lang dir>`; and end-to-end renders in **fr** (`lang="fr" dir="ltr"`) and
**ar** (`lang="ar" dir="rtl"`) with translated headings/labels/consent copy,
localized temperature with graceful unknown-value fallback, correct
singular/plural counts, verbatim humanized stage/decision slugs, verbatim
contact data + CSRF token, and preserved numeric summary counts.

---

## Notes / non-goals

- Locale negotiation is unchanged; these strings also merge into the unified
  catalog + `GET /i18n/{locale}.json` bundle automatically —
  `FileCatalogProvider` auto-discovers `Modules/*/Language`, so there is **zero
  wiring change** for the new Referrals catalog.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- **View i18n backlog COMPLETE.** All view-bearing modules are now localized:
  AccessControl, Events, Identity, Contributions, Gamification, Groups, Courses,
  Streaming, Reporting, Community, **Referrals**. Two `_locale.php`-style
  self-contained families (Groups public pages, Referrals contacts) carry their
  own `<html lang dir>`; everything else localizes through the shared
  `layouts/app`.
