# Membership Lifecycle Review

_A code-grounded gap analysis of the **individual member lifecycle** — the whole
arc by which a person becomes, stays, changes, and eventually leaves as a tracked
member. Deliberately scoped to the **person**: the login account (Identity), the
onboarding handoffs (Identity → Referrals → Groups → Journey), and the
discipleship Membership Journey. **Group structure/lifecycle is a separate review**
(coming next) — this doc touches `group_members` only where a person's belonging
state is part of their own lifecycle._

Prepared 2026-09-15. Method: read the migrations, services, controllers, routes
and existing tests; every finding below cites the code it rests on. No code was
changed — this is the review that precedes any fixes.

Companion docs already in the repo: `docs/member-onboarding.md` (the five-step
become-a-member flow) and `docs/membership-journey.md` (the discipleship spine).
This review builds on both, verifies them against current code, and adds the
lifecycle-completeness lens (transitions, cascades, sweeps, wiring, UI).

---

## 1. The three independent axes (and why that matters)

The platform models a member on **three deliberately decoupled axes**. This is a
strength — it mirrors reality (a person can be an active login with no group and
an early journey stage) — but it also means "membership lifecycle" is really
*three* state machines plus the **bridges between them**, and most of the gaps
below live in those bridges.

| Axis | Owner | State column | States |
| --- | --- | --- | --- |
| **Account** (login/identity) | Identity `users` | `users.status` | `prospect`, `pending_verification`, `active`, `suspended`, `locked`, `deactivated`, `anonymized`, `merged` |
| **Belonging** (group membership) | Groups `group_members` | `group_members.status` | `active`, `pending`, `ended` (+ `approval_state`) |
| **Discipleship** (maturity) | Journey `member_journeys` | stage_code + `status` | stage ladder (`prospect`…`sender`) × lifecycle `active`, `paused`, `completed`, `archived` |

Source: `2026-09-04-000036_CreateIdentityLifecycle.php`,
`2026-09-04-000038_EnhanceGroupMemberships.php`,
`2026-09-08-000058_CreateMembershipJourney` +
`AccountLifecycleService::TRANSITIONS`, `JourneyService::JOURNEY_STATUSES`.

---

## 2. What is solid today ✅

The account state machine and the merge-review workflow are genuinely
well-built; the review should not obscure that.

- **Account state machine is explicit and guarded.**
  `AccountLifecycleService::TRANSITIONS` is a real adjacency map with terminal
  states (`anonymized`, `merged` have no exits), every transition **requires a
  reason** (`REASON_REQUIRED`), rejects `NO_CHANGE` and `ILLEGAL_TRANSITION`,
  and writes an **append-only** `account_state_transitions` evidence row inside a
  transaction alongside the `users` update. Security-relevant states
  (`suspended`, `locked`, `deactivated`, `anonymized`, `merged`) **revoke all
  sessions + tokens** immediately (`REVOKE_ON`). Anonymize **scrubs PII**
  (`scrubPii`) and stamps `anonymized_at`.
- **Identity merge is a proper maker-checker.** `submitMerge` → `approveMerge`
  runs the PDP (`access.request.approve`) **and** denies self-approval
  (`SOD_SELF_APPROVAL` when `requested_by === actor`), records
  `identity_merge_reviews`, and retires the duplicate *through the same state
  machine* (so the merge inherits the transition evidence + revocation). Clean.
- **Journey spine is configurable and rule-driven.** Ordered `journey_stages`
  (org- or group-scoped, most-specific-wins), one `member_journeys` row per
  (person, context), append-only `member_journey_transitions` with
  `direction`/`discipler_id`/`evidence`. `transition()` **auto-opens** a journey
  at the target when none exists, and is scope-checked per leader
  (`authorizeGroupScope`), matching the ACL scope model.
- **Group membership lifecycle is rich.** `group_members` carries
  `membership_type`, `status`, `source`, `effective_from/to`, `left_at`,
  `leave_reason`, `approval_state` + a one-active-slot guard (`active_key`
  unique). `add`/`approve`/`reject`/`leave`/`changeRole` all audit and event.
- **Onboarding steps are individually idempotent and audited**, as
  `docs/member-onboarding.md` documents and code confirms
  (`AccountService::register`, `ReferralService::attributeConversion` replay-safe
  on its UNIQUE key, `GroupMembershipService::add`).

---

## 3. Findings (gaps), by severity

Severity = risk to correctness / auditability / member trust, not effort.

| # | Gap | Axis / bridge | Severity |
| --- | --- | --- | --- |
| M1 | **Account status changes do not cascade to belonging or journey.** Suspend/deactivate/anonymize a user and their `group_members` rows stay `active` and their `member_journeys` stay `active`. | Account → Belonging/Journey bridge | ✅ SHIPPED (belonging half; journey half was already J4) |
| M2 | **Identity merge abandons the duplicate's belongings, journey and history.** The duplicate is marked `merged` but its `group_members`, `member_journeys`, contributions/attendance are **not re-pointed** to the survivor — unlike the Groups merge, which *does* cascade memberships. | Account merge | ✅ SHIPPED (belonging half; journey/contrib/event halves were already J4/C8/E-B2) |
| M3 | **Lifecycle mutation routes lack CSRF (`webcsrf`).** `accounts/{id}/suspend|lock|reactivate|deactivate|anonymize|transition` carry only `authorize:identity.manage` — no `webcsrf` — while the sibling `merges/*` and `identity/policies` POSTs do. Cookie-authenticated admin is CSRF-exposed on the most destructive actions. | Account | 🔴 High |
| M4 | **No journey opens at account creation or referral conversion** ("the one open seam" in the onboarding doc). A brand-new/inactive member has no journey row until they *do* something; first-stage pipeline counts under-report. | Onboarding → Journey bridge | ✅ SHIPPED |
| M5 | **No dormancy / lapsed-member model.** Nothing ages an `active` account or membership into "dormant/lapsed" on inactivity, and nothing re-engages it. The journey has `paused`, but no signal drives it. | All axes | 🟠 Medium |
| M6 | **No verification-expiry / reminder sweep for `pending_verification`.** Self-registrations that never verify sit forever; there is no reminder nudge and no expiry to `deactivated`. (Identity has only `SessionPruneCommand`.) | Account | 🟠 Medium |
| M7 | **Belonging → Journey is one-way and partial.** Joining/leaving a group emits no journey signal, so "integration" (group membership, a standing definition) does not itself advance or regress the journey; only events/courses/contributions do. | Belonging → Journey bridge | ✅ SHIPPED |
| M8 | **No admin lifecycle UI.** `members.php` is a **read-only** roster (0 `<form>`s); every suspend/deactivate/anonymize/transition is API-only. No maker-checker console for account state like the one refunds/merges have. | Account (UX) | 🟠 Medium |
| M9 | **Journey `setStatus` is unguarded and historyless.** `setStatus` (active/paused/completed/archived) writes `member_journeys.status` with **no state-machine guard and no transition row** — unlike stage moves, which are fully audited. Its route has `webcsrf` but no `authorize`/scope beyond the controller. | Discipleship | ✅ SHIPPED |
| M10 | **Account reactivation does not reconcile downstream.** Reactivating a previously-suspended user restores login but does not restore/repair the belongings or journey that M1 left stranded — the inverse cascade is also missing. | Account → others | ✅ SHIPPED |
| M11 | **`prospect` account state vs `prospect` journey stage vs Referrals `prospect` are three different "prospects."** Same word, three tables, no explicit reconciliation — a documentation/model-clarity risk that invites double-counting in reporting. | Cross-axis clarity | 🟡 Low |

---

## 4. The gaps in detail

### M1 — ✅ RESOLVED 2026-09-18 (belonging half)
The account-teardown fan-out (`app/Modules/Shared/Messaging/JobRouter::fanOutTeardown`)
now wires a `membership-teardown` consumer alongside the six existing module
consumers (AccessControl grant-cascade, Contributions, Journey pause, Notifications,
Referrals, Courses, Events). On `account.deactivated / .suspended / .anonymized`
it calls `GroupMembershipService::endActiveForSubject($org, $userId, $reason)`,
which ends every ACTIVE `group_members` row for the subject (`status='ended'`,
`left_at`, `leave_reason='teardown: <reason>'`, `effective_to` backfilled,
`active_key=NULL` so the one-active slot re-opens for M10 reactivation), emitting
a membership event + audit record per row. SYSTEM authority (no scope check — the
account is gone), fault-isolated by the router, idempotent (only `active` rows
flip; a redelivered teardown ends 0), and history (`ended` rows) is preserved.
The **journey half** of M1 was already shipped as J4 (`pauseAllForSubject`). Wiring
pinned in `jobrouter_account_teardown_test.php` (115/0); service behaviour in
`app/Modules/Groups/Services/tests/membership_teardown_test.php` (35/0). Full
suite green: 223 files / 13,866 assertions / 0 failed. No route/OpenAPI change
(544 ops / 466 paths).

--- original finding below ---

### M1 — Account status does not cascade 🔴
`AccountLifecycleService::transition()` updates `users`, writes evidence, and
revokes sessions/tokens — but **stops there**. A grep for any reactor on
`identity.account.status_changed` finds none. Consequences:

- A **suspended/deactivated** person keeps `active` `group_members` rows, so they
  still count in `listForGroup(..., 'active')` rosters, scope resolution, and
  group-size metrics, and their `member_journeys` still surface in the pipeline
  and disciple leaderboards.
- **Anonymize** scrubs the `users` PII but leaves their name/notes potentially
  echoed in `member_journey_transitions.reason`, group `leave_reason`, etc. — a
  GDPR-completeness hole for a feature whose whole point is erasure.

**Shape of a fix:** an idempotent `MemberLifecycleReactor` (or an `event.*`
outbox topic emitted by `transition()`) that, on entry to a terminal/suspended
state, ends active memberships (`group_members.status='ended'`, reason
`account_<status>`) and pauses/archives journeys — all through the *existing*
membership/journey services (no forked writes), fault-isolated, and reversible
where the state is (see M10).

### M2 — ✅ RESOLVED 2026-09-18 (belonging half)
The account-merge fan-out (`JobRouter::accountMerged`) now wires a
`membership-reassign` consumer alongside the journey / sponsorship / enrollment /
registration re-points. On `account.merged` it calls
`GroupMembershipService::reassignForMerge($org, $loserId, $survivorId)`, which
re-points the loser's memberships to the survivor, deduped on the one-active
`(group, membership_type)` slot: a free slot re-points (`user_id` + recomputed
`active_key`); a slot the survivor already holds ends the loser row as a
superseded duplicate (`active_key=NULL`, survivor's membership wins — never a
blind cross-identity rewrite); two active loser rows for the same slot dedup
intra-loser; historical rows re-point owner for continuity but keep
`active_key=NULL`. Mirrors the E-B2 registration / CO6 enrollment merge pattern.
As with the other modules, `fanOutTeardown` **skips** the membership-teardown on
`account.merged` (belongings move to the survivor instead of being ended). The
journey / contribution / event halves of M2 were already shipped as J4 / C8 /
E-B2. Wiring pinned in `jobrouter_account_teardown_test.php` (115/0); dedup
behaviour in `membership_teardown_test.php` (35/0). Full suite green: 223 files /
13,866 assertions / 0 failed.

--- original finding below ---

### M2 — Identity merge abandons the duplicate's belongings 🔴
Contrast is the tell: `GroupLifecycleService` merge **re-points**
`group_members` to the survivor (recomputing `active_key`, ending dups —
`GroupLifecycleService.php:335-350`). `AccountLifecycleService::approveMerge`
does **none** of that for a *person* merge — the duplicate's memberships,
journeys, contributions and event history are stranded on a `merged` (dead)
user id. This both loses data continuity and can **double-count** the same human
until manually cleaned. A person merge should reassign (or explicitly, audibly
choose not to) each belonging/journey/attribution to `primary_user_id`, mirroring
the group-merge cascade, inside the approval transaction.

### M3 — ✅ RESOLVED 2026-09-17
The 7 identity mutations (`merges` submit + the 5 lifecycle actions
`suspend/lock/reactivate/deactivate/anonymize` + `transition`) now carry
`['filter' => ['authorize:identity.manage', 'webcsrf']]`, matching the platform
standard. `webcsrf` is header-exempt for Bearer / `X-WBS-Session` callers so the
JSON API is unaffected. Backed by 7 mechanical assertions in
`app/Modules/Shared/Support/tests/route_filter_guard_test.php` and the frozen
CSRF-EXEMPT entries removed (regression now fails CI). Full suite green:
218 files / 13,595 assertions / 0 failed.

--- original finding below ---

### M3 — Missing CSRF on destructive account routes 🔴
`app/Config/Routes.php` (identity group): the five lifecycle mutations +
`transition` have `['filter' => 'authorize:identity.manage']` only. The platform
standard (seen on `merges/*/approve`, `identity/policies`, and every Events/
Contributions mutation) is `['filter' => ['authorize:...', 'webcsrf']]`. This is
a one-line-per-route fix and should be the **first** thing corrected — it is a
live vulnerability, not a design question.

### M4 — ✅ RESOLVED 2026-09-18
Closed via Option 1 (rule-driven — consistent with the rest of Option C).
`AccountService::register()` emits `journey.signal.member.registered` and
`ReferralService::attributeConversion()` emits `journey.signal.member.converted`,
each through a `JourneySignalPort` seam (Identity + Referrals stay decoupled from
the Journey module; both already had a cycle-free direction to Journey). Two
default membership ENTRY rules seeded: `mbr.register.open_prospect` → opens the
journey at `prospect`, `mbr.convert.open_first_timer` → opens at `first_timer`.
The seeder compiles the `(new)` sentinel from-stage into a `has_journey = false`
gate, so an entry rule fires exactly once (no journey yet) and no-ops on replay —
never clobbering an existing journey. Emission is best-effort + fault-isolated
(mirrors the sponsor-linking pattern: outside the account transaction, never
undoes a valid write) and optional. Result: brand-new members get a first-stage
journey row, so pipeline counts no longer under-report. Pinned by
`register_journey_signal_test.php` (18/0), `conversion_scope_test.php` (29/0) and
the extended `membership_rule_seeder_e2e_test.php` (54/0). Full suite green: 225
files / 13,964 assertions / 0 failed. No route/OpenAPI change.

--- original finding below ---

### M4 — No journey on account/referral creation 🟠
Confirmed: `openJourney()` is called only within Journey; neither
`AccountService::register()` nor `ReferralService::attributeConversion()` opens a
journey or emits a signal (`docs/member-onboarding.md` §"The one open seam"). The
doc's recommended Option 1 — emit `journey.signal.member.registered` /
`journey.signal.member.converted` so a `membership`-facet rule opens the entry
stage — remains the right, rule-driven close. Low-risk, additive.

### M5 — No dormancy/lapsed model 🟠
There is no "last active" watermark on a member and no sweep that ages inactivity
into a `dormant`/`lapsed` state on any axis, nor a re-engagement path. This is
the membership analogue of the event **close-automation** sweep just shipped
(L3): a config-gated, resource-light `members:mark-dormant` sweep over a
last-activity signal, default OFF, emitting a signal the journey/triage can react
to. (The Journey **involvement triage** already computes activity bands — that is
the natural signal source, not a new one.)

### M6 — No verify-expiry / reminder sweep 🟠
`pending_verification` is a real starting state (`AccountService::register`
self-registration path) but nothing nudges or expires it. Mirror the Events
reminder-sweep pattern: a `identity:verify-reminders` / expiry command,
idempotent + watermarked, config-gated.

### M7 — ✅ RESOLVED 2026-09-18
Belonging now drives the journey through the SAME rule engine as courses/events —
no forked path. `GroupMembershipService` emits `journey.signal.group.joined` when
a membership becomes ACTIVE (immediate `add()`, or `approve()` of a pending
request — a not-yet-approved belonging does not advance a stage) and
`journey.signal.group.left` on member-initiated `leave()`. Signals go through a
new `JourneySignalPort` / `JourneySignalAdapter` seam (mirroring the identical
Referrals seam) so Groups stays decoupled from the Journey module — Journey's
controller already depends on Groups, so a direct Groups → Journey dependency
would be a cycle. Emission is best-effort + fault-isolated (a signal failure
never breaks the belonging write) and optional (no port wired ⇒ belongings still
work). Journey context = the person's ORG-WIDE journey (`group_id=null`) while the
originating group is `scope_group_id` so a group-scoped leader's rule can fire.
Two default membership rules seeded: `group.joined` prospect→first_timer (adjust)
and new_believer→in_foundation (require_review). The bulk account-teardown path
(`endActiveForSubject`) deliberately does NOT emit — it is a system action, not a
member leave. Pinned by `membership_journey_signal_test.php` (29/0) and extended
`membership_rule_seeder_e2e_test.php` (42/0). Full suite green: 224 files /
13,903 assertions / 0 failed. No route/OpenAPI change (544 ops / 466 paths).

--- original finding below ---

### M7 — Belonging does not signal the journey 🟠
Standing definition: **"integration" = group membership + foundations/membership
courses.** Courses already emit `course.completed` → journey; **group join/leave
emits nothing to the journey.** `GroupMembershipService::add/approve/leave`
audit + fire a group `event()` but do not emit a `journey.signal.*`. So the
membership half of "integration" never advances the journey. Add
`journey.signal.group.joined` (and a regress/`left` counterpart) from the
membership service, consistent with the existing emitter pattern.

### M8 — ✅ RESOLVED 2026-09-18
The member roster is no longer read-only. Each row now carries a CSP-safe,
no-JS **manage** panel (native `<details>` disclosure — no inline JS, so it works
under `CSPEnabled`) exposing exactly the lifecycle actions LEGAL from that
member's current status: the view's action map mirrors
`AccountLifecycleService::TRANSITIONS`, so an active member offers
suspend/lock/deactivate/anonymize, a suspended or deactivated member offers
reactivate (the M10 inverse), and a terminal `anonymized`/`merged` account offers
nothing ("No actions available"). Each action is a POST form to the pre-existing
`identity/accounts/{id}/{action}` route (`authorize:identity.manage` + `webcsrf`)
carrying the CSRF token and a **required reason** field; `anonymize` shows an
irreversible-action warning, and every row deep-links to the lifecycle history.
The view is the convenience layer only — `AccountLifecycleService` remains the
true guard (illegal edges are rejected server-side regardless of what the UI
shows). Fully localized across all six locales (`Identity.members.act.*`,
`colActions`, `manage`, `reasonLabel`, `history`, `confirmIrreversible`,
`noActions`) with RTL support. No new routes → no OpenAPI change. Pinned by 27
new assertions in `members_view_test.php` (55/0). Full suite green: 225 files /
14,029 assertions / 0 failed.

--- original finding below ---

### M8 — No admin lifecycle UI 🟠
`members.php` renders avatars + a status chip and **zero forms**; the roster is
read-only. Every lifecycle action is API-only, so there is no bespoke,
CSP-safe, no-JS console for an admin to suspend/reactivate/deactivate/anonymize
with a reason (the way refunds/merges have consoles). This is the UX gap that
makes M3 easy to miss and M1/M2 invisible to operators.

### M9 — ✅ RESOLVED 2026-09-18
`JourneyService::setStatus()` now brings a journey's LIFECYCLE moves up to the
same standard its STAGE moves already had: (1) a state-machine GUARD — only the
edges in `STATUS_TRANSITIONS` are legal (active↔paused, either→completed/archived,
completed→active/archived, archived→active only), so a nonsensical jump (e.g.
archived→completed) is rejected with `BAD_STATUS_TRANSITION` (409) listing the
allowed set, and a no-op (status already == target) short-circuits with
`meta.unchanged=true`; (2) an immutable HISTORY row — every real change writes a
`member_journey_transitions` row with `direction='status'`, `from_stage ==
to_stage ==` the current stage (the stage does not move), carrying actor_id +
reason. The new `status` direction is deliberately EXCLUDED from the stage-movement
analytics (funnel momentum / discipler leaderboard only count advance/open/set),
so lifecycle history never inflates progression metrics. Controller threads the
real actor_id + optional reason. `journey.bad_status_transition` follows the same
API-code convention as the pre-existing `bad_status`/`bad_stage` (not a view lang
key — no locale obligation). Pinned by 14 new assertions in
`journey_service_transition_test.php` (52/0). Full suite green: 224 files /
13,917 assertions / 0 failed.

--- original finding below ---

### M9 — Journey `setStatus` is unguarded 🟡
`JourneyService::setStatus()` validates the enum then blind-writes
`member_journeys.status` — **no** allowed-transition check (e.g. `archived` →
`active`?) and **no** `member_journey_transitions` row, so a journey's *lifecycle*
changes are invisible to history while its *stage* changes are fully audited.
Bring it up to the stage-move standard (guard + evidence row).

### M10 — ✅ RESOLVED 2026-09-18
Reactivation is now the coherent INVERSE of the M1/J4 teardown cascade, built as
a pair with it. `AccountLifecycleService::transition()` stages a new
`account.reactivated` outbox signal when an account returns to `active` from a
torn-down state (`REACTIVATE_FROM = suspended|deactivated`, kept symmetric with
`TEARDOWN_TOPIC` — a first activation from `pending_verification`/`prospect`, or a
return from `locked`, tore nothing down and emits nothing). The JobRouter's new
`accountReactivated` fan-out runs two fault-isolated, idempotent restorers:
- `JourneyService::resumeAllForSubject()` — resumes journeys the teardown PAUSED
  (matched by the `teardown:` note marker), clearing the note; a member-paused /
  completed / archived journey is never resurrected.
- `GroupMembershipService::restoreForSubject()` — restores memberships the
  teardown ENDED (matched by the `teardown:` leave_reason marker), recomputing
  `active_key`; a member-initiated leave is never resurrected, and if the person
  re-joined the same (group,type) slot while away the newer active row wins (the
  one-active guard dedupes, the teardown row stays ended).
Only teardown-caused changes are reversed — the restorers key off the markers the
teardown itself stamped, so reactivation is a precise inverse, never a blind
"set everything active". Pinned by `journey_teardown_test.php` (resume, 38/0),
`membership_teardown_test.php` (restore, 50/0),
`jobrouter_account_teardown_test.php` (fan-out, 129/0) and
`account_teardown_events_test.php` (emit, 29/0). Full suite green: 225 files /
14,010 assertions / 0 failed.

--- original finding below ---

### M10 — Reactivation is not a true inverse 🟡
Once M1 is fixed forward (cascade on suspend), reactivation should offer a
reconciliation (restore ended-by-suspension memberships? re-activate the paused
journey?) rather than silently leaving the person half-restored. Design decision,
low urgency, but should be settled when M1 is built so the pair is coherent.

### M11 — ✅ RESOLVED 2026-09-18
The three notions that share the word "prospect" are now reconciled around ONE
canonical definition, and the concrete double-count it enabled is fixed.

**Canonical model (three orthogonal axes — a contact belongs to at most one cell
of each, and they are never conflated):**

| Axis | Field | Meaning | Values |
|------|-------|---------|--------|
| **Account lifecycle** | `users.status` | state of a *platform account* | `prospect` = an account shell that has never authenticated (distinct from a lead) |
| **Discipleship stage** | `member_journeys.stage` / `journey_stages.code` | how far a *person* has progressed | `prospect` → `first_timer` → `new_believer` → … (the ladder) |
| **Outreach lead** | `prospects.state` | lifecycle of an *address-book lead* | `captured` (open) → `converted` (became a person) / `rejected` (dead) |

**The bug it caused:** `DashboardService::wbsFunnel()` counted EVERY `prospects`
row as `win.prospects` while ALSO counting every user in `win.members_unique`.
A lead that had already **converted** (and therefore has a `linked_user_id`
pointing at a real user) was counted twice — once as a prospect, once as a
member — inflating the top of the funnel and breaking the WIN→member ratio.

**The fix:** `wbsFunnel` now counts OPEN leads only — a new
`countOpenContacts()` helper restricts `win.prospects` to
`prospects.state='captured'`. A converted lead is represented exactly once, by
its linked user in `members_unique`; a rejected lead is counted nowhere; and
`users.status='prospect'` / the journey `prospect` stage are treated as separate
axes, not folded into the outreach count. Group scoping honours the address-book
`assigned_group_id` column, and a legacy schema without a `state` column degrades
to counting all rows rather than erroring. Pinned by
`wbs_funnel_prospect_reconciliation_test.php` (6/0), including the
all-converted → zero-open and converted-not-double-counted cases. Full suite
green: 226 files / 14,035 assertions / 0 failed.

--- original finding below ---

### M11 — Three "prospects" 🟡
`users.status='prospect'`, `journey_stages.code='prospect'`, and
`prospects.state` (Referrals) are three distinct notions sharing a word. Nothing
is broken, but reporting/pipeline surfaces can double-count a contact. Worth an
explicit reconciliation note (and possibly a canonical "contact" view) so the
Groups review and any funnel reporting start from one definition.

---

## 5. Suggested sequencing

1. **M3 (CSRF on lifecycle routes)** — security fix, one line per route, do
   first. No design needed.
2. **M1 + M10 (account→belonging/journey cascade, with a coherent inverse)** —
   the core correctness gap; build the forward cascade and the reactivation
   reconciliation together so they are consistent. Reactor/outbox, idempotent,
   reuses existing services.
3. **M2 (person-merge belonging/journey reassignment)** — mirror the group-merge
   cascade inside `approveMerge`; closes the data-continuity + double-count hole.
4. **M4 + M7 (open journey on register/convert; signal it on group join/leave)**
   — completes the onboarding→journey and belonging→journey bridges; both are
   additive emitters matching the existing Option-C pattern.
5. **M8 (admin lifecycle console)** — bespoke no-JS/CSP-safe members console with
   reason-carrying actions (+ optional maker-checker for anonymize), making the
   above operable.
6. **M5 + M6 (dormancy + verify-expiry sweeps)** — config-gated, resource-light
   commands mirroring the Events L3 close sweep and G3 reminder sweep.
7. **M9 (guard + audit journey setStatus)** — small correctness/audit polish.
8. **M11 (reconcile the three "prospects")** — a clarity note, ideally settled
   alongside the Groups review that follows.

### Recommendation
The **account state machine and merge-review are strong**; the membership
lifecycle's real weakness is the **bridges between the three axes** — status
changes and merges that don't cascade (M1, M2), and onboarding/belonging events
that don't reach the journey (M4, M7) — plus one **live security gap** (M3, missing
CSRF) and a **missing operator UI** (M8). Fixing M3 immediately, then M1/M2, would
remove the highest-risk correctness and trust issues before the Groups review
builds on top of this foundation.

Every fix, when scheduled, must follow the standing bespoke pattern already used
across the Events lifecycle work: no-JS / CSP-safe views, resource-light
(one bounded indexed query or version-stamped cache; gate resolved once),
hierarchical-config gating default-OFF for any sweep, reuse of existing services
(no forked writes), 6-locale parity, dedicated wiring tests, OpenAPI regenerated,
full suite green. Reuse existing permission bits where possible (the
`PermissionBits` map is frozen at 41 entries).
