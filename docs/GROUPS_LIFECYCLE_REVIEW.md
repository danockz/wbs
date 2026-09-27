# Groups Lifecycle Review

_A code-grounded gap analysis of the **group lifecycle** — how a group is created,
placed and re-placed in the hierarchy, classified, and eventually archived,
dissolved or merged, plus how those transitions interact with the group's
subtree, its members, and the access/attribution layers that read the tree._

Prepared 2026-09-15, as the follow-up to `docs/MEMBERSHIP_LIFECYCLE_REVIEW.md`
(the individual-member arc). Method: read the migrations, services, controllers,
routes and existing tests; every finding cites the code it rests on. No code was
changed — this is the review that precedes any fixes.

Scope note: this review is about the **group as an entity** (the node and its
tree). Where a *person's* belonging state matters it was covered in the
membership review; here `group_members` appears only as something group
transitions must keep consistent.

---

## 1. The group model at a glance

- **`groups`** (`2026-09-01-000007`) — the node: `parent_id`, `depth`, `path`
  (materialized `/id/id/`), `leader_user_id`, `status` (`active` default),
  `kind_code` (added `000057`), public-profile + join-policy columns
  (`000047`/`000048`), location (`000064`). Lifecycle audit columns
  (`status_reason`, `status_changed_at/by`, `archived_at`, `dissolved_at`,
  `merged_into_id`) added by `000051`.
- **`group_closure`** (`ancestor_id`, `descendant_id`, `distance`) — the
  materialized transitive closure that powers scope, config inheritance and
  rollups. Self-row at distance 0.
- **`group_members`** — belonging, with `membership_type`, `status`,
  one-active-slot guard (`active_key`), approval evidence (see membership review).
- **`group_lifecycle_transitions`** — append-only evidence of every status change.
- **`group_kinds`** + **`group_crosscut_links`** — orthogonal classification and
  the leadership-responsibility cross-cut overlay.

**State machine** (`GroupLifecycleService::TRANSITIONS`):

```
active    → archived | dissolved | merged
archived  → active | dissolved | merged
dissolved → (terminal)
merged    → (terminal)
```

---

## 2. What is solid today ✅

- **The lifecycle state machine is explicit and guarded.** Real adjacency map
  with terminal states, **mandatory reason** (`REASON_REQUIRED`), `NO_CHANGE`
  and `ILLEGAL_TRANSITION` guards, append-only `group_lifecycle_transitions`
  evidence and an audit record, all in one transaction.
- **Dissolve refuses to orphan or strand.** A group may be dissolved only with
  **no active child groups** (`HAS_CHILDREN`) and **no active members**
  (`HAS_MEMBERS`) — a genuinely clean tear-down guard.
- **Merge restructures the tree correctly.** `merge()` rejects self-merge, a
  terminal survivor, and a **survivor inside the merged group's own subtree**
  (`SURVIVOR_IN_SUBTREE`, cycle-safe). It **re-parents direct children via
  `GroupService::move`** (so closure/paths/depths stay correct) and **transfers
  active members** honouring the one-active invariant (dups are ended, not
  duplicated). This is the strongest part of the module.
- **Create/move protect tree integrity.** `create()` enforces `MAX_DEPTH` and
  maintains closure (self + ancestor rows). `move()` rejects self-parenting and
  cycles (descendant-as-new-parent via closure lookup), enforces max depth for
  the **whole subtree height**, rebuilds closure edges, and recomputes
  paths/depths. Solid graph hygiene.
- **Lifecycle governance is scope-checked twice.** Routes gate on
  `group.change.approve` **and** the controller re-checks `authorizeGroupScope`
  per group (merge checks **both** the merged group and the survivor). Each
  carries `webcsrf`.
- **Membership add guards group status.** `GroupMembershipService::add` returns
  `GROUP_INACTIVE` for a non-active group; `GroupPublicService::join` only serves
  `active` groups.

---

## 3. Findings (gaps), by severity

| # | Gap | Area | Severity |
| --- | --- | --- | --- |
| GR1 | **Create / id-scoped move / legacy add-member routes are unauthorized.** `POST /groups`, `POST /groups/{id}/move`, `POST /groups/{id}/members` carry only `auth` (+`webcsrf` on two) — **no `authorize:` filter and no internal PDP/scope check** — although `group.create` (bit 26) and `group.move` (bit 27) exist and the base controller exposes `authorizeGroupScope()`. Any authenticated user can create, restructure, or add members to groups. | Access control | 🔴 High |
| GR2 | **Archive does not cascade to the subtree.** Archiving a parent flips only that one row; its descendant groups stay `active`. A "closed" region of the tree is only half-closed — children remain joinable, listed, and scope-active. | Lifecycle ↔ subtree | 🔴 High |
| GR3 | **Terminal/archived groups still participate in scope, config inheritance and rollups.** `GroupScopeResolver` and `EffectiveConfigResolver` read `group_closure` **without checking `groups.status`**, and dissolve/merge/archive **never delete closure rows**. So a dissolved/merged/archived node still resolves leader scope, inherits/supplies config, and accumulates rollups. | Lifecycle ↔ access/attribution | 🔴 High |
| GR4 | **`GroupService::addMember` (legacy path) skips the group-active guard.** Unlike `GroupMembershipService::add`, it inserts a membership without checking `groups.status`, so the unauthorized `/groups/{id}/members` route (GR1) can add members to an **archived/dissolved** group. | Belonging consistency | 🟠 Medium |
| GR5 | **No leader succession.** `groups.leader_user_id` is set at create and never reconciled: if the leader's **account is deactivated/anonymized** (membership review M1) or they **leave the group**, the group keeps pointing at a dead/absent leader, and `GroupPublicService` will surface them. No "leaderless group" state or reassignment. | Leadership continuity | 🟠 Medium |
| GR6 | **Group lifecycle is single-actor, not maker-checker.** Dissolve/merge (destructive, tree-wide) execute on one authorized actor, unlike the identity **merge** which is a submit→approve workflow with SoD self-approval denial. For an org-shaping action this is an inconsistency worth a decision. | Governance | 🟠 Medium |
| GR7 | **No archive/dissolve of the belongings or downstream on tear-down.** Dissolve requires members already gone (good), but **archive** leaves active memberships on an archived group, and neither archive nor dissolve emits a domain event so downstream (events owned by the group, causes, journeys crediting it) can react. | Lifecycle fan-out | 🟠 Medium |
| GR8 | **Create/move do not validate the parent's status.** `create()` and `move()` look up the parent but never check it is `active`, so a new child can be created under (or a subtree moved under) an **archived/dissolved/merged** parent — directly re-introducing GR2/GR3 inconsistency even without a cascade. | Tree integrity | 🟠 Medium |
| GR9 | **No service-level lifecycle tests.** Groups has rich **view/console** tests but **no `Services/tests/*`** exercising `create`/`move`/closure integrity or the archive/dissolve/merge state machine + member/child transfer. The strongest logic in the module is unguarded by a unit-style test. | Test coverage | 🟠 Medium |
| GR10 | **Reactivation is not a true inverse of archive.** `reactivate` clears `archived_at` and flips status, but (mirroring membership M10) does not restore anything an archive-cascade would have touched (GR2/GR7), so once those are built the pair must be made coherent. | Lifecycle symmetry | 🟡 Low |
| GR11 | **Dissolve/merge leave closure rows behind (storage + correctness).** Beyond GR3's read-time effect, the closure rows for a merged node are never removed, so the table grows monotonically and a naive descendant/ancestor query over a merged id returns stale structure. | Data hygiene | 🟡 Low |
| GR12 | **Kind/crosscut/profile changes are unversioned.** `setKind`, crosscut link/unlink and public-profile edits audit at the row level but there is no lifecycle-style history for classification/overlay changes the way status changes have `group_lifecycle_transitions`. | Auditability | 🟡 Low |

---

## 4. The gaps in detail

### GR1 — Unauthorized create / move / add-member 🔴
`app/Config/Routes.php` first `groups` group:
```
$routes->post('',                 GroupController::create,   ['auth','webcsrf']);        // no authorize:
$routes->post('(:segment)/move',  GroupController::move,     ['auth','webcsrf']);        // no authorize:
$routes->post('(:segment)/members', GroupController::addMember);                          // auth only
```
The `createForm`/`moveForm` **GET**s are gated (`group.create,any`,
`group.move,any`) but the **POST**s that actually mutate are not, and the
controllers call the service directly with no `authorizeGroupScope()`. The
permission bits already exist. This is the highest-risk item: creation and
restructuring of the org tree, and membership insertion, are effectively open to
any authenticated session. Fix = add the `authorize:` filters (and a per-target
scope check on `move`/`addMember`, since those act on an existing node).

### GR2 — Archive doesn't cascade 🔴
`archive()` → `transition(..., 'archived')` updates a single `groups` row. There
is no descendant walk. Archiving a Region therefore leaves its Areas, Local
Assemblies, Fellowships, Cells all `active` — still joinable, still in pickers,
still scope-active. Either archive should cascade to the subtree (with a matching
reactivate un-cascade — see GR10), or it should refuse when active descendants
exist (mirroring dissolve's `HAS_CHILDREN`). The former matches the "close a
region" intent; whichever is chosen must be explicit.

### GR3 — Terminal groups still live in scope/config/rollup 🔴
`GroupScopeResolver::resolve()` and the closure reads in
`EffectiveConfigResolver` filter on the *capability* row's status, **not** on
`groups.status`; and no lifecycle transition prunes `group_closure`. Net effect:
a **dissolved or merged** group can still (a) confer leader scope over its old
descendants, (b) inherit/provide effective config, and (c) collect
attribution/rollups. This is the most subtle correctness gap — the tree "forgets"
a node visually but the access/attribution plane still sees it. Fix options:
have the closure-reading resolvers join `groups.status IN ('active')` (or
`('active','archived')` depending on GR2's decision), and/or prune closure on
dissolve/merge (GR11).

### GR4 — Legacy addMember skips the active guard 🟠
`GroupService::addMember()` checks the group **exists** but not that it is
`active` — unlike `GroupMembershipService::add()` which returns `GROUP_INACTIVE`.
Combined with GR1 (route unauthorized), this is a live path to add members to an
archived/dissolved group. Fix = add the same status guard, or retire the legacy
path in favour of `GroupMembershipService`.

### GR5 — No leader succession 🟠
`leader_user_id` is written once and never reconciled. If that user is
deactivated/anonymized (membership M1) or leaves, the group has a phantom leader
that `GroupPublicService::leaderId()`/contact surfacing will still return. There
is no `leaderless` marker and no auto-succession (e.g. earliest active
leader-type membership). This ties directly to membership M1's cascade — the two
should be designed together.

### GR6 — Single-actor destructive lifecycle 🟠
Dissolve and merge reshape the tree and move members on **one** actor's
authority. The platform already has a maker-checker template (identity merge:
submit → approve, SoD self-approval denial, review log). For an action this
consequential, a decision is warranted: keep single-actor (documented) or add a
request/approve workflow for at least dissolve/merge.

### GR7 — No tear-down fan-out 🟠
Archive leaves active memberships in place (only dissolve requires them gone), and
no lifecycle transition emits a domain event. So an event owned by a
just-archived group, a cause crediting it, or a journey scoped to it get no
signal. Mirror the Events L3 pattern: stage a `group.archived` / `group.dissolved`
/ `group.merged` outbox event (idempotent) that downstream can react to, and
decide archive's stance on active memberships.

### GR8 — Parent-status not validated on create/move 🟠
Neither `create()` nor `move()` checks the target parent is `active`. A child can
be born under an archived parent, or a healthy subtree moved under a dissolved
one — re-introducing exactly the mixed-status inconsistency GR2/GR3 warn about.
Cheap fix: reject a non-active parent in both paths.

### GR9 — No service-level lifecycle tests 🟠
The module's best logic — closure maintenance in `create`/`move`, and the
archive/dissolve/merge state machine with child re-parenting + member transfer —
has **no standalone `Services/tests`** (only view/console tests). This is where a
regression would be silent and expensive. A `group_lifecycle_test.php` +
`group_tree_test.php` (closure integrity, cycle/depth guards, merge transfer,
dissolve guards) would be high-value and match the pattern used across Events.

### GR10 / GR11 / GR12 — symmetry, hygiene, auditability 🟡
Reactivate should invert whatever archive cascades (GR10); dissolve/merge should
prune stale closure rows (GR11); and classification/overlay/profile changes could
carry lifecycle-style history for parity with status changes (GR12).

---

## 5. Suggested sequencing

1. **GR1 (authorize create/move/add-member)** — live access-control gap; add the
   existing `group.create` / `group.move` filters + per-target scope checks. Do
   first, alongside membership **M3** (both are missing-authorization fixes).
2. **GR3 + GR11 (terminal groups out of scope/config/rollup; prune closure)** —
   the deepest correctness gap; decide the status filter for the closure-reading
   resolvers and prune closure on dissolve/merge.
3. **GR2 + GR8 + GR10 (archive cascade + parent-status guard + coherent
   reactivate)** — make archive a real region-close and stop mixed-status trees
   forming; design the cascade and its inverse together.
4. **GR7 (tear-down domain events)** — `group.archived/dissolved/merged` outbox,
   idempotent, so downstream (events/causes/journeys) can react. Reuse the L3
   pattern.
5. **GR5 (leader succession)** — build with membership **M1** (account-status
   cascade), since a deactivated leader is the common trigger.
6. **GR4 (legacy addMember status guard)** — small; fold into GR1's cleanup.
7. **GR9 (service-level lifecycle + tree tests)** — lock the above in with
   standalone tests; ideally land incrementally with each fix.
8. **GR6 (maker-checker decision) + GR12 (classification history)** — governance
   and auditability polish once the correctness gaps are closed.

### Recommendation
The group **state machine, dissolve guards and merge restructuring are strong** —
better, in places, than the membership axis. The real weaknesses are at the
**edges where the lifecycle meets other planes**: destructive/structural routes
that aren't authorized (**GR1**), and terminal/archived nodes that the tree hides
but **scope, config inheritance and rollups still honour** (**GR2, GR3, GR8**).
Fixing GR1 immediately (with membership M3), then GR3/GR2, removes the
highest-risk access-control and correctness issues.

This review also surfaces two **cross-review couplings** with the membership doc
that should be built as pairs, not separately:
- membership **M1** (account-status cascade) ↔ groups **GR5** (leader succession);
- membership **M2** (person-merge belonging reassignment) ↔ groups' already-solid
  **group-merge member transfer** (reuse that proven cascade as the template).

Every fix, when scheduled, must follow the standing bespoke pattern used across
the Events lifecycle work: no-JS / CSP-safe views, resource-light (one bounded
indexed query or version-stamped cache; gate resolved once), hierarchical-config
gating default-OFF for any sweep, reuse of existing services (no forked writes),
6-locale parity, dedicated wiring tests, OpenAPI regenerated, full suite green.
Reuse existing permission bits (`group.create`, `group.move`,
`group.change.approve`); the `PermissionBits` map is frozen at 41 entries.
