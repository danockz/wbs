# WBS Platform — Fix Pack 2026-10-09

**Scope:** Internationalize the **Reporting** module's 2 views — the Win·Build·Send
`funnel` dashboard and the member self-service `member_dashboard` ("my home").
Adds `en, fr, es, pt, zh, ar` translations and a regression test.

Builds on `wbs-fixes-2026-10-08.zip` (Streaming i18n). No schema changes, no new
dependencies, no config changes.

---

## Why

Both views **extend the shared `layouts/app`**, which already emits a dynamic
`<html lang dir>` (RTL for Arabic). So — like Events, Contributions,
Gamification, Courses and Streaming — they only need their **copy** translated,
following the established pattern:

- all body copy via `lang('Reporting.*')` with English as guaranteed fallback;
- `{0}` / `{1}` placeholders (`Welcome, {0}`, `{0} pts to {1}`, `As of: {0}`,
  `Season {0}`, `{0} XP`, `Best: {0}` …) are interpolated in-view with small
  `str_replace` helpers, so output is correct with or without ext-intl;
- free-form service data stays **verbatim** — it is data, not UI copy: member
  display name, badge/achievement/streak/milestone names, event titles +
  RSVP/mode/timezone, certificate titles/status/verification IDs, course
  titles/category/status, group names/role/type/path, and the delayed-job
  warning text. Suppressed funnel cells (e.g. the string `"<5"`) and all numeric
  counts render exactly as the service computed them.

---

## What changed

### New — language files (6 files)
```
app/Modules/Reporting/Language/en/Reporting.php   (English — guaranteed fallback, every key present)
app/Modules/Reporting/Language/fr/Reporting.php
app/Modules/Reporting/Language/es/Reporting.php
app/Modules/Reporting/Language/pt/Reporting.php
app/Modules/Reporting/Language/zh/Reporting.php   (Chinese — code `zh`)
app/Modules/Reporting/Language/ar/Reporting.php   (Arabic — RTL via shared layout)
```
65 keys per locale under two nested groups: `funnel.*` (title/sub, Win/Build/Send
headings + stat labels, metadata `asOf`/`source`/`completeness`/`suppression`
with `{0}`, `na` fallback) and `member.*` (welcome/subtitle/memberSince, standing
+ rank progress with `{0}`/`{1}`, milestones, badges & achievements, streaks,
events, certificates, learning, groups, and the self-scoped meta line).

### New — test (1 file)
```
app/Modules/Reporting/Views/tests/reporting_i18n_test.php   (75 assertions)
```

### Changed — 2 Reporting views (copy localized in place)
```
app/Modules/Reporting/Views/funnel.php
app/Modules/Reporting/Views/member_dashboard.php
```
Only literal English text became `lang('Reporting.*')` calls, with `{0}`/`{1}`
placeholders interpolated in PHP. The self-scoped data access, small-cohort
suppression handling, date formatting, and progress-bar math are unchanged.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-09.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Reporting -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Reporting/Views/tests/reporting_i18n_test.php   # 75 passed, 0 failed

# full suite
# 1107 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`funnel.*`/`member.*` groups (no missing/stray keys), `{0}`/`{1}` preserved in
`welcome`/`ptsToRank`/`asOf`, every view calls `lang('Reporting.*')` with no bare
English headings, and end-to-end renders in **fr** and **ar** with translated
headings/labels, correctly interpolated placeholders, verbatim free-form data
(names, titles, RSVP/role/status, group paths, verification IDs, suppression
markers like `<5`), and preserved numeric output (points, XP, counts).

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged; these strings also
  merge into the unified catalog + `GET /i18n/{locale}.json` bundle
  automatically — `FileCatalogProvider` auto-discovers `Modules/*/Language`, so
  there is **zero wiring change** for the new Reporting catalog.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- i18n module coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification, Groups, Courses, Streaming, Reporting**.
  Remaining view-bearing modules: **Community (1), Referrals (1 self-HTML)**.
