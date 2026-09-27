# WBS Platform — Fix Pack 2026-10-04

**Scope:** Internationalize the **Gamification** module's 9 member-facing views
(group & individual leaderboards, achievements catalogue, points balance, group
standing, ranks ladder, personal standing, streaks, and a subject's unlocked
achievements). Adds `en, fr, es, pt, zh, ar` translations and a regression test.

Builds on `wbs-fixes-2026-10-03.zip` (Contributions i18n). No schema changes,
no new dependencies, no config changes.

---

## Why

These views **extend the shared `layouts/app`**, which already emits a dynamic
`<html lang="…" dir="…">` (RTL for Arabic). So — like Events and Contributions —
they only need their **copy** translated, following the established pattern:

- all body copy via `lang('Gamification.*')` with English as guaranteed fallback;
- numbers, positions and counts interpolated in PHP (no ICU `{}` runtime
  dependency), so output is correct with or without ext-intl;
- "enum-ish" values that are **admin/data-driven** — leaderboard `measure`
  names, the leaderboard **axis bits** (category/project/phase/type/parent), and
  achievement **phase** labels — are localized but **fall back to the raw stored
  value** when a key is missing, so custom measures/phases never break a page;
- badge/phase **colors stay code-driven** and are never affected by translation.

---

## What changed

### New — language files (6 files)
```
app/Modules/Gamification/Language/en/Gamification.php   (English — guaranteed fallback, every key present)
app/Modules/Gamification/Language/fr/Gamification.php
app/Modules/Gamification/Language/es/Gamification.php
app/Modules/Gamification/Language/pt/Gamification.php
app/Modules/Gamification/Language/zh/Gamification.php   (Chinese — code `zh`)
app/Modules/Gamification/Language/ar/Gamification.php   (Arabic — RTL via shared layout)
```
Key groups: shared leaderboard chrome (`seasonRankedBy` with `{0}`/`{1}`,
`withinGroup`, empty states, member/achievement plurals), nested `measure.*`,
`axis.*` and `phase.*` maps, achievements catalogue (`secret`, `xpSuffix`),
points balance, group standing (`seasonByInline`), ranks ladder
(`minPointsSuffix`), personal standing, streaks (`best`/`lastInline`/
`frozenUntil`), and user achievements (`unlockedCount`, `ptsSuffix`,
`inProgress`).

### New — test (1 file)
```
app/Modules/Gamification/Views/tests/gamification_i18n_test.php   (66 assertions)
```

### Changed — 9 Gamification views (copy localized in place)
```
app/Modules/Gamification/Views/_board_groups.php
app/Modules/Gamification/Views/_board_subjects.php
app/Modules/Gamification/Views/achievements.php
app/Modules/Gamification/Views/balance.php
app/Modules/Gamification/Views/group_standing.php
app/Modules/Gamification/Views/ranks.php
app/Modules/Gamification/Views/standing.php
app/Modules/Gamification/Views/streaks.php
app/Modules/Gamification/Views/user_achievements.php
```
Only literal English text became `lang('Gamification.*')` calls, with `{0}`/`{1}`
placeholders interpolated in PHP. All ranking/point math is unchanged.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-04.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Gamification -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Gamification/Views/tests/gamification_i18n_test.php   # 66 passed, 0 failed

# full suite
# 807 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`measure.*`/`axis.*`/`phase.*` groups (no missing/stray keys), `{0}`/`{1}`
preserved in `seasonRankedBy` and `xpSuffix`, every view calls
`lang('Gamification.*')` with no bare English `<h1>`, and end-to-end renders in
**fr** and **ar** with translated headings/labels, localized measure/axis/phase
values, graceful fallback for unknown measure/phase, correct singular/plural
counts, and **preserved numeric output** (positions like `#3`, points, XP).

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged — this pack only
  consumes the already-shipped Phase 1 resolver and the layout's dynamic
  `lang`/`dir`.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- Translations are complete for every key across `fr/es/pt/zh/ar`; wording can be
  refined by a native reviewer with values-only edits (no code change).
- i18n coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification**. Remaining view-bearing modules: Groups,
  Courses, Streaming, Reporting, Community, Referrals.
