# Leadership-as-Scoped-Responsibility: RuBAC + Scope Modes

This note documents the AccessControl changes that model **leadership as a
scoped responsibility**: a leader (or an elevated member) carries responsibilities
that are operational **only within a scope the assigning leader defines**, at the
leader's discretion. Three capabilities were added, built on the existing
MAC → SoD → ABAC → RBAC Policy Decision Point (PDP) rather than beside it.

## 1. Scope modes + multi-group sets

The old grant scope was a single group id plus an `include_descendants` boolean —
only two of the three shapes leaders actually use. Scope is now expressed as a
**mode** (`WBS\Shared\Support\ScopeMode`):

| Mode                   | Covers                                              |
|------------------------|-----------------------------------------------------|
| `self`                 | only the named group                                |
| `self_and_descendants` | the named group **and** every subgroup beneath it   |
| `descendants_only`     | the subgroups beneath the named group, **not** it   |
| `groups`               | a **hand-picked set** of specific groups            |

- Org-wide is still `scope_group_id = NULL` with mode `self`.
- `descendants_only` is the "regional lead who administers the branches but is not
  operational at the region node" case.
- `groups` carries its set in the new `grant_scope_groups` table
  (`grant_type` + `grant_id` → `group_id`), polymorphic across role assignments,
  access requests, delegations, break-glass sessions, and rules.

The single source of truth is `GroupScopeResolver`:

- `grantCoversScoped($scope, $mode, $target, $groupSet = [])` — the mode-aware
  coverage test the PDP and every service call.
- `grantCovers($scope, $bool, $target)` — the legacy boolean signature, kept and
  delegating to the mode-aware one (maps the bool to `self` / `self_and_descendants`).
- `resolveScopeGroups(...)` — expands a scope into the concrete group id list
  (or `null` for org-wide).
- `scopeContains(issuer…, target…)` — **containment**: does the issuer's own
  scope cover every group the target scope would? Used to bound delegation.

Old rows keep working: `scope_mode` is back-filled from `include_descendants`
(`1 → self_and_descendants`) and both columns are maintained on write.

## 2. RuBAC — a general, reusable rule engine

`WBS\AccessControl\Services\RuleEngine` is a **general** (condition → effect)
evaluator, **not** access-only. A `rules` row has:

- `facet` — `access`, `membership`, `gamification`, `notification`, `events`,
  `contributions`. The PDP consumes the `access` facet; other facets call the same
  engine.
- `action_pattern` — exact, `prefix.*`, or `*`.
- `condition` — an ABAC predicate tree, evaluated by the **same**
  `AbacConditionEvaluator` the PDP uses (validated at write time; declarative data,
  never code — no `eval()`).
- `effect` — `allow` / `deny` (meaningful to all facets), plus facet-defined
  `flag` / `adjust` / `require_review` with optional `effect_params`.
- scope (`scope_group_id` + `scope_mode` + optional group set) so a leader's rule
  only bites within their branch, and `priority` (lower runs first).

`RuleEngine::evaluate(org, facet, action, attributes, targetGroupId)` returns a
facet-agnostic `RuleOutcome`. For the access facet the semantics are
**deny-overrides**: any matched `deny` → deny; else any `allow` → allow; else none.

### PDP wiring

`AuthorizationService::decide()` now runs:

```
MAC → SoD → RuBAC(access, deny-overrides) → ABAC(org-wide) → RBAC → default-deny
```

RuBAC runs **before** org-wide ABAC so a leader's scoped guardrail can deny within
their own branch even when a broad org policy or role would otherwise allow. A
rule `deny` is decisive; a rule `allow` is recorded but still requires RBAC/ABAC to
actually grant (a scoped rule is a guardrail, not a standalone grant).

The four RBAC grant queries (role assignments, direct grants, delegations,
break-glass) are now **mode-aware** via `anyGrantCovers()`, honouring
`scope_mode` and loading `groups`-mode sets from `grant_scope_groups`.

## 3. Any leader may sub-assign within their own scope

`RoleAssignmentService::assign()` / `revoke()` previously required the org-wide-ish
`access.assignment.manage` permission covering the target. Authorization is now
**two paths, either sufficient** (`issuerCanAssign`):

- **(a) Delegated admin** — the issuer holds `access.assignment.manage` over a
  scope that **contains** the target scope (`scopeContains`).
- **(b) Leadership containment** — the issuer personally holds, over a scope that
  contains the target, **every permission the assigned role grants**. So any
  leader can hand out responsibilities within their own scope, bounded by what they
  themselves hold there — no separate org-admin step — and can never grant broader
  or grant a permission they don't have.

Assignments also accept `scope_mode` + `scope_groups` and persist the multi-group
set. All mutations remain expiring, idempotent, and audited.

## 4. Cross-cutting groups (opt-in, down-only)

The group hierarchy is a strict single-parent tree, but some groups **cut across**
it — a worship team, a youth network, a choir — drawing members from many
branches. Such a group is an ordinary `groups` row LINKED to the hierarchy
node(s) it spans, via the new `group_crosscut_links` table
(`crosscut_group_id` ↔ `hierarchy_group_id`, many-to-many).

Coverage rule (in `GroupScopeResolver`):

- **Down-only** — a leader whose scope covers a hierarchy node *also* covers the
  cross-cut groups attached to that node. A cross-cut group's own leadership does
  **not** thereby gain the hierarchy (no reverse chaining; cross-cut never chains
  to further cross-cut).
- **Opt-in per grant** — pulled in only when a grant/rule sets
  `include_crosscut = 1`. Default `0`, so existing grants are unchanged.
- Interacts correctly with every mode: `descendants_only` reaches the cross-cut
  groups of its *descendants* but not those attached to the excluded node itself;
  `groups` mode reaches the cross-cut groups attached to the hand-picked set.
- `scopeContains()` is cross-cut-aware, so **delegation is bounded**: a leader can
  only delegate cross-cut reach they themselves hold.

`GroupScopeResolver` gained `crosscutHierarchyNodes()`, `crosscutGroupsUnder()`,
and the private `coveredViaCrosscut()`; `grantCoversScoped()`,
`resolveScopeGroups()`, and `scopeContains()` all take an `includeCrosscut` flag.
The PDP, `RuleEngine`, `RuleService`, and `RoleAssignmentService` read/write the
new `include_crosscut` column and thread it through coverage + containment.

Managed via `WBS\Groups\Services\GroupCrosscutService` (validates same-org, no
self-link, and rejects links already expressed by the tree) and the
`groups/{id}/crosscuts` endpoints.

## 5. Group classification — the "kind" axis (placement vs kind)

Two of the motivating cases — a **department** (management + staff/volunteers
pursuing targets) and an **activity-specific team** (e.g. physical/cyber security,
with its own leadership holding events/meetings) — are both just groups. What was
missing was a way to say WHAT KIND of group each is, independently of WHERE it
sits or WHICH branches it cross-cuts.

Chosen model (option B): keep three orthogonal axes.

| Axis | Answers | Mechanism |
|------|---------|-----------|
| **Placement** | where does it sit? | `groups.parent_id` + `group_closure` (the tree) |
| **Cross-cut** | which branches does it span? | `group_crosscut_links` (§4) |
| **Kind** | what is it? | `group_kinds` catalog + `groups.kind_code` |

- `group_kinds` is an **org-configurable catalog** (department, activity_team,
  ministry, team, committee, network seeded by `GroupKindSeeder`; fully editable).
- `groups.kind_code` is nullable — existing groups are unaffected — and validated
  against an active kind on create / `setKind`.
- `default_placement` (nested|crosscut|either) on a kind is **advisory metadata**
  for the admin UI only; **it does not change scope evaluation**. Per decision,
  scope/authority behave identically regardless of kind — the cross-cut coverage
  and containment rules from §1–§4 apply uniformly.
- **Internal roles (management / staff / volunteer)** are NOT new tables: they use
  the existing `group_members.role` + `membership_type` fields (the type
  vocabulary already includes `member, leader, activity, department, team,
  guest`). A department's management vs staff vs volunteers are memberships with
  different roles; the group has its own `leader_user_id`, its own events
  (`events.group_id`), and its own targets (first-class group milestones) — all
  pre-existing capabilities.

So: a cyber-security **activity_team** is a group (own leader, own members with
management/staff/volunteer roles, own events & meetings, own targets), optionally
cross-cut-linked to the branches it protects, and classified `kind_code =
activity_team`. A **department** is the same with `kind_code = department`.

Managed via `WBS\Groups\Services\GroupKindService` and the `group-kinds`
endpoints; a group's kind is set at create (`kind_code`) or via
`POST groups/{id}/kind`.

## New HTTP surface

`/rules` (CRUD + `/enabled`, `/delete`), gated `authorize:access.rule.manage,any`
(coarse gate; `RuleService` enforces authoritative containment so a leader cannot
author a rule broader than they hold). New permission `access.rule.manage` is
seeded into the org-admin role.

`groups/{id}/crosscuts` (GET links on a node, POST link, POST `/unlink`) and
`groups/{id}/crosscut-nodes` (GET the nodes a cross-cut group spans); mutations
gated `authorize:group.change.approve`.

`group-kinds` (GET list, GET one, POST create, POST `{id}` update) and
`POST groups/{id}/kind` (set/clear a group's classification); mutations gated
`authorize:group.change.approve`.

## Grants that expose the new fields

ALL grant writers now express the full scope model — `scope_mode`,
`scope_groups` (hand-picked set), and `include_crosscut`:

| Writer | Service | Scope input |
|--------|---------|-------------|
| Role assignments | `RoleAssignmentService` | full model |
| RuBAC rules | `RuleService` | full model |
| Delegations | `DelegationService` | full model |
| Access requests | `AccessRequestService` | full model |
| Break-glass | `BreakGlassService` | full model |

The parsing and persistence of a grant's scope is centralized in a single
`GrantScopeWriter` (`parse()` → normalized descriptor with validation;
`columns()` → the row columns; `syncGroupSet()`/`groupSet()` → the hand-picked
set in `grant_scope_groups`). Delegation/access-request/break-glass adopt this
shared writer, so the model is expressed identically everywhere and matches what
the PDP reads back. (`RoleAssignmentService` and `RuleService` keep their own
equivalent helpers from the earlier turns; they read/write the same columns and
`grant_scope_groups` rows.)

Two behaviours worth calling out:

- **Delegation containment upgraded.** `DelegationService` now bounds a
  delegation with full-model `GroupScopeResolver::scopeContains()` — the delegated
  mode, hand-picked set, and cross-cut reach can never exceed what the delegator's
  own authority (role assignment, approved direct grant, or a delegation to them)
  holds. Duration and chain-depth caps are unchanged.
- **Approval preserves scope.** When an access request is approved,
  `materializeRoleAssignment()` copies the request's FULL scope (mode + group set
  + cross-cut) onto the created `role_assignment`, so approval never silently
  narrows what was requested.

## Files

- `app/Modules/Shared/Support/ScopeMode.php` — the mode enum + helpers.
- `app/Modules/Shared/Support/GroupScopeResolver.php` — mode-aware coverage,
  `resolveScopeGroups`, `descendants`, `scopeContains`.
- `app/Modules/AccessControl/Services/GrantScopeWriter.php` — shared scope
  parse/validate/persist used by the delegation, access-request and break-glass
  writers.
- `app/Modules/AccessControl/Services/RuleEngine.php` — the general engine.
- `app/Modules/AccessControl/Policy/RuleOutcome.php` — facet-agnostic result.
- `app/Modules/AccessControl/Services/RuleService.php` — leader-scoped CRUD +
  write-time validation + `rule_revisions` trail.
- `app/Modules/AccessControl/Controllers/RuleController.php` + `/rules` routes.
- `app/Modules/AccessControl/Services/AuthorizationService.php` — PDP wiring +
  mode-aware RBAC coverage.
- `app/Modules/AccessControl/Services/RoleAssignmentService.php` — two-path
  authority + scope modes + multi-group.
- `app/Modules/AccessControl/Database/Migrations/2026-09-08-000054_CreateRuleBasedAccess.php`
  — `scope_mode` columns, `grant_scope_groups`, `rules`, `rule_revisions`.
