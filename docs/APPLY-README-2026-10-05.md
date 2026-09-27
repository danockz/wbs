# WBS Platform — Fix Pack 2026-10-05

**Scope:** A **unified, resource-light translation read layer** that MERGES
translations from **all sources** — the bundled file catalogs already shipped
plus every **DB-backed** source (`notification_templates`, and the
world-countries-style Geo reference `translations` JSON columns) — into one
English-backed catalog per locale, and exposes it to the **frontend** as an
edge-cacheable JSON bundle.

Builds on `wbs-fixes-2026-10-04.zip`. **No schema changes, no new dependencies,
no migrations, no config edits required.** Purely additive code + one route.

---

## Why

Until now translations lived in two disconnected worlds:

- **File catalogs** — CI4-native `lang('Module.key')` PHP arrays with `{0}`
  positional placeholders (AccessControl, Events, Identity, Contributions,
  Gamification so far).
- **DB-authored content** — `notification_templates` (already per-locale,
  versioned by org/key/channel/locale/version) and Geo reference tables with a
  `translations JSON` column, using `:name` / `{{ns.field}}` placeholders.

The frontend needs **one** place to read a string regardless of where it was
authored. This pack adds that merge layer, with a hard **"don't burden server
resources"** constraint baked into the design.

---

## What changed

### New — merge core (`app/Modules/Shared/I18n/`)
```
Interpolator.php            Applies BOTH {0} positional AND :name / {{ns.field}} named
                            placeholders in one pass; missing tokens left literal.
TranslationProvider.php     Interface: one source -> flat {key: string} per locale,
                            plus an O(1) version() change-stamp.
TranslationRegistry.php     Merges providers (ascending priority; last wins) with
                            English merged UNDER the target locale as guaranteed
                            fallback; lazy, memoised, version-stamped cross-request cache.
Providers/FileCatalogProvider.php           Flattens app + module Language/*.php trees
                                            to dotted keys (e.g. "Identity.login.heading").
Providers/NotificationTemplateProvider.php  Active notification_templates rows ->
                                            "notif.<key>.<channel>.subject|body".
Providers/JsonColumnProvider.php            Generic `translations JSON` + `name` tables
                                            (Geo countries/regions/subregions/states) ->
                                            "geo.countries.<id>" etc.
```

### New — frontend delivery
```
app/Modules/Shared/Controllers/TranslationController.php
```
`GET /i18n/{locale}.json[?ns=Prefix,Prefix2]` — returns the merged, English-backed
catalog for one locale (optionally narrowed to dotted key prefixes). Public,
**edge-cacheable**, strong **ETag → 304** before any serialization.

### New — tests
```
app/Modules/Shared/I18n/tests/translation_registry_test.php   (39 assertions)
app/Modules/Shared/I18n/tests/i18n_wiring_test.php            (extended: +10 assertions, now 41)
```

### Changed — wiring
```
app/Modules/Shared/Config/Services.php   + translations() shared service (composition root)
app/Config/Routes.php                    + GET i18n/(:segment) -> TranslationController::bundle
```

---

## Resource discipline (the hard constraint)

The design keeps the **hot path effectively DB-free**:

1. **Lazy + memoised** — a locale's catalog is built only on first request and
   reused for the rest of the request as O(1) array reads.
2. **One load per provider per locale per rebuild** — DB providers run **one
   bounded query per locale** (never one query per string/template).
3. **Version-stamped cross-request cache** — the merged catalog is cached under a
   key embedding each provider's O(1) version token. After the first build, a
   request is a **single cache GET with no DB work** until data actually changes;
   a change flips a version → new key → exactly one rebuild.
4. **Version stamps are themselves short-TTL cached** (5 min) — so even forming
   the cache key costs at most one tiny `COALESCE(MAX(updated_at))` per table
   every few minutes, never a per-request scan.
5. **Frontend bundle is public + shared-cacheable** — no per-user data, so a CDN
   serves one copy per `(locale, ns, version)` to every visitor; `If-None-Match`
   yields a 304 with no body and no JSON encoding.
6. **Degrades gracefully** — with no DB (CLI/tests/offline) the file catalogs
   alone still serve a complete catalog; providers are injected, so the classes
   do no framework calls and stay unit-testable.

---

## Precedence & fallback

- Providers are supplied in **ascending priority**; later providers **override**
  earlier ones on key collision (so org/DB-authored copy can shadow bundled
  defaults). Wired order: **files → notification_templates → Geo**.
- **English is always merged under** the requested locale, so a missing
  translation degrades to English; a completely unknown key returns the key
  itself (callers can detect a miss by identity).

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-05.zip -d /path/to/project-root
```
Then re-apply into the running container and restart PHP (opcache). No
migrations, no `composer` changes, no env changes.

**Optional cache backend:** the layer auto-uses CI4's `cache()` when available.
No cache configured still works (it simply rebuilds per process instead of
per-version); configure the file/Redis cache handler to get the cross-request
savings described above.

---

## Verification

```bash
# lint
find app/Modules/Shared/I18n -name '*.php' -exec php -l {} \; | grep -v 'No syntax'

# merge-layer unit test (no DB needed)
php app/Modules/Shared/I18n/tests/translation_registry_test.php   # 39 passed, 0 failed

# wiring test (service + route + controller + real catalogs)
php app/Modules/Shared/I18n/tests/i18n_wiring_test.php            # 41 passed, 0 failed

# full suite
# 856 passed, 0 failed
```

The registry test proves: both placeholder styles interpolate; merge +
English-fallback + last-wins precedence; **one load per provider per locale** and
**no extra loads on repeat lookups**; the **version-stamped cache** serves a
second "request" without reloading and rebuilds exactly once on a version bump;
`notification_templates` and Geo `translations JSON` providers resolve + fall
back correctly; and the file provider flattens the **real shipped catalogs**.

---

## Frontend usage (example)

```
GET /i18n/fr.json                 -> { "locale":"fr", "strings": { "Identity.login.heading":"Connexion", … } }
GET /i18n/fr.json?ns=Identity     -> only Identity.* keys
GET /i18n/ar.json                 -> Arabic catalog (RTL handled by the layout separately)
```
`{0}` and `:name` tokens remain in the delivered strings for the client to fill,
matching the server-side `Interpolator` so both render identically.

---

## Notes / non-goals

- This is a **read/merge layer**; it does not modify how strings are authored.
  Existing `lang('Module.*')` server rendering is unchanged and still works.
- Adding a new DB translation source later = register one more provider in
  `translations()` (an injected loader + an O(1) version stamp). No new schema,
  no core changes.
- i18n module coverage (view strings) so far: **AccessControl, Events, Identity,
  Contributions, Gamification**. Remaining view-bearing modules (Groups, Courses,
  Streaming, Reporting, Community, Referrals) can resume next; all of their
  file + DB strings now flow through this unified layer automatically.
