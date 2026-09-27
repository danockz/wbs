# WBS Platform — Combined overlay: bespoke views for all 7 zero-view modules (2026-09-10)

Single overlay that supersedes the seven per-module zips. Apply from the repo
root:

```
unzip -o wbs-fixes-2026-09-10-all-views.zip
php tests/run-standalone.php     # 40 files, 1774 assertions, 0 failed
```

No schema changes, no new dependencies. Each module gained a browsable,
fully-localized landing page; three modules that were pure-mutation controllers
(AccessControl already had a read path; **Notifications** and **Meetings** did
not) received a modest **read-only endpoint** (service `list()` + controller
`index()` + a new GET route) to have something to render.

## Modules & new pages (86 files total)

| Module | Route | Page |
|---|---|---|
| Journey | `GET /journey/pipeline` | Disciple-making pipeline |
| AccessControl | `GET /roles` | Roles |
| Geo | `GET /geo/*` | Location reference data |
| Integrations | `GET /integrations/catalog` | Adapter catalog |
| Admin | `GET …/group-config` | Effective group config |
| Notifications | `GET /notifications/campaigns` | Broadcast campaigns |
| Meetings | `GET /meetings` | Meeting schedule |

## Conventions honoured (every page)

- **Self-contained** `<html>` including a per-module `Views/_locale.php` for a
  locale-aware `<html lang dir>` — **RTL for Arabic**.
- **Browser never sees raw JSON**: controllers negotiate — browsers get the
  bespoke view, API clients get JSON.
- **Localized** via `lang('<Module>.*')` with **English fallback**; counts
  interpolated in PHP via `$li()` with PHP singular/plural.
- **Fixed vocabularies** (status/priority/provider/mode/access/decision/…)
  localized with a **raw-value fallback**; server data shown verbatim.
- All six locales (en/fr/es/pt/zh/ar) at **full key parity**.
- **Inline styles only** — renders in the sandboxed in-app preview too.

## Shared/cross-cutting files (final cumulative state, once each)

These are included at their latest state so the whole set applies cleanly in one
shot — do NOT also overlay the per-module zips on top of this one.

- `app/Config/Routes.php` — all seven new GET routes.
- `app/Modules/Shared/Navigation/CoreMenuProvider.php` — dynamic-menu entries for
  every new page (visibility derived from existing route `authorize:` codes; no
  parallel permission list).
- `app/Language/{en,fr,es,pt,zh,ar}/App.php` — `menuItems.*` labels for the new
  menu entries (menu-i18n single-source-of-truth).
- `public/openapi.json` — regenerated (378 paths / 437 operations).

## What each module contributes

For every module `M` (with its page file `P`):

- `app/Modules/M/Views/P.php` + `app/Modules/M/Views/_locale.php`
- `app/Modules/M/Views/tests/*_view_test.php`
- `app/Modules/M/Language/{en,fr,es,pt,zh,ar}/M.php`
- Controller edit (new `index`/page action; existing JSON/mutation actions unchanged)
- Notifications & Meetings only: Service edit (new read-only `list()`)

## Guards satisfied

`php tests/run-standalone.php` is green end-to-end, including: menu coverage,
menu antidrift (permission/scope alignment), menu i18n parity, catalog parity,
and openapi freshness.
