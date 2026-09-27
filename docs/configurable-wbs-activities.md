# Configurable Win–Build–Send Activities

**Status:** implemented end-to-end — migration `000043`, safe formula evaluator,
extended `PointsEngine`/`RuleService`, new `ActivityCatalogService` /
`FollowUpService` / `ConfigService`, DI wiring, controller endpoints + routes,
a default-catalog seeder, unit + integration tests, and an admin-console preview
(`wbs_activity_catalog_preview.html`).
**Author:** platform team · **Date:** 2026-09-05

Everything an org rewards — earning **activities**, **achievements** (with triggers),
and **follow-ups** — is *data-configured*, versioned, and manageable by an admin at
runtime. No code change, deploy, or SQL is required to add a new way to earn, retune
point values, add a conditional bonus, or launch a new follow-up type.

This document explains what we borrowed from a mature reference schema, how it maps
onto our **existing** versioned-rules + immutable-ledger engine (we *extended* the
engine rather than bolting a parallel one beside it), and the exact tables/columns
and service contracts that resulted.

---

## 1. What we studied

The attached reference (`db_schema.md`, a church CMS with a Duolingo-style layer) is
a fully data-driven award engine. Its configurability lives in a small family of
tables, and industry rule-engine patterns (loyalty engines, ServiceNow gamification,
generic ECA "event–condition–action" rule tables) all converge on the same shape:

| Reference table | Idea we adopted |
| --- | --- |
| `award_categories` + `award_activity_types` | Every earning action is a **row** with `base_points`, a `point_type` of **fixed / variable / formula**, a `point_formula`, `min/max_points`, `daily/weekly/monthly_limit`, `cooldown_hours`, and `requires_approval` + `approval_role_id`. |
| `award_conditions` | Per-activity conditional bonuses (`operator` × `value` → pct/fixed/mult). |
| `award_multipliers` | Channel multipliers (call/visit/sms/whatsapp…) gated by JSON conditions + priority. |
| `award_progression_bonuses` | Bonus keyed to a member's membership level. |
| `award_configuration` | Typed runtime key/value knobs. |
| `achievement_definitions` | `trigger_type` (points/count/streak/combo/first_time/cumulative_points/rank_reached/custom) + `trigger_config` JSON. |
| `follow_ups` | First-class Build/Send activity: type, method, outcome, spiritual-health, needs, next-follow-up scheduling. |

**Design principle borrowed and kept:** *the catalog is the config; the ledger is the
truth.* Rules describe how to earn; the append-only `point_ledger` records what was
actually earned, immutably.

---

## 2. What we already had (and kept)

Our engine was, in several respects, **stronger** than the reference and we did not
regress any of it:

- `gamification_rules` — **versioned & immutable**: editing supersedes the active
  version so every historical award references the exact rule version that produced it.
- `point_ledger` — append-only, tamper-evident, idempotent
  (`UNIQUE(rule_id, subject_id, source_ref, entry_type)`); reversals post compensating
  entries and never edit history.
- Declarative, **eval-free** multipliers: `[{factor, when:{field,op,value}}]`.
- `achievement_definitions` with `trigger_type` + `trigger_config` (already richer than
  a flat category list), plus `rank_definitions`, `streak_definitions`, seasons,
  campaigns/projects, and group-scoping (`group_id` + `include_descendants`,
  most-specific-wins resolution).
- Anti-gaming: one per-period cap, cooldown, and a held→review→approve/reject workflow.

So the job was **not** to rebuild an award engine — it was to close the *configurability
and Win-Build-Send framing* gaps against the reference.

---

## 3. Gap analysis → what this change adds

| # | Gap vs. reference | Resolution |
| --- | --- | --- |
| 1 | No first-class **activity catalog** grouped for an admin UI, and no **Win/Build/Send** dimension. | New `activity_categories` table (org+group scoped, carries `phase`). Every rule now carries `category_id` + `phase` + display metadata (`activity_name`, `icon`, `color`, `sort_order`). |
| 2 | Point value was fixed-base only. | `point_mode` = **fixed / variable / formula**, with `point_formula`, `min_points`, `max_points` clamp. A **safe** (no-`eval`) arithmetic evaluator runs formulas. |
| 3 | Only one period cap. | Added simultaneous **`daily_limit` + `weekly_limit` + `monthly_limit`** on top of the existing `per_period_cap`/`cooldown`. |
| 4 | `requires_review` was an unrouted boolean. | `approval_role_code` routes a held award to a specific role; carried onto the `fraud_reviews` (approval-queue) row as `assigned_role`. |
| 5 | No follow-ups. | Full module: `follow_up_types` + `follow_up_methods` (both configurable) and a `follow_ups` record table that **earns points** through the same engine. |
| 6 | No typed runtime config store. | `gamification_config` (typed key/value, editable flag, `updated_by`). |
| 7 | Achievements had no phase. | `achievement_definitions.phase` added so the whole system filters/report by W-B-S. |

Everything is **additive and backward compatible**: new columns are nullable or
defaulted, existing rows keep working (`phase` defaults to `general`, `point_mode` to
`fixed`), and the idempotency/versioning guarantees are untouched.

---

## 4. The Win–Build–Send model (phase + category)

Two dimensions, per the user's "both" decision:

- **`phase`** — the coarse lifecycle stage: `win` | `build` | `send` | `general`.
  Present on activity categories, rules, achievements, and follow-up types, so a
  dashboard can show "your Build progress" or report giving by phase.
- **`category`** — the finer grouping inside a phase (e.g. `ATTENDANCE`, `GROWTH`,
  `GIVING`, `OUTREACH`), each with its own icon/colour/order for the admin UI.

Suggested default mapping (seeded, fully editable):

| Phase | Meaning | Example categories / activities |
| --- | --- | --- |
| **Win** | Reach & convert new people | outreach, first-time attendance, recruitment, salvation |
| **Build** | Disciple & grow existing members | attendance streaks, foundation school, mentorship, small-group follow-up |
| **Send** | Mobilise members to serve & give | leadership roles, giving, testimony, sending/outreach follow-up |

---

## 5. Schema (migration `000043`)

### 5.1 `activity_categories`
`id, organization_id, group_id?, include_descendants, code, name, phase,
description?, icon?, color?, sort_order, status, created_at, updated_at` ·
`UNIQUE(organization_id, group_id, code)`.

### 5.2 `gamification_rules` — new columns (the "activity" is a rule)
`category_id?`, `phase` (default `general`), `activity_name?`, `icon?`, `color?`,
`sort_order`, `point_mode` (default `fixed`), `point_formula?`, `min_points?`,
`max_points?`, `daily_limit?`, `weekly_limit?`, `monthly_limit?`, `approval_role_code?`.
All carried through the versioned `row()` builder so they version like everything else.

### 5.3 `achievement_definitions` — new column
`phase` (default `general`).

### 5.4 `follow_up_types`
`id, organization_id, group_id?, include_descendants, code, name, phase,
description?, requires_outcome, default_next_days?, award_rule_code?, icon?, color?,
sort_order, status, timestamps` · `UNIQUE(organization_id, group_id, code)`.

### 5.5 `follow_up_methods`
`id, organization_id, code, name, multiplier_key?, sort_order, status, timestamps` ·
`UNIQUE(organization_id, code)`. `multiplier_key` feeds the rule multiplier `when`
field `method`, so "a visit is worth 1.5× an SMS" is pure config.

### 5.6 `follow_ups` (records)
`id, organization_id, group_id?, subject_user_id, follower_user_id, type_code,
method_code, status, performed_at?, summary?, outcome?, spiritual_health?, needs(JSON)?,
next_follow_up_at?, next_notes?, award_ledger_id?, created_at, updated_at`.

### 5.7 `gamification_config`
`id, organization_id, config_key, config_value, config_type (string|integer|float|
boolean|json), description?, is_editable, updated_by?, created_at, updated_at` ·
`UNIQUE(organization_id, config_key)`.

### 5.8 `fraud_reviews` — new column
`assigned_role?` (approval routing target).

---

## 6. Point computation pipeline (extended, still eval-free)

```
1. Resolve active rule version (unchanged).
2. Gate: per_period_cap, cooldown, AND daily/weekly/monthly limits.  ← extended
3. Base points by point_mode:                                        ← new
     fixed   → rule.points
     variable→ clamp(data.value ?? rule.points, min, max)
     formula → clamp(SafeFormula(point_formula, {base_points, ...data}), min, max)
4. × declarative multipliers (unchanged, clamped 0.1–10).
5. round → integer points.
6. Persist to point_ledger (unchanged idempotency/versioning).
7. held? → fraud_reviews row incl. assigned_role.                    ← extended
8. Achievement + campaign hooks (unchanged).
```

**Safe formula evaluator** (`WBS\Gamification\Support\FormulaEvaluator`): a
shunting-yard parser over `+ - * / %`, parentheses, numbers, whitelisted variables
(`base_points` plus numeric event-data fields), and whitelisted functions
`min, max, floor, ceil, round, abs`. Anything else → the formula is rejected at
*save* time (`RuleService`) and treated as base points at *award* time. **No `eval`,
no `create_function`, no variable-variables** — same security posture as the existing
declarative multipliers.

---

## 7. Service layer

- **`ActivityCatalogService`** — full CRUD for `activity_categories`
  (`define`/`showCategory`/`listCategories`/`disable`); `catalog()` returns the whole
  earning surface grouped by phase → category → activities (rules), for an admin
  console or member "ways to earn" screen.
- **`RuleService`** (extended) — accepts and versions all new activity columns;
  validates `point_mode`/`point_formula`/limits; `show()` returns the current version
  plus full version history.
- **`PointsEngine`** (extended) — point modes, min/max clamp, multi-period limits,
  approval routing.
- **`FollowUpService`** — full CRUD for `follow_up_types` + `follow_up_methods`
  (define/show/list/disable) and for follow-up **records**: `record()` creates and
  (if the type has an `award_rule_code`) awards points through `PointsEngine` passing
  `method` as multiplier input; `showRecord`/`updateRecord`/`cancelRecord` cover the
  record lifecycle (status transitions, reschedule, idempotent award-on-completion);
  `dueFollowUps()`/`historyForSubject()` are the read views.
- **`ConfigService`** — typed CRUD over `gamification_config`: `get`/`show`/`all`/
  `set`/`delete` with cast-on-read and read-only-key protection on write and delete.

## 7a. HTTP surface (all under the `gamification/` group)

Every configuration surface is full CRUD. "Delete" is a **soft-delete**
(`status = inactive`) for definitions so historical awards/records keep resolving;
config keys hard-delete (read-only keys refuse it); earning activities keep their
**immutable-versioned** edit (an update supersedes the active version).

| Method | Route | Purpose |
| --- | --- | --- |
| `GET`  | `activity-catalog` | Full earning surface grouped by phase → category → activity. |
| **Earning activities** | | |
| `GET` / `POST` | `rules` | List / create an activity. |
| `GET` | `rules/{code}` | Read one — current version + version history. |
| `POST` | `rules/{code}` | Update (supersedes to a new version). |
| `POST` | `rules/{code}/disable` | Soft-delete (status=inactive). |
| **Activity categories** | | |
| `GET` / `POST` | `activity-categories` | List / define (upsert) a category. |
| `GET` | `activity-categories/{code}` | Read one (+ active-activity count). |
| `POST` | `activity-categories/{code}/disable` | Soft-delete a category. |
| **Follow-up types** | | |
| `GET` / `POST` | `follow-up-types` | List / define (upsert) a type. |
| `GET` | `follow-up-types/{code}` | Read one. |
| `POST` | `follow-up-types/{code}/disable` | Soft-delete a type. |
| **Follow-up methods** | | |
| `GET` / `POST` | `follow-up-methods` | List / define (upsert) a method. |
| `GET` | `follow-up-methods/{code}` | Read one. |
| `POST` | `follow-up-methods/{code}/disable` | Soft-delete a method. |
| **Follow-up records** | | |
| `POST` | `follow-ups` | Record a follow-up (awards the follower). |
| `GET`  | `follow-ups/due` | The "who needs following up" work queue. |
| `GET`  | `follow-ups/{id}` | Read one record. |
| `PATCH` | `follow-ups/{id}` | Update — status transition, outcome/notes, reschedule. |
| `POST` | `follow-ups/{id}/cancel` | Cancel (soft; status=cancelled). |
| `GET`  | `subjects/{id}/follow-ups` | Follow-up history for a member. |
| **Runtime config** | | |
| `GET`  | `config` | All typed config for the org. |
| `GET`  | `config/{key}` | Read one (value cast + metadata). |
| `POST` | `config/{key}` | Upsert a typed config key. |
| `DELETE` | `config/{key}` | Delete a key (read-only keys refuse). |

Mutating catalog/config/type/method endpoints require `authorize:gamification.manage`;
recording/updating/cancelling a follow-up and reading due/one/history are available to
authenticated members (the follower is the actor).

**Follow-up record lifecycle.** A record can be created in any status. Points are
awarded (idempotently, via a fixed `followup:{id}` ledger ref) the first time it
enters a counting state (`completed`/`in_progress`) for an award-linked type — so a
`pending → completed` transition via `PATCH` awards then, exactly once. Cancelling
does not claw back points already earned (the follow-up was genuinely performed); use
`PointsEngine::reverse('followup:{id}')` if a reversal is truly warranted. Route
ordering places static paths (`follow-ups/due`) and `.../disable` / `.../cancel`
before the `{code|id}` catch-alls so they can't be shadowed.

## 7b. Tests

- `tests/unit/FormulaEvaluatorTest.php` — locks the safe evaluator (arithmetic,
  functions, precedence, div-by-zero → null, unknown tokens rejected, `isValid`).
- `tests/integration/ConfigurableActivitiesTest.php` (16 tests) — DB-backed proof of
  fixed/variable/formula point modes + clamp, daily limit, approval routing, catalog
  grouping, follow-up point award with method multiplier, typed/read-only config, and
  the **CRUD gap-fills**: rule read-one + version history, category read-one with
  activity count, config read-one/delete (read-only protected), follow-up type/method
  read-one + disable, and the full follow-up record lifecycle (create pending →
  read → update-to-completed awards once → no double-award → cancel → cancelled
  refuses further edits, plus requires-outcome enforcement). Self-skips when no test
  DB is reachable.
- `tests/integration/HierarchicalGroupScopeTest.php` (11 tests) — proves the
  configuration surfaces are **hierarchical-group-permission compliant** on both
  axes: data-scope inheritance (a district inherits a region's categories /
  follow-up types most-specific-wins, but only when the ancestor sets
  `include_descendants`; a subgroup override hides the inherited row; org-wide
  rows always show) **and** authorization (the PDP honours
  `role_assignments.scope_group_id` + `include_descendants` — parent-with-
  descendants covers a child, an unrelated branch is denied, a group-scoped grant
  cannot act org-wide, and the coarse `any`-scope gate admits a group-scoped
  admin for the in-service check). Self-skips without a DB.

---

## 7c. Hierarchical group-permission compliance

Two independent things must both respect the group tree; this build makes both
use the **same** hierarchy source (`group_closure`) via one shared helper,
`WBS\Shared\Support\GroupScopeResolver`.

**1. Data-scope resolution (which config a group sees).** `activity_categories`
and `follow_up_types` are org+group scoped (`group_id` + `include_descendants`;
`follow_up_methods` and `gamification_config` are intentionally org-wide, and
activity **rules** are unique on `(org, code, version)` so they are org-wide by
code — nothing to inherit there). Resolution is **most-specific-wins over the
full ancestor chain**: the group's own row, then each ancestor nearest-first
(only when that ancestor is flagged `include_descendants = 1`), then the org-wide
(`NULL`) default. `resolveByCode()` powers single-record lookups (e.g. recording
a follow-up against an inherited type); list endpoints build the same union and
collapse to one row per code. This matches `RankService`/`CampaignService`, which
now share the identical closure walk.

**2. Authorization (which group's config a caller may manage).** The PDP's RBAC
check previously ignored `scope_group_id`, so a group-scoped grant silently
authorized org-wide — a latent escalation hole (no scoped grants were seeded, so
closing it is backward-safe). `AuthorizationService::rbacGrants()` now loads each
grant's `scope_group_id` + `include_descendants` and admits the request only when
a grant **covers** the action's target group (`GroupScopeResolver::grantCovers`:
org-wide grant covers all; exact match covers; ancestor-with-descendants covers a
descendant; an org-wide action needs an org-wide grant). Because a route filter
cannot know a resource's target group, group-scoped write endpoints use a
two-layer pattern (as `StreamService` does): the route filter
`authorize:gamification.manage,any` is a **coarse capability gate** (holds the
permission in *any* scope), and the controller then calls
`BaseController::authorizeGroupScope()` for the **authoritative per-group** PDP
decision, passing the request's `group_id`. Org-wide-only surfaces (config,
methods, rules) keep the plain `authorize:gamification.manage` gate, so a purely
group-scoped admin cannot touch them.

---

## 8. Why extend, not fork

The user chose "extend the existing engine". Consequences we honoured:

- **One ledger, one truth.** No parallel `user_awards` table to reconcile; the
  reference's computed fields (`base_points`, `multiplier_applied`, `bonus_applied`)
  are all derivable and are returned in the award result + ledger `explanation`.
- **Versioning preserved.** New config is just more versioned rule columns.
- **Security preserved.** Formulas and conditions are declarative and whitelisted;
  the engine never executes admin-supplied code.
- **Hierarchy preserved.** Group scoping (data inheritance) and group-scoped
  authorization both flow through one shared `group_closure` resolver, so the new
  surfaces obey the same tree rules as ranks, campaigns and streams — see §7c.
