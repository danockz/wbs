# WBS Platform — Bespoke views: Integrations module (2026-09-10)

**Scope:** the fourth of the seven zero-view modules. No schema changes, no new
dependencies, no new routes. Overlay onto the working tree with `unzip -o`.

## What's added

The Integrations module had no bespoke view — `GET /integrations/catalog`
rendered the generic admin console. It now has an **integration catalog** page:
active provider adapters grouped by category, each card showing the adapter's
family, version, and its honestly-declared capabilities (FR-INT-011 — the UI
never claims operations an adapter does not declare).

Wired via `CatalogController::index()` — browsers get the view; API clients
(Accept: application/json / XHR / Bearer) still get the same JSON payload.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new
  `Integrations/Views/_locale.php` for a locale-aware `<html lang dir>` —
  **RTL for Arabic**.
- **Localized** via `lang('Integrations.*')` with **English fallback**; the `{0}`
  adapter count is interpolated in PHP via `$li()` with PHP singular/plural.
- Adapter **category** is a fixed vocabulary localized with a **raw-value
  fallback**; code/display_name/family and capability tokens are server data
  shown verbatim (capabilities humanized in-view). Capabilities are accepted as
  either an array or a JSON string.
- **Inline styles only** — renders in the sandboxed in-app preview too.

> i18n: new `Integrations.*` keys ship in all six locales so the parity gate stays green.

## Files

**New**
- `app/Modules/Integrations/Views/catalog.php`
- `app/Modules/Integrations/Views/_locale.php`
- `app/Modules/Integrations/Views/tests/integrations_catalog_view_test.php`
- `app/Modules/Integrations/Language/{en,fr,es,pt,zh,ar}/Integrations.php`

**Changed**
- `app/Modules/Integrations/Controllers/CatalogController.php` (view wiring; JSON unchanged)

## Verify

```
php app/Modules/Integrations/Views/tests/integrations_catalog_view_test.php   # 35 passed, 0 failed
php tests/run-standalone.php                                                   # 37 files, 1630 assertions, 0 failed
```
