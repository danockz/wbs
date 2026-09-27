# Universal Dynamic Menu — design proposal

**Goal:** one canonical, categorised navigation that every surface (web SSR, JSON
clients, future mobile) reads, where each item appears **only if** the current
user can actually reach it **in the current scope** — computed from the systems
that already exist, never a hand-maintained parallel permission list.

**Guiding principle:** the menu is a *projection of enforcement*, not a second
enforcement layer. Hiding an item is a convenience; the route's `authorize:` filter
remains the security boundary. A hidden item that is somehow requested still gets
denied by the PDP. This keeps the menu safe even if it is stale.

---

## 1. Why this is a good fit (reuse, don't fork)

| Need | Already in the platform |
|------|------------------------|
| "Can this subject do X?" | `AuthorizationService::decide()` / `isAllowed()` (default-deny PDP) |
| Destination → required capability | Route table `['filter' => 'authorize:code']` / `authorize:code,any` |
| "In which groups?" | `GroupScopeResolver` + `role_assignments` (self / self_and_descendants / descendants_only / groups) |
| Feature on/off per group (inheritance-aware) | `EffectiveConfigResolver::resolve(groupId, capability)` (default OFF) |
| Stable capability vocabulary | RbacBootstrapSeeder permission codes (`event.create`, `access.role.manage`, …) |
| Per-module contribution | PSR-4 module pattern (each module ships its own Config/Services) |

The menu adds exactly **one** new concept — a declarative *menu item catalog* — and
one **service** that folds it through the three existing gates.

---

## 2. Data model — a declarative catalog (contributed per module)

A menu item is metadata, not logic. Each module registers its own items (mirrors
how modules already register services/routes), so navigation stays co-located with
the feature that owns it and there is no central file everyone edits.

```php
// WBS\Shared\Navigation\MenuItem  (immutable DTO)
new MenuItem(
    id:            'events.create',            // stable unique id
    category:      MenuCategory::EVENTS,        // top-level bucket (see §4)
    label:         'Create event',             // i18n key in practice
    icon:          'calendar-plus',
    route:         'events/create',            // canonical URL OR named route
    // --- visibility gates (all must pass) ---
    permission:    'event.create',             // PDP action; null = always-visible link
    scopeCheck:    'any',                        // 'exact' | 'any' (mirrors authorize filter)
    featureFlag:   'events.enabled',            // EffectiveConfigResolver capability; null = ungated
    // --- presentation ---
    order:         20,
    parentId:      null,                        // for sub-menus
    badge:         'events.pending_count',      // optional lazy count provider id (see §6)
    match:         ['events', 'events/*'],      // active-state highlighting
);
```

Registration (per module, discovered like services):

```php
// app/Modules/Events/Config/Menu.php
final class Menu implements MenuProvider
{
    public static function items(): array
    {
        return [
            new MenuItem('events.browse', MenuCategory::EVENTS, 'Events', 'calendar',
                route: 'events', permission: null, order: 10),
            new MenuItem('events.create', MenuCategory::EVENTS, 'Create event', 'calendar-plus',
                route: 'events/create', permission: 'event.create', scopeCheck: 'any',
                featureFlag: 'events.enabled', order: 20),
            // ...
        ];
    }
}
```

No new tables are required for v1 (catalog lives in code, like routes). If you
later want **org-configurable ordering/labels/hidden items**, add a thin
`menu_overrides` table (org_id, item_id, hidden, sort_order, label_override) that
the resolver layers on top — same pattern as `EffectiveConfigResolver` overrides.

---

## 3. The resolver — `MenuService::buildFor()`

```php
// WBS\Shared\Navigation\MenuService
public function buildFor(string $orgId, string $subjectId, ?string $scopeGroupId, array $ctx): MenuTree
{
    $items = $this->catalog->all();                 // all registered MenuItems
    $visible = [];

    foreach ($items as $item) {
        // (a) FEATURE GATE — skip if the feature is OFF for this scope.
        if ($item->featureFlag !== null) {
            $groupForFlag = $scopeGroupId ?? $this->primaryGroup($subjectId);
            if (! $this->config->resolve($groupForFlag, $item->featureFlag)->data['enabled']) {
                continue;
            }
        }

        // (b) PERMISSION GATE — ask the SAME PDP the routes ask.
        if ($item->permission !== null) {
            $decision = $this->pdp->decide(new AccessRequest(
                organizationId: $orgId,
                subjectId:      $subjectId,
                action:         $item->permission,
                attributes:     [
                    'mfa_level'   => $ctx['mfa_level'] ?? 'none',
                    'scope_check' => $item->scopeCheck,        // 'any' | 'exact'
                    'group_id'    => $scopeGroupId,            // exact-scope target
                ],
            ));
            if (! $decision->isPermitted()) {
                continue;
            }
        }

        $visible[] = $item;
    }

    return MenuTree::categorise($visible);          // group by category, sort, prune empties
}
```

**The alignment guarantee:** the `permission` + `scopeCheck` on a menu item are the
*same string and same mode* used in the route's `authorize:` filter. So the menu
answer and the route answer come from one PDP call shape — they cannot disagree.
(§7 adds a CI test that proves this mechanically.)

---

## 4. Categories (the taxonomy)

Fixed top-level buckets keyed by enum, org-relabelable later. Suggested set,
ordered:

1. **Overview** — dashboard, my contacts & follow-ups, my sessions
2. **People** — members, invites, address book / downline, staff bulk
3. **Groups** — directory by location, my groups, memberships, group config
4. **Events** — browse, create, attendance, logistics, expenses
5. **Learning** — courses, enrollments, completions
6. **Giving** — contributions, campaigns, refunds
7. **Communications** — notifications, broadcasts, meetings
8. **Streaming** — streams, console, giving overlays
9. **Reports** — dashboards, exports
10. **Access & Security** — role assignments, delegations, break-glass, rules, policies
11. **Administration** — org settings, providers, integrations

Empty categories (all items hidden for this user) are pruned automatically, so a
member sees maybe 3 categories while an org admin sees all 11 — **same catalog,
different projection.**

---

## 5. Delivery surfaces (one computation, many renders)

Consistent with your "one canonical URL, HTML or JSON by negotiation" rule:

- **`GET /me/menu`** — behind `auth`. Returns the resolved `MenuTree`.
  - `Accept: application/json` → `{ scope, categories: [{ key, label, items:[…] }], generatedAt }`
  - HTML request → server-rendered `<nav>` partial (no external assets; inline per
    your CSP posture) so there is **no flash of an empty/over-full menu**.
- **SSR partial** — a `menu()` view helper renders the same tree into the layout on
  every page, so navigation is correct on first paint and JS is optional.
- **Scope switcher** — the "per scope" part of your ask. The user picks their active
  group scope (from the groups their assignments cover); the chosen scope is passed
  to `buildFor()` and the menu recomputes. Store the active scope in the session so
  it persists across pages.

---

## 6. Badges / counts (kept off the hot path)

Counts like "Pending approvals (3)" are expensive and change often. Keep them out
of the menu build:

- A `badge` id points to a lazy **count provider** (e.g. `approvals.pending_count`).
- The SSR menu renders the item without the number; the client fetches
  `GET /me/menu/badges` (or the item's own endpoint) after paint and fills them in.
- Providers are themselves permission/scope-gated, so a badge can never leak a
  count the user could not otherwise see.

---

## 7. Correctness & anti-drift (the part that makes it trustworthy)

Add a **CI test** that walks the menu catalog and asserts, for every item with a
`route` + `permission`:

1. the route exists in the route table, and
2. the route's `authorize:` filter declares the **same** permission code (and the
   `,any` vs exact mode matches `scopeCheck`).

This makes it structurally impossible for the menu to advertise a destination the
PDP would deny for a mismatched reason, or to point at a dead route (your recent
`/me/sessions` 404 class of problem would be caught here). Fits the stub-harness
style you already verify with — it needs only the route table + the catalog, no DB.

---

## 8. Performance — the resource-light design (this is the important part)

### 8.1 What the naive version would cost (and why we reject it)

`AuthorizationService::decide()` is **not** one query. A single call can touch
`role_assignments ⋈ role_permissions`, `access_requests`, `delegations`,
`break_glass_sessions`, `abac_policies`, `object_labels`, `subject_clearances`,
and `grant_scope_groups`. So "one PDP call per menu item, every page load" is
roughly **(items × ~8 queries)** per request — an admin menu could fire 300+
queries just to draw a sidebar. That is the burden we design out. **Three rules:**

> **R1 — Compute rarely.** The menu is recomputed only when the user's *access
> actually changes*, not per request.
> **R2 — Load grants once.** When we do compute, we read the subject's grants a
> single time and answer every item in memory — never per item.
> **R3 — Serve from the edge of the stack.** Most requests should re-use a cached
> tree or return `304 Not Modified` and do **zero** menu work.

### 8.2 R2 — one grant snapshot, all items answered in memory

Add `AuthorizationService::permittedActions(org, subject, string[] actions, scope)`
returning the **allowed subset**. It loads the subject's effective grants **once**
(the same tables `decide()` uses, fetched in a handful of `WHERE subject_id = ?`
reads), builds an in-memory set of `(action → covered scope groups)`, then answers
all N menu actions by set lookup. Cost drops from *N × 8 queries* to a **fixed
~8 queries regardless of menu size**. `decide()` stays the authority for the
route filters; this is a batch read path over the same data, not a second policy.

### 8.3 R1 — version-stamped cache, O(1) invalidation (no scanning)

Cache the resolved `MenuTree` under a key that embeds a **version stamp**:

```
menu:{orgId}:{subjectId}:{scopeGroupId}:v{grantVersion}:c{configVersion}
```

- `grantVersion` = a small integer counter per subject (or per org for shared
  policy). Its **only** invalidation action is `INCR` — the stale key is simply
  never read again and expires on its TTL. No cache scanning, no key enumeration,
  no cross-node fan-out.
- Bump points are the writers you **already have** — one line each:
  `RoleAssignmentService.assign/revoke`, `DelegationService`, `AccessRequestService`
  (approve/revoke), `BreakGlassService`, `RuleService`, `AbacPolicyService`. A grant
  change the user makes is the natural, infrequent moment to recompute.
- `configVersion` covers feature-flag flips via `EffectiveConfigResolver` writers.
- Use the existing cache: `Config\Cache` handler is `file` today with `redis`
  available and `dummy` backup — so this degrades safely if Redis is down. TTL is a
  safety net (e.g. 1 h), **not** the primary freshness mechanism; the version stamp
  is.

Result: for a user whose access hasn't changed, the menu is computed **once** and
then read from cache indefinitely — a single `GET` from file/Redis, no PDP, no DB.

### 8.4 R3 — ETag / 304 so most requests do zero work

`GET /me/menu` sets `ETag: "{grantVersion}.{configVersion}.{scope}"` and honours
`If-None-Match`. An unchanged menu returns **`304 Not Modified` with an empty
body** — no cache read, no serialization, no bytes on the wire. For SSR, embed the
same version token in the layout so the nav partial is emitted from the request-
scoped cached tree (computed at most once per request, shared by every partial on
the page).

### 8.5 Keep expensive, volatile data OFF the menu path

Badge counts ("3 pending approvals") are the classic hidden cost — they change
constantly and would defeat every cache above. Per §6 they are **not** part of the
tree: the menu ships the *labels* (fully cacheable), and counts are fetched lazily
by a separate, individually-gated `GET /me/menu/badges` after paint, each with its
own short TTL. Volatility is quarantined away from the cacheable structure.

### 8.6 Cost summary

| Scenario | Naive | This design |
|----------|-------|-------------|
| Page load, access unchanged | items × ~8 queries | **0 queries** (cache hit or 304) |
| First load after a grant change | items × ~8 queries | **~8 queries once**, then cached |
| Menu size growth (more items) | linear query growth | **flat** (set lookups in memory) |
| Invalidation on a grant edit | cache scan / TTL wait | **one `INCR`** |
| Badge counts | recomputed inline every load | lazy, isolated, own TTL |

Feature-flag lookups are already resolver-cached via inheritance walking, so they
add nothing on the cached path.

---

## 9. Rollout plan (incremental, no big bang)

1. **Catalog + DTOs + `MenuService`** in `WBS\Shared\Navigation` (Shared module, so
   every module can contribute). Stub-harness the resolver against fake PDP/config.
2. **`AuthorizationService::permittedActions()`** batch read path (§8.2) — the
   grant-snapshot answer. Build the menu on this from day one so it is *born*
   resource-light (one grant load, in-memory answers), not retrofitted later.
3. **Seed catalogs** for the modules you navigate most first: Overview, People,
   Groups, Events. Prove the projection differs correctly for member vs leader vs
   admin fixtures.
4. **`GET /me/menu`** (JSON) with **version-stamped cache + ETag/304** (§8.3–8.4)
   and the anti-drift CI test (§7). Add the one-line `grantVersion`/`configVersion`
   `INCR` to the existing grant/config writers.
5. **SSR partial + scope switcher**, wired into the dashboard layout using the
   request-scoped cached tree; retire the hard-coded links in `me.php` (they become
   catalog items).
6. **Lazy badges endpoint** (§8.5) — labels stay on the cached tree, counts fetched
   separately after paint.
7. **Optional `menu_overrides` table** for org-configurable ordering/labels.

---

## 10. What this explicitly does NOT do

- It does **not** replace `authorize:` route filters — those stay as the authority.
- It does **not** invent a new permission system — it consumes the existing codes.
- It does **not** fork feature gating — it calls `EffectiveConfigResolver`.
- It does **not** hard-code visibility per role — visibility is *derived*, so adding
  a role or changing a grant changes the menu automatically with zero nav edits.
