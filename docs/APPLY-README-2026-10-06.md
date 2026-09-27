# WBS Platform — Fix Pack 2026-10-06

**Scope:** Internationalize the **Groups** module's 7 views — the authenticated
group detail page (extends the shared layout) plus the **5 self-contained public
pages** (directory, group landing, join form, join confirmation, not-found) and
their shared public nav. Adds `en, fr, es, pt, zh, ar` translations, per-view
`<html lang dir>` (incl. **RTL for Arabic**) on the self-contained pages, and a
regression test.

Builds on `wbs-fixes-2026-10-05.zip` (unified translation merge layer). No schema
changes, no new dependencies, no config changes.

---

## Why

Groups has two kinds of views, handled two ways (both already used elsewhere):

- **`detail.php`** extends the shared `layouts/app` — which already emits a
  dynamic `<html lang dir>` — so it only needs its **copy** translated (the
  Events/Contributions/Gamification pattern).
- **The 5 public pages** (`/g`, `/g/{slug}`, `/g/{slug}/join`, join-done,
  not-found) are **self-contained**: they render their own `<html>` with
  bespoke per-group themed inline styles and are served with **no `auth`
  filter**. Like the Identity pages, each now includes a small `_locale.php`
  helper and emits `<html lang="…" dir="…">` so they are locale-aware and
  **RTL-correct** for Arabic.

All of these strings also flow automatically into the unified merge layer /
frontend bundle shipped on 2026-10-05 (the `FileCatalogProvider` discovers the
new `Groups/Language` tree with no wiring change).

---

## What changed

### New — language files (6 files)
```
app/Modules/Groups/Language/en/Groups.php   (English — guaranteed fallback, every key present)
app/Modules/Groups/Language/fr/Groups.php
app/Modules/Groups/Language/es/Groups.php
app/Modules/Groups/Language/pt/Groups.php
app/Modules/Groups/Language/zh/Groups.php   (Chinese — code `zh`)
app/Modules/Groups/Language/ar/Groups.php   (Arabic — RTL)
```
Key groups: `brand` (the untranslated product name "Win–Build–Send"), `nav.*`
(shared public nav), `detail.*`, `directory.*` (incl. a `summary` template with
`{0}..{3}` and singular/plural group/location words), `page.*` (landing hero,
CTA, sections, contact), `join.*` (form labels, sponsor line, open/approval
policy), `joinDone.*` (pending vs welcome, set-password), `notFound.*`.

### New — self-contained locale helper + test
```
app/Modules/Groups/Views/_locale.php                       (locale/dir + $li() interpolation, framework-guarded)
app/Modules/Groups/Views/tests/groups_i18n_test.php        (65 assertions)
```

### Changed — 7 views
```
app/Modules/Groups/Views/_public_nav.php         copy via lang('Groups.nav.*')
app/Modules/Groups/Views/detail.php              copy via lang('Groups.detail.*') (shared layout)
app/Modules/Groups/Views/public_directory.php    + dynamic <html lang dir>, copy, pluralized summary
app/Modules/Groups/Views/public_join.php         + dynamic <html lang dir>, copy, {0} interpolation
app/Modules/Groups/Views/public_join_done.php    + dynamic <html lang dir>, copy
app/Modules/Groups/Views/public_not_found.php    + dynamic <html lang dir>, copy
app/Modules/Groups/Views/public_page.php         + dynamic <html lang dir>, copy, chips/CTA/sections
```
Per-group theming, layout, links, and the CSP-safe no-JS forms are unchanged —
only literal English text became `lang('Groups.*')` calls, with `{0}`
placeholders interpolated in PHP.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-06.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Groups -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Groups/Views/tests/groups_i18n_test.php   # 65 passed, 0 failed

# full suite
# 921 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
groups (no missing/stray keys), `{0}` preserved in `directory.summary` and
`join.metaTitle`, every view calls `lang('Groups.*')` with no hardcoded
`lang="en"`, the 5 public pages emit a dynamic `<html lang dir>` and include
`_locale.php`, and end-to-end renders in **fr** (LTR) and **ar** (RTL) with
correct `lang`/`dir`, translated copy, group-name/sponsor/slug/member
interpolation, and pluralized directory counts.

---

## Notes / non-goals

- Locale negotiation, the shared layout, and per-group theming are unchanged.
- English remains the guaranteed fallback via CI4 `lang()` resolution; these
  strings also merge into the unified catalog + `GET /i18n/{locale}.json`
  bundle automatically.
- i18n module coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification, Groups**. Remaining view-bearing modules:
  **Courses (3), Streaming (3), Reporting (2), Community (1), Referrals (1)**.
