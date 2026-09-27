# Assessment: Activities & the Membership Journey

_Date: 2026-09-08. Scope: assess the platform's current model of (a) member
**activities** and (b) the **membership journey** (a person's path from first
contact to a sending, serving member), and recommend whether to redefine._

This is a findings-and-options document, not a change. Nothing below has been
built yet — it exists so we can agree the target before touching code.

---

## 1. Executive summary

**Activities are in good shape.** The Win–Build–Send (WBS) activity model is
first-class, configurable, and consistent: a `phase` (win|build|send|general) +
`category` taxonomy runs across gamification rules, achievements, and follow-up
types, all feeding one immutable point ledger with group attribution and
rollups. Follow-ups are a full module (types, methods, outcomes, spiritual
health, needs, next-follow-up scheduling). Recommendation: **keep, with small
additions** (see §5).

**The membership journey is the real gap.** Every *ingredient* of the journey
exists, but the **journey itself is not modeled**. A person's progress —
prospect → convert → first-timer → new believer → member → worker/volunteer →
leader → sender — is spread across four modules with **no connecting spine**, no
explicit stages, no transitions, and no single view of "where is this person on
their journey and what's next." Recommendation: **redefine — introduce an
explicit, configurable Membership Journey** (see §4, §6).

---

## 2. What exists today (grounded in code)

### 2.1 Activities — STRONG

| Concern | Where | State |
| --- | --- | --- |
| WBS phase dimension | `phase` on `activity_categories`, `gamification_rules`, `achievement_definitions`, `follow_up_types` | ✅ first-class, seeded, editable |
| Activity catalog | `activity_categories` (org+group scoped, most-specific-wins) | ✅ |
| Earning activities | `gamification_rules` (point_mode fixed/variable/formula, limits, approval role) | ✅ versioned + safe (no eval) |
| Follow-ups | `follow_up_types` / `follow_up_methods` / `follow_ups` + `FollowUpService` | ✅ full module (outcomes, spiritual_health, needs, next scheduling, awards) |
| Attribution & ranking | `point_ledger` + rollups; receiving-group→membership-fallback→org | ✅ per standing rules |
| Group/team milestones | first-class group awards + campaigns | ✅ |

**Gap in activities (minor):** activities are defined and *scored*, but they are
not tied to journey *progression* — completing "Foundation School" earns points
but does not, by itself, advance a member's stage. That linkage is what §4
introduces.

### 2.2 The journey ingredients — PRESENT BUT DISCONNECTED

| Journey stage (conceptual) | Nearest existing data | Module | Connected? |
| --- | --- | --- | --- |
| Reached / captured lead | `prospects.state` = captured | Referrals | ✅ has state |
| Converted | `prospects.state` = converted + `referral_attributions` | Referrals | ⚠️ marks prospect, but no link to the journey |
| Account exists | `users.status`: prospect → pending_verification → active … | Identity | ⚠️ **account** lifecycle, not **discipleship** journey |
| Group member | `group_members` (status pending/active, `membership_type`) | Groups | ⚠️ membership, not stage |
| Worker / leader | `membership_type` ∈ {member, leader, activity, department, team, guest} + roles | Groups/ACL | ⚠️ a role/type, not a tracked transition |
| Being discipled | `follow_ups` (spiritual_health, needs, next) | Gamification | ⚠️ events, not a stage machine |

Two different state machines already exist and are **easy to confuse**:

- **Identity `AccountLifecycleService`** — `prospect, pending_verification,
  active, suspended, locked, deactivated, anonymized, merged`. This is about the
  **login account** (security/GDPR), NOT discipleship.
- **Groups membership** — `pending → active → (left/rejected)` per (user, group,
  type). This is about **belonging to a group**, NOT the person's overall stage.

Neither answers the pastoral question: *"Is this person a first-timer, a new
believer in foundation class, an established member, a worker, or a leader — and
what is the next step?"*

---

## 3. The core finding

> **The membership journey is implied by data in four modules but is not a
> first-class, configurable concept.** There is no journey definition, no
> ordered stages, no transition record, no per-member "current stage + next
> step," and no way to drive Win/Build/Send activities *off* a member's stage.

Consequences today:
- **No pipeline view** — a leader cannot see "12 first-timers, 8 in foundation
  school, 3 ready to be workers" for their scope.
- **No stage-based automation** — follow-ups and nudges can't be triggered by
  "entered New Believer stage 14 days ago, no foundation class yet."
- **Attribution is activity-only** — we credit *who did an activity*, but not
  *who moved a person from stage to stage* (the Build/Send disciple-making credit).
- **Reporting gaps** — `MemberDashboardService` shows *milestones* (tenure,
  counts) but there is no journey/stage progression to report on or rank by.

---

## 4. Recommendation — introduce a first-class Membership Journey

Model the journey as its own small, **configurable** spine that *references*
(does not duplicate) the existing modules. Design principles, consistent with the
platform's established patterns:

1. **Configurable, not hard-coded.** A `journey_stages` catalog (org-scoped,
   inheritance-aware via the hierarchical config resolver, most-specific-wins),
   each stage carrying a `phase` (win|build|send) so the journey and the activity
   model share one vocabulary. Ships with a seeded default ladder that any org
   can edit:

   | Order | Stage (default) | Phase |
   | --- | --- | --- |
   | 1 | Prospect / Contact | win |
   | 2 | First-Timer | win |
   | 3 | New Believer / Convert | win |
   | 4 | In Foundation / Growth | build |
   | 5 | Established Member | build |
   | 6 | Worker / Volunteer | build→send |
   | 7 | Leader | send |
   | 8 | Sender / Multiplier | send |

2. **One membership-journey record per person** (`member_journeys`): current
   stage, entered-at, the group context it applies to, and status. History is an
   append-only `member_journey_transitions` trail (from_stage, to_stage, reason,
   actor, evidence, timestamp) — the same immutable-trail pattern used for
   access/audit.

3. **Transitions are driven three ways** (all reusing existing engines):
   - **Manual** — a leader advances a member (scoped + audited, bounded by the
     ACL scope model we just finished).
   - **Rule-based** — the general **RuBAC RuleEngine** (facet `membership`, which
     already exists) evaluates conditions like "completed Foundation course →
     advance to Established" and proposes/auto-applies a transition. This is
     exactly the reuse the RuBAC engine was built for.
   - **Event-driven** — conversion (`referral_attributions`), first attendance
     (`event_attendance`), course completion (`courses`), and follow-up outcomes
     emit signals that a rule can key on.

4. **Attribution for disciple-making.** A transition records *who moved the
   member* (the discipler), so Build/Send credit and ranking can reward moving
   people along the journey — a first-class Win-Build-Send outcome, not just
   activity counts. Uses the same receiving-group→membership-fallback→org
   attribution rule already in force.

5. **Bridges, not rewrites.** The journey does NOT replace account lifecycle or
   group membership. It sits above them:
   - convert a `prospect` → seeds/reads the person's journey at "New Believer";
   - `group_members` stays the record of *belonging*; the journey is the record
     of *maturity/role progression*;
   - `follow_ups` become the operational tool that *services* a stage
     (`follow_up_types` already carry a `phase`; add an optional `stage_code`).

---

## 5. Smaller activity refinements (optional, low-risk)

- **Link activities to stages.** Let an `activity_category` / `gamification_rule`
  / `follow_up_type` optionally declare a `stage_code` it belongs to, so a
  dashboard can show "Build activities for a New Believer" and rules can gate on
  stage.
- **Journey-aware follow-ups.** `follow_up_types.stage_code` + a "recommended
  next follow-up per stage" so the due-list is stage-sensitive.
- **Nothing to remove.** The activity model is sound; these are additive.

---

## 6. Options for how far to go now

| Option | Scope | Effort | When |
| --- | --- | --- | --- |
| **A — Assess only** | This document; no build | none | ✅ done |
| **B — Journey spine (recommended first slice)** | `journey_stages` catalog + `member_journeys` + `member_journey_transitions`, a `JourneyService` (manual + audited transitions, scoped by the ACL model), seeded default ladder, read APIs + a pipeline view | medium | ✅ **DELIVERED** — see `docs/membership-journey.md` |
| **C — B + rule-driven transitions** | Wire the RuBAC `membership` facet to propose/auto-apply transitions from course/event/follow-up signals (auto-apply configurable per rule) | medium+ | ✅ **DELIVERED** — see `docs/membership-journey.md` |
| **D — B + C + disciple-making attribution & ranking** | Credit + leaderboards for moving people along the journey; stage-linked activities/follow-ups | larger | ✅ **DELIVERED** — see `docs/membership-journey.md` |

### Option B — as built (redefinition decisions applied)

- **Module** `app/Modules/Journey` — migration `000058`, `JourneyService`,
  `JourneyController`, `JourneyStageSeeder`, routes under `/journey`.
- **Ladder** = the §4.1 default, **seeded but fully editable** (org + optional
  group-scoped stages, most-specific-wins).
- **Granularity** = hybrid: one org-wide primary journey per person
  (`group_id` NULL) **plus** optional per-group-context journeys.
- **Auto-advance** = deferred to Option C, but the schema carries `source='rule'`
  and `evidence_*` and the per-rule apply-mode decision is recorded, so no schema
  change is needed to add it.
- **Scope** = per-member moves are group-scope-checked with the same ACL scope
  model finished in the RuBAC/scope work; stage-ladder config gated by
  `gamification.manage`.
- **Disciple-making hook** = every transition records `discipler_id`, ready for
  Option D credit/ranking.
- **Validated**: 22/22 service unit assertions (ladder ordering, idempotent open,
  advance/regress/terminal, unknown-stage rejection, pipeline counts, history +
  discipler capture, independent per-group context, pause). `php -l` clean.

### Option C — as built (rule-driven transitions)

- Bridges platform **signals** to the **existing** general RuBAC `RuleEngine`
  (facet `membership`) — no parallel evaluator, no hard-coded progression logic.
- **Per-rule auto-vs-propose = the rule's effect**: `adjust` auto-applies,
  `require_review`/`flag` queue a proposal; `allow`/`deny` ignored. No new column.
- `JourneySignalService.ingest()` enriches context with the member's current
  stage, evaluates rules within the signal's group scope, then applies or
  proposes; a **regress guard** blocks backward moves unless
  `effect_params.allow_regress=true`.
- New table `journey_stage_proposals` (migration `000059`) is the propose queue;
  approve applies the same `source=rule` transition (proposal id as evidence).
- New endpoints: `POST /journey/signals`, `GET /journey/proposals`,
  `POST /journey/proposals/{id}/approve|reject`.
- **Validated**: 23/23 signal-engine unit assertions (auto-apply, propose, dedup,
  regress guard both ways, effect filtering, missing-target, approve/reject,
  re-approve conflict, opens journey on first signal). `php -l` clean.
- Signal **emitters** are now **WIRED** in all four sources — Courses
  (`course.completed`), Events (check-in), Follow-ups (record), Contributions
  (`contribution.succeeded`). Each is fault-isolated and a no-op until an org
  authors `membership` rules. Emitters advance the member's org-wide journey
  while passing the originating group as `scope_group_id` so group-scoped leader
  rules still fire.
- **Projects wired into emitters** (migration `000061`): a signal carries a
  `project_code` — intrinsic `cause_id` for contributions, explicit pass-through
  for events/courses/follow-ups (no auto campaign resolution). It is exposed to
  rules for gating, written onto the transition + proposal (carried through
  approval), and tagged onto the Option-D disciple-making award so credit lands
  on project boards. Distinct from group campaigns, which already auto-feed via
  `PointsEngine::feedFromActivity()`.
- **Recommended next activities** (`JourneyRecommendationService`,
  `GET /journey/members/{id}/recommendations`): a pure read that turns the
  `stage_code` links into a "what to do next" view for a member's current + next
  ladder stage(s), group-scope resolved most-specific-wins, versioned rules
  collapsed to the highest active version, active-only. Scope options
  `current|next|both|from_current` (+`ahead`); entry-stage fallback when the
  member has no journey yet. Validated by 16/16 unit assertions.

### Option D — as built (disciple-making attribution + stage links)

- **Reuses the existing gamification stack** — immutable `point_ledger`,
  anti-gaming limits, group attribution/rollups, leaderboards — via
  `PointsEngine::award()`. No new ledger, no parallel scoring.
- `JourneyService` fires a narrow `JourneyTransitionListener` after each
  committed transition; `JourneyAttributionService` credits the `discipler_id` on
  **forward** moves only. **Config-gated, OFF by default**
  (`disciplemaking_award_enabled`); rule configurable
  (`disciplemaking_rule_code`, default `disciple.advance`, seeded editable).
- **Idempotent** credit via `source_ref = journey:{journey_id}:{to_stage}`;
  listener exceptions can never roll back a stage change.
- **Disciple-making leaderboard** `GET /journey/leaderboard/disciplers` ranks by
  distinct people moved forward — read from the transition trail (a metric the
  point ledger can't express).
- **Stage-linked activities**: nullable `stage_code` (migration `000060`) added
  to `activity_categories`, `gamification_rules`, `follow_up_types` and wired
  through their define methods; advisory only, never gates awarding.
- **Validated**: 14/14 attribution/leaderboard unit assertions (config gate,
  forward-only, no-discipler skip, idempotent dedup, phase filter, ranking,
  listener-exception isolation) + Option B/C regression green. `php -l` clean.

**My recommendation:** confirm the **stage ladder + phases** (§4.1) and the
**boundary rule** (journey ≠ account lifecycle ≠ group membership), then build
**Option B** as the spine, since C and D both depend on it and reuse engines we
already have (RuBAC, point ledger, ACL scope, audit).

### §3 reporting gap — CLOSED (discipleship funnel & progression report)

The one open thread this assessment named in §3 — *"there is no journey/stage
progression to report on or rank by"* — is now delivered. The pipeline board
answers "who is at each stage now and who is stalled"; the new **funnel report**
answers the cohort question "how far does the body progress along the ladder,
and where does it thin out?".

- **Route + surface**: `GET /journey/funnel` (plain scoped read; optional
  `?group_id=` + `?window=DAYS`). Menu item *"Discipleship funnel"* under People
  (order 35, between the pipeline and stages); also linked from the pipeline
  board header. Browsers get a self-contained, no-JS, CSP-safe view
  (`Views/funnel.php`); API clients get JSON.
- **Two aggregate lenses** (`JourneyService::funnel()`):
  1. **Current-state funnel** — for each ladder stage, `at_or_beyond` = Σ current
     headcount at that stage or any later stage (monotonic by construction, since
     a Leader has passed New-Believer). Yields `reach_pct` (share of all active
     at-or-beyond here), `conversion_pct` (share of the stage's cohort who
     advanced past it), and `stall_pct` (share sitting at exactly this stage).
  2. **Recent momentum** — `moves_in` / `movers_in`: arrivals INTO each stage
     within a window (advance/open/set on the transition trail), so a leader sees
     where movement is happening, not just the static shape.
- **Resource-light** (standing constraint): exactly TWO grouped-COUNT reads —
  one over `member_journeys` for the distribution, one over
  `member_journey_transitions` for the window's arrivals — regardless of member
  or transition volume. No per-member transfer. Scope matches `pipeline()`
  (org-wide primary journeys when group_id is null, else the group context).
- **i18n**: `Journey.funnel.*` (22 keys) + `Journey.funnelLink` top-level, 6-locale
  parity; menu label `menu.people_funnel` in all 6 App locales.
- **Validated**: `journey_funnel_test.php` (56/0: monotonic reach, all three
  percentages incl. terminal-stage conversion=0, momentum window cutoff +
  distinct-user counting, group isolation, window clamp, empty-context safety,
  and controller/route/view/i18n/menu wiring). Full suite green (150 files /
  11,344 assertions / 0 failed). Preview: `preview_journey_funnel.html`.

---

## 7. Open questions for you

1. **Stage ladder** — accept the seeded default in §4.1, or adjust the
   names/order/phases to your tradition?
2. **Journey granularity** — ONE journey per person org-wide, or one per group
   context (e.g. a person can be "Leader" in one ministry and "Member" in
   another)?
3. **Auto-advance** — should rule-based transitions auto-apply, or only *propose*
   a transition for a leader to confirm?
4. **How far now** — Option B only, or B+C in one pass?
