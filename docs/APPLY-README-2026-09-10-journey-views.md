# WBS Platform — Bespoke views: Journey module (2026-09-10)

**Scope:** the first of the seven zero-view modules to receive a bespoke browser
view. No schema changes, no new dependencies, no new routes. Builds on the
sponsorship-views add-on; overlay onto the working tree with `unzip -o`.

## What's added

The Membership Journey module had **no** bespoke view — `GET /journey/pipeline`
(the richest read surface) fell back to the generic data-page. It now has a
proper **pipeline** page: a birds-eye of how many active members sit at each
stage of the discipleship ladder, ordered by the ladder's own sort order, with a
proportional share bar per stage and a phase (Win/Build/Send) chip.

Wired via `JourneyController::pipeline()` — browsers get the view; API clients
(Accept: application/json / XHR / Bearer) still get the exact same JSON.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new `Journey/Views/_locale.php`
  for a locale-aware `<html lang dir>` — **RTL for Arabic**.
- **Localized** via `lang('Journey.*')` with **English fallback**; the group-scope
  `{0}` label is interpolated in PHP via `$li()`; counts + share % computed in PHP
  (no ICU runtime dependency).
- The stage **phase** (win|build|send) is localized with a **raw-value fallback**;
  stage names/codes are server data shown verbatim & escaped.
- **Inline styles only** — renders in the sandboxed in-app preview too.

> Note on i18n: this pass is English-first *for view copy*, but because the repo
> enforces a global catalog-parity gate, the new `Journey.*` keys ship in all six
> locales (en/fr/es/pt/zh/ar) so the suite stays green. What's deferred to a later
> pass is broader per-endpoint view coverage, not these translations.

## Files

**New**
- `app/Modules/Journey/Views/pipeline.php`
- `app/Modules/Journey/Views/_locale.php`
- `app/Modules/Journey/Language/{en,fr,es,pt,zh,ar}/Journey.php`
- `app/Modules/Journey/Views/tests/journey_i18n_test.php`

**Changed (view wiring; JSON behaviour for API unchanged)**
- `app/Modules/Journey/Controllers/JourneyController.php`

## Verify

```
php app/Modules/Journey/Views/tests/journey_i18n_test.php   # 35 passed, 0 failed
php tests/run-standalone.php                                 # 34 files, 1509 assertions, 0 failed
```
