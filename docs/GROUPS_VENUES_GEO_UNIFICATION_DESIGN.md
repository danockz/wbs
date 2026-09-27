# Design: Groups + Venues + Geo Unification
## The global→local drill-down for the physical group / location directory

**Status:** ✅ SHIPPED 2026-09-20, then **CORRECTED 2026-09-20** (see the banner below)
**Date:** 2026-09-20
**Author:** Platform agent
**Authoritative inputs:**
- `uploads/Pre_Development_Decision_Guide.md` §8 (Geographic reference & location design), §7.3 (Groups), FR-EVT-001/002 (venue modes), FR-NOT-005 (geo targeting).
- `uploads/db_schema.md` — the **legacy** church-CMS schema being rebuilt (informative precedent, not a target: bigint IDs, `groups.venue_id` FK, `group_venues` join, venues carrying full geo hierarchy, `families.town_village_id`).
- `uploads/locationservice.md` — the legacy `LocationService` (informative: `getVenueLocationHierarchy()` walks town→city→state→country→subregion→region at depth 6; `getGeoHierarchyBatch()` for bulk resolution).
- Current codebase: `app/Modules/{Geo,Groups}`, `docs/SRS_CURRENT_STATE.md`.
- UI language: dark-theme card + sidebar layout (member dashboard / courses screenshots).

---

> ## ⚠ CORRECTION (2026-09-20): a group's geo comes FROM ITS VENUE — it is not replicated
>
> The first cut of this design denormalized resolved geo **onto `groups`** as well as
> onto `venues` (migration `000078`, plus the pre-existing `000064` columns) so the
> directory could read a single table. That was wrong: it made **three** entities carry
> the same fact — group, venue and the Geo reference hierarchy — so `groups` and
> `venues` became two competing sources of truth for one location, and every venue move
> became a two-table write with a fan-out re-stamp.
>
> **The corrected model is a single chain:**
>
> ```
> groups.primary_venue_id ──► venues ──► Geo reference (countries / states / cities / towns_villages)
>                                 └── venues.latitude / venues.longitude      (GPS)
>                                 └── venues.{country,state,city,town_village}_id + *_label
> ```
>
> A group **has no coordinates and no place ids of its own**. It *meets somewhere*, and
> that somewhere is the venue; readers join the venue and take its geo. A group with no
> venue is simply **Unlocated** — it is *not* "located by its own copy", and
> `groups.address_id` is a postal/contact address, **not** a geo fallback.
>
> **What changed in code:** migration `000085_GroupGeoLivesOnVenue` drops the replicated
> columns from `groups` (`geo_country_id`, `geo_state_id`, `geo_city_id`, `country_label`
> from 000078; `location_group_key`, `region_label`, `city_label`, `latitude`,
> `longitude` from 000064) and their two indexes. `LocationSyncService` lost
> `stampGroup()` / `restampGroupsForVenue()` entirely — it now writes **one** table.
> `GroupPublicService::{directory,geoDirectory}()` and `GroupService::listForOrgWithGeo()`
> resolve geo through the primary venue. Kept on `groups`: `primary_venue_id` (the link),
> `address_id` (postal), `location_text` (free-text human description — not geo).
>
> **Why this is better, concretely:** moving a venue is now a ONE-row write and every
> group that meets there follows automatically — no fan-out, no drift window, no second
> copy to reconcile. The read cost is a single LEFT JOIN on an indexed pointer
> (`groups_primary_venue_idx`), which is not the thing that needed optimizing.
>
> Sections below are corrected in place; §3 D1, §4.1–4.3 and §9 carry the amendments.

---

## 1. Problem statement

Groups, Venues, and Geo are each individually complete but **loosely coupled**, and the coupling can silently drift:

1. **Two independent addresses.** A group's physical location comes from `groups.address_id`; a venue's from `venues.address_id`. When a group *meets at* a venue, these are two separate address rows that can disagree. There is no notion of "this group's home venue."
2. **Venues can't be drilled by geo.** `venues` carries only `address_id` + raw lat/long — **no** resolved `country_id`/`state_id`/`city_id` of its own. To bucket venues by place you must join through `addresses` every time (or you simply can't group them cheaply).
3. **Denormalized group labels are never synced.** `groups.region_label` / `city_label` / `location_group_key` (migration 000064) are written by nothing — dead columns the public directory doesn't trust, so `directoryByLocation()` re-joins `addresses` on every render. *(Correction: the fix is not to start populating them — it is to **delete** them. A group's place is its venue's place; see the banner above.)*
4. **No unified directory.** `/g` nests **groups** Country→State→City, and `/venues` lists venues flat. There is no single global→local drill-down that shows *place → venues there → the groups that meet there*.

The legacy system the platform is replacing had solved the shape (though crudely): `groups.venue_id` was a direct FK to a **primary venue**, a `group_venues` join carried `assignment_type`, and **venues themselves carried the full geo hierarchy** (`country_id`, `state_id`, `city_id`, `town_village_id`), so a venue could be placed on the map and drilled by region without a join. We adopt that *shape* with the platform's modern conventions (UUIDv7, org-scoping, consent, effective-config, CSP-safe views).

---

## 2. Goals & non-goals

### Goals
- **G1 — One source of truth for a group's physical location: ITS VENUE.** A group's location *is* its primary venue's location, read through `groups.primary_venue_id`. No geo is copied onto the group, and `groups.address_id` is **not** a geo fallback (it is a postal/contact address). A group with no venue has no location and is bucketed **Unlocated**.
- **G2 — Venues are first-class geo citizens.** A venue carries its own resolved geo hierarchy (denormalized IDs + labels), kept in sync from its address, so venues can be bucketed/drilled and mapped cheaply.
- **G3 — A unified global→local directory.** One drill-down: **Region → Country → State → City → (Town) → Venue → Groups meeting there**, plus the existing groups-by-place view, from the same resolved data.
- **G4 — No forked writes; reuse existing services.** Extend `LocationService`, `GroupService`, `GroupPublicService`; reuse `venue_group_assignments`, `GeoResolverService`, `EffectiveConfigResolver`.
- **G5 — Consent & discovery honored.** Venue facility data is public per its `discovery_status`; personal/home location stays consent-gated (unchanged). The public directory shows only discoverable groups/venues; the admin browser sees all.
- **G6 — Resource-light, without replication.** Venues carry the denormalized bucket keys + resolved ids (one table, indexed); the directory is one bounded read plus a single LEFT JOIN on an indexed pointer. Denormalization is allowed **within** the entity that owns the fact — never **across** entities.

### Non-goals
- Not changing the Geo **reference** hierarchy (regions/countries/states/cities/towns) — it stays as-is.
- Not moving personal/prospect location into venues (that stays in `addresses` + consent).
- Not building live maps/tiles in this pass (coordinates are exposed; a map layer is a later, optional enhancement — the in-app preview can't load external tiles anyway).
- Not touching the frozen 41-permission budget — reuse `venue.manage`, `group.change.approve`, and public (no-auth) reads.

---

## 3. Decisions (proposed — pending your confirmation)

These correspond to the four questions asked earlier; I state a recommended default for each, grounded in the authoritative inputs.

| # | Decision | Recommended | Rationale |
|---|----------|-------------|-----------|
| **D1** | Source of truth for a group's physical location | ✅ **CORRECTED: the primary venue IS the location — read through the pointer, never copied onto the group, no `groups.address_id` fallback** | Matches legacy `groups.venue_id`; a group "meets somewhere," and that somewhere is a managed, discoverable venue. Copying it onto `groups` created a second source of truth and a re-stamp fan-out on every venue move. A group with no venue is Unlocated — honest, and fixable by assigning a venue. |
| **D2** | Deliverable | **Both the data model AND the drill-down directory** | The relationships are only meaningful once surfaced; you asked to "make them related" *and* described the drill-down. |
| **D3** | Directory surface | **Both** — public discovery directory + admin browser | Public honors discovery status; admin sees all. Reuses the existing `/g` (public) and adds a geo-first entry point. |
| **D4** | Venues get resolved geo IDs | **Yes — denormalize on venues ONLY, synced from address/GPS** | §8.2 wants indexed geo lookup; legacy venues carried full hierarchy; without it there's no cheap drill-down (item 2 above). Venues are the ONE place resolved geo lives, so groups inherit it by reference. |

> **If you prefer a lighter first cut:** D1 could be "keep independent, cross-linked" (no auto-sync) and D4 "resolve via address join" — smaller, but leaves the drift problem and a slower directory. My recommendation is the fuller option because the sync + denormalization are exactly what removes the drift and the per-render joins.

---

## 4. Target data model

### 4.1 The relationship, conceptually

```
Geo reference hierarchy (immutable, org-agnostic)
  regions → subregions → countries → states → cities → towns_villages
        ▲              (referenced by IDs, never modified here)
        │
  addresses (org-scoped location record: geo IDs + line1/2 + lat/long + geo_point + precision + consent)
        ▲
        ├──────────────── venues.address_id           (a facility AT an address)
        │                    │
        │                    └── venues.{country_id,state_id,city_id,town_village_id,*_label,
        │                                location_group_key,latitude,longitude}   ← the ONLY resolved geo
        │
        └──────────────── groups.address_id           (POSTAL/contact address — NOT a geo source)

groups.primary_venue_id ──► venues.id                  ← a group's home venue: the link that CARRIES its
                                                          location. Groups store no geo of their own.
venue_group_assignments (venue_id, group_id, assignment_type: primary|secondary|overflow)   ← EXISTS; primary row mirrors groups.primary_venue_id
```

### 4.2 Schema changes (new migrations — additive, nullable, idempotent)

**Migration A — `AddVenueGeoResolution` (Geo module):** give venues their own resolved geo hierarchy.

```sql
ALTER TABLE venues
  ADD COLUMN country_id       MEDIUMINT UNSIGNED NULL AFTER address_id,
  ADD COLUMN state_id         MEDIUMINT UNSIGNED NULL AFTER country_id,
  ADD COLUMN city_id          MEDIUMINT UNSIGNED NULL AFTER state_id,
  ADD COLUMN town_village_id  MEDIUMINT UNSIGNED NULL AFTER city_id,
  ADD COLUMN country_label    VARCHAR(120) NULL AFTER town_village_id,
  ADD COLUMN state_label      VARCHAR(120) NULL AFTER country_label,
  ADD COLUMN city_label       VARCHAR(120) NULL AFTER state_label,
  ADD COLUMN location_group_key VARCHAR(160) NULL AFTER city_label,   -- directory bucket, mirrors groups
  ADD KEY venues_geo_idx (organization_id, country_id, state_id, city_id),
  ADD KEY venues_locbucket_idx (organization_id, location_group_key);
```

**Migration B — `AddGroupPrimaryVenue` (Groups module):** the group→home-venue link.

```sql
ALTER TABLE groups
  ADD COLUMN primary_venue_id CHAR(36) NULL AFTER address_id,
  ADD KEY groups_primary_venue_idx (primary_venue_id);
```

**Migration D — `GroupGeoLivesOnVenue` (000085, Groups module): the CORRECTION.** Undo the replication.

```sql
ALTER TABLE `groups` DROP INDEX groups_geo_idx;
ALTER TABLE `groups` DROP INDEX groups_locbucket_idx;
ALTER TABLE `groups`
  DROP COLUMN geo_country_id, DROP COLUMN geo_state_id,      -- from 000078
  DROP COLUMN geo_city_id,    DROP COLUMN country_label,     -- from 000078
  DROP COLUMN location_group_key, DROP COLUMN region_label,  -- from 000064
  DROP COLUMN city_label,                                    -- from 000064
  DROP COLUMN latitude,       DROP COLUMN longitude;         -- from 000064
```

Migration `000078_AddGroupGeoResolution` is **kept in the chain** (not deleted) and marked
superseded, so an already-migrated database and a fresh `migrate:refresh` both replay
correctly: 000078 adds, 000085 removes. `down()` on 000085 re-adds the columns nullable —
the values are re-derivable, but under the corrected model nothing writes them.

Notes:
- All columns nullable/defaulted; both migrations idempotent (add-if-absent, matching the codebase pattern with `resetDataCache()` + `fieldExists`).
- No hard FKs across modules (consistent with existing style — logical refs + service guards), so venue delete/soft-delete doesn't cascade-break a group; the sync service clears the link instead.
- Geo IDs are `MEDIUMINT UNSIGNED` to match the reference tables exactly (type/collation parity — §8.2).

### 4.3 What stays

- `addresses` — unchanged; still the geocodable record with precision/consent.
- `venue_group_assignments` — unchanged; remains the M:N with `primary|secondary|overflow`. **`groups.primary_venue_id` is kept consistent with the `assignment_type='primary'` row** (one is the fast pointer, the other the full relationship + audit). The sync service is the single writer of both.
- ~~`groups.{region_label,city_label,location_group_key,latitude,longitude}` — now actually populated by the sync service.~~ **CORRECTED: these are DROPPED by 000085.** A group carries no geo. What stays on `groups` is `primary_venue_id` (the link), `address_id` (postal) and `location_text` (free-text human description such as "Behind the market" — a label, not a coordinate or a place id).

---

## 5. Service design

### 5.1 The location-resolution & sync spine (single writer)

A new **`LocationSyncService`** (Geo module) owns all denormalization so nothing drifts. It is the *only* writer of resolved geo columns and location labels.

```
resolveAndStampVenue(venueId):
    load venue → its address_id → address geo IDs (country/state/city/town)
    if address missing geo IDs but has lat/long → GeoResolverService.resolve() to fill them
    write venues.{country_id,state_id,city_id,town_village_id, *_label, location_group_key}
    (location_group_key = a stable bucket, e.g. "state:<id>" or "country:<id>" — same scheme groups use)

stampGroupFromPrimaryVenue(groupId):
    v = group.primary_venue_id ? getVenue(v) : null
    if v:  groups.{address_id?, region_label, city_label, location_group_key, latitude, longitude} ← venue's resolved values
    else:  fall back to groups.address_id → resolve directly (existing path)
```

**Triggers (all via existing write paths — no new cron needed):**
- `LocationService.createVenue/updateVenue` → `resolveAndStampVenue()` in the same transaction.
- `assignVenueToGroup(type='primary')` → also set `groups.primary_venue_id` + `stampGroupFromPrimaryVenue()`.
- Changing/removing the primary assignment → clear/repoint `groups.primary_venue_id` + re-stamp.
- `GroupService.update` when `address_id` changes and there's no primary venue → `stampGroupFromPrimaryVenue()` (fallback path).
- A one-time **backfill command** (`geo:backfill-location`) stamps existing venues/groups idempotently.

Fault-isolated + idempotent: re-running produces the same values; a resolution failure leaves prior values intact and logs, never throws into the caller's write.

### 5.2 The directory service (read side)

Extend `GroupPublicService` (public) and add an admin reader. Both build on **one** resolved dataset.

- **`geoDirectory(orgId, opts)`** — returns the drill-down tree keyed off the **denormalized** columns (no per-row `addresses` join):
  - Levels: **Country → State → City → Venue → Groups**. (Region/subregion available as an optional top wrapper for multi-country orgs; single-country orgs start at Country.)
  - A venue node lists the groups whose `primary_venue_id` = that venue (and, configurably, secondary/overflow assignments).
  - Groups with a location but no venue hang on a synthetic "(No venue)" node within their city — nothing dropped.
  - Groups with no resolved location land in a pinned **"Unlocated"** bucket (mirrors current `nestByGeo` behavior).
- **Pure shaping stays testable** — like the existing `nestByGeo()`, the nesting is a pure function over rows (DB-free), so it's unit-tested without a database.
- **Resource-light:** one bounded indexed read over `venues` + `groups` filtered by `location_group_key`/geo IDs; result cached under a version stamp invalidated (O(1) INCR) whenever `LocationSyncService` writes.

### 5.3 Consent & discovery (unchanged rules, applied here)
- Public `geoDirectory` includes only groups with `status='active'` and public discovery, and venues with `discovery_status IN ('public','unlisted'-if-linked)`. Facility data is non-personal (§8.3) so venue coordinates are shown.
- Admin `geoDirectory` (gated `venue.manage` or `group.change.approve`) sees all, including private venues and archived groups, clearly flagged.
- Personal/home/prospect location is **never** surfaced here — this directory is groups + venues only.

---

## 6. Routes & UI

### 6.1 Routes (reusing existing groups/geo prefixes — no new permission bits)

| Method | Path | Handler | Access |
|--------|------|---------|--------|
| GET | `/g` | `GroupPublicController::directory` (existing) | public — groups-by-place (kept) |
| GET | `/g/map` *(new)* | `GroupPublicController::geoDirectory` | public — **global→local drill-down** (place→venue→groups) |
| GET | `/g/place/(:segment)` *(new, optional)* | `GroupPublicController::place` | public — a single place node (deep-link/SEO) |
| GET | `/venues` | `VenueController::index` (existing) | `auth` |
| GET | `/venues/directory` *(new)* | `VenueController::geoDirectory` | `auth` + `venue.manage` — admin browser (sees all) |
| POST | `/venues/(:segment)/groups` | `VenueController::assignToGroup` (existing, extended to set primary) | `venue.manage` + `webcsrf` |

If new routes are added, OpenAPI is regenerated (`tools/gen_openapi.py`).

### 6.2 Views (CSP-safe, no-JS, 6-locale, dark theme matching current UI)
- **`public_geo_directory.php`** — nested disclosure (`<details>`/`<summary>`) tree: Country ▸ State ▸ City ▸ Venue → group cards (name, tagline, meeting venue + address, contact, join link). Counts per node. RTL-aware. Uses the existing card/chip visual language from the member dashboard.
- **`venue_geo_directory.php`** (admin) — same tree, but shows every venue (with discovery status), each venue's assigned groups (primary/secondary/overflow), capacity, and quick links to edit venue / assign group.
- Reuse `_locale.php`, `Avatar`, and existing chip/card CSS patterns. All copy via `lang('Groups.geoDirectory.*')` / `lang('Geo.venueDirectory.*')` across en/es/fr/pt/zh/ar.

### 6.3 Illustrative drill-down

```
🌍 Ghana  (12 groups · 5 venues)
  └─ Greater Accra  (7 groups · 3 venues)
       └─ Accra  (5 groups · 2 venues)
            ├─ 🏛 Grace Chapel Auditorium  (cap 800) — 2 groups
            │     • Accra Central Assembly        [Join →]
            │     • Legon Fellowship              [Join →]
            └─ 🏛 Community Hall, Osu  (cap 150) — 1 group
                  • Osu Senior Cell               [Join →]
       └─ Tema  (2 groups · 1 venue) …
  └─ Ashanti  (5 groups · 2 venues) …
🌐 Unlocated  (3 groups)   ← pinned bottom
```

---

## 7. Data-integrity & edge rules

1. **Primary-venue consistency.** `groups.primary_venue_id` must equal the `venue_group_assignments` row with `assignment_type='primary'`. The sync service writes both atomically; a reconciliation check (in the backfill command + a test) asserts they never disagree.
2. **Venue soft-delete / reassignment.** Deleting/soft-deleting a venue clears `primary_venue_id` on any group pointing at it and re-stamps that group from its `address_id` fallback (never leaves a dangling pointer).
3. **Cross-org safety.** A group may only point at a venue in the same `organization_id` (service guard).
4. **Geo drift.** If a venue's address changes geo, `resolveAndStampVenue()` re-derives IDs/labels and re-stamps every group whose primary venue it is.
5. **Unresolvable location.** Falls to the pinned "Unlocated" bucket; never blocks a group/venue from existing.
6. **Historical integrity.** Reference rows deactivated via `flag` stay resolvable for existing stamps (§8.2); only *new* selection is restricted.

---

## 8. Testing strategy (standalone, per existing discipline)

- **`location_sync_test.php`** — `resolveAndStampVenue` fills geo IDs/labels from address; from lat/long via resolver when IDs absent; idempotent re-run; failure leaves prior values.
- **`group_primary_venue_test.php`** — assigning primary venue sets `groups.primary_venue_id` + stamps labels; removing it repoints to `address_id` fallback; primary pointer ↔ assignment row stay consistent; cross-org rejected.
- **`geo_directory_shape_test.php`** — pure `geoDirectory` nesting: place→venue→groups; "(No venue)" node; pinned "Unlocated"; counts; discovery filtering (public excludes private venues/inactive groups; admin includes all).
- **`venue_directory_view_test.php`** / **`public_geo_directory_view_test.php`** — 6-locale key parity, RTL, CSP-safe (no inline JS), correct drill-down render, join links.
- **DB-dialect caveat (from recent incidents):** because standalone fakes don't render SQL, add a note to run the new migrations against real MySQL before go-live (the `archived_at` / `audit_log` bugs were exactly this class).
- Full suite stays green; OpenAPI regenerated if routes change.

---

## 9. Rollout plan (incremental, each step green) — ✅ SHIPPED 2026-09-20

1. ✅ **Migrations** — `000076_AddVenueGeoResolution` (venue geo ids/labels + bucket key + 2 idx), `000077_AddGroupPrimaryVenue` (groups.primary_venue_id + idx), `000078_AddGroupGeoResolution` (groups.geo_country/state/city_id + country_label + idx). All additive, idempotent, `resetDataCache()`-guarded, no hard cross-module FK.
2. ✅ **`LocationSyncService`** (single writer of denormalized geo) wired into `createVenue`/`updateVenue`/`deleteVenue`(detachPrimaryVenue)/`assignVenueToGroup`(applyPrimaryPointer + cross-org guard). Idempotent + fault-isolated. Tests: `location_sync_test.php` (23/0), `venue_primary_pointer_test.php` (21/0).
3. ✅ **`geo:backfill-location` command** (`BackfillLocationCommand.php`, group WBS, `--org=` option) — stamps existing venues/groups idempotently + primary-pointer↔assignment-row reconciliation report.
4. ✅ **`geoDirectory` readers** — `GroupPublicService::geoDirectory()`+pure `nestByGeoVenue()` (public); `LocationService::venueDirectory()`+pure `nestVenuesByGeo()` (admin). Pure-shape tests: `geo_venue_directory_nesting_test.php` (23/0), `venue_directory_nesting_test.php` (15/0).
5. ✅ **Views + routes + i18n** — `public_geo_directory.php` (`GET /g/map`, auth-free), `venue_geo_directory.php` (`GET /venues/directory`, `venue.manage`); both native `<details>` no-JS/CSP-safe, RTL-aware. Routes declared before their catch-alls. `Groups.geoDirectory.*` + `Geo.venueDirectory.*` across en/es/fr/pt/zh/ar. View test `geo_directory_views_test.php` (36/0). OpenAPI regenerated (546 routes). Menu-coverage exclusions triaged.

6. ✅ **CORRECTION — venue becomes the sole geo source (2026-09-20).** Migration `000085_GroupGeoLivesOnVenue` drops the replicated group geo columns + indexes; `LocationSyncService` loses `stampGroup()`/`restampGroupsForVenue()` (venue-only writer now); the 4 `LocationService` re-stamp call sites become pointer writes only; `GroupPublicService::{directory,geoDirectory}()` and `GroupService::listForOrgWithGeo()` resolve geo through `primary_venue_id`; `geo:backfill-location` now stamps venues and reports **group placement coverage** (placed / venue-without-geo / no-venue) instead of stamping groups. Tests: `location_sync_test.php` rewritten for the venue-only contract (24/0), `venue_primary_pointer_test.php` updated (21/0), NEW `group_geo_from_venue_test.php` guards the SQL itself — asserting every geo field is read off the `v.` alias and that **no** `g.*` geo column is selected (24/0).

**Standalone suite after the correction: 236 files / 14,338 assertions / 0 failed** (was 232/14,175 at first ship; 227/14,049 before this feature). GroupService.update NOT touched (venue-driven path is authoritative; groups.address_id has no direct writer).

**Go-live note (DB-dialect):** the standalone fakes don't render SQL — run migrations **000076 / 000077 / 000078 / 000085** against the real MySQL 8.4 instance before go-live to catch dialect issues the suite can't (per prior array-as-SQL / migration-drift incidents). After migrating, run `php spark geo:backfill-location` once: it stamps every venue and then reports how many groups are **placed** (venue with resolved geo) versus venue-without-geo versus no-venue, so unsited groups are visible instead of silently Unlocated.

---

## 10. Open questions for you

1. ~~**D1–D4 confirmations** (§3)~~ **SETTLED by your correction:** a group's location comes from its venue and geo is not replicated across the three entities. D2 (model + directory), D3 (both surfaces) and D4 (denormalize on venues) stand as shipped.
2. **Region/subregion top level:** start the public drill-down at **Country** (single-country org) or always wrap in **Region → Country**? (Recommend: start at Country, show Region only when >1 country exists.)
3. **Secondary/overflow venues in the public tree:** show a group only under its **primary** venue, or under **all** assigned venues? (Recommend: primary only in public; all in admin.)
4. **New public route name:** `/g/map` vs `/directory` vs fold into `/g` with a `?view=geo` toggle? (Recommend: `/g/map` as a distinct, cacheable entry.)

---

*This design reuses existing services and tables, adds only additive nullable schema, honors consent/discovery and the frozen permission budget, and follows the standing CSP-safe / 6-locale / standalone-test conventions. It is ready to implement on your confirmation of §3 / §10.*
