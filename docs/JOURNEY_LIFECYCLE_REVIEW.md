# Membership-Journey & Activities Lifecycle Review

_A code-grounded gap analysis of the **membership-journey lifecycle** — how a
person's journey is opened, advanced/regressed across a configurable stage
ladder (manually, by rule-driven signal, or via a maker-checker proposal),
paused/archived, triaged by involvement, and how the configurable **activity**
model that feeds it is defined and retired — plus how these interact with the
membership, group and access-control lifecycles reviewed earlier._

Prepared 2026-09-15, as the fourth review in the sequence after
`docs/MEMBERSHIP_LIFECYCLE_REVIEW.md`, `docs/GROUPS_LIFECYCLE_REVIEW.md` and
`docs/ACCESS_CONTROL_LIFECYCLE_REVIEW.md`. Method: read the migrations, the
Journey services (Journey / JourneySignal / Involvement / Attribution /
Recommendation), the controller, routes, the JobRouter signal wiring, the
Gamification activity catalog, and the existing tests; every finding cites the
code it rests on. **No code was changed** — this is the review that precedes any
fixes.

Relationship to the earlier design doc: `docs/ACTIVITIES_AND_MEMBERSHIP_JOURNEY_
ASSESSMENT.md` (2026-09-08) was the *pre-build* "should we redefine?" assessment.
This document is its complement: a *post-build* lifecycle gap analysis of the
system that assessment recommended and that has since shipped (Options B/C/D +
involvement triage, per `docs/TODO_INVOLVEMENT_BASED_TRIAGE.md`).

Scope note: this review is about the **journey as a first-class object** — its
state machine, its stage ladder, the signal→transition→proposal pipeline, and
the involvement snapshot that classifies members. The point/award ledger is
treated as correct background (it was covered by the gamification work) and only
appears where a journey transition should have propagated into it.

---

## 1. The journey model at a glance

- **`journey_stages`** (`000058`) — the org/group-configurable, ordered ladder.
  `code` (stable machine code), `phase` (`win|build|send|general`), `sort_order`,
  `is_terminal`, `is_entry`, `status` (`active|inactive`). Unique on
  `(organization_id, group_id, code)`; a group's stage of a given code overrides
  the org-wide one of the same code.
- **`member_journeys`** (`000058`) — ONE current-stage record per
  `(person, context)` where context = `group_id` (NULL = org-wide primary).
  `stage_code` + denormalised `stage_phase`, `stage_entered_at`, `previous_stage`,
  `status` (`active|paused|completed|archived`), `source`
  (`manual|conversion|rule|import`), `source_ref`. Unique on
  `(organization_id, user_id, group_id)`.
- **`member_journey_transitions`** (`000058`) — append-only, immutable history.
  `from_stage`/`to_stage`, `direction` (`advance|regress|set|open`), `actor_id`,
  `discipler_id` (credited mover — Build/Send attribution), `evidence_type`/ref.
- **`journey_stage_proposals`** (`000059`) — the maker-checker queue for
  rule-driven moves that carry a `require_review`/`flag` effect. `status`
  (`pending|approved|rejected|superseded`); open-proposal dedup enforced in the
  service (a NULLable `group_id` defeats a UNIQUE key).
- **`member_involvement_snapshots`** (`000068`) — the materialized per-context
  triage snapshot: three inputs (recency `last_activity_at`, participation
  `participation_bps`, quantum-of-work `quantum` = own + downline) reduced to a
  `band` (`hot|warm|cold`) + a denormalised `stage_code`, so the pipeline board
  reads stage × band in ONE grouped query.
- **`stage_code` links** (`000060`) — added to `activity_categories`,
  `gamification_rules`, `follow_up_types` so activities/rules/follow-ups can be
  tied to a stage.

**The journey state machine (as built):**

```
(no journey) --open--> active
active <---> paused              (setStatus)
active/paused --> completed      (setStatus; explicit close)
active/paused --> archived       (setStatus; explicit close)
stage moves: advance | regress | set   (transition; does NOT change status)
```

A key, deliberate design point (documented in `transition()`): **reaching the
terminal stage does NOT complete/close the journey** — a Sender/Multiplier is
still an active member and stays in the pipeline. `completed`/`archived` are
explicit human closes via `setStatus`.

**Signal pipeline:** JobRouter emits `journey.signal.*` (course.completed,
event.attended via CheckinService, contribution.verified) → `JourneySignalService::
ingest` evaluates **membership-facet RuBAC rules** in the originating group scope
→ effect `adjust` auto-applies a `transition`; effect `require_review`/`flag`
queues a deduped `propose`; `allow`/`deny` are ignored. A regress is refused
unless the rule opts in (`allow_regress`).

**The good news up front.** This is the most complete lifecycle in the platform
and several things are genuinely well built and should be protected:

1. **Append-only, actor-and-discipler-attributed transition history** — every
   move (including `open`) writes an immutable `member_journey_transitions` row.
2. **Three move channels with the right trust model** — manual (scope-checked),
   rule-`adjust` (auto), rule-`require_review` (maker-checker proposal queue with
   approve/reject). Deduped so a repeatedly-firing signal can't spam the queue.
3. **RuBAC reuse** — journey automation runs on the *same* membership-facet rule
   engine + `AbacConditionEvaluator` (no parallel evaluator, no `eval`), honouring
   the standing constraint.
4. **Signal emission is fault-isolated** — `JobRouter::emitJourneySignal` swallows
   errors so journey automation can never break the award/reward primary path.
5. **Involvement triage is resource-light and correct-by-construction** — a
   materialized snapshot refreshed on the write path (transition / batch
   recompute), never a per-member fan-out on the render hot path; bands computed
   by ordered pure rules (no eval); config through the single hierarchical store
   (`EffectiveConfigResolver`), default OFF.

The findings below are the gaps around the edges of that solid core.

---

## 2. Findings, ranked

### J1 — Involvement bands go stale: nothing refreshes a member who has gone quiet (HIGH)

The involvement snapshot is refreshed **on a journey transition** (the
`JourneyTransitionListener` hook) or by an **explicit admin/batch recompute**
(`POST /journey/involvement/recompute` → `recomputeContext`). There is **no
scheduled command** (Journey has no `Commands/` directory; the only reference to
`recomputeContext` outside the service is the manual route).

The whole point of the triage is to surface members who are **going cold** — but
a member going cold produces *no transition and no activity*, so **nothing
triggers a refresh for exactly the members the board most needs to reclassify.**
A member classified `hot` the day they advanced stays `hot` in
`member_involvement_snapshots` until someone manually recomputes the context,
even after 90 days of silence. The `computed_at`/`window_days` columns record how
stale a row is but nothing acts on them.

This is the involvement mirror of the membership review's **M5 (no dormancy
sweep)** and **M6 (no verify-expiry sweep)**: the classification is correct at
write time but drifts because there is no periodic re-evaluation.

**Fix direction:** add a scheduled `journey:involvement:recompute` command (per
org/context, bounded, idempotent — `recomputeContext` already exists and is built
for exactly this) and run it on the same cron cadence as the other sweeps.
Optionally prioritise rows whose `computed_at` + `window_days` has lapsed so the
pass is cheap. This is the single highest-value fix here.

### J2 — Retiring or reordering a stage strands members sitting on it (HIGH)

`defineStage` upserts a stage and can flip its `status` to `inactive`, but it
does **no dependency check** against `member_journeys` first. Meanwhile
`resolveStage` (used by both `transition` and `openJourney`) filters
`status='active'`. So when a stage a member currently occupies is set inactive:

- The member's `member_journeys.stage_code` becomes an **orphan** pointing at a
  stage the resolver will no longer return.
- `transition()` computes direction via `compareStages`/`direction` against the
  ladder; with the current stage missing from the active ladder, direction
  derivation degrades (the member's "from" is unknown), and the pipeline
  `ladder()` (active-only) drops that bucket — so **the stranded members
  disappear from the board** while still being live journeys.
- `is_entry` / `is_terminal` are likewise unguarded: nothing prevents defining
  **two entry stages** or removing the only entry stage (which would make
  `openJourney` with no explicit `stage_code` fail `NO_STAGE`), and nothing
  prevents a `sort_order` collision that makes advance/regress non-deterministic.

This is the journey analogue of the Groups review's **GR2/GR3** (a terminal
node/stage left referenced by live records) and the group-kind "advisory"
guarantee — retiring a classification must not silently orphan the things
classified by it.

**Fix direction:** before setting a stage `inactive` (or deleting it), block if
any active `member_journeys` sit on it, or require a **target-stage migration**
(move affected members to a named replacement, writing proper transition rows).
Add ladder invariants: exactly one `is_entry` per context, unique `sort_order`,
and refuse removing the last entry stage. Pair the reorder path with a
transaction so the ladder is never observed mid-reindex.

### J3 — `setStatus` is an unguarded free-for-all transition — no legal-move matrix (HIGH)

`setStatus` validates only that the target string is one of
`active|paused|completed|archived` and then writes it. There is **no state
machine**: a `completed` journey can be flipped straight back to `active`, an
`archived` one to `paused`, etc., with no guard, no reason, and — unlike
`transition` — **no audit/transition row** (it writes `member_journeys` directly
and records nothing in `member_journey_transitions`).

This mirrors membership finding **M9 (journey `setStatus` unguarded)** and
**M10 (reactivation not a true inverse)** — flagged there for the person arc and
confirmed here in the journey service itself. Two concrete consequences:

- **No forensic trail for lifecycle closes/reopens.** The history table records
  every *stage* move but not a single *status* move, so "who archived this
  journey and when/why" is unanswerable.
- **Terminal states aren't terminal.** `completed`/`archived` can be silently
  undone, so reports keyed on them are unreliable.

**Fix direction:** give `setStatus` an explicit allowed-transition matrix
(e.g. `active↔paused`, `active/paused→completed|archived`, and an explicit,
audited `reopen` rather than a silent flip), require a reason on close, and write
a `member_journey_transitions` row (or a sibling status-history row) for every
status change so the trail is complete.

### J4 — Journey does not react to account deactivation, membership loss, or group lifecycle (MED — cross-review coupling)

`member_journeys` key on `user_id` + `group_id` but nothing closes or pauses a
journey when the underlying person or context goes away:

- **Account deactivated/suspended (membership M1)** → the person's journeys stay
  `active` and keep appearing in every pipeline/funnel/leaderboard.
- **Person merged (membership M2)** → the loser's journeys are abandoned (no
  reassignment to the survivor), exactly the belongings-orphaning pattern M2
  describes.
- **Group archived/dissolved/merged (groups GR2/GR3)** → group-context journeys
  (`group_id` = the dead group) linger; `pipeline(groupId)` and the involvement
  board still resolve them, and the snapshot's `mis_board_idx` still returns
  them, because — as GR3 established — the group-side resolvers ignore
  `groups.status`.

This is the same account/scope-cascade gap already logged for ACL (**AC3/AC9**)
and membership (**M1/M2**): a signal exists on the source side (once M1/M2 and
GR2/GR3 emit it) but the Journey module does not consume it.

**Fix direction:** subscribe Journey to the account and group lifecycle signals —
on account deactivate/suspend → pause the person's journeys; on merge → reassign
to the survivor (dedup against an existing survivor journey per context); on group
archive/dissolve/merge → archive or re-point group-context journeys. Sequence
after M1/M2 and GR2/GR3 land their emitters.

### J5 — ✅ RESOLVED 2026-09-17
`POST /journey/signals` now carries
`['filter' => ['authorize:gamification.manage,any', 'webcsrf']]` (matching the
sibling proposal-review routes). The scope-param mismatch is also fixed:
`JourneyController::signal()` now authorizes the EFFECTIVE `scope_group_id` —
the rule-firing scope that `JourneySignalService::ingest()` actually honours,
defaulting to the journey-context `group_id` — not just `group_id`. A caller can
therefore no longer fire a group-scoped leader's rules in a branch they don't
manage. The second PDP check runs only when the two scopes differ (common equal
case stays one call). Guard-test assertion + `journey_signal_test.php` (44
passed) cover it.

--- original finding below ---

### J5 — The `signals` ingestion endpoint is authorization-thin relative to what it can do (MED)

`POST /journey/signals` carries only the `auth` group filter — **no `webcsrf`
and no `authorize:` capability** on the route (compare the sibling
`proposals/*/approve|reject` and `stages` writes, which all carry
`authorize:gamification.manage,any` + `webcsrf`). The controller's `signal()`
does call `authorizeGroupScope(SCOPE_ACTION, groupId)`, which is a real scope
check — but:

- with **no `webcsrf`**, a browser cookie-auth session is exposable to CSRF on an
  endpoint that can auto-`adjust` a member's stage (and mint disciple-making
  credit + points via the transition), and
- the scope check uses the **journey context `group_id`**, while `ingest`
  separately honours a caller-supplied **`scope_group_id`** to decide *whose
  rules fire*; a caller can pass a `scope_group_id` different from the
  scope-checked `group_id`, so the rule-firing scope isn't the scope that was
  authorized.

This is the same missing-`webcsrf`/`authorize:` pattern flagged in Groups
**GR1**, Membership **M3**, and ACL **AC11**, plus a scope-parameter mismatch
specific to the dual group arguments here.

**Fix direction:** add `webcsrf` (browser write) to the `signals` route; decide
whether external system callers should use a separate token-authenticated
non-browser route instead. In `ingest`, either derive `scope_group_id` from the
authorized `group_id` or scope-check `scope_group_id` explicitly so rule-firing
can't exceed the caller's authorized branch.

### J6 — Proposal queue has no expiry, no reminders, and no supersede-on-move (MED) — ✅ RESOLVED 2026-09-17

**Resolution.** Three coordinated fixes, all standalone-tested:

1. **supersede-on-move** — `ProposalSupersedeListener` (a `JourneyTransitionListener`,
   so it fires after every committed transition and MUST NOT throw) re-derives, for
   each still-`pending` proposal in the SAME `(user, journey context)`, the direction
   from the member's NEW current stage to the proposal's target. A proposal now at
   (`same`) or behind (`regress`) the member is marked `superseded` — except a
   deliberate regress proposal (`direction='regress'`), left for a human. Wired into
   the primary `JourneyService` listener array; uses a second, listener-less
   `JourneyService` for its read-only `compareStages` so it can't recurse.
2. **approveProposal guard** — re-derives direction against the member's CURRENT
   stage at approval time. If the member is already at the target (`same`) or the
   move would now `regress` them (and the proposal wasn't a deliberate regress), it
   refuses with `PROPOSAL_STALE` (409) AND closes the stale proposal as `superseded`,
   so it can't be retried.
3. **Aging pass** — `JourneyProposalAgingService::sweepPendingProposals()` runs the
   uniform remind → escalate → (optional) timeout lifecycle over pending proposals
   oldest-first, each step watermark-guarded (`reminded_at`/`reminder_count`/
   `escalated_at`, added by migration `2026-09-17-000084`). Reminders go to the
   proposal group's active `leader` members (org-wide proposals → `proposal_review_role`
   holders); escalation → `proposal_escalate_role` holders (falling back to the
   reviewers). Timeout marks the proposal `rejected` (default) or `superseded` per
   `journey.proposal_timeout_action`, system actor; `journey.proposal_timeout_days=0`
   (default) = never auto-terminate. Registered as sweep `journey.proposal-aging` in
   the shared registry; per-org thresholds are platform settings
   (`journey.proposal_remind_hours` 48, `journey.proposal_escalate_hours` 120,
   `journey.proposal_timeout_days` 0) seeded by `AdminConfigSeeder`.

Tests: `app/Modules/Journey/Services/tests/proposal_aging_test.php` (24) +
supersede/approve-guard blocks appended to `journey_signal_test.php` (now 44). Error
keys `journey.proposal_stale` etc. follow the existing raw-identifier convention (no
lang entries), matching the rest of the Journey/Gamification error surface.

**Original finding (for reference):**

### J6 — Proposal queue has no expiry, no reminders, and no supersede-on-move (MED)

`journey_stage_proposals.status` includes `superseded`, and `ingest` dedups open
proposals — but **nothing ever sets `superseded`**, and there is no timeout or
reminder:

- If a member advances (manually or by another rule) **past** a stage a pending
  proposal targets, that proposal stays `pending` and can later be `approved`,
  driving a **regress or a stale move** the reviewer no longer intends.
- Pending proposals accumulate forever with no reminder/escalation to the leader
  whose queue they sit in (`jsp_pending_idx` is built for that queue but nothing
  ages it).

This is the journey twin of ACL **AC4/AC5** (stale pending access requests, no
approver reminders) and shares the same fix home.

**Fix direction:** on any transition, mark still-open proposals for that
`(user, context)` whose target is now redundant/behind as `superseded`; add a
pending-proposal aging pass (timeout → `superseded` or `rejected`, plus a leader
reminder) to the same scheduled sweep as J1. Guard `approveProposal` to re-derive
direction against the member's *current* stage and refuse an unintended regress.

### J7 — Activity catalog / rules / follow-up types can be retired out from under live journeys and stage links (MED)

`ActivityCatalogService::disable` sets a category `inactive` with no check on
dependents, and the `stage_code` links added by `000060` to `activity_categories`
/ `gamification_rules` / `follow_up_types` are plain nullable columns with no
referential guard. So:

- Disabling a category or a gamification rule that a **membership rule** relies
  on (to fire a `journey.signal.*` → transition) silently breaks that automation
  with no warning — the journey simply stops advancing for that evidence type.
- A `stage_code` link can point at a stage later retired (J2), leaving a dangling
  reference the UI will render as an unknown stage.

Same class as J2 (retire-without-dependency-check) but on the activity side.

**Fix direction:** on disable, surface (or block on) dependents — membership
rules referencing the category/rule, and `stage_code` links pointing at it — and
validate `stage_code` against the active ladder when set. At minimum, warn; ideally
require the operator to re-point or acknowledge.

### J8 — Idempotency of signal-driven transitions relies on stage equality, not evidence identity (MED)

`transition` short-circuits when `fromCode === target` ("already there,
unchanged"), and `ingest` skips `same`/unwanted-`regress` directions — so a
**replayed identical signal for a member already at/after the target is a
no-op**, which is good. But idempotency is keyed on **stage position, not on the
evidence**: two *different* qualifying signals (e.g. two different completed
courses that both map to the same "advance to In-Foundation" rule) each fire a
transition/credit if they arrive while the member is still below the target, and
a signal that arrives out of order (member already advanced past, then a stale
earlier evidence replays with `allow_regress`) can still move them.

The attribution service is idempotent per `(journey, destination stage)`
(confirmed in `JourneyAttributionService`) — so **disciple-making credit** is
protected — but the **transition/notification** path is only position-idempotent.
Worth confirming this matches intent; for an at-least-once JobRouter this is the
kind of edge that produces duplicate "you advanced!" notifications.

**Fix direction:** decide the idempotency contract explicitly. If evidence-level
dedup is wanted, carry an `evidence_ref` uniqueness check into `transition`
(skip if a transition already exists for this journey with this evidence). At
minimum, document that transitions are position-idempotent, not evidence-idempotent.

### J9 — Thin negative/edge test coverage for the lifecycle guards that don't yet exist (MED)

Coverage of the *happy paths* is good (transition, funnel, triage, pipeline,
members-at-stage, recommendation, signal, admin workflow, i18n, involvement
config + service). But there is **no test** for the guards this review says are
missing — because the guards are missing: no test for retiring an occupied stage
(J2), no `setStatus` legal-move matrix test (J3), no stale-band / scheduled
recompute test (J1), no proposal-supersede/expiry test (J6). Adding the tests
first pins the intended behaviour before the fixes land.

**Fix direction:** add red→green service tests for each of J1/J2/J3/J6 before
implementing them, in the existing `Services/tests/` style.

### J10 — Multiple journeys per person are independent with no primary/rollup reconciliation (LOW)

A person can hold one journey per `(group_id)` context plus the org-wide primary
(`group_id = NULL`), and `journeysForUser` returns them all. Nothing keeps them
*consistent*: advancing a member to "Leader" in one group context does not inform
the org-wide primary, and there is no defined "the person's overall stage is the
max/primary of their contexts" rule. For most reporting the org-wide primary is
read directly, so this is low-impact, but it's an open modelling question the
funnel/leaderboard implicitly assume away.

**Fix direction:** define (and document) whether the org-wide primary journey is
authoritative and whether group-context advances should roll up to it — mirroring
the contribution-attribution rollup decision already settled for giving.

---

## 3. Suggested sequencing

Front-loads the sweeps/guards that keep the board honest, defers the modelling
question:

1. **J9** — add the guard tests (red) first so J1/J2/J3/J6 land green.
2. **J1** — scheduled involvement recompute command (highest value; the triage
   is misleading without it; `recomputeContext` already exists).
3. **J3** — `setStatus` legal-move matrix + status-history/audit rows.
4. **J2** — stage-retire/reorder dependency guard + ladder invariants
   (one entry, unique order, no strand).
5. **J5** — `webcsrf` + scope-parameter reconciliation on `signals` (pairs with
   GR1/M3/AC11).
6. **J6** — proposal supersede-on-move + aging/reminder pass (shares the sweep
   home with J1 and mirrors AC4/AC5).
7. **J7** — activity/rule/follow-up disable dependency checks + `stage_code`
   validation.
8. **J4** — consume account (M1/M2) and group (GR2/GR3) lifecycle signals
   (**after** those emitters exist).
9. **J8, J10** — decide + document the idempotency contract and the
   multi-journey/primary rollup rule. Lowest urgency.

## 4. Cross-review couplings (explicit)

- **J1 ↔ Membership M5/M6:** the involvement staleness is the same "no periodic
  re-evaluation" gap as dormancy/verify-expiry; share a scheduled-sweep home.
- **J3 ↔ Membership M9/M10:** unguarded journey `setStatus` and non-inverse
  reactivation were flagged for the person arc; confirmed in the journey service.
- **J4 ↔ Membership M1/M2, Groups GR2/GR3, ACL AC3/AC9:** account/scope-cascade
  — Journey must consume the same lifecycle signals the other modules must emit.
- **J5 ↔ Groups GR1 / Membership M3 / ACL AC11:** the recurring missing-`webcsrf`
  /`authorize:` pattern on a mutating route.
- **J6 ↔ ACL AC4/AC5:** stale pending approvals with no expiry/reminders.

No code was changed in the course of this review.
