# Dynamic menu — language awareness

The universal menu is fully localized in all six supported locales
(`en, fr, es, pt, zh, ar`, `ar` right-to-left) **without** giving up any of the
zero-server-burden / edge-cache properties described in the other
`DYNAMIC-MENU-*` docs. This note records what is localized, where the strings
live, and — most importantly — how the locale is folded into cache identity so a
CDN never cross-serves one language to another.

---

## What is localized

| Surface | Source of the label | Resolver |
|---------|---------------------|----------|
| **Category** headers (Overview, People, …) | `App.menu.<categoryKey>` | `MenuCategory::label()` |
| **Item** labels (Dashboard, Create event, …) | `App.menuItems.<id>` | `MenuItem::displayLabel()` |
| Launcher a11y (`Open menu`, `Primary navigation`) | `App.menu.openMenu` / `primaryNav` | view / fragment |

Both resolvers follow the same discipline as the rest of the platform's i18n:

- resolve `lang('App.…')` **only when the framework is booted**; in CLI/tests they
  fall back to the hardcoded English carried on the object, so a raw key can never
  render;
- English is the guaranteed fallback — a missing translation in any locale degrades
  to English, never to `App.menuItems.foo`.

### `MenuItem::displayLabel()`

The item id (`overview.dashboard`) is addressed as a **flat** key with dots
collapsed to underscores — `App.menuItems.overview_dashboard` — so the catalog is a
single flat map, not a nested tree. The English `$label` passed to the `MenuItem`
constructor stays the fallback and the single source of truth: the
`menu_i18n_test` asserts the English `menuItems` value equals the provider label for
every catalog item, and `menu_antidrift` keeps ids and routes honest.

Resolution happens only when the tree/bundle is actually (re)built — the non-304
path, which is already the rare tier — so localization adds **nothing** to the
common 304 hot path.

---

## Where the strings live

```
app/Language/<locale>/App.php
  ├── 'menu'      => [ overview, people, …, openMenu, primaryNav ]   # category + chrome
  └── 'menuItems' => [ overview_dashboard, people_members, … ]        # 55 item labels
```

The `catalog_parity_test` (repo-wide) guarantees every non-English `App.php`
mirrors the English key set exactly, including `menu.*` and `menuItems.*`, with no
missing/stray keys and no placeholder drift.

---

## Locale as cache identity (the important part)

The menu is delivered as ONE cacheable resource. Because labels are localized at
build time, **the locale is part of what identifies a cached menu.** If it were not,
a shared edge could hand a French tree to an English user, or a browser could paint
a stale-language menu after a switch. Three places carry the locale:

1. **Per-user `GET /me/menu`** — `MenuService::etag($grantVer, $scope, $locale)`
   folds the active locale into the ETag; the response adds `Accept-Language` to
   `Vary` (and keeps `Cookie`, which carries `wbs_locale`) and sets
   `Content-Language`. A language switch therefore fails the `If-None-Match` check
   and re-renders, while everything else still 304s.

2. **`GET /me/menu/authority`** (shared, tenant-wide, `public, s-maxage=86400`) —
   the bundle `version` (which doubles as the ETag body) folds in the locale, the
   response adds `Accept-Language`/`Cookie` to `Vary`, sets `Content-Language`, and
   the `Surrogate-Key` gains a `menu-authority-<locale>-org-<org>` tag so a CDN
   caches one copy **per (org, locale, version)** and can purge a single language.

3. **Client (`public/assets/js/menu.js`)** — the `localStorage` cache key is
   `wbs.menu.<scope>.<locale>`, so the instant-paint-from-cache step renders the
   correct language immediately; a scope **or** language switch paints its own
   cached tree with no flash of the previous language, then revalidates.

The locale is read the same session-free, DB-free way everywhere: the CI4 request
locale that the global `LocaleFilter` already resolved (from `?lang=` → `wbs_locale`
cookie → org default → geo → `Accept-Language` → `en`), never the session.

---

## Resource-light guarantees preserved

- No per-string DB query and no per-request disk scan: labels come from the
  bundled file catalogs (memoised) and are resolved only on the rare build path.
- The 304 hot path is untouched — it compares an ETag string; the locale is just
  one more term in it.
- The authority bundle stays shareable at the edge; it is simply keyed by locale in
  addition to org + version, so cross-language traffic collapses to one copy per
  language rather than one per user.

---

## Tests

| Test | Covers |
|------|--------|
| `Shared/Navigation/tests/menu_i18n_test.php` (32) | item/category key + label parity vs catalog across all locales; `displayLabel()` localization + English fallback; localized labels in `toArray()` + both `MenuBundle` shapes; locale folded into `etag()`/`etagFor()`/bundle versions |
| `Shared/I18n/tests/catalog_parity_test.php` (142) | repo-wide: every module + `App.php` mirrors English keys (incl. `menu.*`/`menuItems.*`) with placeholder integrity |
| `Shared/Navigation/tests/menu_headers_test.php` | the documented `Cache-Control`/`ETag`/`Vary`/`Surrogate-Key` contract still holds |
| `Shared/I18n/tests/i18n_wiring_test.php` | menu ETag + category labels are locale-aware end to end |
