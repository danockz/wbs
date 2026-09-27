# WBS Platform — Fix Pack 2026-10-03

**Scope:** Internationalize the **Contributions** module's 6 member-facing views
(cause donor list, cause progress, giving commitments, giving metrics,
partnership status, partnership tiers). Adds `en, fr, es, pt, zh, ar`
translations and a regression test.

Builds on `wbs-fixes-2026-10-02.zip` (Identity view i18n). No schema changes,
no new dependencies, no config changes.

---

## Why

These views **extend the shared `layouts/app`**, which already emits a dynamic
`<html lang="…" dir="…">` (RTL for Arabic). So — unlike the self-contained
Identity pages — they only need their **copy** translated, following the same
pattern already shipped for the Events module:

- all body copy via `lang('Contributions.*')` with English as guaranteed fallback;
- numbers, percentages and **money** interpolated in PHP (no ICU `{}` runtime
  dependency), so output is correct with or without ext-intl;
- money stays in **minor units** and is formatted by the existing shared
  `_money.php` include — untouched;
- enum labels (commitment `status`, `frequency`) are localized but **fall back to
  the raw stored value** when a key is missing, so unknown values never break a
  page; the badge **color** stays code-driven and is never affected by
  translation.

---

## What changed

### New — language files (6 files)
```
app/Modules/Contributions/Language/en/Contributions.php   (English — guaranteed fallback, every key present)
app/Modules/Contributions/Language/fr/Contributions.php
app/Modules/Contributions/Language/es/Contributions.php
app/Modules/Contributions/Language/pt/Contributions.php
app/Modules/Contributions/Language/zh/Contributions.php   (Chinese — code `zh`)
app/Modules/Contributions/Language/ar/Contributions.php   (Arabic — RTL via shared layout)
```
Key groups: donor list (`donors`, `gift`/`gifts`, `anonymous`, …), cause
progress (`percentOfGoal` with `{0}`, `raised`, `target`, …), commitments
(nested `status.*` and `frequency.*` enum maps, `perFrequency`, `nextDue`,
`started`), giving metrics (`personalPgv`, `groupGgv`, `multiplier`, …),
partnership status (`level`, `givingStreak`, `lastGift`, …), partnership tiers
(`minPgvSuffix`, `consecutiveMonths` with `{0}`, …).

### New — test (1 file)
```
app/Modules/Contributions/Views/tests/contributions_i18n_test.php   (55 assertions)
```

### Changed — 6 Contributions views (copy localized in place)
```
app/Modules/Contributions/Views/cause_donors.php
app/Modules/Contributions/Views/cause_progress.php
app/Modules/Contributions/Views/commitments.php
app/Modules/Contributions/Views/metrics.php
app/Modules/Contributions/Views/partnership_status.php
app/Modules/Contributions/Views/partnership_tiers.php
```
The shared `_money.php` include and all money/number math are unchanged — only
literal English text became `lang('Contributions.*')` calls, with `{0}`
placeholders interpolated in PHP.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-03.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Contributions -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Contributions/Views/tests/contributions_i18n_test.php   # 55 passed, 0 failed

# full suite
# 741 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`status.*`/`frequency.*` groups (no missing/stray keys), `{0}` preserved in
`percentOfGoal` and `consecutiveMonths`, every view calls
`lang('Contributions.*')` with no bare English `<h1>`, and end-to-end renders in
**fr** and **ar** with translated headings/labels, localized enum values,
graceful fallback for unknown status/frequency, correct singular/plural counts,
and **preserved money/number output** (e.g. `GHS 1,500.00`, `1.25×`).

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged — this pack only
  consumes the already-shipped Phase 1 resolver and the layout's dynamic
  `lang`/`dir`.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- Translations are complete for every key across `fr/es/pt/zh/ar`; wording can be
  refined by a native reviewer with values-only edits (no code change).
