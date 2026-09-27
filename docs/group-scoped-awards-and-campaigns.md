# Group-scoped configurable awards & group campaigns ("projects")

_Status: **IMPLEMENTED** (migration 000042). Extends the annual, org-wide
gamification spine (SRS FR-GAM-001..008) with two capabilities the SRS review
surfaced as gaps:_

- **FR-GAM-009 — Group-scoped configurable awards/ranks.** Ranks, achievements
  and streaks are configurable **per hierarchical group**, not only org-wide.
- **FR-GAM-010 — Group campaigns / "projects".** Time-boxed, target-based
  campaigns scoped to a group (optionally its subgroups), with repeatable wins,
  optional roll-up to the general season, and a configurable end-of-period
  top‑N recognition.
- **FR-GAM-011 — Flexible seasons/durations.** A campaign runs over an
  **arbitrary** start/end window, independent of the annual season calendar,
  delivering "any season/duration, per group, per activity".

---

## 1. Why campaigns instead of overloading the season

The general **season** (`SeasonService`) is deliberately annual, org-wide, and
singular (one active per org, calendar rollover, balance snapshots). Overloading
it with per-group arbitrary windows would break its idempotent rollover
guarantees. Instead, a **campaign** is a first-class, group-owned, time-boxed
competition that sits *beside* the season. It may optionally roll its points
into the season (`rollup_to_general`), but it never mutates the season lifecycle.

This mirrors the user's requirement: a group runs a 3‑month "project" bundling
Win/Build/Send (or any) activities toward a target — a race — while the annual
season keeps running underneath.

---

## 2. Group scoping of the configurable catalogs

Before this change only `gamification_rules` and `badges` carried `group_id`;
`rank_definitions`, `achievement_definitions` and `streak_definitions` were
org-wide only. Migration 000042 adds to each:

| Column | Meaning |
|--------|---------|
| `group_id` (NULL) | Owning group. **NULL = org-wide default** (backward compatible — every existing row stays org-wide). |
| `include_descendants` (default 1) | When set on a group's definition, it also applies to that group's subgroups. |

The old `UNIQUE(organization_id, code)` becomes `UNIQUE(organization_id,
group_id, code)`, so a group can **override** an org-wide code with its own
tuning (e.g. a stricter rank ladder for a flagship chapter).

### Resolution — most-specific-wins

`RankService::tiers($org, $groupId)` resolves the effective ladder by walking a
**scope chain**: the group itself, then its ancestors nearest-first (via
`group_closure`), then the org-wide (`group_id IS NULL`) set. The first scope in
the chain that has any active tiers wins; a subgroup with no ladder of its own
inherits its parent's, and ultimately the org default. `determineRank()` /
`nextRank()` accept the same optional `$groupId`, so a member's standing can be
computed against their group's ladder. Passing no group preserves the original
org-wide behaviour exactly.

---

## 3. Group campaigns ("projects")

### What a project *is* (the base case — no teams)

A group project is, at its core, a simple thing: **bundle a set of
Win-Build-Send activities, give them a target, and set start/close dates.**
Nothing else is required. Members individually make progress toward the target
as they do those activities, earn awards when they hit it, and the top
performers are recognised at close.

The minimum to create one:

| You must supply | Meaning |
|-----------------|---------|
| `group_id` | The owning group whose members compete. |
| `code`, `name` | Identity. |
| `activity_scope` | The **bundle** — which activity/rule codes feed progress (empty = all activities). |
| `metric` + `target_value` | What the target is measured in (`points`/`amount`/`volume`/`count`) and the threshold to hit. (`tiered` mode carries thresholds in its ladder instead.) |
| `starts_at`, `ends_at` | The running window. |

Base lifecycle — **no teams anywhere in it**:

1. `POST campaigns` → **draft**.
2. *(tiered only)* `POST campaigns/{id}/tiers` — add the ladder.
3. `POST campaigns/{id}/activate` → **active** (the only guard is: tiered needs ≥1 tier).
4. Progress accrues automatically as members do the bundled activities (the feed
   hook calls `recordProgress`), crediting each **individual**.
5. `POST campaigns/{id}/close` → **completed**; snapshots the top‑N individuals.

That is the whole workflow for most projects. The `team_*` fields below and the
[team challenge](#optional-team-challenge-a-side-competition--the-individual-is-always-the-point)
are a **separate, opt-in overlay** — omit `team_challenge` and none of it exists.

### `group_campaigns`

| Field | Purpose |
|-------|---------|
| `group_id`, `include_descendants` | The owning group; whether subgroup members compete too. |
| `season_id` (NULL) | Optional link to a general season (required for roll-up). |
| `code`, `name`, `description`, `category` | Identity; `category` labels the theme (win/build/send/giving/custom). |
| `activity_scope` (JSON) | Which rule codes / activity types feed progress. |
| `metric` | `points` \| `amount` \| `volume` \| `count` — what the target is measured in. |
| `award_mode` | `single` (win once at target) \| `repeatable` (win each target multiple, capped by `max_awards`) \| `tiered` (a ladder of thresholds — silver/gold/diamond — each won once; see `campaign_tiers`). |
| `target_value` | Threshold to hit (minor units for `amount`). Optional for `tiered` (tiers carry their own thresholds). |
| `repeatable`, `max_awards` | For `repeatable` mode: how many times (NULL = unlimited). |
| `badge_code`, `award_points` | Badge and/or points granted on **each** target hit (single/repeatable). Tiers carry their own badge/points. |
| `rollup_to_general` | Whether `award_points` also post to the linked season ledger. |
| `recognize_top_n` | How many top **individual members** to recognize at close. |
| `subject_type` | Always `user` — the individual member is the subject. |
| — *optional team overlay below* — | *Everything from here is inert unless `team_challenge = 1`.* |
| `team_challenge` | Optional (0/1). Adds a team side-competition overlay (see below). |
| `team_mode` | `subtree` \| `adhoc` — how teams form when `team_challenge = 1`. |
| `recognize_top_teams` | Optional. How many top **teams** to recognize at close. |
| `team_target_value` | Optional. The **group/team milestone** target: a single threshold each competing group/team must reach to earn its own award. NULL = teams keep a running scoreboard only (no milestone award). |
| `team_badge_code` | Optional. Badge granted to a **group/team** when it reaches `team_target_value`. |
| `team_award_points` | Optional. Points granted to a **group/team** milestone (rolled up like individual points when `rollup_to_general` + `season_id`). |
| `starts_at`, `ends_at`, `status` | Arbitrary window (UTC); `draft → active → completed`/`cancelled`. |

### Progress & configurable awards (single / repeatable / tiered)

`campaign_progress` accumulates each subject's metric (one row per subject,
`UNIQUE(campaign_id, subject_id)`), plus `awards_count` and a `last_source_ref`
for event idempotency.

`CampaignService::recordProgress()` adds to the running total and grants awards
per the campaign's `award_mode`:

- **single** — one award when the target is first reached.
- **repeatable** — an award for **every target multiple crossed**. The pure
  helper `awardsDeserved(value, target, repeatable, maxAwards)` computes the
  count: below target → 0; `floor(value / target)`, capped at `maxAwards` when
  set. `award_index` = the multiple (1,2,3…).
- **tiered** — a ladder of thresholds (`campaign_tiers`, e.g. silver/gold/
  diamond), each with its own badge/points, each granted once. The pure helper
  `tiersReached(value, tiers, alreadyGranted)` returns the newly-reached tiers
  (a single big jump can cross several at once). `award_index` = the tier
  position, so each tier is idempotent.

Each win appends a `campaign_awards` row (`UNIQUE(campaign_id, subject_id,
award_index)`), and — when configured — a `badge_awards` row and/or a
`point_ledger` row. **Repeatable badge wins** are enabled by widening
`badge_awards`'s idempotency key to include `source_ref`: campaign wins carry a
distinct `source_ref` (`campaign:{id}:{index}`) per win, while ordinary awards
(NULL `source_ref`) still cannot duplicate within a season.

### Automatic activity feed (the hook)

Campaigns are fed automatically from the central award chokepoint.
`PointsEngine::award()`, after posting a **final** (spendable) award, calls
`CampaignService::feedFromActivity()`, which:

1. resolves the subject's active group memberships and their ancestor groups
   (via `group_closure`), plus any campaign they are explicitly rostered on;
2. selects active, in-window campaigns whose owning group covers the member
   (direct membership, or a subgroup when `include_descendants`), or that the
   member is rostered on, **and** whose `activity_scope` includes the rule code
   (empty scope = all activities);
3. credits the **individual member** by the campaign's metric — `count`→1,
   `points`→points awarded, `amount`→`amount_minor`, `volume`→`volume` (drawn
   from the award's `data`) — driving their own progress/target/tiered awards;
4. if the campaign has a **team challenge**, also tallies that same amount into
   the group/team it was **directed at**. Attribution follows *where the
   contribution went*: when the event designates an explicit target
   (`opts['target_ref']` + `target_kind`, e.g. a donation earmarked to a
   specific group), that group/team is credited; otherwise it falls back to the
   member's own team (`memberTeam()` — their subtree subgroup or ad hoc roster
   team). Credit goes to **that group only** (no roll-up to parents).

`RewardCoordinator` passes `amount_minor` through so a **giving** project
measured in `amount` is fed the contribution value. The feed is best-effort
(never fails the primary award), idempotent (per-campaign `source_ref`), and does
**not** run for held awards or the campaign roll-up itself — the roll-up writes
the ledger directly, and the achievements' bare PointsEngine has no
CampaignService injected, so there is no feedback loop.

### Optional team challenge (a side competition — the individual is always the point)

**The campaign is always about the individual member doing more.** Individual
progress and individual target/tiered awards are the core mechanic and are
always on; the leaderboard ranks **individuals**. A **team challenge** is an
**optional overlay** (`team_challenge = 1`) that adds a friendly side
competition: each individual's *same* contribution **also** tallies into a team
total in `campaign_team_standings`. It never changes an individual's own
progress or awards — it only adds a team scoreboard.

Teams form in one of two modes (`team_mode`, only meaningful when
`team_challenge = 1`):

#### `team_mode = "subtree"` (default) — teams from the group tree

- Teams are the owner group's immediate children. A member tallies into
  whichever child sits on their group's ancestor path (`resolveTeams()` → pure
  `attributedTeams()`): a member of `north-east` (under `north`) tallies to
  **north**. If the owner has no children, everyone tallies to the owner as a
  single team. Team ref = a **group id** (`team_kind = 'group'`).

#### `team_mode = "adhoc"` — rosters aggregated across the hierarchy

For teams that are **not** aligned to the group tree — e.g. a "Red vs Blue"
challenge whose members are hand-picked from many different groups:

- Teams are first-class **rosters** (`campaign_teams`) populated by explicit
  membership (`campaign_team_members`), drawn from members **anywhere** in the
  hierarchy. Managed via `defineTeam` / `addTeamMember` / `removeTeamMember`.
- A user belongs to **at most one** ad hoc team per campaign
  (`UNIQUE(campaign_id, user_id)`). Team ref = a **campaign_teams id**
  (`team_kind = 'team'`).
- Activating an ad hoc team-challenge campaign requires ≥1 team.
- **Eligibility vs attribution are separate.** Eligibility to *earn as an
  individual* follows the normal group scope (plus an explicit roster spot). The
  roster only decides which team a member's contribution *tallies into* — a
  member in scope but not on any roster still competes individually; they just
  don't move a team's score.

#### Group/team milestones (parity with individuals)

Just as each **individual** has their own milestone (their cumulative total and
target/tiered awards), each competing **group/team** has its **own** milestone:

- A group/team accumulates only the contributions **directed at it** in
  `campaign_team_standings.total_value` (`target_only` — no roll-up to parent
  groups). A member's personal total is still the cumulative of **all** their
  contributions across whatever groups they gave to.
- When `team_target_value` is configured, a group/team earns **one** milestone
  award (its `team_badge_code` badge + optional `team_award_points`) the first
  time its cumulative total reaches that target — mirroring an individual's
  `single` award. Granted via `grantTeamAward()` with the group/team as the
  award subject (`subject_type = 'group'` or `'team'`), idempotent by a
  team-scoped `source_ref` (`campaign:{id}:team:{ref}`), and tracked by
  `campaign_team_standings.awards_count` (0 → 1).
- `team_target_value = NULL` keeps the pre-existing behaviour: teams have a
  running scoreboard for ranking/recognition but no milestone award.

#### Shared

- **Attribution is per (campaign, user).** The feed's `source_ref` is namespaced
  `{ref}:{campaign}:{user}`, so a member's replayed event is deduped while
  distinct members each add to their team total. `contributors` counts distinct
  participating members (incremented on a member's first contribution).
- Team standings are read via `teamStandings()` / `GET campaigns/{id}/team-standings`,
  which resolves each team's `display_name` (group name or roster name — no PII).

### Member's hierarchical group on the leaderboard (O(1) reads)

The leaderboard shows each member's **specific hierarchical group** as a full
path (e.g. *Greater Accra Region › Accra Metro District*). Because the
leaderboard is the hot, concurrency-bound read path, ancestry is **never**
recomputed per row or per request:

- **Write-time denormalization (rare):** `recordProgress()` stamps the member's
  most-specific (deepest) active group onto `campaign_progress.subject_group_id`
  on their *first* contribution — either from a caller-supplied
  `opts['subject_group_id']` (the activity feed already knows it → zero cost) or
  one indexed lookup (`mostSpecificGroup()`), amortized onto the infrequent
  write.
- **Read-time stitching (hot):** `leaderboard()` returns the rows, then
  `attachGroupPaths()` reads the leaf groups' **materialized** `groups.path`
  (the ancestor UUID chain GroupService maintains) and resolves every referenced
  name in **one** bounded `groups` query — the org's group set is tiny (single
  org, ≤9 levels). Names are stitched in memory by the pure helpers
  `parsePathIds()` + `groupPathNames()`.

Total added read cost is **O(rows) + O(1) queries**, independent of the number
of concurrent users. Each row gains `group_id`, `group_name` (leaf), and
`group_path` (list of ancestor names, root→leaf). Only non-PII group names are
exposed.

### End-of-campaign recognition

`close()` marks the campaign `completed` and snapshots recognition into
`campaign_recognitions` (immutable, `UNIQUE(campaign_id, subject_id)`):

- when `recognize_top_n > 0`, the top **individual** members (`subject_type = 'user'`);
- when the campaign has a team challenge and `recognize_top_teams > 0`, the top
  **teams** (`subject_type = 'group'` or `'team'`).

Re-close is idempotent. This satisfies "recognize a configurable top‑X members
(and optionally top teams) across a hierarchical/specific group per any activity
per any season/duration".

---

## 4. HTTP surface (under `gamification`, `authorize:gamification.manage` for writes)

| Method & path | Action |
|---------------|--------|
| `GET  groups/{groupId}/campaigns` | List a group's campaigns (`?status=`). |
| `GET  campaigns/{id}` | Read one campaign (config + state, with `tiers` and ad hoc `teams` inlined; `activity_scope` decoded). |
| `PATCH campaigns/{id}` | Edit a **draft** campaign's fields (name, dates, target, scope, team overlay …). Frozen once active. |
| `GET  campaigns/{id}/leaderboard` | Top **individual members** by accumulated metric, each with their specific hierarchical `group_id`, leaf `group_name`, and full `group_path` (root→leaf names). |
| `GET  campaigns/{id}/team-standings` | Top **teams** (optional team challenge). |
| `GET  campaigns/{id}/tiers` | Tier ladder (silver/gold/diamond …). |
| `POST campaigns` | Create (draft); tiered may include a `tiers` array; `team_challenge`/`team_mode` optional. |
| `POST campaigns/{id}/tiers` | Add a tier (draft only). |
| `GET  campaigns/{id}/teams` | Ad hoc rosters + member counts. |
| `POST campaigns/{id}/teams` | Define an ad hoc team (team_challenge + team_mode=adhoc). |
| `PATCH campaigns/{id}/teams/{teamId}` | Rename / re-style an ad hoc team (draft or active). |
| `DELETE campaigns/{id}/teams/{teamId}` | Delete an ad hoc team + its roster (**draft only**). |
| `POST campaigns/{id}/teams/{teamId}/members` | Add a member (from anywhere) to a roster. |
| `DELETE campaigns/{id}/members/{userId}` | Remove a member from any roster. |
| `POST campaigns/{id}/activate` | Draft → active (tiered needs ≥1 tier; ad hoc challenge needs ≥1 team). |
| `POST campaigns/{id}/cancel` | Cancel. |
| `POST campaigns/{id}/progress` | Record a member's progress (grants due awards; optional `team_ref` tally). |
| `POST campaigns/{id}/close` | Complete + snapshot top‑N members (and top teams). |

`defineRank` / `disableRank` now accept an optional `group_id` to target a
group's ladder.

---

## 5. Backward compatibility & safety

- All new `group_id` columns default to NULL/org-wide; existing rows and code
  paths are unchanged.
- No provider secrets or PII cross any new boundary; leaderboards expose
  `subject_id` + metric only.
- Points roll-up is opt-in and only ever **adds** ledger entries (idempotent via
  `source_ref`); it never touches season rollover.
- Every mutation is idempotent via a UNIQUE key (progress replay, repeat awards,
  recognition, roll-up ledger).

## 6. Tests

- `tests/unit/CampaignAwardsDeservedTest.php` — the target-award math:
  `awardsDeserved` (below/exact/over target, non-repeatable cap, repeatable
  multiples, max-awards cap, zero-target safety) and `tiersReached` (below first
  threshold, single tier, multiple tiers in one jump, skip already-granted, all
  granted) plus `attributedTeams` group-vs-group roll-up (child team, direct
  child, outside any team, aggregate owner, outside subtree). 16 cases.
- `RankService` group-chain resolution and the `feedFromActivity` group/scope
  matching are covered by integration once `spark migrate` runs in CI (DB-backed).

## 7. Deferred

- **Team-award recipients:** a team win currently grants the badge/points to the
  team's **group id** (`subject_id`, `subject_type=group`). Fanning a team badge
  out to every current member's profile is a follow-up (needs product rules on
  membership-at-time-of-win).
- **Group-subject achievements evaluation** remains org-wide (group-scoped
  achievement *evaluation* is still the documented deferral from §B2); campaigns
  are the group-scoped competition mechanism.
