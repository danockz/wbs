# Dynamic menu — the floor tier (derive-don't-store)

The "best" version, built and proven. It removes the last inefficiency of the
per-user cache: it stores **nothing per user**. A capability word is a pure
function of a subject's ROLE-SET, so we cache one word per ROLE (a table of a few
dozen bytes) and DERIVE any user's word on demand by OR-ing their roles' words.

## The chain of "best", honestly ranked

| Tier | Per-user cost | 500k footprint | Server work / navigation |
|------|---------------|----------------|--------------------------|
| Good — per-user word + ETag/304 | 1 word each | ~76 MB Redis | 304, or 8-byte get |
| Better — distinct-word memoization | shared by word | ~33 KB (trees) | render once per distinct word |
| **Best — derive-don't-store (this)** | **0 bytes** | **~123 B table + ~16 KB trees** | **OR-fold roles → render; nothing stored** |

## How it works

```
roleWord[r]  = OR over p in permissions(r) of (1 << bit(p))     // built once
word(user)   = OR over r in roles(user) of roleWord[r]          // derived on demand
visible(i)   = (word & item[i].mask) == item[i].mask            // Theta(1) per item
```

- `roleWord` is built from `role_permissions ⋈ permissions` (the data you already
  have) into a `RoleWordTable` — tiny, immutable, **versioned** by a fingerprint of
  its contents.
- A user's word is never stored. Adding a user costs **zero** server state; their
  word is computed from their role list (which you already store) in a few ORs.
- The distinct answers across the whole tenant number ~O(distinct role-sets), not
  O(users). Measured below: **500,000 users → 11 distinct menus.**

## Files (added this tier)

| File | Role |
|------|------|
| `RoleWordTable.php` | role → word table; `fromRolePermissions()`, `deriveWord(roleCodes)`, content `version()`. Fail-closed on unknown roles/bits. |
| `MenuBundle.php` | client/edge handoff. `assemble()` = per-user render bundle (word + masks + version) for local rendering; `authorityBundle()` = tenant-wide role→word table + catalog so the client derives ANY user's word itself. |
| `MenuService` (extended) | `deriveWord()` / `buildFromRoles()` (Tier-2 becomes an in-memory OR, no grant query); role-table `version()` folded into ETag + word key. |
| `tests/menu_floor_test.php` | 18 assertions incl. the 500k collapse, client-bundle equivalence, and role-edit invalidation. |

## Measured (sandbox, PHP 8.4)

```
[collapse]  500,000 users -> 11 distinct menus, 11 renders, ~456 ms total
[footprint] role->word table: 123 B | distinct trees: 11 x ~1540 B = ~16.5 KB
            per-user state: 0 B
```

- 18/18 floor assertions pass; prior tier still 30/30 (no regression).
- Client local fold (AND-mask against the shipped word) equals the server render
  exactly — rendering can leave the server entirely.
- Client can derive any user's word from the authority bundle alone (matches the
  server) — no per-user round trip.

## Where the server work actually goes now

- **Between grant/role changes:** ~nothing. Tier 0 (304) is a version compare a CDN
  absorbs; a client with the authority bundle renders and even scope-switches
  locally.
- **On a role-set change for a user:** derive again (a few ORs). No cache write.
- **On a role-PERMISSION change (rare, admin action):** rebuild the ~123 B table
  once, bump its version → every ETag/key changes → clients revalidate. O(1)
  invalidation, no scan, fleet-wide.
- **On a deploy (structure change):** catalog fingerprint changes → same O(1)
  fleet-wide invalidation (see DYNAMIC-MENU-PROTOTYPE.md).

## Run

```bash
php app/Modules/Shared/Navigation/tests/menu_floor_test.php        # 18/18
php app/Modules/Shared/Navigation/tests/menu_stub_test.php         # 30/30 (regression)
php app/Modules/Shared/Navigation/tests/menu_integration_test.php  # 18/18 (DB bridge + endpoints)
php app/Modules/Shared/Navigation/tests/menu_bench.php             # throughput + footprint
```

## Wired end-to-end (this pass)

The floor tier is now connected to real ACL data and exposed over HTTP.

### New pieces

| File | Role |
|------|------|
| `MenuWordProvider.php` | DB bridge (read-only). `roleWordTable(org)` from `role_permissions ⋈ permissions`; `roleCodesForSubject(org,user,scope)` from `role_assignments`; `grantVersion(org,user)`. Pure helpers (`buildTableFromRows`, `roleCodesFromRows`, `versionFromAssignmentRows`) are DB-free and unit-tested. |
| `Controllers/MenuController.php` | `GET /me/menu` (+`?bundle=1`), `GET /me/menu/authority`. Does the `If-None-Match` → 304 handshake (Tier 0). |
| `Config/Services.php` | `menuCatalog()` (shared), `menuWordProvider()` (shared), `menu(org)` (per-tenant, wired with that org's role→word table). |
| `Config/Routes.php` | the two routes above, behind `auth`. |
| `tests/menu_integration_test.php` | 18 assertions: table/role/scope/version from rows, derive→render→bundle, ETag/304 semantics, role-permission-edit invalidation. |

### Endpoints

```
GET /me/menu                 -> { categories:[…], count, scope, version, etag }
GET /me/menu?bundle=1        -> { word, items:[{…,mask}], categories, version }   (client renders locally)
GET /me/menu?scope=<groupId> -> menu for that active scope
GET /me/menu/authority       -> { roleWords:{code:word}, items, categories, version }  (client derives any word)
```

All send `ETag` + `Cache-Control: private, must-revalidate`. A matching
`If-None-Match` returns **304, empty body** — the render never runs.

### Versioning is STATELESS (no counter table)

The subject "grant version" is a short `xxh128` of their `(role_id, scope_group_id)`
assignment set — it changes exactly when their assignments change, is order-
independent, and needs **no write-path INCR and no new storage**. The role→word
table carries its own content fingerprint; the catalog carries its structure
fingerprint. The ETag composes all three, so authority edits, role-permission
edits, and deploys each invalidate correctly and fleet-wide with zero stored state.

### Note: aspirational catalog routes

A few starter catalog items (e.g. `events/create`, `members`) point at routes not
yet built — harmless, because the menu only affects DISPLAY and each real route
keeps its own `authorize:` filter. The planned anti-drift CI test (walk catalog →
assert route exists + permission matches the filter) will flag these as the missing
feature routes land; today they simply render as links to not-yet-implemented pages.

## The one discipline that keeps us at the floor

The 45,000:1 collapse holds ONLY while menu visibility is keyed on **coarse
capability** (permission bits). The moment an item's visibility depends on
**per-instance** state ("manage THIS event only if you own it"), it cannot live in
the shared word and it fractures the collapse.

Rule: **menu = coarse capability only; per-instance authorization stays on the
route/page** (the `authorize:` filter already enforces it). The bundle drives
DISPLAY only — the word is server-signed/versioned; a tampered word can mis-show a
link but never bypass the PDP. Keep that line and 500k concurrent users really does
cost a few kilobytes.

## Still-open levers (if you ever need lower)

- **Pack scope class into spare bits.** P=41 leaves 23 free bits; encoding a scope
  tier in the same word makes scope-switching a pure client re-mask, zero round trip.
- **Sign the word (JWT-style claim).** Ship it in the session token; the edge trusts
  it until the version changes — the app tier sees only revalidations.
- **Precompute the distinct-word set at deploy.** With ~11 answers per tenant you can
  pre-render and pin them at the edge; user requests never reach origin.
