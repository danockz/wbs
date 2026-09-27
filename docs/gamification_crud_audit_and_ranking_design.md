# Gamification — CRUD Gap-Audit & Ranking/Roll-up Model Design

_Status: proposal for review. No code changed by this document._
_Date: 2026-09-06_

This document has two parts, as requested:

- **Part A — CRUD gap-audit** of every group-scoped Gamification entity, measured
  against the confirmed hierarchical-group contract.
- **Part B — Ranking / attribution / roll-up model** design (schema + engine),
  reflecting the **reversed** standing rule.

---

## 0. Confirmed semantics (the contract everything is measured against)

**Hierarchical group = an action's reach is a _scope_, not a single node**, defined
by two fields together:

- `group_id` — the anchor node (`NULL` = org-wide root)
- `include_descendants` — whether the effect flows **down** to that node's subgroups

Invariants (single source of truth = `WBS\Shared\Support\GroupScopeResolver`):

1. A node **always** affects itself.
2. Affecting subgroups is **opt-in** (`include_descendants = 1`), never automatic.
3. Org-wide (`NULL`) is the universal scope; only an org-wide grant may act org-wide.
4. **Downward reach / authorization** → `grantCovers(scope, includeDescendants, target)`.
5. **Upward inheritance / resolution** → `chain()` / `resolveByCode()`.

### Two axes — do not conflate

| Axis | Mechanism | Direction |
|---|---|---|
| **Config / authority scope** (rules, badges, ranks, categories, follow-up types, campaigns) | `group_id` + `include_descendants` via `grantCovers()` / `chain()` | flows **down** by opt-in |
| **Earned credit / ranking** (individual, group, team, dept, activity, project) | `subject_id` + `subject_type` + **NEW** target/ancestor attribution | **rolls UP** (see Part B) |

### ⚠️ REVERSED standing rule (user decision, 2026-09-06)

> **SUPERSEDED:** "NO ancestor roll-up: credit only the exact target group/team."
>
> **NEW RULE:** A contribution credits its **exact receiving group/team AND
> accumulates to every ancestor** up the chain — affecting **both awards and
> ranking** at each level. Individual-within-group ranking follows the
> **receiving (event/cause) group** for the *primary* attribution of that entry
> (not the member's membership group); when an activity has **no receiving group**,
> the entry falls back to **the higher group of the member's group, or org-level
> only** if the member has no group. Individuals are ranked **org-wide and
> group-wide (the groups they belong to / gave to / participated in)**; groups are
> ranked **at every ancestor level**. An **individual's own total is the SUM of all
> their group participations / givings / involvements.**

### Participation is open across groups (user decision, 2026-09-06)

> A member may **attend/participate in ANY allowed group's event** and/or
> **contribute to ANY group's cause** — participation is NOT restricted to the
> member's own membership groups, and **the member need not belong to the
> receiving group at all**. When an activity HAS a receiving group, the credited
> **`group_id` is that RECEIVING group** (event/cause), taken from the acted-on
> entity — this is already the data model:
>
> - `events.group_id` — the organizing group; plus
>   `event_attendance_group_attribution(attendance_id, group_id)` already credits
>   **one or more** groups per check-in.
> - `causes.group_id` — the owning group a contribution is directed at.
>
> **Membership fallback (reinstated).** When an activity carries **no** receiving
> group, the entry falls back to **the member's own group** (rolled up to its
> ancestors as usual); if the member belongs to no group, the entry is **org-level
> only (`group_id = NULL`)**. So a receiving group is always preferred, but the
> member's group is consulted as the fallback rather than dropping group credit
> entirely.

Unchanged decisions: group/team threshold = single `target_value` + single award
(no tier ladder for groups); model both **phase** (win|build|send) **and
categories**; **no `eval()`** for point formulas; **extend** the existing engine —
`point_ledger` stays the single tamper-evident source.

Settled build parameters (see end of doc for detail): **project** = first-class
`project_code` column; **ranking measure** = configurable (`metric`/`measure`,
default `points`); **ancestor award minting** = opt-in (`rollup_awards`);
**multi-group check-in** = configurable (`group_credit_mode`, default `per_group`);
**group attribution** = receiving group, membership fallback, else org-level;
**individual total** = SUM of all their group participations/givings.

---

## Part A — CRUD gap-audit

### A.1 Legend
- **C/R/U/D** = Create / Read / Update / Delete(or soft-retire).
- **Scope-gated?** = is there an authoritative per-group check at write time
  (not merely the route's coarse `,any` filter)? In THIS codebase that check is
  `BaseController::authorizeGroupScope()` / `authorizeAwardScope()` at the
  **controller** layer (the PDP resolves the grant's `include_descendants`), not
  a service-level `grantCovers()`. (The original audit searched for `grantCovers`
  and wrongly reported zero coverage — see Gap 1 ⟳.)
- **Resolves via chain()?** = does read/resolution honour ancestor `include_descendants`?

### A.2 Findings per entity

> **Note:** the "Scope-gated (write)" column below is shown as **audited → now**
> to reflect the C-1 fix. "Audited" was the original (partly mistaken) reading;
> "now" is the verified/implemented state via `authorizeGroupScope()` at the
> controller layer.

| Entity | Service | C | R | U | D | Scope-gated (write) audited → now | Resolves via chain() |
|---|---|---|---|---|---|---|---|
| Point rules | `RuleService` | ✅ `create` | ✅ `list/show` | ✅ `update` (versioned) | ⚠️ `disable` only | ⚠️ org-wide-only → ✅ **per-group (C-1)** | ❌ (flat `group_id`) |
| Badges | `BadgeService` | ✅ `define` | ✅ `list/show` | ✅ `update` **(C-2)** | ⚠️ `disable` only | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Rank ladders | `RankService` | ✅ `define` | ✅ `list/show/tiers` | ✅ `update` **(C-2)** | ⚠️ `disable` only | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Activity categories | `ActivityCatalogService` | ✅ `define` | ✅ `list/show/catalog` | ✅ `update` **(C-2)** | ⚠️ `disable` only | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Follow-up types | `FollowUpService` | ✅ `defineType` | ✅ `list/show` | ✅ `updateType` **(C-2)** | ⚠️ `disableType` | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Follow-up methods | `FollowUpService` | ✅ `defineMethod` | ✅ `list/show` | ✅ `updateMethod` **(C-2)** | ⚠️ `disableMethod` | n/a (org-wide) | n/a |
| Achievements | `AchievementService` | ✅ `define` | ✅ `show/listAll` | ✅ `update` **(C-2)** | ⚠️ `disable` only | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Streak definitions | `StreakService` | ✅ `define` | ✅ `show/definitions` | ✅ `updateDefinition` **(C-2)** | ⚠️ `disableDefinition` | ✅ (already `authorizeGroupScope`) | ✅ `resolveByCode` |
| Campaigns | `CampaignService` | ✅ `create` | ✅ `show/listForGroup` | ✅ `update` | ✅ `cancel/close` | ⚠️ org-wide-only → ✅ **per-group (C-1)** | ✅ (owner subtree) |
| Campaign teams | `CampaignService` | ✅ `defineTeam` | ✅ `listTeams` | ✅ `updateTeam` | ✅ `deleteTeam` | ⚠️ org-wide-only → ✅ **per-group (C-1)** | n/a |

### A.3 The two systemic gaps

**Gap 1 — No write-time group-scope authorization anywhere in Gamification.**
`grep grantCovers app/Modules/Gamification` → **zero hits.** Every `define/create/
update/disable` takes `(organizationId, data)` and does **not** receive an
`issuerId`, so it *cannot* check that the editing leader's `gamification.manage`
grant actually covers the `group_id` they are writing to. The routes use
`authorize:gamification.manage,any`, which (by the documented convention) is only
a **coarse capability gate** — it admits any manager in any scope and defers the
authoritative per-group check to the service, **which never happens**. This is the
exact hole we just closed in AccessControl.

> **Consequence:** a district-scoped gamification manager can currently define or
> disable an org-wide rule, or a rule for a sibling region, because nothing
> re-checks scope after the coarse gate. Security-relevant.

> **⟳ CORRECTED after deeper read (2026-09-06).** The original finding above
> overstated the gap. The platform *does* have an authoritative per-group check —
> `BaseController::authorizeGroupScope($action, $targetGroupId)` (and the row-level
> `authorizeAwardScope` / `awardWithinScope`) — which calls the hierarchy-aware PDP
> with the resource's `group_id`. Many surfaces already use it. The ACTUAL state:
>
> | Surface | Route gate | Per-group check | Verdict |
> |---|---|---|---|
> | activity-categories define/disable | `,any` | `authorizeGroupScope` ✅ | correct |
> | ranks / achievements / streaks / badges define/disable/grant/revoke | `,any` | `authorizeGroupScope` ✅ | correct |
> | follow-up **types** define/disable | `,any` | `authorizeGroupScope` ✅ | correct |
> | awards approve/reject/pending | `,any` | `authorizeAwardScope`/`awardWithinScope` ✅ | correct |
> | **point rules** create/update/disable | **exact** `gamification.manage` | none | ⚠️ org-wide-ONLY: a group leader cannot manage their own group's rules |
> | **campaigns** (+ tiers/teams/members/activate/cancel/progress/close) | **exact** | none | ⚠️ org-wide-ONLY, yet `group_campaigns.group_id` is REQUIRED — a region/district leader literally cannot create a campaign for their own group |
> | follow-up **methods** define/disable | **exact** | none | acceptable (methods are org-wide, no `group_id`) |
>
> So it is **not** an open barn door — the coarse-gate surfaces are properly
> re-checked. The real defect is the **inverse**: genuinely group-scoped entities
> (**rules, campaigns**) are locked to **org-wide** managers, breaking delegated
> group administration. C-1 fixes those by moving them to the coarse `,any` gate
> **backed by `authorizeGroupScope()`** on the entity's target group.

**Gap 2 — Update is missing on most catalog entities.**
Only `RuleService`, `CampaignService` (and campaign teams) support `update`.
Badges, ranks, activity categories, follow-up types, achievements, and streak
definitions are **define + disable only** — to change a threshold/points/name an
admin must disable and recreate, which loses the code's identity and history.
(`RuleService::update` is intentionally different — rules are **versioned**, so an
"update" mints a new immutable version; that design should be preserved.)

### A.4 Secondary observations (not blocking)
- `point_ledger` and the leaderboard snapshots key on `subject_id` only — **no
  group attribution dimension** (drives Part B).
- `LeaderboardService` ranks `subject_type` in {`user`,`group`,`team`} only; there
  is **no standalone board** for cross-cutting **department / activity group /
  project** dimensions (they exist as membership types + campaign mechanics only).
- Route verbs are inconsistent (`disable` via POST `/disable`, no DELETE for
  catalog entities) — cosmetic, align when adding CRUD.

### A.5 Recommended remediation (Part A)
1. **Write-time scope gating — ✅ IMPLEMENTED (2026-09-06).** The platform's
   authoritative per-group check is `BaseController::authorizeGroupScope($action,
   $targetGroupId)` (calls the hierarchy-aware PDP with the resource's `group_id`;
   the grant's own `scope_group_id`/`include_descendants` are resolved inside the
   PDP), **not** the `grantCovers()` helper the original audit searched for. Most
   surfaces already used it. The two that did not — **point rules** and
   **campaigns** — were locked to org-wide grants; they now use the coarse
   `,any` route gate + `authorizeGroupScope()` on the entity's own group (create =
   body `group_id`; update/disable = resolved via `RuleService::ruleGroupScope()` /
   `CampaignService::campaignGroupScope()`). See Part C-1 for the exact change list.
2. **Fill the `update` verb** for badges, ranks, activity categories, follow-up
   types, achievements, streak definitions (mutable name/threshold/points/config;
   keep `code` immutable; audit each change). Preserve RuleService's versioned
   model. — ✅ **IMPLEMENTED (2026-09-06, C-2).** Each service gained a **partial**
   `update()` (`StreakService::updateDefinition`, `FollowUpService::updateType`
   /`updateMethod`) that loads the existing row by `(org, code, group_id)`,
   returns NOT_FOUND when absent, **merges** the partial body over the stored row
   (so omitted fields keep their values — unlike the full-replace `define()`
   upsert), forces `code` + `group_id` immutable, then delegates to `define()`
   for the entity's own validation + persistence (no duplicated validation). New
   PATCH routes: `PATCH badges|ranks|achievements|streak-definitions|
   activity-categories|follow-up-types|follow-up-methods/{code}`, each gated per
   the entity (group-scoped ones `,any` + `authorizeGroupScope`; methods stay
   org-wide `gamification.manage`). RuleService's **versioned** `update` is
   untouched (still supersedes rather than mutating award history).
3. **Normalise soft-retire**: keep `disable` (soft, audited) as the delete story
   for referenced catalog rows; add hard `delete` only where nothing references
   the row (mirror `RoleService::delete` in-use guard).

---

## Part B — Ranking / attribution / roll-up model

### B.1 Problem statement
The reversed rule requires that a single contribution:
- credit the **individual** (org-wide, as today), **and**
- credit the **receiving (event/cause) group** (individual-within-group ranking), **and**
- **accumulate up every ancestor** of that receiving group (group ranked at ancestor
  levels), **and**
- feed the same numbers into **cross-cutting** dimensions (department, activity
  group/category, project, ad hoc team) the subject/target belongs to.

Today `point_ledger` has **no `group_id`**, so none of the group/ancestor/cross-cut
ranking can be derived from the ledger — it can only rank individuals org-wide.

### B.2 Design principles
- **Extend, don't fork.** `point_ledger` stays the single tamper-evident source of
  truth. We add an **attribution** dimension to it, plus a **derived, rebuildable**
  rollup table for fast ranking. Awards are still minted by the existing engine.
- **Attribution ≠ duplication.** One contribution = **one** individual ledger
  entry. Group/ancestor/cross-cut standings are **aggregations** of that entry via
  an attribution key — we do **not** write N duplicate ledger rows up the tree
  (that would corrupt the individual's own total and the idempotency key).
- **Awards vs. ranking both roll up, but independently.** Ranking is a SUM;
  an award is minted once per (subject, threshold) when its own cumulative crosses
  its single `target_value`. Ancestors mint their **own** award off their **own**
  rolled-up total — not a copy of the child's award.

### B.3 Schema changes — ✅ IMPLEMENTED (2026-09-06, migration 000045)

> **Status:** built in `2026-09-05-000045_RankingRollupAndGroupAttribution.php`
> exactly as specified below, with **one addition**: `point_ledger` also gains
> `amount_minor BIGINT NOT NULL DEFAULT 0`. Rationale — the roll-up's `volume`
> measure must be a pure function of the ledger for `rebuild()` to reconstruct it;
> without a per-entry raw amount, `volume_minor` could not be recomputed on
> rebuild. `amount_minor` is populated from `$opts['amount_minor']` /
> `data.amount_minor` / `data.volume` on award and negated on reversal.
> Engine wiring (B.4) is implemented in `RollupService` + `PointsEngine`; the
> rebuild/backfill command is `gamification:rebuild-rollup`.
>
> **Call-site wiring done:** `RewardCoordinator::onSucceeded()` (the
> contribution.succeeded consumer) now resolves the cause's owning group and
> passes it as `receiving_group_id`, plus `project_code = cause_id` and
> `amount_minor`, so verified givings credit the exact receiving group and roll
> up to its ancestors (falling back to the giver's own group when the cause is
> org-wide). Refunds already compensate correctly because `reverse()` reads the
> stored `group_id`/`amount_minor` off the original entries.
>
> **✅ Remaining award sites wired (2026-09-06):** the other three award sites now
> thread the activity's own group and phase as the RECEIVING group, so a
> group-scoped course / achievement / follow-up credits that exact group and
> rolls up its ancestors, while org-wide ones still use PointsEngine's membership
> fallback → org-level:
> - **`FollowUpService::awardFor()`** — passes the follow-up row's `group_id` as
>   `receiving_group_id` and the type's `phase` (both `record()` and
>   status-`update()` callers). A Build/Send follow-up now scores its group.
> - **`AchievementService::unlock()`** — a group-scoped definition
>   (`achievement_definitions.group_id`) passes its `group_id` + `phase` when
>   posting the spendable bonus; org-wide definitions fall back as before.
> - **`JobRouter::courseCompleted()`** — passes through `group_id`, `phase` and
>   `category_code` from the course-completion payload when present.
>
> All three still degrade gracefully: absent a group on the activity, the earner's
> membership group (then org-level) is used, exactly as `resolveGroupId()` defines.

### B.3 Schema changes (as specified)

**B.3.1 Attribute each ledger entry to the acted-on group (`group_id`).**
```
ALTER TABLE point_ledger
  ADD COLUMN group_id      CHAR(36)    NULL AFTER subject_type,  -- EXACT credited group (the event/cause group). NULL = org-only.
  ADD COLUMN category_code VARCHAR(64) NULL AFTER group_id,      -- cross-cut: activity category
  ADD COLUMN project_code  VARCHAR(64) NULL AFTER category_code, -- cross-cut: project (first-class, spans groups/categories)
  ADD COLUMN phase         VARCHAR(8)  NULL AFTER project_code,  -- win|build|send
  ADD KEY pl_group_idx    (organization_id, season_id, group_id),
  ADD KEY pl_category_idx (organization_id, season_id, category_code),
  ADD KEY pl_project_idx  (organization_id, season_id, project_code);
```
- **`group_id` prefers the RECEIVING group** (event/cause), and falls back to the
  member's own group only when there is no receiving group (nullable in the column
  to allow genuinely org-level activities and historical backfill). Resolution
  order:
  1. explicit receiving `group_id` passed on the activity/event `$opts`;
  2. `events.group_id` / the rows in `event_attendance_group_attribution`
     (multi-group check-ins → one ledger entry per credited group, see B.4.1);
  3. `causes.group_id` for a contribution;
  4. **membership fallback** — the member's own group (if several, the member's
     primary/highest group; roll-up handles its ancestors);
  5. else `NULL` (pure org-level — counts to the individual org-wide, to no group).
- **Individual total = SUM of all group participations/givings/involvements.** The
  member's own `subject_id`/`points` are recorded as today; the org-wide individual
  standing is exactly the sum over all their ledger entries (across every group
  they gave to / participated in). `group_id` additionally *tags* which group (and,
  by roll-up, which ancestors) each entry credits.

**B.3.2 Derived rollup table (rebuildable cache for ranking).**
```
CREATE TABLE group_point_rollup (
  organization_id CHAR(36) NOT NULL,
  season_id       CHAR(36) NOT NULL,
  group_id        CHAR(36) NOT NULL,   -- an ancestor-or-self of some ledger group_id
  category_code   VARCHAR(64) NOT NULL DEFAULT '*',  -- '*' row = all categories combined
  project_code    VARCHAR(64) NOT NULL DEFAULT '*',  -- '*' row = all projects combined
  phase           VARCHAR(8)  NOT NULL DEFAULT '*',  -- '*' row = all phases combined
  points          BIGINT NOT NULL DEFAULT 0,  -- measure: points
  volume_minor    BIGINT NOT NULL DEFAULT 0,  -- measure: raw amount/volume (minor units)
  contributions   INT    NOT NULL DEFAULT 0,  -- measure: event/contribution count
  -- (extend with further measures as columns; boards pick the ranking measure at
  --  read time — decision (ii): ranking measure is CONFIGURABLE, not hard-wired)
  updated_at      DATETIME(6) NOT NULL,
  PRIMARY KEY (organization_id, season_id, group_id, category_code, project_code, phase)
);
```
- One contribution tagged with `group_id = L` contributes to **L and every
  ancestor of L** (via `group_closure`, which already materialises ancestor
  distance) — this is the **roll-up**. Each ancestor row is an independent SUM, so
  a region's rank = sum over its whole subtree, exactly as required.
- `category_code`/`project_code`/`phase` = `'*'` rows are the "all-combined"
  totals; specific-value rows power cross-cutting **activity / project / phase**
  boards without extra tables. (A `'*'` sentinel is used rather than `NULL` because
  these columns are part of the composite PRIMARY KEY, and MySQL disallows NULLable
  PK columns.)
- **Rebuildable**: the whole table is a pure function of `point_ledger` +
  `group_closure`, so it can be dropped and recomputed → no new source of truth.

**B.3.2a Config flags for the settled decisions.** Reuse existing columns where
present; add the few that are missing.
- **Ranking measure (ii)** — already partly modeled: `group_campaigns.metric`
  (`points|amount|volume|count`). Generalise the same `metric` name onto any group
  threshold row and honour it in both minting and boards. Boards also take a
  `measure` query param; default `points`.
- **Ancestor award opt-in (iii)** — ADD `rollup_awards TINYINT(1) NOT NULL
  DEFAULT 0` to the group-threshold config (`group_campaigns`, and any future
  group-threshold table). `0` = ancestor is ranked but mints no award; `1` = mint
  its own single award on its own crossing. (Distinct from the existing
  `rollup_to_general`, which is about counting to the general season, not ancestor
  awards.)
- **Multi-group credit mode (iv)** — ADD `group_credit_mode VARCHAR(16) NOT NULL
  DEFAULT "per_group"` to `gamification_rules` (`per_group|individual_once`).
  Applies when an activity yields multiple credited groups (multi-group check-in).

**B.3.3 Cross-cutting dimensions that are NOT tree ancestors.**
Department / activity-group / project / ad-hoc-team are memberships, not tree
nodes. Two are already representable:
- **Activity category / project / phase** → first-class columns above
  (`category_code`, `project_code`, `phase`) on the ledger and rollup; each powers
  its own board.
- **Department / ad hoc team** → aggregate by `group_membership` of the given
  `type` (`department`,`team`) — a membership-join board, computed on read or
  cached in a sibling `membership_point_rollup` if performance requires. No tree
  walk; it's a set aggregation.

### B.4 Engine changes — ✅ IMPLEMENTED (2026-09-06)

> **Where:** new `RollupService` (attribution + ancestor upsert + rebuild) and
> `PointsEngine` wiring. `RollupService::resolveGroupId()` implements the B.3.1
> resolution order (receiving group → group/team subject is own group →
> **membership fallback** via `primaryMembershipGroup()` = explicit `primary`
> membership else deepest active group by `groups.depth` → NULL). `applyDelta()`
> walks `group_closure` (ancestor-or-self) and upserts each of the 8 combined
> cells (`category`/`project`/`phase` each kept specific or rolled to `'*'`) with
> `INSERT … ON DUPLICATE KEY UPDATE`. Roll-up fires ONLY when an entry is/【becomes】
> **final**: on `award()` (final), on `clearHeld()` / `approveAward()` (held→final),
> and a compensating negative delta on `reverse()` (guarded so a never-counted
> `held` entry is not double-subtracted; `rejectAward` never rolled up so needs no
> compensation). Idempotency key widened to include `group_id`.
> **✅ C-3b DONE (2026-09-06; extended leaf-inclusive 2026-09-08):** ancestor
> *award minting* opt-in (`rollup_awards`) is implemented on the group-campaign
> milestone path. When a subtree campaign sets `rollup_awards = 1`, a member's
> verified contribution — already tallied to its owner-direct-child team — ALSO
> tallies into **every group on the member's own ancestor-or-self path within the
> owner's subtree: from the member's most-specific (leaf) group up to and
> including the campaign owner** (`milestoneAwardChain()`, ordered leaf→owner;
> falls back to the above-the-team-only `ancestorAwardChain()` when the member's
> leaf group is unknown). Each such group is a first-class
> milestone subject: it accumulates in its own `campaign_team_standings` row and
> mints its own SINGLE award at `team_target_value` via the existing
> `grantTeamAward()` (distinct team-scoped `source_ref` per group ⇒ no idempotency
> collision). `rollup_awards = 0` preserves today's behaviour exactly (only the
> single direct-child team tallies/mints). Every level uses the campaign's single
> `team_target_value` (settled "single target + single award"); the resolver is
> shaped so a future per-ancestor target table can override without a rewrite.
> Ancestors are *ranked* regardless of the flag (that came with C-3); only the
> *award* is gated. Accepted on `create`/`update`, echoed by `show`.
>
> **✅ Interpretation RESOLVED (user decision, 2026-09-08): leaf-inclusive.** The
> member's competing "team" is still the campaign owner's **direct-child subgroup**
> (`resolveTeams` semantics) and is tallied by the caller, but milestone minting
> now runs over the member's **OWN ancestor-or-self path** within the owner's
> subtree — i.e. from the member's **most-specific (leaf) group** up to and
> INCLUDING the owner — so the member's own group is a first-class milestone
> subject too (parity with individuals), not merely ranked. Implemented as
> `CampaignService::milestoneAwardChain($org, $ownerId, $memberLeaf, $teamRef)`:
> set math `(ancestor-or-self of memberLeaf) ∩ (descendant-or-self of ownerId)`,
> excluding `$teamRef` (already tallied), ordered leaf→owner. The member's leaf is
> the denormalized `subject_group_id` (opts → progress row → cheap lookup). When
> the leaf is unknown the engine falls back to the previous
> `ancestorAwardChain()` (above the direct-child team only) so an edge/group-less
> contribution never mis-mints. `rollup_awards = 0` still keeps today's behaviour
> exactly (only the single direct-child team tallies/mints). Every level uses the
> campaign's single `team_target_value`.

### B.4 Engine changes (as specified)

> **✅ B.4.1 Multi-group check-in DONE (2026-09-06).** Implemented as
> `PointsEngine::awardMultiGroup($org, $rule, $subject, $sourceRef, $groupIds, $opts)`
> and wired into the attendance path:
> - **Any member, any group's event.** The credited-group set is the event's own
>   organizing group (`events.group_id`, the primary) **unioned with every**
>   `event_attendance_group_attribution` row — these need NOT be groups the member
>   belongs to, matching the standing rule that a member may attend/participate in
>   ANY allowed group's event.
> - **`per_group` (default)** — one full ledger entry **per distinct credited
>   group**, all sharing source_ref `attendance:{id}`; the idempotency UNIQUE
>   already includes `group_id`, so distinct groups write distinct rows and a
>   re-scan de-dupes per group. The individual's org-wide total is the SUM (counts
>   once per credited
>   group); each group's standing is independent and rolls up its OWN ancestors.
>   Anti-gaming caps/cooldown/limits are enforced **once** (primary entry); the
>   2nd..Nth carry a new `suppress_limits` opt so a single action isn't rejected as
>   a repeat of itself. A cap hit on the PRIMARY aborts; per-group failures below
>   that are recorded in `skipped[]` and the rest still credit.
> - **`individual_once`** — ONE individual ledger entry against the primary group +
>   **rollup-only** `applyDelta()` for the other groups (their standings rise, the
>   individual is not multiplied).
> - **Call site:** `CheckinService` now takes an optional `PointsEngine` (DI in
>   Events `Services::checkin()`), and `recordAttendance()` best-effort awards
>   `event.attended` via `awardMultiGroup()` on both the fresh and the
>   already-present (re-scan) paths; a capped/unconfigured award never fails the
>   check-in. A plain `reverse('attendance:{id}')` compensates all credited groups
>   symmetrically (they share the source_ref) if the attendance is later voided.

**B.4.1 Multi-group check-in (CONFIGURABLE — decision (iv)).** An event check-in
may credit **several** groups (`event_attendance_group_attribution`). The
activity/rule config picks the mode via `group_credit_mode`:
- `per_group` (default) — emit **one ledger entry per credited `group_id`**, each
  idempotent on `(rule_id, subject_id, source_ref, entry_type, group_id)`. The
  individual's org-wide total is the SUM, so the activity counts once per credited
  group; each group's standing is independent.
- `individual_once` — emit **one** individual ledger entry against the primary
  `group_id`; the other credited groups receive **rollup-only** deltas (their
  standings rise, but the individual is not multiplied).

1. **`PointsEngine::award()`** — accept `group_id`, `category_code`, `project_code`,
   `phase` in `$opts`, persist them on the ledger row. `group_id` defaults via the
   resolution order in B.3.1 (receiving group → membership fallback → else NULL).
   Idempotency key extended to include `group_id` (see B.4.1).
2. **Roll-up updater** — after a `final` (or on `clearHeld`) entry lands, upsert
   `group_point_rollup` for **each ancestor-or-self** of the entry's `group_id`
   (bounded set; org tree depth ≤ 9). For each such group, update the fully-specific
   cell `(category_code, project_code, phase)` **and** the combined-total cells
   where each of those three dimensions is rolled to the `'*'` sentinel (so every
   "all" board stays consistent). On `reverse`/`reject`, apply the compensating
   delta. Wrap in the same transaction as the ledger write.
3. **Award minting up the chain (OPT-IN — decision (iii))** — the *self* group with
   a configured threshold always mints on crossing. An **ancestor** mints its own
   single award only when its threshold row sets `rollup_awards = 1`. Crossing is
   measured on the group's **rolled-up** total in the threshold's configured
   `measure` (decision (ii)) vs. its single `target_value`; mint once, idempotent
   per (group, threshold, season). Ancestors are always *ranked* on subtree totals
   regardless of this flag — only the *award* is gated. Never copy the child's
   award upward.
4. **Rebuild command** — `gamification:rebuild-rollup [--season=]` recomputes
   `group_point_rollup` from the ledger (disaster-recovery + backfill for the new
   columns on historical rows).

### B.5 Ranking read model — ✅ IMPLEMENTED (2026-09-06, C-4)

> **Where:** `LeaderboardService` gained `groups()`, `groupStanding()`,
> `individuals()`, and `byDimension()`; all read the derived `group_point_rollup`
> (groups/cross-cut) or `point_ledger` (individuals-within-group), and every one
> takes a **configurable `measure`** (`points` | `volume` | `contributions`,
> mapped to `points`/`volume_minor`/`contributions` on the rollup and
> `SUM(points)`/`SUM(amount_minor)`/`COUNT(*)` on the ledger). Cross-cut axes
> (`category`/`project`/`phase`) filter to a specific value or default to the
> `'*'` combined cell. Group boards rank a parent's **direct children** (closure
> distance 1) or all groups; a node's own number is its subtree sum, giving
> "ranked at ancestors' level". Individual boards accept `within_group` +
> `include_subtree`. Decoration is PII-free (groups expose name/depth/parent only;
> individuals expose display_name only, never email).
>
> **Endpoints (all under the `auth` group, member-visible):**
> - `GET gamification/leaderboards/individuals?within_group=&include_subtree=1&measure=&category=&project=&phase=&season_id=&limit=`
> - `GET gamification/leaderboards/groups?parent=&measure=&category=&project=&phase=&season_id=&limit=`
> - `GET gamification/leaderboards/groups/{groupId}/standing?measure=&…`
> - `GET gamification/leaderboards/by/{category|project|phase}/{value}?measure=&limit=`
>
> The original `GET gamification/leaderboard` (org-wide individuals) is unchanged.
>
> **✅ C-4b DONE (2026-09-06):** `departments`/`teams` membership-join board.
> `LeaderboardService::membershipBoard($org, $type, …)` ranks each group that has
> active members of the given `membership_type` (`department`|`team`|`activity`|
> `leader`|`member`|`guest`) by the SUM of those members' final ledger totals in
> the chosen measure — a set aggregation (a member contributes their whole
> org-wide total), deliberately distinct from `group_point_rollup`. No schema
> work (per B.3.3). Endpoint:
> `GET gamification/leaderboards/membership/{type}?measure=&season_id=&limit=`.

### B.5 Ranking read model (as specified)
Add dimension-aware boards, all reading the derived tables:
- `individuals` — org-wide (today) **and** `within_group=<id>` (filter ledger by
  `group_id` = that group or, if "including subgroups", its subtree).
- `groups` — from `group_point_rollup` at any `group_id` (a node's board ranks its
  **children**; a node's own number is its subtree sum) → gives "ranked at
  ancestors' level".
- `by_category` / `by_project` / `by_phase` — `group_point_rollup` cells filtered
  to a specific value on that axis (with the other axes at `'*'`), or the
  corresponding ledger column for individuals.
- `departments` / `teams` — membership-join aggregation by membership `type`.

### B.6 Interaction with awards & idempotency (safety)
- **Ledger idempotency preserved** → the key becomes
  `(rule_id, subject_id, source_ref, entry_type, group_id)` so an activity that
  legitimately credits multiple groups (multi-group check-in) is not falsely
  deduplicated, while a repeat of the *same* (event, group) still collapses. The
  individual's org-wide total is the SUM over their entries, exactly as today.
- **Roll-up is derived** → can never disagree with the ledger for long (rebuildable),
  and a bug there cannot corrupt the tamper-evident source.
- **Awards remain single-per-threshold** → group tier ladders explicitly excluded;
  each ancestor mints at most one award for its own crossing.
- **Reversals** → compensating deltas to rollup mirror the ledger reversal.

### B.7 Migration/backfill plan — ✅ IMPLEMENTED (2026-09-06, C-5)

> **Executable runbook:** `docs/runbooks/ranking-rollup-enable.md`. Backfill is
> `RollupService::backfillLedgerAttribution()` (idempotent; fills contribution
> rows' `group_id`/`project_code` from the cause and `amount_minor` from the
> contribution, only where still NULL/0), exposed as
> `php spark gamification:rebuild-rollup --backfill` (backfills then rebuilds).
> Steps 1–5 below map to the runbook's steps; step 6 tests are ✅ DONE: the pure
> fan-out math is locked by `tests/unit/RollupDimensionCellsTest.php`, and the
> DB-backed engine is proven by `tests/integration/RankingRollupAndMultiGroupTest.php`
> — a 3-level hierarchy (Region › District › Cell + sibling District2) asserting
> leaf→ancestor roll-up deltas (siblings stay zero), reversal symmetry (every
> level returns to zero), rebuild-reproduces-incremental, org-wide individual
> total unchanged by attribution, per_group fan-out (one entry per credited group,
> idempotent on replay), individual_once (one entry + rollup-only deltas), and
> "any member, any group's event" (a group-less member is still credited to the
> event's group and its ancestors). Self-skips without a test DB.

### B.7 Migration/backfill plan (as specified)
1. Ship schema (B.3) with nullable columns → no behaviour change yet.
2. Backfill `group_id`/`category_code`/`project_code`/`phase` on historical rows
   where the source event still carries them (best-effort; membership fallback for
   `group_id` where no receiving group; NULL where unknown).
3. Build `group_point_rollup` via the rebuild command.
4. Turn on roll-up writes in the engine + ancestor award minting.
5. Extend `LeaderboardService` + routes for the new boards.
6. Tests: unit (pure ancestor-set roll-up math, category/phase bucketing) +
   integration (contribution to leaf → correct deltas at every ancestor; reversal
   symmetry; single-award-per-threshold; org-wide individual unchanged).

---

## Part C — Proposed sequencing

1. **C-1 (scope gating) — ✅ DONE (2026-09-06).** After the corrected read (see
   Part A Gap 1 ⟳), the coarse-gate surfaces were already re-checked; the real
   defect was that genuinely group-scoped entities (**rules, campaigns**) were
   locked to **org-wide** managers. Fixed by moving them to the coarse
   `authorize:gamification.manage,any` route gate + an authoritative per-group
   `authorizeGroupScope()` re-check in the controller against the entity's own
   `group_id`:
   - **Rules** (`ConfigController::createRule/updateRule/disableRule`): create
     gates on the target group from the body (`targetGroupId()`); update/disable
     resolve the rule's owning group via new `RuleService::ruleGroupScope($org,$code)`
     (latest version's `group_id`; NOT_FOUND when the code is unknown) then
     `authorizeGroupScope('gamification.manage', $groupId)`. Org-wide rules
     (`group_id = NULL`) still require an org-wide grant.
   - **Campaigns** (all 13 writes: create, update, tiers, teams+members,
     activate, cancel, progress, close): create gates on the body's `group_id`;
     every campaign-scoped write resolves the owning group via new
     `CampaignService::campaignGroupScope($org,$campaignId)` then re-checks. A
     region/district leader can now run their own group's campaigns.
   - **Routes flipped** to `,any` (coarse cap gate): `rules` create/update/disable
     and all `campaigns/*` writes. `showRule`, `config` set/delete, and follow-up
     **methods** stay exact `gamification.manage` (methods are org-wide, no
     `group_id`).
   - Unchanged (already correct): activity-categories, ranks, achievements,
     streaks, badges (grant/revoke) via `authorizeGroupScope`; awards
     approve/reject/pending via `authorizeAwardScope`/`awardWithinScope`.
2. **C-2: fill `update` CRUD** for the catalog entities (Part A.5 #2). — ✅ **DONE
   (2026-09-06):** partial `update()` added to all six services + PATCH routes;
   see Part A.5 #2 for details. (A.5 #3 — hard `delete` with in-use guards — is
   deferred; soft `disable` remains the delete story for referenced catalog rows.)
3. **C-3: ranking schema + engine roll-up** (Part B.3–B.4) behind nullable columns.
   — ✅ **DONE (2026-09-06):** migration 000045 (ledger group/category/project/
   phase/amount_minor + widened idempotency key + `group_point_rollup` +
   `rollup_awards`/`group_credit_mode` flags); `RollupService`; `PointsEngine`
   attribution + ancestor roll-up on all final transitions/reversals;
   `gamification:rebuild-rollup` command. **C-3b:** ancestor award *minting*
   opt-in (`rollup_awards`) — ✅ **DONE (2026-09-06; leaf-inclusive 2026-09-08)**
   on the group-campaign milestone path (`milestoneAwardChain()` tallies every
   group on the member's leaf→owner path within the owner's subtree; each opted-in
   group mints its single award at `team_target_value`). See Part B.4 step 3.
4. **C-4: ranking read model + boards** (Part B.5) and rebuild command. — ✅
   **DONE (2026-09-06):** `LeaderboardService::groups/groupStanding/individuals/
   byDimension` + 4 endpoints (rebuild command already shipped in C-3). **C-4b:**
   `departments`/`teams` membership board — ✅ **DONE (2026-09-06):**
   `membershipBoard()` + `GET gamification/leaderboards/membership/{type}`.
5. **C-5: backfill + enable** (Part B.7). — ✅ **DONE (2026-09-06):**
   `RollupService::backfillLedgerAttribution()` (contribution rows → cause group /
   project / amount, idempotent, NULL/0-only), wired as
   `gamification:rebuild-rollup --backfill`; runbook at
   `docs/runbooks/ranking-rollup-enable.md`; unit test
   `tests/unit/RollupDimensionCellsTest.php` locks the roll-up fan-out math and
   integration test `tests/integration/RankingRollupAndMultiGroupTest.php` proves
   the DB-backed engine (ancestor deltas, reversal symmetry, rebuild parity,
   per_group / individual_once multi-group check-in, "any member, any event").

### Settled decisions (user, 2026-09-06) — build against these

- **(i) "project" = first-class COLUMN.** A dedicated `project_code VARCHAR(64)
  NULL` on `point_ledger` and a matching dimension in `group_point_rollup`, indexed
  for its own boards. Projects are cross-cutting (a project spans groups and
  categories), so they get their own attribution axis rather than being folded into
  `category_code`. `NULL` = not project-attributed.

- **(ii) Ranking measure = CONFIGURABLE.** A board is not hard-wired to points.
  `group_point_rollup` stores **all** measures side by side —
  `points`, `volume_minor`, `contributions` (extend with `count`/other measures as
  needed) — and the **measure to rank by is a parameter** of the read
  (`?measure=points|volume|contributions|…`) and of any award threshold
  (`measure` alongside `target_value`). Individual/org boards read the same
  measures off the ledger. Default measure = `points` when unspecified.

- **(iii) Ancestor award minting = OPT-IN.** Off by default. An ancestor mints its
  own single award only when its group threshold row explicitly enables it
  (`rollup_awards = 1` on the group threshold / campaign config). Ranking still
  rolls up unconditionally (ancestors are always *ranked* on subtree totals); only
  the *minting of an award* at the ancestor is gated by the opt-in flag.

- **(iv) Multi-group check-in = CONFIGURABLE.** The activity/rule config chooses
  between two modes via a `group_credit_mode` setting:
  - `per_group` (each credited group gets its own ledger entry; the individual's
    org-wide total counts the activity once per credited group), or
  - `individual_once` (ONE individual ledger entry against a primary `group_id`;
    the other credited groups get **rollup-only** attribution so their standings
    still rise, but the individual is not multiplied).
  Default = `per_group`. The idempotency key
  `(rule_id, subject_id, source_ref, entry_type, group_id)` supports both:
  `per_group` writes N rows (distinct `group_id`); `individual_once` writes one
  ledger row + N−1 rollup deltas.
