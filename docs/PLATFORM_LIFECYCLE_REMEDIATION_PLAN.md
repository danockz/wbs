# Platform Lifecycle Remediation Plan

_A consolidation of the eight module lifecycle reviews into one cross-cutting
findings map and a phased remediation roadmap. Its thesis: the ~75 individual
findings are not 75 unrelated bugs — they collapse into **three systemic gaps**
that recur in nearly every module, and each is best closed once, as shared
infrastructure, rather than patched ~30 times._

Prepared 2026-09-15, after the eight reviews below. No code was changed by any of
those reviews or by this plan; this is the decision document that should precede
the first fix.

**Source reviews (read these for the code-grounded detail behind every finding
id):**

| Module | Doc | Findings |
| --- | --- | --- |
| Membership | `docs/MEMBERSHIP_LIFECYCLE_REVIEW.md` | M1–M11 |
| Groups | `docs/GROUPS_LIFECYCLE_REVIEW.md` | GR1–GR12 |
| Access Control | `docs/ACCESS_CONTROL_LIFECYCLE_REVIEW.md` | AC1–AC12 |
| Journey / Activities | `docs/JOURNEY_LIFECYCLE_REVIEW.md` | J1–J10 |
| Contributions | `docs/CONTRIBUTIONS_LIFECYCLE_REVIEW.md` | C1–C10 |
| Referrals | `docs/REFERRALS_LIFECYCLE_REVIEW.md` | R1–R9 |
| Gamification | `docs/GAMIFICATION_LIFECYCLE_REVIEW.md` | G1–G9 |
| Notifications | `docs/NOTIFICATIONS_LIFECYCLE_REVIEW.md` | N1–N8 |
| Courses | `docs/COURSES_LIFECYCLE_REVIEW.md` | CO1–CO7 |
| Meetings | `docs/MEETINGS_LIFECYCLE_REVIEW.md` | MT1–MT6 |
| Events (theme pass) | `docs/EVENTS_LIFECYCLE_THEME_PASS.md` | E-A/B/C |
| Identity | `docs/IDENTITY_LIFECYCLE_REVIEW.md` | ID1–ID5 |
| Integrations | `docs/INTEGRATIONS_LIFECYCLE_REVIEW.md` | IN1–IN5 |
| Streaming | `docs/STREAMING_LIFECYCLE_REVIEW.md` | ST1–ST6 |
| Community | `docs/COMMUNITY_LIFECYCLE_REVIEW.md` | CM1–CM5 |

Also relevant: `docs/EVENT_LIFECYCLE_REVIEW.md` (+ `docs/TODO_EVENT_LIFECYCLE_REMAINING.md`)
and `docs/ACTIVITIES_AND_MEMBERSHIP_JOURNEY_ASSESSMENT.md`.

**Coverage note (2026-09-15): the lifecycle-review phase is now COMPLETE.** All
lifecycle-bearing modules have been reviewed — the original eight, plus Courses,
Meetings, Events (theme pass), and the four formerly-uncovered modules **Identity,
Integrations, Streaming, Community**. The only modules without a dedicated review
are pure infrastructure/support with no independent lifecycle: **Admin** (settings/
config resolver), **Audit** (append-only logger), **Geo** (reference data),
**Reporting** (read-side projections), **Shared** (no services). The Identity
review surfaced the **keystone finding (ID1)**: the Theme B emitter does not exist
yet — it is pulled into Phase 0. No code has been changed; this plan remains the
pre-implementation decision document.

---

## 1. Executive summary

Across eight modules the reviews found the individual services to be, on the
whole, **well engineered in the small** — idempotent write paths, double-entry
ledgers, maker-checker with SoD, versioned rules, race-safe rollups, fail-closed
consent. The defects cluster not inside a service but in the **seams between
lifecycles**: what happens to X when Y is torn down, who runs the queue that X
parks work in, and which routes actually enforce the authority the services
assume upstream.

Three systemic gaps account for the large majority of the HIGH/MED findings:

- **Theme A — Missing/weak authorization on mutating routes.** Seven modules have
  at least one state-changing route that lacks `auth`, `authorize:`, and/or
  `webcsrf`. Severity ranges from CSRF exposure (PATCH configs) to **fully open**
  graph/credit/messaging triggers (Referrals, Notifications).
- **Theme B — Missing lifecycle-cascade signals.** No module reacts when an
  account is deactivated/suspended/merged or a group is archived/dissolved/merged.
  Grants, journeys, sponsorships, commitments, rollups, deliveries and belongings
  all outlive the entity that justified them, because the teardown never fans out.
- **Theme C — Missing scheduled sweeps / queue runners.** Nearly every module
  parks work in a table (deferred sends, pending approvals, held points, stale
  proposals, dormant members, unreconciled money, cold contacts) that has a
  purpose-built status/index — but **no scheduler ever processes it**. The data
  model anticipates a runner that was never built.

There are also **module-local HIGH defects** that are genuine bugs (not theme
instances) and must be fixed regardless — these are called out in §5 and get
their own fast-track phase.

**Recommended shape of the work:** three shared platform capabilities (a
route-filter audit + test harness; a lifecycle-signal bus; a unified scheduled-
sweep runner) plus a short list of module-local bug fixes, delivered in the
phased order in §6. Doing the shared work first means each module's fix becomes
"subscribe / register / gate," not "invent the mechanism again."

---

## 2. Theme A — Authorization on mutating routes

**What the reviews found (by severity of exposure):**

| Finding | Route(s) | Gap | Severity |
| --- | --- | --- | --- |
| **R1** | `POST referrals/sponsorships`, `.../attribute`, `.../links` | **No filter at all** — unauthenticated graph rewrite + conversion-credit minting | HIGH |
| **N5** | `POST notifications/send` | Only rate-limited; trusts caller `user_id` + arbitrary body → phishing/trigger | MED–HIGH |
| **M3** | membership lifecycle routes | Missing `webcsrf` | HIGH |
| **GR1** | `group create` / `move` / `add-member` | No `authorize:`; controller doesn't scope-check | HIGH |
| **AC11** | 7 ACL controllers' mutations | Verify `authorize:` + `_csrf` on every grant mutation | MED |
| **J5** | `POST journey/signals` | No `webcsrf`; scope-param mismatch (`group_id` vs `scope_group_id`) | MED |
| **G6** | ~10 gamification PATCH routes | POSTs carry `webcsrf`, PATCHes don't; one lacks `authorize:` | MED |

**Why it's one problem:** the pattern is identical everywhere — a mutation route
that relies on the service being called only from a trusted place, without the
route itself proving the caller is authenticated, authorized for the capability,
and CSRF-protected. The services are (mostly) written to be safe *if reached
legitimately*; the routes don't guarantee legitimacy.

**Shared fix — "route-filter audit + mechanical guard":**

1. **Enumerate** every non-GET route across all modules and classify: does it
   change state? If yes it MUST carry `auth` + an `authorize:<capability>` code +
   the CSRF filter (note the standing correction: the alias is **`_csrf`** in ACL
   contexts / **`webcsrf`** for browser cookie-auth writes — reconcile the two
   and document one rule).
2. **Add a route-filter test** (one test, data-driven over the route table) that
   asserts every state-changing route has the required filters. This converts the
   whole class from "spot it in review" to "CI fails on regression" — and is the
   single highest-leverage artifact in this plan.
3. **Fix the offenders** in exposure order: R1 (fully open) → N5 → GR1 → M3 →
   J5 → AC11 → G6.
4. **Public endpoints stay public deliberately** (donor `causes/*/contribute`,
   referral landing `r/{code}`, signed `webhooks/payments/*`) — the test's
   allowlist encodes that intent so "unauthenticated" is a recorded decision, not
   an oversight.

**Definition of done:** the route-filter test is green with an explicit
allowlist; R1/N5 are closed; every state-changing route is gated or allowlisted.

---

## 3. Theme B — Lifecycle-cascade signals

**What the reviews found:** nothing fans out when a person or group is torn down.

| Trigger | Consumers that DON'T react today | Findings |
| --- | --- | --- |
| **Account deactivate / suspend** | ACL grants stay active; journeys stay active; sponsorships keep routing; giving commitments keep reminding; notification deliveries keep targeting | M1, AC3, J4, R7, C8, N7 |
| **Person merge** | Loser's belongings, grants, journeys, sponsorship edges, prefs/suppressions all abandoned (not reassigned to survivor) | M2, AC3, J4, R5/R7, N7 |
| **Group archive / dissolve / merge** | `group_closure` never pruned; resolvers ignore `groups.status`; scope grants, rollups, causes, journeys keep referencing the dead node | GR2, GR3, AC9, G5, C6, J4 |

**Why it's one problem:** every one of these is "entity X disappeared; dependent Y
still points at it." Each module could poll for orphans, but that's N pollers and
N race windows. The clean shape is a **single teardown event** that the owning
module emits once and every dependent subscribes to — the same outbox/JobRouter
mechanism the platform already uses for `contribution.succeeded`,
`course.completed`, etc.

**Shared fix — "lifecycle-signal bus":**

1. **Define canonical teardown events** on the existing outbox:
   `account.deactivated`, `account.suspended`, `account.merged{loser,survivor}`,
   `group.archived`, `group.dissolved`, `group.merged{from,into}`.
2. **Emit them once** from the owning services (Identity account lifecycle;
   Group lifecycle) — this is the prerequisite half; several reviews note the
   consumer fix is blocked until the emitter exists.
   **CONFIRMED by the Identity review (ID1): `AccountLifecycleService.transition`
   today stages NO outbox event at all** — it updates `users`, writes an
   `account_state_transitions` row, revokes sessions/tokens, and audits, but emits
   nothing other modules can subscribe to. So the entire Theme B consumer set
   (M1/M2, AC3, J4, C8, R7, N7, CO6, MT5, E-B2, plus Integrations IN-teardown and
   Community CM2) is currently blocked on a **non-existent emitter**. ID1 is a
   small, local, transactional change (stage the events inside the existing
   transaction) and is **pulled forward to Phase 0/early-Phase-1** so the source
   exists and is asserted before any consumer is built. Add `account.anonymized`
   to the set (Identity ID3: PII scrub must fan out). Also add the provider-side
   `connection.disabled/revoked` (Integrations IN1) and `stream.ended`
   (Streaming ST3) to the teardown-signal family.
   **Also note (ID2): `approveMerge` retires the duplicate but moves NO
   belongings** — the merge consumers must do the re-point, through the authorized
   path, on `account.merged`.
3. **Subscribe each dependent** with a documented policy per module:
   - *Grants (AC):* cascade-revoke role assignments + outbound delegations; close
     break-glass. (AC3)
   - *Journeys (J):* pause on deactivate; reassign on merge; archive group-context
     journeys. (J4)
   - *Sponsorships (R):* stop auto-linking to a gone sponsor; re-point loser's
     edges to survivor via the reassignment path. (R7)
   - *Commitments (C):* pause/cancel a deactivated member's active commitments.
     (C8)
   - *Notifications (N):* cancel pending/deferred; merge prefs/suppressions/
     verified channels (most-restrictive wins). (N7)
   - *Belongings (M):* the membership cascade M1/M2 describe.
   - *Enrollments (Courses):* withdraw a deactivated learner's active
     enrollments; re-point a merged learner's enrollments/completions to the
     survivor; block new completions on an archived course. (CO6)
   - *Meetings:* on `event.cancelled` cancel the linked meeting + expire join
     tokens; on account teardown revoke participant grants / re-point evidence.
     (MT5, and E-B1 adds `event.cancelled` as a Meetings-subscribed signal)
   - *Registrations/orders (Events):* release a deactivated registrant's
     seats/holds; re-point a merged person's registrations/orders/attendance to
     the survivor through the authorized path. (E-B2)

   Add **`event.cancelled`** to the canonical teardown set (Events already emits a
   real cancel fan-out — roster notices + refund requests — so this is extending
   an existing fan-out, not building one; Meetings subscribes). Events'
   `EventService::cancel` is the **reference implementation** for a guarded,
   idempotent, maker-checker teardown cascade the other modules should copy.
4. **Fix the group-side foundation first (GR2/GR3):** prune `group_closure` on
   dissolve/merge and make `GroupScopeResolver`/`EffectiveConfigResolver`/
   `RollupService` status-aware. Until this lands, AC9/G5/C6/J4's group half can't
   be correct because the closure still resolves dead nodes.

**Merge is not re-pointing `subject_id` silently:** several reviews stress that a
merge must move belongings/grants **through the authorized path** (maker-checker
where the originals had it), never a blind `UPDATE ... SET user_id = survivor`,
which would launder authority onto a different identity.

**Definition of done:** the six teardown events are emitted; each dependent module
has a subscriber + a test proving the cascade; `group_closure` is pruned and
resolvers filter `groups.status`.

---

## 4. Theme C — Scheduled sweeps / queue runners

**What the reviews found:** work parked in a table with no processor.

| Parked work | Table / column | Missing runner | Findings |
| --- | --- | --- | --- |
| Deferred notifications | `notification_deliveries.status='deferred'`, `defer_until` | release-deferred | **N1** |
| Campaign fan-out | `notification_campaigns.status='queued'` | run-campaigns | **N2** |
| Digest batching | `notification_preferences.digest_frequency` | build-digest | N4 |
| Failed season rollover | `season_transitions.status='failed'` | retry/reclaim | **G1** |
| Held points / fraud reviews | `point_ledger.state='held'`, `fraud_reviews.status='open'` | age/escalate | G2 |
| Stale pending access requests | `access_requests.status='pending'`, `ar_expiry_idx` | expire + remind | AC4, AC5 |
| Delegation expiry | `delegations` (`dl_expiry_idx`) | expire-lapsed | AC1 |
| Journey involvement staleness | `member_involvement_snapshots.computed_at` | recompute | **J1** |
| Stale journey proposals | `journey_stage_proposals` | supersede/age | J6 |
| Reconciliation / dead-letter | `reconciliation_cases`, `webhook_inbox` (quarantined/failed) | reconcile | **C4**, C5 |
| Cold-contact / follow-up-due | `prospects.next_follow_up_at`, `temperature` | remind + decay | R4 |
| Member dormancy / verify-expiry | membership status, verification | dormancy sweep | M5, M6 |
| Meeting attendance evidence | `meeting_attendance_evidence.applied=0` never processed | evidence→attendance reconciler | MT2 |
| Ticket-hold expiry | stale `ticket_holds` (`held` past `expires_at`) never released | `events.expire-holds` ✅ | E-C1 |
| Waitlist promotion on capacity raise | `capacity` increased but waitlist not promoted | ride capacity-change seam | E-C2 / G8 |
| Credential version retirement | rotated secrets stay decryptable forever; no active-version pointer | `integrations.prune-retired-credentials` ✅ | IN3 |
| Auth token expiry | credential-setup / refresh tokens lazy-expire, never pruned | token prune (join `SessionPruneCommand`) | ID4 |
| Stream relay health | heartbeats ingested, no processor; stuck-`live` streams | relay/stream health sweep | ST4 |
| Content retention | soft-`deleted` posts linger indefinitely | retention purge | CM4 |

**Why it's one problem:** twelve places independently expect a periodic job that
was never written. Each already has the *idempotent operation* to run (e.g.
`recomputeContext`, `expireLapsed`, `reverse`) — what's missing is a **scheduler
that calls them**. Building twelve cron entries invites twelve inconsistent
retry/locking/observability stories.

**Shared fix — "unified sweep runner":**

1. **One scheduler** (spark task list / cron manifest) that invokes each module's
   idempotent sweep on a cadence, with a shared contract: bounded batch,
   idempotent, per-org scoping, structured "swept N" logging, and a lock so two
   workers don't overlap.
2. **Each module contributes a sweep command** implementing that contract. Many
   of the underlying operations already exist and just need a command +
   registration (AC's `AccessExpireCommand` is the template; Journey's
   `recomputeContext`, Contributions' `reverse`/ledger checks, Gamification's
   rollover retry all exist as service methods).
3. **Standardize the "queue with SLA" shape** so approvals/reviews/proposals get
   uniform remind→escalate→timeout behaviour (AC5, G2, J6, R4, N-digest all want
   the same thing).
4. **Observability:** each sweep emits a metric/heartbeat so a *stuck* runner is
   itself detectable — otherwise we've rebuilt the same silent-failure mode.

**Definition of done:** one scheduler manifest; every table in the matrix above
has a registered, tested, idempotent sweep with heartbeat logging.

---

## 5. Module-local HIGH defects (genuine bugs — fast-track, independent of themes)

These are not theme instances; they are correctness bugs that should be fixed on
their own track, fastest first because several are silent data/money corruption:

| Finding | Defect | Impact |
| --- | --- | --- |
| **C1** | Refund of a **manual** contribution posts no compensating ledger entry (source_ref format mismatch `manual:` vs `contribution:`) | Ledger silently out of balance |
| **C2** | `refund.succeeded` / `charge.dispute.created` webhooks are KNOWN types but unhandled → acked + dropped | Provider refunds & chargebacks lost; `reversed`/`disputed` never set |
| **C3** | No cumulative-refund guard (validates vs original only) | Partial refunds can exceed the original |
| **R2** | `attributeConversion` marks **all** a referrer's captured prospects converted (unscoped UPDATE) | Funnel data corrupted; leads drop out of follow-up |
| **R3** | `join_group` decision has no side effects (no membership, no journey signal, no user link) | The conversion→integration hinge is a dead record |
| **AC2** | Revoking/expiring a role/request doesn't cascade to delegations derived from it (`parent_id=NULL`) | Privilege retained after source authority lost |
| **G3** | `clearHeld` skips the achievement eval `approveAward` runs; records no actor | Same held→final action yields different outcomes |
| **CO1** | `completeLesson` has no ownership check (IDOR) — any authed user can complete lessons on ANY enrollment | Forged course completions → points/badge/journey-advance for another user |
| **CO2** | `enroll` ignores `enrollment_policy` (invite/approval), `prerequisites`, and course `status` | Invite/approval courses fail **open**; prereqs/draft/archived bypassed |

Note the overlap: **C2/C3/C1 are also why Theme-C reconciliation (C4) matters** —
reconciliation is the safety net that would *detect* these in production. Fix the
bugs and build the detector.

---

## 6. Phased roadmap

Ordering principle: **shared enabler before its dependents; silent
data/money/message corruption before latent risk; tests before the fix they
guard.**

**Phase 0 — Safety nets & bug fixes (fast-track, no new infra needed)**

_Progress log (2026-09-15): baseline suite 163 files / 12,015 assertions green.
After the shipped items below: **207 files / 13,272 assertions green.** Each fix
landed with a dedicated standalone test; no regressions. **Shipped: all Phase-0
module-local HIGHs; the Theme-A route-filter harness + three fully-open route
HIGHs (R1, N5, GR1-open); the Phase-1 unified sweep-runner foundation driving 6
sweeps; SIX Theme-B account-teardown consumers (AC3 grants, C8 commitments, J4
journeys, N7 notifications, R7 sponsorships, CO6 enrollments) wired end to end
through a fault-isolated JobRouter fan-out; the group-side foundation
GR2 (closure pruning on dissolve/merge) + GR3 (status-aware scope resolution);
AND the group teardown EMITTER (GR-emit) + its first group-half consumer AC9
(revoke group-scoped grants/delegations/break-glass/requests + multi-group-set
prune).**_

- ✅ **SHIPPED — Courses access-control bugs CO1 + CO2.** `completeLesson` now takes
  the authenticated `actingUserId` and denies any enrollment the caller doesn't own
  (IDOR closed); it also refuses progress on non-active enrollments. `enroll` is now
  fail-closed: course must be published + in-org, `prerequisites` must be completed,
  and `enrollment_policy` decides state (open→active, approval→pending +
  `approveEnrollment`, invite→denied unless an authorized assisted caller). Test:
  `app/Modules/Courses/Services/tests/enrollment_gating_test.php` (19 assertions).
- ✅ **SHIPPED — Identity ID1 (the Theme B emitter).**
  `AccountLifecycleService.transition` now stages canonical teardown events on the
  outbox, INSIDE the state-change transaction (exactly-once): `account.deactivated`,
  `account.suspended`, `account.anonymized`, and `account.merged{loser,survivor}`.
  Non-teardown transitions (active/locked), no-change, illegal, and rolled-back
  transitions emit nothing. OutboxService injected via the factory. Test:
  `app/Modules/Identity/Services/tests/account_teardown_events_test.php` (22
  assertions). **This unblocks the entire Phase 2 consumer set.**
- ✅ **SHIPPED — Streaming ST1 (live-only giving gate).** `give()` now requires the
  stream to exist, be in-org, and be `live` before creating a Contributions intent —
  no money captured on draft/ended streams. Test:
  `app/Modules/Streaming/Services/tests/stream_giving_live_gate_test.php` (8
  assertions).
- ✅ **SHIPPED — Contributions money-path bugs C1 + C2 + C3.** `RefundService` now
  posts a compensating ledger entry on every refund: full refunds use
  `LedgerService::reverseEntry`, partials use a new `postProportionalReversal`
  that scales each original `journal_lines` row by `refundAmount/originalTotal`,
  swaps debit/credit direction, and applies a last-line rounding nudge so the
  entry balances (C1). The webhook handler now processes the known-but-dropped
  `refund.succeeded` / `charge.dispute.created` types, flipping contributions to
  `reversed` / `disputed` (C2). Refund validation now guards cumulative refunds
  via `executedRefunds()` SUM, not just the original amount, so partials can't
  exceed the original (C3, `REFUND_EXCEEDS_REMAINING`). Test:
  `app/Modules/Contributions/Services/tests/refund_money_path_test.php` (21
  assertions).
- ✅ **SHIPPED — Referrals R2 (conversion scope).** `attributeConversion` no longer
  runs an unscoped `UPDATE prospects … WHERE referrer_id=X AND state=captured`
  (which flipped ALL a referrer's captured prospects). It now targets exactly the
  prospect who converted — priority `prospect_id` opt → `email_hash`(+`link_id`)
  opt → default `linked_user_id == convertedUserId`; an unmatched conversion
  changes NOTHING rather than everything. Controller threads `prospect_id` +
  `email_hash` through. Test:
  `app/Modules/Referrals/Services/tests/conversion_scope_test.php` (12 assertions).
- ✅ **SHIPPED — AccessControl AC2 (delegation provenance + cascade).** Delegations
  derived from a role_assignment or access_request had `parent_id = NULL`, so
  `revoke()`'s parent-walking cascade never reached them → privilege retained
  after the source authority was lost. New migration `…000074` adds
  `source_grant_type` + `source_grant_id` to `delegations`; `authoritiesFor`/
  `grant` stamp the ROOT grant on the whole sub-delegation chain (children inherit
  the parent's root). `RoleAssignmentService::revoke` and
  `AccessRequestService::revoke` now call `DelegationService::revokeBySourceGrant`
  (one flat `WHERE source_grant_id` flips the entire subtree), and the
  `expireLapsed` sweep calls `expireOrphanedBySource` so lapsed (not just
  explicitly revoked) grants also tear down their delegations. Test:
  `app/Modules/AccessControl/Services/tests/delegation_source_cascade_test.php`
  (20 assertions).
- ✅ **SHIPPED — Referrals R3 (`join_group` side effects).** A recorded
  `join_group` decision was a dead log row — no membership, no journey signal, no
  stage move. `recordDecision` now (a) ensures the contact's linked platform user,
  (b) creates/requests a `group_members` row in the `target_group_id` under the
  group's join policy (`open` → active, otherwise pending), and (c) emits a
  `journey.signal.group.joined` signal (org-wide journey context, scoped to the
  originating group so a group-scoped leader's rule fires), then advances the
  prospect's mirrored `journey_stage` off `prospect`. All three run through the
  platform's OWN Groups/Journey services via two new narrow ports
  (`GroupMembershipPort` + `JourneySignalPort`, prod adapters wired in the
  factory) — no fork. Side effects are best-effort/non-fatal: the append-only
  decision history is always preserved, and older callers without the ports wired
  still record decisions. Test:
  `app/Modules/Referrals/Services/tests/join_group_side_effects_test.php` (29
  assertions).
- ✅ **SHIPPED — Gamification G3 (held→final door parity).** The two "held →
  final" doors were asymmetric: `approveAward` ran achievement evaluation and
  recorded the approver, while `clearHeld` (fraud-clear) did neither — so an
  identical held award released via the fraud path could be denied an achievement
  the approval path would have unlocked, with a weaker audit trail. Both doors now
  call one shared `finalizeEntry` (rollup + achievement eval), so everything
  downstream of "points are now spendable" is identical; `clearHeld` gained an
  `actorId` param and both doors (plus `rejectAward`) now stamp `resolved_by` on
  the `fraud_reviews` row. New migration `…000075` adds `fraud_reviews.resolved_by`.
  Test (REAL PointsEngine + AchievementService over an in-memory DB):
  `app/Modules/Gamification/Services/tests/held_final_parity_test.php` (17
  assertions) proves both doors unlock the same achievement and record an actor.
- ✅ **SHIPPED — Identity ID2 (merge is now honest about belongings).**
  `approveMerge` does the governance correctly (maker-checker SoD via the PDP,
  terminal-state guard) and retires the duplicate to `merged` (merged_into_id →
  primary) while emitting `account.merged` — but it does NOT re-point the
  duplicate's belongings, because that is each owning module's job on the
  `account.merged` event, through its authorized write path (a blind
  `UPDATE SET user_id=survivor` would launder authority/PII), and it lands in
  **Phase 2** with the rest of the Theme-B consumer set. The Phase-0 correctness
  slice (per the review) is honesty: `approveMerge` now reports
  `belongings_repointed = false` + a `belongings_note` +
  `merged_event_staged` in the response, and stamps `belongings_repointed = false`
  in the audit metadata, so an operator who approved the merge is never misled
  into thinking history moved. Test:
  `app/Modules/Identity/Services/tests/merge_belongings_honesty_test.php` (21
  assertions) — also covers SoD self-approval denial, non-pending refusal, missing
  request, and PDP denial. **The actual cross-module re-point remains Phase 2.**
- ✅ **SHIPPED — Theme A route-filter guard harness + the three fully-open route
  HIGHs (R1, N5, GR1-open).** The single highest-leverage artifact: a data-driven
  test (`app/Modules/Shared/Support/tests/route_filter_guard_test.php`, 16
  assertions) parses the canonical route table the same way the OpenAPI generator
  does (group prefix + inherited group filters) and enforces two invariants over
  every non-GET route — (1) it carries `auth`/`authorize:` unless on an explicit
  PUBLIC allowlist, and (2) it carries `webcsrf` unless on the PUBLIC or
  CSRF-EXEMPT allowlist. Both allowlists are CLOSED and rot-checked (a listed
  route that no longer exists also fails), so "unauthenticated"/"CSRF-exempt" is a
  recorded decision and any NEW ungated state-changer fails CI. Fixes landed with
  it, in exposure order:
  - **R1** — `referrals/links|sponsorships|attribute` were UNAUTHENTICATED (no
    filter at all): open cloaked-link creation, sponsorship-graph rewrite, and
    conversion-credit minting. Now `auth` + capability (`referral.link.create` /
    `sponsor.reassign.approve` / `admin.manage` for the crediting hook) + `webcsrf`.
  - **N5** — `notifications/send` was rate-limited only (unauthenticated, trusting
    caller `user_id` + arbitrary body). Now `auth` + `notification.send` +
    `webcsrf`, keeping the broadcast rate-limit.
  - **GR1 (open slice)** — `groups/(:segment)/members` (add-member) had no filter.
    Now `auth` + `group.change.approve` + `webcsrf`; per-group scope still enforced
    in the service. (The remaining GR1 controller scope-check work stays Phase 3.)
  Regenerated `public/openapi.json` (security/responses shift with the new
  filters); `openapi_fresh_test` green.
- ✅ **Phase-0 module-local HIGHs COMPLETE** — CO1, CO2, ID1, ST1, C1, C2, C3, R2,
  AC2, R3, G3, ID2 all shipped suite-green.
- ✅ **SHIPPED — Phase 1 unified sweep-runner FOUNDATION (Theme C).** The single
  scheduler the reviews called for, replacing ~18 bespoke cron entries with one
  retry/locking/observability story. New shared framework under
  `app/Modules/Shared/Sweep/`: `SweepContract` (key + description + idempotent,
  bounded, per-org `run()`), `SweepResult` (uniform swept/details/skip/fail),
  `SweepRegistry` (unique-key catalogue, fail-fast on dup), `SweepLock` +
  `MySqlSweepLock` (GET_LOCK advisory lock, connection-scoped auto-release,
  fail-closed), and `SweepRunner` (per-sweep lock → skip-on-contention, per-sweep
  error isolation so one throw can't starve the batch, structured heartbeat line
  per sweep). Driven by `php spark sweep:run [--org] [--only=k,k] [--list]
  [--grace] [--hours] [--limit]`. Wired via `SharedServices::sweepRegistry()` /
  `sweepRunner()`. **4 EXISTING idempotent operations folded in as the first
  sweeps** (no forked logic): `acl.expire` (AccessRequest+BreakGlass expireLapsed
  — AC1/AC4/AC5), `events.close-due` (EventCloser — L3), `events.reminders-due`
  (EventNotifier — G3-events), `journey.involvement-recompute` (InvolvementService
  — J1). Two more folded in afterward: `contributions.commitment-due`
  (CommitmentService::processDue — due-giving reminders) and
  `identity.session-prune` (SessionService::prune — expire/prune sessions, ID4).
  **6 sweeps now registered.** Tests:
  `app/Modules/Shared/Sweep/tests/sweep_runner_test.php` (29 — lock skip, error
  isolation, aggregation, heartbeat, release-on-throw) +
  `sweep_registration_test.php` (26 — adapters satisfy the contract, unique-key
  manifest).
  - **⚠️ G1 correction:** `gamification.season-rollover` was drafted as a sweep but
    REMOVED before shipping — `SeasonService::rollover()` has no `ends_at`/boundary
    check and unconditionally closes any `active` season, so cadencing it would
    prematurely end every org's live season. G1's real need is a *reclaim of
    FAILED `season_transitions`* (a distinct, new operation); it stays deferred
    until that operation exists, then plugs into the same contract. Recorded so
    nobody re-adds the unsafe version.
  - Remaining sweep OPERATIONS that still need new code (ST4, CM4) plug into
    this same contract next. (M5/M6 dormancy + E-C1 holds + IN3 credential
    retire/prune now SHIPPED below.)
- ✅ **SHIPPED — MT2 meeting evidence → attendance reconciler (7th sweep).**
  `meeting_attendance_evidence.applied` was never flipped — provider presence was
  captured but never became platform attendance. New
  `MeetingService::reconcileEvidence(orgId, limit)` (bounded, idempotent via the
  `applied=0` watermark) joins evidence→meeting→event and, for
  `attendance_policy='streaming'` events, records a `streaming` attendance through
  the Events check-in path (new `CheckinService::checkInFromMeetingEvidence()`),
  so awards / journey signals / group attribution / the one-active-attendance
  UNIQUE key all apply and a duplicate evidence row dedupes idempotently.
  `checkin`/`manual`-policy evidence is marked advisory (applied, no attendance);
  rows with no linked user/event stay UNAPPLIED for a later pass; a port failure
  leaves the row for retry. Cross-module write goes through a narrow
  `EventAttendancePort`/`EventAttendanceAdapter` (Events stays the sole
  `event_attendance` writer — no forked path). Registered as sweep
  `meetings.evidence-reconcile` (**7 sweeps now**). Tests:
  `app/Modules/Meetings/Services/tests/evidence_reconcile_test.php` (20) +
  extended `sweep_registration_test.php` (30).
- ✅ **SHIPPED — N1 release-deferred notifications (8th sweep) + N2 queued-campaign
  fan-out (9th sweep).**
  - **N1:** the RetentionPolicyGate could DEFER a delivery (quiet-hours /
    frequency-cap) but its computed `defer_until` was never persisted, so deferred
    rows sat forever with nothing watching the clock. Fix: migration
    `2026-09-15-000072_AddDeliveryDeferUntil` adds `defer_until DATETIME(6)` +
    `nd_defer_idx (status, defer_until)`; `NotificationService::send()` now
    persists `defer_until` on a deferred row; new
    `NotificationService::releaseDeferred(orgId, limit)` (bounded, idempotent —
    `status='deferred' AND defer_until <= now`) flips due rows to `queued` and
    stages the same `notification.dispatch` outbox job a fresh send uses. Legacy
    NULL-`defer_until` rows are skipped, not blindly released. Registered as sweep
    `notifications.release-deferred`.
  - **N2:** nothing consumed a campaign's `queued` status, so an
    approved+queued campaign never reached its audience. New
    `CampaignService::fanOutQueued()` / `fanOutAllQueued()` resolve the audience
    via a narrow `CampaignAudiencePort` (`CampaignAudienceResolver`: group +
    descendants via `group_closure`, else whole org) and issue one gated
    `send()` per recipient with a stable `(campaign,user)` dedupe key — so a
    crash/replay never double-sends — then flip the campaign to `sent`.
    Per-recipient opt-out / quiet-hours still enforced by the gate (a deferred
    one is later picked up by N1). SoD (`approved_by ≠ requested_by`) already
    enforced at `approve()`. Registered as sweep
    `notifications.run-queued-campaigns` (**9 sweeps now**). Tests:
    `app/Modules/Notifications/Services/tests/release_deferred_test.php` (18) +
    `.../campaign_fanout_test.php` (16) + extended `sweep_registration_test.php`
    (38).
- ✅ **SHIPPED — G1 season-rollover reclaim (10th sweep).** A `failed` rollover,
  or a `running` transition row abandoned by a crashed worker, used to pin the
  UNIQUE `transition_key` for that year boundary FOREVER — every later attempt
  returned `ROLLOVER_IN_PROGRESS` and the annual season could never close, with no
  recovery path. Since the rollover transaction is atomic (nothing partial commits
  on failure), retry is safe; the guard just refused it. Fix: migration
  `2026-09-15-000077_AddSeasonTransitionLease` adds `updated_at` (heartbeat) +
  `attempts` + `st_reclaim_idx (status, updated_at)`; `SeasonService::rollover()`
  now RECLAIMS a non-completed row — always for `failed`, and for `running` only
  once its heartbeat is older than a 30-min lease (a crashed worker) or when an
  operator passes `--force` — re-claiming the SAME row (status-guarded against a
  racing reclaimer, `attempts++`) and re-running the shared `runTransition()`
  body. New `reclaimStuckRollovers(orgId, limit)` (bounded, idempotent) sweeps
  failed + stale-running rows and skips fresh-lease ones; `--force` added to
  `SeasonRolloverCommand`. Registered as sweep `gamification.reclaim-rollovers`
  (**10 sweeps now**). Tests:
  `app/Modules/Gamification/Services/tests/rollover_reclaim_test.php` (20 — fresh
  completes, already-completed dedupe, failed reclaimed, fresh-lease blocks,
  stale-lease self-heals, --force, failing-tx→failed→retry, sweep reclaims 2 +
  skips fresh + idempotent re-run) + extended `sweep_registration_test.php` (42).
- ✅ **SHIPPED — C4/C5 contributions reconciliation + webhook dead-letter (11th
  sweep).** `reconciliation_cases` was defined but never written or read — the
  safety net for C1 (out-of-balance ledger), C2 (dropped refunds/disputes) and C3
  (over-refund) drift did not exist, and `quarantined`/`failed` webhooks were a
  dead-letter queue with no processor. New `ReconciliationService::reconcile()`
  (bounded, idempotent) runs three detectors per org: **missing_ledger**
  (`succeeded` contributions with no `contribution:<id>` journal entry),
  **amount_mismatch** (ledger debits ≠ contribution amount), each opening a case
  keyed by a stable `dedupe_ref`; and it **auto-resolves** open cases whose
  discrepancy has since cleared, so the surfaced open-case count stays truthful.
  **C5:** it re-queues `quarantined` webhooks that are now verified AND of a known
  type (flips them to `received` for the normal handler) and reports the count
  still stuck in `quarantined`/`failed`. Migration
  `2026-09-15-000078_AddReconciliationDedupeRef` adds `dedupe_ref` +
  UNIQUE `rc_dedupe_uq (organization_id, dedupe_ref)` for idempotent
  case-opening; `WebhookInboxService::KNOWN_TYPES` promoted to `public` as the
  shared re-queue contract. Read surface `openCases()`/`openCaseCount()` for the
  admin dashboard. Registered as sweep `contributions.reconcile-ledger`
  (**11 sweeps now**). Tests:
  `app/Modules/Contributions/Services/tests/reconciliation_test.php` (17 —
  missing/mismatch open, healthy opens none, idempotent re-run, auto-resolve after
  ledger posted, dead-letter requeue vs stuck) + extended
  `sweep_registration_test.php` (46).
- ✅ **SHIPPED — R4 outreach follow-up + temperature decay (12th sweep).** The
  `prospects` triage columns (`next_follow_up_at`, `temperature`,
  `last_contacted_at`) had purpose-built indexes and dashboard tiles but nothing
  acted on them: a due follow-up sat unremarked and a contact that went cold
  stayed whatever temperature a human last set (going cold produced no event). New
  `ContactBookService::processFollowUps(orgId, limit)` (bounded, idempotent) does
  two jobs: (1) reminds each due contact's OWNER (`next_follow_up_at <= now`)
  exactly once per due date through the gated NotificationService (dedupe key
  includes the due date — the CommitmentService pattern), skipping ownerless rows;
  (2) decays temperature one step (hot→warm after 14d, warm→cold after 30d, cold
  terminal) measured from `last_contacted_at` (fallback `created_at`), guarded on
  the observed temperature so a concurrent human update isn't clobbered. Reminders
  flow through a narrow `FollowUpNotifierPort`/`FollowUpNotifierAdapter` (no direct
  Notifications coupling). Registered as sweep `referrals.follow-up-decay`
  (**12 sweeps now**). Tests:
  `app/Modules/Referrals/Services/tests/follow_up_decay_test.php` (13 — due+owner
  reminded once, no-owner/not-due skipped, hot→warm/warm→cold thresholds, fresh
  contact stays hot, cold terminal, org scoping, idempotent re-run) + extended
  `sweep_registration_test.php` (50).
- ✅ **SHIPPED — M5 member dormancy (13th sweep) + M6 verify-expiry (14th sweep).**
  Both config-gated (hierarchical group config, DEFAULT OFF), resource-light,
  idempotent — the membership analogues of the L3 event close-automation sweep.
  - **M5:** there was no dormancy/lapsed model and no runner — a member who
    stopped participating stayed `active` and the involvement `cold` band was a
    read-only signal. New `InvolvementService::processDormancy()` reads the
    ALREADY-materialised involvement snapshots (no per-member recompute) and flips
    a new soft, reversible `member_journeys.dormancy_state` axis: cold + stale
    (last activity older than `journey.dormancy.after_days`, default 2× window,
    floored 60d) → `dormant` (stamps `dormant_since`); warm/hot or recent activity
    → re-engaged (`active`, watermark cleared). The hard `status` lifecycle is
    untouched. Migration `2026-09-15-000079_AddJourneyDormancy` (dormancy_state +
    dormant_since + `mj_dormancy_idx`). Sweep `journey.mark-dormant`. Test
    `dormancy_sweep_test.php` (14).
  - **M6:** `pending_verification` was a real starting state but nothing nudged or
    expired it. New `AccountLifecycleService::processVerifyExpiry()` reminds
    accounts past `remind_after_days` (deduped per round via a `verify_reminded_at`
    watermark + notification dedupe key, cadence-limited by `remind_every_days`)
    and deactivates accounts past `expire_after_days` (default 30) through the
    normal audited transition path (reason `verify_expired`, SYSTEM authority).
    Migration `2026-09-15-000080_AddVerifyReminderWatermark` (verify_reminded_at +
    verify_reminder_count + `users_status_created_idx`). Config gate +
    verify-reminder flow via narrow `LifecycleConfigPort`/`VerifyReminderPort`
    (adapters over EffectiveConfigResolver + the gated NotificationService). Sweep
    `identity.verify-expiry`. Test `verify_expiry_test.php` (14). Both extended
    `sweep_registration_test.php` (**14 sweeps**, 58).
- ✅ **SHIPPED — E-C1 ticket-hold expiry (15th sweep).** Pure inventory hygiene,
  the seats-side analogue of session-prune. `ticket_holds` are short-lived atomic
  inventory reservations, but the checkout/hold paths only expired stale holds
  LAZILY — `expireStaleHolds($eventId)` fires per-event only when SOMEONE attempts
  a fresh hold on that same event. A quiet event therefore accumulates `held` rows
  past their `expires_at` forever: those seats never re-enter capacity maths, and
  the `th_active_uq (event_id, user_id, status)` UNIQUE key wedges the buyer out of
  ever holding again. New `RegistrationService::processExpiredHolds(?org, limit)`
  is the background equivalent — bounded batch, org-scoped or all-orgs, performing
  the same idempotent transition (`held` + `expires_at <= now` → `expired`) with
  status+expiry re-asserted inside the UPDATE so a hold consumed/refreshed mid-pass
  is never clobbered. Capacity maths already ignore expired holds, so this only
  cleans state — it can never over- or under-count a seat; a second pass releases
  0. No config gate, no notifications. Sweep `events.expire-holds`. Test
  `app/Modules/Events/Services/tests/expire_holds_test.php` (15 — stale released,
  live/consumed/expired/released untouched, org scope + all-orgs, row-limit,
  accurate counts, idempotent) + extended `sweep_registration_test.php`
  (**15 sweeps**, 62).
- ✅ **SHIPPED — IN3 credential-version retire + prune (16th sweep).** Security
  hygiene: `CredentialVault::put()` versioned each slot (v1, v2, …) but rotation
  kept ALL historical versions active and decryptable forever, with no
  active-version pointer — after a compromise-driven rotation the leaked secret
  stayed live in the vault, and a specific leaked version could not be
  pinned/rolled back/disabled. Now:
  - Migration `2026-09-15-000081_AddCredentialVersionRetirement` adds
    `connection_credentials.status` (active|retired) + `retired_at` + index
    `cc_status_retired_idx (status, retired_at)`, and BACKFILLS every existing slot
    so only its MAX version stays active (older versions retired at their
    created_at) — the one-active invariant holds immediately. Idempotent.
  - `CredentialVault::put()` now runs in a transaction: it RETIRES the prior
    active version (stamps `retired_at`) before inserting the new active version,
    so a rotation immediately supersedes the old secret. `useSecret()` pins to the
    ACTIVE version (a retired/leaked version is never decrypted for live ops), with
    a defensive fall back to max-version only if no row carries the marker.
  - New `CredentialVault::pruneRetired(?conn, graceDays=30, limit)` — bounded batch
    that hard-DELETEs retired versions past their grace window; re-asserts
    status+grace in the DELETE so a version that became active mid-pass is never
    deleted, and NEVER touches an active version. Idempotent.
  - Sweep `integrations.prune-retired-credentials` (pure security hygiene — no
    config gate, no notifications; mirrors session-prune). Credentials are keyed by
    connection, so the sweep scopes to a connection only when one is passed, else
    prunes platform-wide.
  - This is also the first `Integrations/Services/tests/` file (starts closing the
    IN5 "no lifecycle tests" gap). Test
    `app/Modules/Integrations/Services/tests/credential_retirement_test.php` (23 —
    rotation retires prior + one-active invariant, useSecret pins active, prune
    respects grace + never touches active, connection scoping + all-orgs,
    idempotent, accurate counts) + extended `sweep_registration_test.php`
    (**16 sweeps**, 66).
- ✅ **SHIPPED — Theme B consumer #1: AC3 grant-teardown cascade, wired end to
  end.** ID1's teardown events (`account.deactivated|suspended|anonymized`, and
  `account.merged`) now fan out through the existing outbox → JobRouter to a new
  `AccessControl\Services\GrantCascadeService::onAccountTornDown()`. It
  cascade-revokes the subject's active `role_assignments`, every active
  `delegation` they received OR granted **including sub-delegation subtrees**
  (bounded BFS over `parent_id`), and expires their active `break_glass_sessions`
  — as SYSTEM authority (no maker-checker; the account is gone), fully audited
  (`acl.grant.cascade_teardown`, actor_type=system), and idempotent (only `active`
  rows, so redelivery is a no-op). **Merge policy (ID2-aligned):** the cascade
  revokes the LOSER's grants and never copies them to the survivor — authority is
  re-established through the authorized grant path, never inherited by a blind
  re-point. JobRouter routes `account.merged` to the loser id only. Tests:
  `app/Modules/AccessControl/Services/tests/grant_cascade_test.php` (23 — subtree
  revoke, bystander isolation, idempotent re-run, system-authority audit, merge =
  loser) + `app/Modules/Shared/Messaging/tests/jobrouter_account_teardown_test.php`
  (23 — every topic routes with reason=topic, merge targets the loser, empty
  payloads short-circuit + ack).
- ✅ **SHIPPED — Theme B consumers #2 (C8) and #3 (J4), plus a fault-isolated
  fan-out.** The JobRouter teardown handler now fans each `account.*` event out
  to every subscribed consumer through `fanOutTeardown()`, where each consumer is
  **fault-ISOLATED** (a throw is logged and the remaining consumers still run —
  proven by a test that makes the grant cascade throw and asserts the commitment
  consumer still fires) and the job is always acked (idempotent consumers make
  redelivery safe).
  - **C8 (commitments)** — new `CommitmentService::cancelActiveForSubject()`
    cancels a torn-down member's ACTIVE recurring commitments so pledges stop
    reminding forever (system authority, no owner check, idempotent). Test:
    `app/Modules/Contributions/Services/tests/commitment_teardown_test.php` (10).
  - **J4 (journeys)** — new `JourneyService::pauseAllForSubject()` (pause a
    gone/frozen person's active journeys so they leave every pipeline/funnel/
    leaderboard) and `reassignForMerge()` (on merge, re-point the loser's journeys
    to the survivor, ARCHIVING any that would collide with a survivor journey in
    the same context — the (org,user,group) unique key — so the survivor's own
    progress wins; never a blind overwrite). JobRouter PAUSES on deactivate/
    suspend/anonymize but REASSIGNS (not pauses) on merge. Test:
    `app/Modules/Journey/Services/tests/journey_teardown_test.php` (18).
  - **N7 (notifications)** — new `NotificationService::onAccountTornDown()` cancels
    the subject's inflight (`queued`/`deferred`) deliveries and inserts a hard
    `do_not_contact` suppression at scope `all` (insert-once) so a gone account is
    never a send target; essential legal/security mail keeps its lawful basis via
    the RetentionPolicyGate. Test:
    `app/Modules/Notifications/Services/tests/notification_teardown_test.php` (15).
  - **R7 (sponsorships)** — new `SponsorshipService::onAccountTornDown()` disables
    the subject's ACTIVE cloaked referral links so a gone/frozen sponsor stops
    auto-linking NEW prospects (`ReferralService::resolve` only returns `active`
    links, so a disabled link resolves to nothing); historical sponsorship edges
    are left intact (attribution is never rewritten). New
    `SponsorshipService::reassignForMerge()` handles the merge half: the loser's
    active DOWNLINE is re-parented to the survivor through the existing
    close-old/open-new `assign()` path (acyclic + single-active guarded; a
    downline member who IS the survivor is skipped, not self-sponsored), the
    loser's OWN active member edge is closed, and the loser's referral links are
    re-pointed to credit the survivor. Non-merge teardown DISABLES; merge
    RE-POINTS (links skipped on the teardown fan-out so a merged referrer's
    still-valuable funnels move rather than die). Tests:
    `app/Modules/Referrals/Services/tests/sponsorship_teardown_test.php` (29).
  - **CO6 (enrollments)** — new `EnrollmentService::withdrawActiveForSubject()`
    withdraws a gone/frozen learner's in-flight (`pending`/`active`) enrollments
    (stamping `withdrawn_at`) so they stop accruing progress or earning completion
    rewards; `completed` enrollments (verified completions + certificates) are
    historical facts and are left intact. New
    `EnrollmentService::reassignForMerge()` handles the merge half: the loser's
    enrollments (and their `course_completions`) are re-pointed to the survivor,
    deduped against courses the survivor is already in (the loser's duplicate row
    is `withdrawn`/superseded, honouring `UNIQUE(course_id,user_id)`). Also closed
    a related gap: `evaluateCompletion`/`overrideCompletion` now refuse to MINT a
    NEW completion on an `archived` course (already-recorded completions still
    honoured). New nullable column via migration
    `2026-09-15-000076_AddEnrollmentWithdrawnAt`. Tests:
    `app/Modules/Courses/Services/tests/enrollment_teardown_test.php` (29).
  Wiring test grown to 85 assertions
  (`app/Modules/Shared/Messaging/tests/jobrouter_account_teardown_test.php`) — now
  covers all six consumers + fault isolation across them.
  - **GR2 (closure pruning)** — `GroupLifecycleService::transition` now prunes
    `group_closure` when a group goes TERMINAL (`dissolved`/`merged`): every row
    mentioning the node as ancestor OR descendant (including its distance-0 self
    row) is deleted, so no resolver keeps resolving a dead node. `archived` is
    reversible and deliberately keeps its closure. On a merge the children are
    re-parented under the survivor first (existing `GroupService::move` rebuilds
    their closure), so only the merged node's own rows are removed. Idempotent.
    Test: `app/Modules/Groups/Services/tests/group_closure_prune_test.php` (14).
  - **GR3 (status-aware resolution)** — `GroupScopeResolver::ancestors()` and
    `descendants()` now drop TERMINAL and (reversible) ARCHIVED groups via a
    shared `withoutDeadGroups()` filter, so scope inheritance, config resolution,
    rollups and rank ladders never inherit FROM or cascade INTO a dead/hidden
    node — and this flows through `chain()`, `grantCoversScoped()` and
    `resolveScopeGroups()`. FAIL-OPEN: when the `groups` table is empty/absent
    (resolver-only fakes) the ids pass through unchanged, so every existing
    caller/test is unaffected. Test:
    `app/Modules/Shared/Support/tests/group_scope_status_aware_test.php` (15).
  With GR2/GR3 landed, the group-half consumers (AC9, G5, C6, J4-group) are
  UNBLOCKED — the closure and resolvers are now honest about dead nodes.
  - **GR-emit (group teardown emitter)** — the group-side analogue of the Identity
    ID1 account emitter. `GroupLifecycleService::transition` now stages canonical
    group lifecycle events on the outbox INSIDE the state-change transaction
    (durable exactly with the transition): `group.archived`, `group.dissolved`,
    and `group.merged` (carrying `from_group_id` + `into_group_id`/
    `survivor_group_id`). `OutboxService` added as an optional ctor dep (wired in
    `Groups\Config\Services::groupLifecycle`). Asserted in
    `app/Modules/Groups/Services/tests/group_closure_prune_test.php` (now 24).
  - **AC9 (group-scoped grant teardown)** — new
    `GrantCascadeService::onGroupTornDown()` revokes authority scoped to a dead
    group: exact-scope `role_assignments`, `delegations` (+ sub-delegation
    subtrees), `break_glass_sessions` (→ expired), and live (`pending`/`approved`)
    `access_requests`; PLUS prunes the dead group from every hand-picked
    `grant_scope_groups` set and revokes any `groups`-mode role_assignment/
    delegation whose set becomes EMPTY. Ancestor `self_and_descendants` grants are
    deliberately left (dissolve is leaf-only; merge re-parents children under the
    survivor). Merge REVOKES the loser group's authority, never copies it to the
    survivor (ID2/AC3 alignment). SYSTEM-authority, audited, idempotent. Test:
    `app/Modules/AccessControl/Services/tests/grant_group_cascade_test.php` (37).
  - **J4-group (group-context journeys)** — new
    `JourneyService::archiveGroupContextJourneys()` archives every `active`/
    `paused` journey whose `group_id` IS the dead group, so those group-scoped
    journeys stop appearing in that branch's pipelines/funnels/leaderboards.
    Org-wide (NULL group) and other groups' journeys are untouched; completed/
    archived rows are left as-is. Deliberately ARCHIVE (history retained), not
    re-point-to-survivor on merge — a member's discipleship stage is
    context-specific and personal, so the member re-enters the survivor context
    via the ordinary open/advance path. SYSTEM authority, idempotent. Tested in
    `app/Modules/Journey/Services/tests/journey_teardown_test.php` (now 28).
  - **C6 (contribution attribution)** — new
    `CauseService::reattributeGroupCauses()` re-homes a dead RECEIVING group's
    causes so giving stops attributing to a phantom node. On MERGE the loser's
    causes move to the SURVIVOR (it inherits the absorbed group's giving history);
    on DISSOLVE they roll UP to the nearest LIVE ancestor (walking
    `groups.parent_id`, skipping any dead/archived link), or to org level (NULL
    `group_id`) when no live ancestor survives — matching the membership-fallback
    → ancestor-rollup attribution rule. A merge whose survivor is itself dead (or
    equals the dead group) falls back to the dissolve/ancestor path. Contribution
    and ledger rows are NEVER rewritten — only the group the cause hangs on — so
    all rollups recompute correctly from live ownership. SYSTEM authority,
    idempotent (once a cause names a live group it no longer matches the dead
    one). To support the ancestor walk, GR-emit now carries the dead group's
    pre-prune `parent_id` in the `group.dissolved`/`group.merged` payloads. Tested
    in `app/Modules/Contributions/Services/tests/cause_reattribution_test.php`
    (21).
  - **G5 (invite-source validity)** — new
    `ContactBookService::onGroupTornDown()` keeps outreach invite SOURCES valid
    when a group dies. A pending contact can be pinned to a group two ways, both
    of which go stale on teardown: (1) a group-context INVITE
    (`prospects.invite_context_type = 'group'` + `invite_context_id`), and (2)
    downline board OWNERSHIP (`assigned_group_id`). On MERGE both re-point to the
    SURVIVOR (an in-flight invite lands in the group that continues). On DISSOLVE
    the invite is FAIL-CLOSED — the stale group context is cleared to NULL so no
    one is admitted against a dead node, forcing a fresh valid invite — while
    board ownership rolls UP to the nearest live ancestor (or org level when none
    survives) so the contact never vanishes from every leader's board. A merge
    whose survivor is itself dead falls back to the dissolve rule. Contacts,
    decisions and follow-up history are never deleted — only the two group
    pointers move. SYSTEM authority, idempotent. Tested in
    `app/Modules/Referrals/Services/tests/contact_group_teardown_test.php` (29).
  - **JobRouter group fan-out** — `group.dissolved`/`group.merged` dispatch to
    `fanOutGroupTeardown` (fault-isolated consumers: `grant-group-cascade`,
    `journey-group-archive`, `cause-reattribution`, `contact-invite-repair`);
    `group.merged` threads the survivor to C6/G5 and targets the FROM/loser group
    for AC9/J4-group (never the survivor); `group.archived` is recognised + acked
    as a REVERSIBLE no-op. Test:
    `app/Modules/Shared/Messaging/tests/jobrouter_group_teardown_test.php` (39).
- ✅ **GROUP-HALF CONSUMER SET COMPLETE** — AC9 (authority), J4-group (journeys),
  C6 (contribution attribution), G5 (invite sources) all ship on the shared group
  fan-out seam, each fault-isolated + idempotent, with merge (→survivor) vs
  dissolve (→ancestor/org, fail-closed) policies consistent across all four.
- ✅ **E-B1 + MT5 (event cancellation → meeting teardown) SHIPPED.**
  - **E-B1** — `EventService::cancel()` now stages an `event.cancelled` domain
    event on the outbox (mirrors the existing `event.completed` emit), carrying
    `event_id`, `organization_id`, `group_id`, `was_published`, reason, actor, and
    timestamp. Idempotent: a re-cancel short-circuits on the draft/published
    BAD_STATE guard before reaching the emit. Verified by a source-inspection
    assertion in `event_lifecycle_guards_test.php` (66).
  - **MT5** — new `MeetingService::onEventCancelled()`: every meeting linked to
    the cancelled event that is still `scheduled`/`live` flips to `canceled`, and
    all UNEXPIRED per-user join tokens on those meetings are expired (stamped to
    now) so a held token can no longer be redeemed (`verifyJoinToken` already
    rejects expired). `ended`/`canceled` meetings and other events' meetings are
    untouched; attendance evidence/participant history is preserved. SYSTEM
    authority, idempotent. Test:
    `app/Modules/Meetings/Services/tests/meeting_event_cancel_test.php` (15).
  - **JobRouter** — new `event.cancelled` topic → `eventCancelled()` fans out the
    fault-isolated `meeting-event-cancel` consumer. Test:
    `app/Modules/Shared/Messaging/tests/jobrouter_event_cancelled_test.php` (11).
- ✅ **E-B2 (event registrations on account teardown / merge) SHIPPED.**
  - **Teardown half** — `RegistrationService::releaseActiveForSubject()`: for a
    deactivated/suspended/anonymized person, every active registration on an
    UPCOMING (draft/published) event is cancelled — a freed CONFIRMED seat
    promotes the next waitlisted person (same promotion path as `cancel()`), a
    cancelled waitlisted entry expires its waitlist row. All the subject's live
    inventory `ticket_holds` are released and stray `waiting` waitlist rows
    expired, so seats/holds return to inventory. Registrations on events that
    already ran (completed/cancelled) are left as history. Idempotent.
  - **Merge half** — `RegistrationService::reassignForMerge()`: the loser's event
    footprint re-points to the survivor honouring each UNIQUE constraint —
    registrations (`event_id,user_id`), attendance (`active_key =
    event_id:user_id`, rebuilt on re-point), live holds, and waitlist entries.
    A per-event would-be duplicate is instead superseded (registration
    cancelled), voided (attendance), released (hold) or expired (waitlist) —
    history preserved, never a blind rewrite. Group attribution is never
    rewritten. loser==survivor / empty → all-zero no-op.
  - **JobRouter** — `fanOutTeardown` adds the fault-isolated
    `registration-teardown` consumer (skipped on merge); `accountMerged` adds the
    `registration-reassign` consumer. Tests:
    `app/Modules/Events/Services/tests/registration_teardown_test.php` (30) +
    extended `jobrouter_account_teardown_test.php` (100).
- ✅ **THEME B LIFECYCLE-SIGNAL CONSUMERS COMPLETE.** Individual-side (AC3, C8,
  J4, N7, R7, CO6, E-B2) + group-side (AC9, J4-group, C6, G5) + event-cancel
  (E-B1→MT5) all ship on the outbox→JobRouter fan-out, each fault-isolated +
  idempotent, with consistent teardown-vs-merge (release/pause vs re-point-to-
  survivor) and dissolve-vs-merge (ancestor/org fail-closed vs survivor) policies.
- ⏳ **NEXT**: remaining sweep OPERATIONS that need new code (M5/M6),
- ⏳ **NEXT (PARKED 2026-09-16):** the entire remaining backlog is consolidated in
  `docs/TODO_REMAINING_BACKLOG.md` — Theme C remainder (**ST4, CM4**, then the
  queue/age sweeps G2/G4, J6, N4, ID4), Theme A route-guard remainder (M3, J5,
  AC11, G6), Integrations IN4/IN5, parked Events items (G8/E-C2, L4–L6), and
  Identity ID3. Resume with **ST4**.
- ✅ **SHIPPED — Identity ID5** lifecycle tests beyond the ID1 emitter test. New
  `app/Modules/Identity/Services/tests/lifecycle_matrix_test.php` (31) covers the
  transition MATRIX (legal forward moves, illegal jumps, terminal states admit
  nothing, NO_CHANGE / BAD_STATUS / REASON_REQUIRED / USER_NOT_FOUND guards), the
  revocation cascade (deactivate revokes sessions+tokens; activation does NOT),
  anonymize PII scrub, and the merge MAKER-CHECKER (submit guards incl. terminal
  refusal + bad pair, SoD self-approval denial, PDP denial, valid approval by a
  different actor retiring the duplicate into the survivor, bad-state re-approval,
  and the honest `belongings_repointed=false`).
- ✅ **SHIPPED — Integrations IN1/IN2**: `ConnectionService::disable()` is now a
  guarded, audited, cascading transition — requires a legal prior state (never
  from draft/terminal), a reason + actor, records an immutable audit entry,
  auto-revokes the connection's active `capability_grants`, and stages
  `connection.disabled` on the outbox; idempotent on an already-disabled row. A
  real terminal `revoke()` was added (same path, `connection.revoked`, runnable
  from `disabled`). The AuditLogger + OutboxService deps are nullable (existing
  callers unaffected; null → transition + cascade still happen, observers skipped)
  and wired in `Integrations/Config/Services.php`. JobRouter recognises the two new
  topics (grants auto-revoked at source; reserved seam for the Meetings MT5 /
  Streaming cut-off). Test
  `app/Modules/Integrations/Services/tests/connection_teardown_test.php` (33).
- ✅ **ALREADY SHIPPED — G1** (season rollover no longer wedges). Verified this
  pass: `SeasonService::rollover()` reclaims a `failed` row or a `running` row past
  its stale lease (`RECLAIM_LEASE_SECONDS`, `--force` override), bumps `attempts`,
  restamps the heartbeat; `reclaimStuckRollovers()` + the registered
  `gamification.reclaim-rollovers` sweep self-heal in the background; migration
  `2026-09-15-000077_AddSeasonTransitionLease` + `SeasonRolloverCommand --force`.
  Test `rollover_reclaim_test.php` (20). (The plan TODO was stale.)
- Rationale: these were live corruption/exposure issues with small, local fixes;
  none needed the buses/runners built yet.

**Phase 1 — Unified sweep runner (Theme C)**
- Build the scheduler + shared sweep contract, **modelled on the two working
  Events commands** (`events:close-due`, `events:reminders-due`) — the reference
  runner pattern — and fold those two into the unified runner.
- Register the existing/near-existing sweeps first: **AC1, AC4/AC5, J1, N1, N2,
  C4/C5, G2, R4, M5/M6, J6, N4**, plus **MT2** (meeting evidence→attendance
  reconciler) and **E-C1** (`events:expire-holds`).
- Rationale: closes the largest bucket of findings, much of it user-visible (N1
  dropped messages, N2 dead campaigns, J1 stale triage) with operations that
  already exist.

**Phase 2 — Lifecycle-signal bus (Theme B)**
- Land the group-side foundation **GR2/GR3** (closure pruning + status-aware
  resolvers) — unblocks AC9, G5, C6, J4-group.
- Emit the six teardown events from Identity + Groups (**M1/M2, GR2/GR3**).
- Subscribe dependents: **AC3, J4, R7, C8, N7**.
- Rationale: needs the emitters first; sequenced after the sweeps because several
  cascades reuse sweep machinery (e.g. cancelling deferred sends).

**Phase 3 — Route-authz completion & remaining MED/LOW**
- Finish Theme A: **GR1, M3, J5, AC11, G6** behind the harness allowlist.
- Remaining per-module MED/LOW (state-machine guards M9/M10/J3, audit trails C9,
  governance R6, recognition-on-reversal G9, template pinning N6, etc.) — pull
  from each review's own §3 sequencing.

**Cross-cutting throughout:** add the missing service-level tests each review
called for (M-tests, AC10, J9, C10, R9, G8, N8) alongside the fix they cover, so
every phase lands red→green.

---

## 7. How to use this document

- **Per-finding detail** lives in the eight source reviews (§1 table); this plan
  never restates the code evidence, only the grouping and order.
- **Each review's own §3 sequencing** remains valid for intra-module ordering;
  this plan governs **inter-module** ordering and the shared-infra decisions.
- **Nothing here changes code.** The first executable step is Phase 0: the
  route-filter test harness + the seven bug fixes.

No code was changed in the course of this consolidation.
