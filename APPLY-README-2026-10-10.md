# WBS Platform — Fix Pack 2026-10-10

**Scope:** Internationalize the **Community** module's 1 view — the community
`feed`. Adds `en, fr, es, pt, zh, ar` translations and a regression test.

Builds on `wbs-fixes-2026-10-09.zip` (Reporting i18n). No schema changes, no new
dependencies, no config changes.

---

## Why

The feed view **extends the shared `layouts/app`**, which already emits a
dynamic `<html lang dir>` (RTL for Arabic). So — like Events, Contributions,
Gamification, Courses, Streaming and Reporting — it only needs its **copy**
translated, following the established pattern:

- all body copy via `lang('Community.*')` with English as guaranteed fallback;
- post count + singular/plural chosen in PHP (no ICU `{}` runtime dependency),
  so output is correct with or without ext-intl;
- the post `visibility` value is localized but **falls back to the raw stored
  value** when a key is missing, so custom values never break a page;
- free-form service data stays **verbatim** (data, not UI copy): post author id,
  timestamps, title, and — critically — the **already-sanitized `body_html`**,
  which is still emitted without re-escaping exactly as before; reaction/comment
  counts are rendered as-is.

---

## What changed

### New — language files (6 files)
```
app/Modules/Community/Language/en/Community.php   (English — guaranteed fallback, every key present)
app/Modules/Community/Language/fr/Community.php
app/Modules/Community/Language/es/Community.php
app/Modules/Community/Language/pt/Community.php
app/Modules/Community/Language/zh/Community.php   (Chinese — code `zh`)
app/Modules/Community/Language/ar/Community.php   (Arabic — RTL via shared layout)
```
11 keys per locale: `title`, `post`/`posts` plurals, `visibleToYou`, `empty`,
`memberFallback`, `pinned`, and a nested `visibility.*` map
(group/public/private/org).

### New — test (1 file)
```
app/Modules/Community/Views/tests/community_i18n_test.php   (30 assertions)
```

### Changed — 1 Community view (copy localized in place)
```
app/Modules/Community/Views/feed.php
```
Only literal English text became `lang('Community.*')` calls. The sanitized
`body_html` output path, visibility handling, and reaction/comment counters are
unchanged.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-10.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Community -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Community/Views/tests/community_i18n_test.php   # 30 passed, 0 failed

# full suite
# 1137 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. the nested
`visibility.*` group (no missing/stray keys), the view calls `lang('Community.*')`
with no bare English, and end-to-end renders in **fr** and **ar** with translated
heading/labels, localized visibility with graceful unknown-value fallback,
correct singular/plural counts, verbatim free-form data (author id, title,
un-re-escaped `body_html`), and preserved numeric reaction/comment counts.

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged; these strings also
  merge into the unified catalog + `GET /i18n/{locale}.json` bundle
  automatically — `FileCatalogProvider` auto-discovers `Modules/*/Language`, so
  there is **zero wiring change** for the new Community catalog.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- i18n module coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification, Groups, Courses, Streaming, Reporting,
  Community**. Remaining view-bearing module: **Referrals (1 self-HTML view)** —
  the last one, which will need an in-view `<html lang dir>` include like the
  Groups public pages.
