# WBS Platform — Fix Pack 2026-10-08

**Scope:** Internationalize the **Streaming** module's 3 views — the streams
listing (`index`), the organizer `dashboard`, and the overlay & co-host
`overlays` console. Adds `en, fr, es, pt, zh, ar` translations and a regression
test.

Builds on `wbs-fixes-2026-10-07.zip` (Courses i18n). No schema changes, no new
dependencies, no config changes.

---

## Why

All three views **extend the shared `layouts/app`**, which already emits a
dynamic `<html lang dir>` (RTL for Arabic). So — like Events, Contributions,
Gamification and Courses — they only need their **copy** translated, following
the established pattern:

- all body copy via `lang('Streaming.*')` with English as guaranteed fallback;
- counts interpolated + singular/plural chosen in PHP (no ICU `{}` runtime
  dependency), so output is correct with or without ext-intl;
- `{0}`-style placeholders (`started {0}`, `expires {0}`, `retrieved {0}`,
  `Stream {0}` …) are interpolated in-view with a small `str_replace` helper;
- data-driven "enum" values — stream `status` (live/ended/scheduled/…),
  `access_policy`, provider-metric `exactness`, co-host `role`, and
  `overlay_type` — are localized but **fall back to the raw stored value** when
  a key is missing, so unknown provider values never break a page; status
  **colours** and **money formatting** stay code-driven and are never affected
  by translation;
- provider-supplied identifiers (metric names, provider names, destination
  labels) stay **verbatim** — they are data, not UI copy.

---

## What changed

### New — language files (6 files)
```
app/Modules/Streaming/Language/en/Streaming.php   (English — guaranteed fallback, every key present)
app/Modules/Streaming/Language/fr/Streaming.php
app/Modules/Streaming/Language/es/Streaming.php
app/Modules/Streaming/Language/pt/Streaming.php
app/Modules/Streaming/Language/zh/Streaming.php   (Chinese — code `zh`)
app/Modules/Streaming/Language/ar/Streaming.php   (Arabic — RTL via shared layout)
```
57 keys per locale. Groups: listing (`title`, stream plurals, `liveFirst`,
`empty`, `started` with `{0}`), nested `status.*`/`access.*`/`exactness.*`/
`role.*`/`overlayType.*` maps, dashboard (`engagement`, `chatMessages`,
`polls`, `providerMetrics`, `source`/`retrieved` with `{0}`), and the overlays
console (`cohostsHeading`, `noCohosts`/`noOverlays` with `{0}`, `tokenActive`/
`tokenExpires`/`tokenExpired`/`noToken`, `versionTag`, `live`/`hidden`,
`liveFromLedger`, `updatedAt`, `consoleNote` with `{0}`).

### New — test (1 file)
```
app/Modules/Streaming/Views/tests/streaming_i18n_test.php   (70 assertions)
```

### Changed — 3 Streaming views (copy localized in place)
```
app/Modules/Streaming/Views/index.php
app/Modules/Streaming/Views/dashboard.php
app/Modules/Streaming/Views/overlays.php
```
Only literal English text became `lang('Streaming.*')` calls, with `{0}`
placeholders interpolated in PHP. The token-secret discipline (status only,
never the hash/plaintext), the ledger-recomputed cause figures, and money
formatting are all unchanged.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-08.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

---

## Verification

```bash
# lint
find app/Modules/Streaming -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted i18n test
php app/Modules/Streaming/Views/tests/streaming_i18n_test.php   # 70 passed, 0 failed

# full suite
# 1032 passed, 0 failed
```

The i18n test checks: all 6 locales mirror the English key set incl. nested
`status.*`/`access.*`/`exactness.*`/`role.*`/`overlayType.*` groups (no
missing/stray keys), `{0}` preserved in `started`/`noCohosts`/`consoleNote`,
every view calls `lang('Streaming.*')` with no bare English headings, and
end-to-end renders in **fr** and **ar** with translated headings/labels,
localized status/access/exactness/role/overlay-type with graceful
unknown-value fallback, correct singular/plural counts, interpolated `{0}`
placeholders, and preserved numeric + money output (viewer counts, versions,
percentages, amounts).

---

## Notes / non-goals

- Locale negotiation and the shared layout are unchanged; these strings also
  merge into the unified catalog + `GET /i18n/{locale}.json` bundle
  automatically — `FileCatalogProvider` auto-discovers `Modules/*/Language`, so
  there is **zero wiring change** for the new Streaming catalog.
- English remains the guaranteed fallback via CI4 `lang()` resolution.
- i18n module coverage after this pack: **AccessControl, Events, Identity,
  Contributions, Gamification, Groups, Courses, Streaming**. Remaining
  view-bearing modules: **Reporting (2), Community (1), Referrals (1
  self-HTML)**.
