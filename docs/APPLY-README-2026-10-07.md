# WBS Platform — Fix Pack 2026-10-07

**Scope:** Internationalize the **Courses** module's 3 views — the course
catalogue (`index`), the authoring/overview page, and the learner syllabus. Adds
`en, fr, es, pt, zh, ar` translations and a regression test.

Builds on `wbs-fixes-2026-10-06.zip` (Groups i18n). No schema changes, no new
dependencies, no config changes.

---

## Why

All three views **extend the shared `layouts/app`**, which already emits a
dynamic `<html lang dir>` (RTL for Arabic). So — like Events, Contributions and
Gamification — they only need their **copy** translated, following the
established pattern:

- all body copy via `lang('Courses.*')` with English as guaranteed fallback;
- counts interpolated + singular/plural chosen in PHP (no ICU `{}` runtime
  dependency), so output is correct with or without ext-intl;
- data-driven "enum" values — course `status` (draft/published/archived) and
  lesson `drip` — are localized but **fall back to the raw stored value** when a
  key is missing, so custom values never break a page; the status **color**
  stays code-driven and is never affected by translation.

---

## What changed

### New — language files (6 files)
```
app/Modules/Courses/Language/en/Courses.php   (English — guaranteed fallback, every key present)
app/Modules/Courses/Language/fr/Courses.php
app/Modules/Courses/Language/es/Courses.php
app/Modules/Courses/Language/pt/Courses.php
app/Modules/Courses/Language/zh/Courses.php   (Chinese — code `zh`)
app/Modules/Courses/Language/ar/Courses.php   (Arabic — RTL via shared layout)
```
Key groups: catalogue (`title`, course/lesson plurals, `empty`), nested
`status.*` and `drip.*` maps, overview (`lessonsLbl`, `required`, `description`,
`contentAttached`/`noContent`, `courseMeta` with `{0}`, `viewSyllabus`), and
learner syllabus (`syllabusTitle`, `unlocks` with `{0}`, `available`,
`contentLabel` with `{0}`).

### New — test (1 file)
```
app/Modules/Courses/Views/tests/courses_i18n_test.php   (41 assertions)
```

### Changed — 3 Courses views (copy localized in place)
```
app/Modules/Courses/Views/index.php
app/Modules/Courses/Views/overview.php
app/Modules/Courses/Views/syllabus.php
```
Only literal English text became `lang('Courses.*')` calls, with `{0}`
placeholders interpolated in PHP. Lesson positions, counts, and the private
content-ref handling are unchanged.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-07.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Courses -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Courses/Views/tests/courses_i18n_test.php   # 41 passed, 0 failed

# full suite
# 962 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`status.*`/`drip.*` groups (no missing/stray keys), `{0}` preserved in
`courseMeta`/`unlocks`, every view calls `lang('Courses.*')` with no bare English
`<h1>`, and end-to-end renders in **fr** and **ar** with translated
headings/labels, localized status/drip with graceful unknown-value fallback,
correct singular/plural counts, and preserved numeric output (lesson counts,
positions).

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged; these strings also
  merge into the unified catalog + `GET /i18n/{locale}.json` bundle automatically.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- i18n module coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification, Groups, Courses**. Remaining view-bearing
  modules: **Streaming (3), Reporting (2), Community (1), Referrals (1)**.
