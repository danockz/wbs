# WBS Platform — Bespoke views: AccessControl module (2026-09-10)

**Scope:** the second of the seven zero-view modules. No schema changes, no new
dependencies, no new routes. Overlay onto the working tree with `unzip -o`.

## What's added

AccessControl reads previously rendered through the generic branded admin
console (compliant, but not bespoke). It now has a flagship **role catalogue**
page for `GET /access-control/roles`: every role in the organization with its
permission grants shown as code chips, a per-role permission count, and a
collapse ("+N more") when a role carries many permissions.

Wired via `RoleController::index()` — browsers get the bespoke view; API clients
(Accept: application/json / XHR / Bearer) still get the same JSON payload (via
the admin-console JSON path).

## Conventions honoured

- **Self-contained page** (own `<html>`) including a new
  `AccessControl/Views/_locale.php` for a locale-aware `<html lang dir>` —
  **RTL for Arabic**.
- **Localized** via `lang('AccessControl.rolesView.*')` with **English fallback**;
  the `{0}` role count is interpolated in PHP via `$li()` with PHP singular/plural.
- Role code/name and permission codes are server data shown **verbatim & escaped**.
- **Inline styles only** — renders in the sandboxed in-app preview too.

> i18n: the new `rolesView.*` keys ship in all six locales (en/fr/es/pt/zh/ar) so
> the repo-wide catalog-parity gate stays green.

## Files

**New**
- `app/Modules/AccessControl/Views/roles.php`
- `app/Modules/AccessControl/Views/_locale.php`
- `app/Modules/AccessControl/Views/tests/accesscontrol_roles_view_test.php`

**Changed**
- `app/Modules/AccessControl/Controllers/RoleController.php` (view wiring; JSON unchanged)
- `app/Modules/AccessControl/Language/{en,fr,es,pt,zh,ar}/AccessControl.php` (rolesView.* keys)

## Verify

```
php app/Modules/AccessControl/Views/tests/accesscontrol_roles_view_test.php   # 31 passed, 0 failed
php tests/run-standalone.php                                                   # 35 files, 1540 assertions, 0 failed
```
