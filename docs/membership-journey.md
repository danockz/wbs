# Membership Journey (discipleship spine)

_Implements assessment Options B, C & D — the configurable journey spine,_
_rule-driven transitions, and disciple-making attribution + stage-linked_
_activities. Module: `app/Modules/Journey`. Migrations `000058` (spine), `000059`_
_(proposal queue), `000060` (stage links), `000061` (project attribution on_
_moves). Seeders `JourneyStageSeeder`, `JourneyGamificationSeeder`._

The Membership Journey records a person's **maturity / role progression** —
prospect → first-timer → new believer → foundation → established member → worker
→ leader → sender. It is deliberately separate from, and sits **above**, three
things it does not duplicate:

| Concern | Owned by | The journey's relationship |
| --- | --- | --- |
| Login-account lifecycle (active/suspended/…) | Identity `users.status` | independent — a journey is about discipleship, not the account |
| Group belonging (member of group X) | Groups `group_members` | independent — belonging ≠ stage |
| Referral capture/conversion | Referrals `prospects` / `referral_attributions` | a conversion can **open** a journey (source = conversion) |

## Data model

Three tables (migration `2026-09-08-000058_CreateMembershipJourney`):

- **`journey_stages`** — the configurable, ordered ladder. Org + optional group
  scoped (`group_id` NULL = org-wide catalog); most-specific-wins, a group's own
  stage of a code overrides the org-wide one. Each stage carries a Win/Build/Send
  `phase` (shared vocabulary with the activity model), a `sort_order` (ladder
  position), `is_entry` (where a new journey opens) and `is_terminal` (top rung).
- **`member_journeys`** — ONE current-stage record per **(person, context)**.
  `group_id` NULL = the person's **org-wide primary** journey; a non-null
  `group_id` = an optional **per-group-context** journey. This is the hybrid
  granularity: one overall stage, adjustable per group where you need it (e.g.
  a Leader in one ministry, a Member in another).
- **`member_journey_transitions`** — append-only, immutable history of every
  change: `from_stage`/`to_stage`, `direction` (open/advance/regress/set),
  `reason`, the `actor_id` (who performed it) **and** the `discipler_id` (who is
  credited with moving them — the hook Option D will reward), plus `evidence_*`
  and `source`.

## Default stage ladder (editable)

Seeded org-wide by `JourneyStageSeeder`; every field is editable and any org can
add/remove/reorder stages.

| Order | Code | Name | Phase |
| --- | --- | --- | --- |
| 10 | `prospect` | Prospect / Contact | win (entry) |
| 20 | `first_timer` | First-Timer | win |
| 30 | `new_believer` | New Believer | win |
| 40 | `in_foundation` | In Foundation / Growth | build |
| 50 | `established` | Established Member | build |
| 60 | `worker` | Worker / Volunteer | build |
| 70 | `leader` | Leader | send |
| 80 | `sender` | Sender / Multiplier | send (terminal) |

Reaching the terminal stage does **not** close the journey — a Sender is an
active member and stays visible in the pipeline. `paused` / `completed` /
`archived` are explicit closes via the status endpoint.

## HTTP API

All under `/journey`, `auth` filter. Stage-ladder writes require the
`gamification.manage` permission (the platform's engagement-model permission).
Per-member moves are additionally **group-scope-checked** inside the controller
against the transition's target group context, so a leader can only move members
within their own scope — the same scope model as the rest of AccessControl. A
NULL context (org-wide primary journey) requires an org-wide grant.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/journey/stages?group_id=` | effective ordered ladder for a context |
| POST | `/journey/stages` | define/update a stage (`gamification.manage`) |
| GET | `/journey/pipeline?group_id=` | counts per stage for a context |
| GET | `/journey/stages/{code}/members?group_id=&limit=&offset=` | members at a stage |
| GET | `/journey/leaderboard/disciplers?group_id=&phase=&since=&limit=` | disciple-making leaderboard (Option D) |
| POST | `/journey/members/{userId}/open` | open a journey (defaults to entry stage) |
| POST | `/journey/members/{userId}/transition` | move to `to_stage` (advance/regress derived) |
| POST | `/journey/members/{userId}/status` | pause / resume / complete / archive |
| GET | `/journey/members/{userId}?group_id=` | a journey + full transition history |
| GET | `/journey/members/{userId}/all` | all of a person's journeys across contexts |
| GET | `/journey/members/{userId}/recommendations?group_id=&scope=&ahead=` | stage-linked "what to do next" for the member |

### Transition body

```json
{
  "to_stage": "new_believer",
  "group_id": null,
  "reason": "Prayed the prayer of salvation at Sunday service",
  "discipler_id": "…",          // defaults to the actor
  "evidence_type": "follow_up", // course|event|follow_up|attestation|…
  "evidence_ref": "…",
  "source": "manual"            // manual|conversion|rule|import
}
```

`direction` is computed from ladder order (`advance` vs `regress`); moves between
codes not both in the ladder are `set`. Transitioning a person who has no journey
yet opens one at the target stage.

## Rule-driven transitions (Option C — DELIVERED)

Signals from around the platform (a course completed, an event attended, a
follow-up outcome, a verified contribution) can advance the journey automatically
— **without any hard-coded progression logic**. The rules are data in the
existing general RuBAC engine (`facet = 'membership'`), authored and scoped by
leaders exactly like access rules. `JourneySignalService` is the only bridge:

1. builds a context = the signal's attributes **plus** the member's current stage
   and phase;
2. asks the shared `RuleEngine` which `membership` rules match, **within the
   leader-authored scope** of the signal's group;
3. for each matched rule, reads `effect_params.to_stage` and acts on the rule's
   **effect**, which is how the per-rule auto-vs-propose choice is expressed (no
   new column needed):

   | Rule effect | Behaviour |
   | --- | --- |
   | `adjust` | **auto-apply** the transition immediately (`source=rule`) |
   | `require_review` | **propose** — queue a pending row for a leader to approve/reject |
   | `flag` | propose too (a soft nudge) |
   | `allow` / `deny` | ignored by the journey facet |

A rule that would move a member **backwards** is honoured only when its
`effect_params.allow_regress = true`, so a stray signal can never demote people.

### Signal action convention

`journey.signal.<domain>.<event>`, e.g. `journey.signal.course.completed`,
`journey.signal.event.attended`, `journey.signal.follow_up.recorded`,
`journey.signal.contribution.verified`. Rules match with exact patterns or
`journey.signal.*`.

### Example membership rules

Auto-advance a New Believer to Foundation when they complete the foundation
course (create via the existing rules API, `facet: membership`):

```json
{
  "facet": "membership",
  "code": "advance_to_foundation",
  "name": "New believer completes foundation course → In Foundation",
  "effect": "adjust",
  "action_pattern": "journey.signal.course.completed",
  "condition": { "all": [
    { "attr": "current_stage", "op": "eq",  "value": "new_believer" },
    { "attr": "course_code",   "op": "eq",  "value": "foundation-101" }
  ]},
  "effect_params": { "to_stage": "in_foundation", "reason": "Completed Foundation 101" },
  "scope_mode": "self_and_descendants"
}
```

Propose (don't auto-apply) promotion to Leader after enough serving, for a leader
to confirm:

```json
{
  "facet": "membership",
  "code": "propose_leader",
  "name": "Worker with 12+ months service → propose Leader",
  "effect": "require_review",
  "action_pattern": "journey.signal.*",
  "condition": { "all": [
    { "attr": "current_stage",  "op": "eq",  "value": "worker" },
    { "attr": "service_months", "op": "gte", "value": 12 }
  ]},
  "effect_params": { "to_stage": "leader" }
}
```

### Option C API

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/journey/signals` | ingest a signal; runs membership rules (scope-checked) |
| GET | `/journey/proposals?group_id=` | pending proposal review queue |
| POST | `/journey/proposals/{id}/approve` | approve → applies the transition, closes proposal |
| POST | `/journey/proposals/{id}/reject` | reject → no transition |

`POST /journey/signals` body: `{ "user_id", "action", "group_id"?,
"attributes": { … extra condition inputs … }, "discipler_id"?, "evidence_type"?,
"evidence_ref"? }`. Response: `{ matched, applied: [...], proposed: [...] }`.
Approving a proposal applies exactly the same `source=rule` transition, with the
proposal id as `evidence_ref`. History is never rewritten.

### Wired signal emitters (DELIVERED)

Four source modules now emit journey signals automatically. Every emit is
**fault-isolated** (a signal failure can never affect the primary operation) and
a **no-op until an org authors `membership` rules**, so nothing changes behaviour
until you opt in by writing rules.

| Source | Emit point | Signal action | Scope group | `project_code` | Extra attributes |
| --- | --- | --- | --- | --- | --- |
| Courses | `course.completed` (via `JobRouter`) | `journey.signal.course.completed` | course's `group_id` | explicit pass-through | `course_id`, `course_code` (slug) |
| Events | `CheckinService` on successful check-in | `journey.signal.event.attended` | event's `group_id` | explicit pass-through (`checkIn*(..., $projectCode)`) | `event_id` |
| Follow-ups | `FollowUpService::record()` (completed only) | `journey.signal.follow_up.recorded` | follow-up `group_id` | explicit pass-through (`data.project_code`) | `follow_up_type`, `follow_up_phase`, `outcome`, `spiritual_health`; **`discipler_id` = the follower** |
| Contributions | `contribution.succeeded` (via `JobRouter`) | `journey.signal.contribution.verified` | (n/a — org-wide) | **intrinsic = `cause_id`** | `amount_minor`, `cause_id` |

**Journey context vs. rule scope.** Emitters advance the member's **org-wide
primary** journey (`group_id = null`) while passing the originating group as
`scope_group_id`, so a group-scoped leader's rule still fires for their branch
without fragmenting the member's journey. `ingest()` accepts both fields
separately (`group_id` = which journey to move; `scope_group_id` = which leaders'
rules may fire, defaulting to `group_id`).

**Project attribution.** A signal may also carry a `project_code` — the same
first-class, cross-cutting project dimension the gamification ledger uses. It is
**intrinsic for contributions** (the giving `cause_id`) and **explicit
pass-through** for the other three sources (they have no project column, so a
caller supplies one or it stays NULL — there is no automatic campaign
resolution). `project_code` is: (1) exposed to membership rules as a condition
attribute so leaders can gate on it (e.g. "advance only if the activity was part
of the Easter Outreach project"); (2) written onto the immutable transition row
and any pending proposal (migration `000061`, carried through approval); and
(3) tagged onto the Option-D disciple-making award, so the credit shows on the
same **project boards / rollups** as that project's other activity. NULL = not
project-attributed (behaviour unchanged).

> Note: this is distinct from **group campaigns** (which the docs also call
> "projects"). Point awards already auto-feed every matching campaign via
> `PointsEngine::feedFromActivity()`; that path is unchanged and needs no journey
> wiring. `project_code` here is the ledger *tag* dimension, not the campaign.

You can still emit manually from anywhere via `POST /journey/signals` or
`JourneyServices::journeySignals()->ingest()`.

## Disciple-making attribution & stage-linked activities (Option D — DELIVERED)

Option D rewards **moving people forward**, and lets activities/follow-ups be
tagged to a stage — reusing the existing gamification stack end to end (no new
ledger, no parallel scoring).

### Disciple-making credit

`JourneyService` fires a narrow `JourneyTransitionListener` after every committed
transition. `JourneyAttributionService` implements it and, on a **forward** move
(`advance`, or an `open` that lands above the entry stage) that names a
`discipler_id`, credits the discipler through the normal `PointsEngine::award()`:

- **config-gated, OFF by default** — `gamification_config.disciplemaking_award_enabled`
  (org-scoped; the platform's default-off principle). Turn it on to activate.
- rule is configurable — `disciplemaking_rule_code` (default `disciple.advance`,
  a SEND-phase activity seeded editable like any other);
- the award is tagged with the destination stage's **phase**, so the existing
  Win/Build/Send boards and rollups pick it up automatically;
- **idempotent**: `source_ref = "journey:{journey_id}:{to_stage}"`, so replays,
  re-approved proposals, and regress→re-advance to the same stage never
  double-credit (the ledger's `pl_idem_uq` enforces it);
- group attribution follows the standard receiving→membership→org resolution;
  the journey's `group_id` is passed as the receiving group so credit lands where
  the discipling happened.

A listener failure can **never** roll back the member's stage change — `notify()`
swallows downstream exceptions by design (the transition is already durable).

### Disciple-making leaderboard

`GET /journey/leaderboard/disciplers?group_id=&phase=&limit=&since=` ranks
disciplers by **distinct people moved forward** (then by number of advances),
read straight from the immutable transition trail — a metric the point ledger
cannot express (points reward *activities*; this rewards *moving distinct people*
up the ladder). Org-wide when `group_id` is omitted, else that context; optional
`phase` (destination phase) and `since` filters.

### Stage-linked activities

A nullable `stage_code` (migration `000060`) was added to `activity_categories`,
`gamification_rules` and `follow_up_types`, and wired through their `define`
methods. It is **advisory metadata only** — it never gates awarding — so a
dashboard can show "Build activities for a New Believer" and rules/reads can
filter by stage. NULL = not stage-specific (the existing default).

### Recommended next activities

`GET /journey/members/{userId}/recommendations?group_id=&scope=&ahead=` turns
those `stage_code` links into a member-facing "what to do next" view.
`JourneyRecommendationService` (a pure read — it awards nothing) resolves the
member's position on the effective ladder, then returns the active
activities / categories / follow-up types linked to the relevant stage(s):

- **`scope`** — `both` (default: the member's **current** stage *and* the
  **next** rung), `current`, `next`, or `from_current` (current plus the next
  `ahead` stages, `ahead` default 1, capped at 5). Each returned stage is tagged
  `position: current | next | ahead`.
- **No journey yet?** It recommends the **entry** stage — the natural first step.
- **At the terminal stage?** `next` simply returns empty (handled gracefully).
- Catalog rows are **group-scope resolved** with the same most-specific-wins rule
  as the catalogs themselves (the group's own row beats a nearer ancestor with
  `include_descendants`, beats org-wide); for versioned `gamification_rules` the
  highest active version wins. Only `active` rows appear.

Response shape:

```json
{
  "user_id": "…", "group_id": null,
  "current_stage": "new_believer", "has_journey": true,
  "stages": [
    { "code": "new_believer", "name": "New Believer", "phase": "win",
      "position": "current",
      "activities": [ { "code": "nb.devotion", "name": "Daily Devotion", "phase": "win", "points": 10, "point_mode": "fixed", "stage_code": "new_believer", … } ],
      "categories": [ … ],
      "follow_up_types": [ { "code": "welcome_call", "name": "Welcome Call", … } ] },
    { "code": "in_foundation", "name": "In Foundation", "phase": "build",
      "position": "next", "activities": [ … ], "categories": [ … ], "follow_up_types": [ … ] }
  ],
  "totals": { "activities": 2, "categories": 1, "follow_up_types": 1 }
}
```

### Setup for Option D

```
php spark migrate --all
composer seed:journey-gamification   # seeds config (award OFF) + editable rule
# then, to activate:  set gamification_config disciplemaking_award_enabled = true
```

## Status

The membership-journey scope is complete end to end: configurable spine (B),
rule-driven transitions (C), disciple-making attribution + leaderboard and
stage-linked activities (D), wired signal emitters across Courses / Events /
Follow-ups / Contributions with project attribution, and the recommended
next-activities read (see "Recommended next activities" above). No journey items
remain open.

## Setup

```
php spark migrate --all
composer seed:journey     # seeds the default ladder (idempotent)
```
