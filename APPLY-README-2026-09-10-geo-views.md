# WBS Platform — Bespoke views: Geo module (2026-09-10)

**Scope:** the third of the seven zero-view modules. No schema changes, no new
dependencies, no new routes. Overlay onto the working tree with `unzip -o`.

## What's added

The Geo module had no bespoke view — `GET /venues` returned raw JSON to browsers.
It now has a **venue directory** page: the organization's facilities in a table
with type, status, capacity, discoverability, and formatted coordinates, plus a
"{0} of {1}" result count.

Wired via `VenueController::index()` — browsers get the view; API clients
(Accept: application/json / XHR / Bearer) still get the same JSON payload.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new `Geo/Views/_locale.php`
  for a locale-aware `<html lang dir>` — **RTL for Arabic**.
- **Localized** via `lang('Geo.*')` with **English fallback**; the "{0} of {1}"
  count is interpolated in PHP via `$li()`.
- Venue **status** is a fixed vocabulary localized with a **raw-value fallback**;
  `venue_type` is humanized in-view; names/capacity/coords shown verbatim, with
  graceful "—" / "Not geolocated" fallbacks.
- **Inline styles only** — renders in the sandboxed in-app preview too.

> i18n: new `Geo.*` keys ship in all six locales so the catalog-parity gate stays green.

## Files

**New**
- `app/Modules/Geo/Views/venues.php`
- `app/Modules/Geo/Views/_locale.php`
- `app/Modules/Geo/Views/tests/geo_venues_view_test.php`
- `app/Modules/Geo/Language/{en,fr,es,pt,zh,ar}/Geo.php`

**Changed**
- `app/Modules/Geo/Controllers/VenueController.php` (view wiring; JSON unchanged)

## Verify

```
php app/Modules/Geo/Views/tests/geo_venues_view_test.php   # 33 passed, 0 failed
php tests/run-standalone.php                                # 36 files, 1584 assertions, 0 failed
```
