# WBS Platform — Fix Pack 2026-10-12

**Scope:** Final i18n **consolidation pass** — (1) close the dynamic menu's
language-awareness gap so menu ITEM labels localize (categories already did), and
key the menu caches by locale; (2) add two repo-wide i18n regression tests; (3)
document the menu i18n contract.

Builds on `wbs-fixes-2026-10-11.zip` (Referrals i18n — completed the view
backlog). No schema changes, no new dependencies, no config/route changes.

---

## Why

The view i18n backlog is complete, but an audit of the dynamic menu found a gap:
**category** headers were localized (`lang('App.menu.<key>')`) while **item**
labels (`Dashboard`, `Create event`, …) were hardcoded English and passed through
`MenuItem::toArray()` / `MenuBundle` untranslated. The per-user menu ETag already
carried a locale term, but the shared authority bundle did not — a CDN could
cross-serve one language to another — and item labels never actually changed with
locale.

---

## What changed

### Menu item localization (single source of truth)
- `MenuItem::displayLabel()` — resolves `lang('App.menuItems.<id>')` (item id with
  dots → underscores, addressing a FLAT key), falling back to the hardcoded English
  `$label` in CLI/tests or on a missing key. `toArray()` now emits it.
- `MenuBundle::assemble()` + `authorityBundle()` — both emit `displayLabel()`.
- Resolution happens only on the rare (re)build path, never on the 304 hot path.

### Menu strings (6 locales)
- `app/Language/<locale>/App.php` gains a **`menuItems`** block: 55 keys, one per
  catalog item, translated in `en, fr, es, pt, zh, ar`. English values are kept
  identical to the `CoreMenuProvider` labels (enforced by test).

### Locale as cache identity (no edge cross-serve)
- `MenuBundle::version()` + `authorityBundle()` version fold in the active locale.
- `MenuController`:
  - per-user `GET /me/menu` — ETag now `"m<grantVer>.<scope>.<catalogVer>.<roleTblVer>.<locale>"`;
    `Vary` adds `Accept-Language`; sets `Content-Language`.
  - `GET /me/menu/authority` — ETag folds locale; `Vary` adds `Accept-Language, Cookie`;
    sets `Content-Language`; `Surrogate-Key` gains `menu-authority-<locale>-org-<org>`.
  - new session-free/DB-free `activeLocale()` (CI4 request locale → `?lang=` →
    `wbs_locale` cookie → `en`), same discipline as `activeScope()`.
- `public/assets/js/menu.js` — `localStorage` cache key is now
  `wbs.menu.<scope>.<locale>`, so a language switch paints its own cached tree with
  no flash of the previous language.

### Tests (2 new, repo-wide)
```
app/Modules/Shared/Navigation/tests/menu_i18n_test.php       (32 assertions)
app/Modules/Shared/I18n/tests/catalog_parity_test.php        (142 assertions)
```
- `menu_i18n_test` — item/category key + label parity vs the catalog across all
  locales; `displayLabel()` localization + English fallback; localized labels in
  `toArray()` and both bundle shapes; locale folded into `etag()`/`etagFor()`/bundle
  versions.
- `catalog_parity_test` — sweeps **every** Language tree (app + 11 modules): each
  non-English locale mirrors the English key set exactly (nested keys flattened,
  no missing/stray) with `{0}`/`{1}` placeholder integrity. This is the standing
  guarantee that no future module or key can silently diverge from English.

### Docs
- `docs/DYNAMIC-MENU-I18N.md` (new) — what's localized, where strings live, and how
  locale becomes cache identity.
- `docs/DYNAMIC-MENU-EDGE.md` — header-contract table updated with the `<locale>`
  ETag term, `Vary: Accept-Language`, `Content-Language`, and the per-locale
  surrogate key.

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-12.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No migrations,
no `composer` changes, no env changes. (The browser will fetch the updated
`menu.js`; old cache keys simply age out.)

---

## Verification

```bash
# lint
find app -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# targeted
php app/Modules/Shared/Navigation/tests/menu_i18n_test.php     # 32 passed, 0 failed
php app/Modules/Shared/I18n/tests/catalog_parity_test.php      # 142 passed, 0 failed

# full suite
# 1357 passed, 0 failed
```

Cross-module parity: **13 English catalogs, 611 keys × 5 locales, 0 problems.**

---

## Notes / non-goals

- The menu stays DISPLAY-only; route `authorize:` filters remain the security
  boundary. Localization touches labels + cache identity only, never visibility.
- Resource-light rules preserved: no per-string DB query, 304 hot path unchanged,
  authority bundle still edge-shareable (now keyed by org + locale + version).
- **i18n program status:** all view-bearing modules localized (packs 09-29 → 10-11)
  **and** the universal dynamic menu is now language-aware end to end, with two
  standing regression tests to keep every locale in parity.
