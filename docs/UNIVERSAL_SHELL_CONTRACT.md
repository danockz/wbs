# Universal Shell Contract (Phase 1)

This phase introduces a single canonical browser document layout at:

- `/home/runner/work/wbs/wbs/app/Views/layouts/universal.php`

`layouts/app.php` is now a compatibility delegator to that layout, so existing `extend('layouts/app')` views keep working.

## Variants

The universal layout accepts data-driven shell variants (no duplicated document templates):

- `public`
- `authenticated`
- `auth`
- `admin`
- `minimal`
- `error`

Variants are supplied via `$wbsShellVariant` / `$shellVariant`.

## Shared shell composition

Canonical shell partials are under:

- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/context.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/head.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/topbar.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/menu.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/scripts.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/open.php`
- `/home/runner/work/wbs/wbs/app/Modules/Shared/Views/shell/close.php`

Legacy includes (`_shell_open.php` and `_shell_close.php`) are compatibility wrappers over these canonical partials.

## Asset capability allowlist

Only allowlisted, same-origin capabilities are loadable by page data:

- `shell-app-js` → global shell JS (`/assets/js/app*.js` via `Config\Assets` when available)
- `calendar` → local placeholder bundle (`/assets/js/capabilities/calendar.js`) when present

Rules:

- capability names must match `^[a-z0-9._-]+$`
- unknown capability names are ignored
- no arbitrary script/style URLs are accepted
- no CDN references

## Locale / RTL

Locale and direction remain server-authoritative through existing locale configuration:

- `<html lang>` and `<html dir>` are derived server-side
- locale switcher remains server-posted (`/prefs/locale`)
- language catalogs remain authoritative

## AJAX / security

Normal server-rendered links and forms remain primary. JS is progressive enhancement only:

- `assets/js/app.js` enhances `form[data-ajax]` submits only
- CSRF token fallback remains same-origin cookie + header strategy
- no inline event handlers
- no `javascript:` URLs
- no SPA routing

## Foldable menu shell behavior

The shell now supports:

- mobile off-canvas menu toggle
- desktop collapse/expand menu state
- Escape-to-close on mobile
- focus handoff to menu/toggle on open/close
- best-effort persistence via `localStorage` with safe fallback when blocked

The menu remains server-authoritative and localized through `MenuFragment` + `/me/menu`.

## Later migration sequence (mechanical/reversible)

1. Keep converting self-contained views to extend `layouts/universal`.
2. Preserve compatibility wrappers until all modules migrate.
3. Add page capabilities only through allowlisted entries.
4. Retire compatibility wrappers and legacy injection fallback when no longer needed.
