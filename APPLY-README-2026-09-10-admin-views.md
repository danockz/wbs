# WBS Platform — Bespoke views: Admin module (2026-09-10)

**Scope:** the fifth of the seven zero-view modules. No schema changes, no new
dependencies, no new routes. Overlay onto the working tree with `unzip -o`.

## What's added

The Admin module had no bespoke view — `GET /admin/groups/{id}/config/{capability}`
rendered the generic admin console. It now has an **effective configuration**
page: how a capability resolves for a group, showing the effective value, which
group the value came from, the version, the resolution decision, and the
inheritance mode — the human-readable face of `EffectiveConfigResolver`.

Wired via `AdminController::resolveGroupConfig()` — browsers get the view; API
clients (Accept: application/json / XHR / Bearer) still get the same JSON.

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new `Admin/Views/_locale.php`
  for a locale-aware `<html lang dir>` — **RTL for Arabic**.
- **Localized** via `lang('Admin.*')` with **English fallback**.
- `decision` and `inheritance_mode` are fixed vocabularies localized with a
  **raw-value fallback**; capability/group/source ids shown verbatim. The
  effective value renders booleans as localized Enabled/Disabled, scalars
  verbatim, and structured values as compact JSON, with graceful
  "No effective value" / "— none —" fallbacks.
- **Inline styles only** — renders in the sandboxed in-app preview too.

> i18n: new `Admin.*` keys ship in all six locales so the parity gate stays green.

## Files

**New**
- `app/Modules/Admin/Views/group_config.php`
- `app/Modules/Admin/Views/_locale.php`
- `app/Modules/Admin/Views/tests/admin_group_config_view_test.php`
- `app/Modules/Admin/Language/{en,fr,es,pt,zh,ar}/Admin.php`

**Changed**
- `app/Modules/Admin/Controllers/AdminController.php` (view wiring; JSON unchanged)

## Verify

```
php app/Modules/Admin/Views/tests/admin_group_config_view_test.php   # 34 passed, 0 failed
php tests/run-standalone.php                                          # 38 files, 1675 assertions, 0 failed
```
