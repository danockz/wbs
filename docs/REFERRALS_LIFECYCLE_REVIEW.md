# Referrals (Outreach / Sponsorship / Downline) Lifecycle Review

_A code-grounded gap analysis of the **outreach & sponsorship lifecycle** — how a
prospect is captured (cloaked referral link, member address-book, staff bulk, or
guest-from-invite), tracked through follow-ups/decisions/temperature triage,
linked to a platform user, attributed as a conversion, and how the sponsorship
graph (single active sponsor per member, upline/downline) is assigned, reassigned
under maker-checker, and traversed — plus how these interact with the membership,
journey, groups and contribution lifecycles reviewed earlier._

Prepared 2026-09-15, as the sixth review in the sequence after membership, groups,
access control, journey/activities, and contributions. Method: read the five
migrations, the eleven services/ports (Referral, Sponsorship,
SponsorReassignment, SponsorResolver, ContactBook, Fraud, plus Course/Event
registrar adapters), the three controllers, the routes, and the tests; every
finding cites the code it rests on. **No code was changed** — this is the review
that precedes any fixes.

Scope note: this review is about the **prospect and the sponsorship edge as
first-class objects** — their state machines, the graph invariants (single active
sponsor, no cycles), the maker-checker reassignment, and the outreach triage that
is meant to keep a downline warm. Where a *person's* membership state matters it
was covered in the membership review; here `prospects`/`sponsorships` appear as
the pre-member and graph layers that must stay consistent with it.

---

## 1. The referrals model at a glance

**Tables** (`000009` CreateReferrals, `000027` ExtendTracking, `000052`
Reassignments, `000062` GrowProspectsToContacts, `000063` ProspectDecisions):

- **`referral_links`** — opaque cloaked `code`, `status` `active|…`, per referrer.
- **`referral_clicks`** — salted `ip_hash`/`device_hash` (never raw IP),
  fraud-flaggable.
- **`sponsorships`** — the graph edge: `member_id`, `sponsor_id`, `active`,
  `effective_from/to`, `reason`, plus `active_key` (= member_id when active, NULL
  historical) with a UNIQUE index → **one active sponsor per member**.
- **`prospects`** — grown from a capture record into a full **address-book
  contact**: `state` `captured|converted|rejected`, `owner_user_id`,
  `assigned_group_id`, `linked_user_id`, `source` `member|staff_bulk|link|import`,
  contact details, `journey_stage` (mirrors Journey codes), `temperature`
  `hot|warm|cold`, `last_contacted_at`/`next_follow_up_at`/`follow_up_count`,
  invite context, and a two-flag coordinate-consent gate.
- **`prospect_decisions`** — append-only dated decisions; canonical type
  `join_group` carries `target_group_id`.
- **`referral_attributions`** — conversion credit, unique on
  `(converted_user_id, conversion_type, source_ref)` for idempotency.
- **`sponsor_reassignments`** — maker-checker re-parenting with before/after path,
  impact projection, eligibility state, immutable review trail.

**Prospect state machine:** `captured → converted | rejected`
**Sponsorship edge:** `active → (superseded: active=0, effective_to set)`; one
active per member enforced by `active_key` UNIQUE.
**Reassignment:** `pending → approved | rejected | cancelled` (SoD, eligibility
re-checked at approve).

**The good news up front.** The graph and reassignment machinery are genuinely
well built and should be protected:

1. **The sponsorship graph invariants are correct and enforced in the right
   place** — `assign()` guards self-sponsor and cycles (`wouldCycle` via upline
   walk), closes the prior active edge and opens the new one **transactionally**,
   and relies on the `active_key` UNIQUE index as the ultimate one-active-sponsor
   guard (belt-and-braces with the app logic). Re-assigning the same sponsor is
   an idempotent no-op.
2. **Downline/upline traversal is bounded and cycle-guarded** — `downline()` is a
   breadth-first walk with a `seen` diamond/cycle guard and a `maxLevels` bound,
   using `whereIn` per level (one query per level, not per node), explicitly
   replacing a raw recursive CTE. `upline()` is likewise bounded.
3. **Sponsor reassignment is a model maker-checker** — SoD (checker ≠ maker),
   **eligibility re-checked at approve** (the graph may have changed while
   pending), re-parent delegated to `assign()` (so history/ledger are never
   rewritten), only non-finalized metrics recalculated, before/after path +
   impact recorded, and an immutable audit + review trail.
4. **Conversion attribution is idempotent** — unique `(converted_user_id,
   conversion_type, source_ref)` with an insert-catch replay path.
5. **Privacy is respected on the funnel** — clicks store salted hashes not raw
   IPs, analytics emit PII-free aggregates, the landing page never reveals the
   sponsor, and precise coordinates require a two-flag consent gate before an
   address is persisted.

The findings below are the gaps around the edges of that solid core — and the
first is HIGH because it exposes graph mutation.

---

## 2. Findings, ranked

### R1 — Core referral mutations are unauthenticated: `links`, `sponsorships`, `attribute` have NO auth filter (HIGH)

The `referrals` route group applies filters **per route**, and three mutating
routes carry none:

```
$routes->post('links',        '…ReferralController::createLink');       // no filter
$routes->post('sponsorships', '…ReferralController::assignSponsor');     // no filter
$routes->post('attribute',    '…ReferralController::attribute');         // no filter
```

The analytics/reassignment routes below them correctly carry `auth` (+
`authorize:`), and the controller methods do **not** self-guard — `assignSponsor`
and `attribute` just read input + `orgId()` and call the service. So:

- **`POST referrals/sponsorships`** lets an unauthenticated caller **rewrite the
  sponsorship graph** — assign or move any member under any sponsor (cycle/self
  guards still apply, but nothing checks the caller is allowed to re-parent that
  member). This is the graph that drives downline attribution, VBCS rollups, and
  upline chains.
- **`POST referrals/attribute`** lets an unauthenticated caller **mint conversion
  credit** for any referrer against any user (only self-referral is blocked).
- **`POST referrals/links`** lets anyone create cloaked links for any referrer.

This is the same missing-`auth`/`authorize:`/`webcsrf` pattern flagged in Groups
GR1, Membership M3, ACL AC11 and Journey J5 — but here it is at its most severe
because the endpoints are entirely open, not merely missing CSRF. Note the
contrast with the sibling reassignment routes, which are fully gated
(`auth` + `authorize:sponsor.reassign.approve` + `webcsrf`): the direct
`assignSponsor` path is an **unguarded bypass of the very maker-checker workflow
the reassignment service exists to enforce.**

**Fix direction:** add `['auth', 'authorize:<code>', 'webcsrf']` to all three
routes. For `assignSponsor`, decide whether direct assignment should even be
exposed outside the maker-checker flow (initial assignment vs re-parent); at
minimum gate it and scope-check that the caller may sponsor/re-parent the target.
For `attribute`, gate it to a system/service capability (it's a
conversion-crediting hook, not a public action). Add a route-filter test in the
GR1/M3/AC11/J5 style.

### R2 — `attributeConversion` converts EVERY captured prospect of a referrer, not the one who converted (HIGH)

After inserting the attribution, `attributeConversion` runs:

```
$this->db->table('prospects')
    ->where('referrer_id', $referrerId)
    ->where('state', 'captured')
    ->update(['state' => 'converted']);
```

There is **no prospect-id / user-id / email match** — so a single conversion by
one person flips **all** of that referrer's still-captured prospects to
`converted` in one statement. Consequences:

- The prospect funnel is corrupted: everyone a referrer ever captured shows as
  converted the moment any one of them converts, destroying conversion-rate
  analytics and the downline triage board (`by_stage`/`by_temperature` summaries
  read `state`/`journey_stage`).
- A `captured` prospect who never actually converted is silently marked
  `converted`, so follow-up stops targeting them (they drop out of the "still a
  prospect" working set).

The `converted_user_id` is known at call time and `prospects` carries
`email_hash`, `linked_user_id`, and (via the attribution) `link_id` — so the row
that actually converted is identifiable; the code just doesn't scope the update.

**Fix direction:** scope the state flip to the converting prospect — match on
`linked_user_id = convertedUserId`, or the `link_id`/`email_hash` that ties the
capture to this conversion — and update only that row (or none if the conversion
didn't originate from a captured prospect). Add a test with two captured
prospects asserting only the converter flips.

### R3 — Recording a `join_group` decision does nothing beyond logging — no membership, no journey signal (HIGH — cross-review coupling)

`recordDecision` appends a `prospect_decisions` row (append-only history, good)
but has **no side effects**. For the canonical `join_group` decision — the one
the whole "≥3 dated decisions, one must be join_group" requirement is built
around, carrying `target_group_id` — nothing:

- creates or requests a `group_members` belonging in the target group,
- links/creates the platform user (`ensureContactUser` exists and is used by the
  attendance path, but is **not** called from `recordDecision`),
- emits a `journey.signal.*` so the Journey module can advance the person's stage
  (the "integration = group membership + foundations courses" arc), or
- moves the prospect's own `state`/`journey_stage`.

So the decision that is supposed to be the hinge of conversion→integration is a
dead record. This couples directly to the Journey review (J-series: signals drive
stage advance) and the membership "integration" definition — the plumbing to turn
a recorded decision into an actual belonging + journey advance is absent.

**Fix direction:** on a `join_group` decision, (a) ensure the linked user
(`ensureContactUser`), (b) create or request the `group_members` row in
`target_group_id` under the group's join policy (respecting the membership
lifecycle's approval/maker-checker), and (c) emit a journey signal so the stage
ladder can react. Decide whether other decision types (salvation, baptism) also
emit journey signals. Sequence with the Journey J-series signal work.

### R4 — Outreach triage rots: no follow-up-due sweep and no temperature decay runner (MED)

`prospects` carries `next_follow_up_at`, `last_contacted_at`, `follow_up_count`
and `temperature`, and there are purpose-built indexes (`pr_followup_idx`,
`pr_temp_idx`) and summary tiles that count "due now" — but **nothing acts on
them**: there is no command/cron that reminds an owner a follow-up is due, and no
process that **decays temperature** (hot→warm→cold) as a contact goes untouched.
`temperature` only ever changes when a human passes it into `createContact`/
`updateContact`/`recordFollowUp`.

So the downline "who needs attention" board — the stated birds-eye triage — goes
stale exactly like the Journey involvement snapshot (J1): a contact that has gone
cold stays whatever temperature it was last set to, because going cold produces
no event. `next_follow_up_at` in the past just sits there unremarked.

This is the same "sweep/decay with no scheduled runner" pattern as Membership
M5/M6, Journey J1, ACL AC1, and Contributions C4/C5.

**Fix direction:** add a scheduled command that (a) notifies owners of
follow-ups due (`next_follow_up_at <= now`, deduped per due date like
CommitmentService), and (b) decays `temperature` based on `last_contacted_at`
age against configurable windows. Share the cron home with the other sweeps.

### R5 — `ensureContactUser` mints real `users` rows as a side effect of attendance, with weak identity hygiene (MED)

When a contact attends an event/course, `ensureContactUser` reuses a user by
email or **creates a `pending_verification` user** — and if the contact has no
email, it fabricates one: `"{uuid}@contacts.invalid"`. It also auto-links a
sponsor. Concerns:

- **Duplicate identities:** email match is the only dedup. A contact with a
  slightly different email (or none) creates a fresh user even when the person
  already exists under a different address or as another contact — seeding the
  very person-merge problem membership M2 describes. There's no phone-based or
  fuzzy match, and no check against existing `prospects.linked_user_id`.
- **`@contacts.invalid` accounts** accumulate as unverifiable ghost users that
  nonetheless carry sponsorships and can receive registrations, points and VBCS
  metrics.
- The user is created **inline in the attendance path** with no maker-checker and
  outside the membership module's account-creation guards.

**Fix direction:** route contact→user promotion through the Identity module's
account-creation path (so status/verification/sponsor rules are consistent),
strengthen dedup (phone + email + existing link), and avoid fabricated emails
(use a nullable email + a distinct `contact_stub` status rather than a fake
address). Feed these stubs into the M2 merge tooling.

### R6 — Prospect `rejected` state and contact deletion/archival are unmodeled (MED)

The prospect state machine includes `rejected`, but no service method ever sets
it (grep shows only `captured`→`converted`). There is no "this lead went cold /
opted out / bounced" terminal transition, no soft-delete/archive for a contact,
and no honoring of a withdrawal of consent (the `consent` flag is set at capture
but there's no path to revoke it and stop processing). For a module holding
**private prospect PII** under a consent model, the absence of a
reject/opt-out/erase path is a data-governance gap.

**Fix direction:** add explicit `reject`/opt-out transitions (with reason), a
consent-withdrawal path that stops follow-ups and purges precise coordinates, and
an archive/erase capability for right-to-be-forgotten. Terminal states should
drop the contact from the active triage working set.

### R7 — Sponsorship graph does not react to account deactivation or member merge (MED — cross-review coupling)

`sponsorships` key on `member_id`/`sponsor_id` but nothing reconciles the graph
when a person's account changes:

- **Deactivated/suspended sponsor (membership M1):** their active edges stay
  `active`, so `upline`/`downline`/rollups still route through a non-participating
  member, and new members can still be auto-linked to them via
  `linkSponsor`/`SponsorResolver`.
- **Merged person (membership M2):** the loser's sponsorship edges (as member and
  as sponsor) aren't re-pointed to the survivor — orphaning part of the graph.

The reassignment service is the correct tool for moving an edge, but nothing
*triggers* it on an account lifecycle event.

**Fix direction:** on account deactivate/suspend, reassign or flag the member's
downline (they can't sponsor if they're gone) and stop auto-linking new members
to them; on merge, re-point the loser's edges to the survivor through the
reassignment path (preserving history). Consume the same M1/M2 signals ACL AC3
and Journey J4 need.

### R8 — Fraud assessment is advisory on clicks only; capture and attribution aren't gated by it (MED)

`FraudService::assess` is wired into `recordClick` (assessed before insertion) —
good — but the verdict appears to flag/annotate rather than block, and neither
`captureProspect` nor `attributeConversion` consults fraud state. So a link
identified as abusive can still capture prospects and mint conversion credit; the
fraud signal informs analytics but doesn't fail-close the funnel.

**Fix direction:** decide the enforcement policy — if a click/link is assessed
high-risk, gate capture and (especially) attribution/credit on it, or hold the
attribution for review. At minimum, carry the fraud verdict onto the prospect and
attribution so downstream credit can be discounted.

### R9 — Thin test coverage for the lifecycle/graph edges (MED)

Only two service tests exist (`sponsor_resolver_test`, `followup_pre_event_
registration_test`). None cover: the `assign()` single-active-slot + cycle guard,
the reassignment SoD/eligibility-recheck, `attributeConversion` scoping (R2 would
have been caught), the unauthenticated-route exposure (R1), or the missing
`join_group` side effects (R3). Given the graph invariants and money-adjacent
attribution here, coverage is thin.

**Fix direction:** add service + route-filter tests for the graph invariants,
reassignment maker-checker, attribution scoping, and the auth gaps — before the
R1/R2/R3 fixes so they land red→green.

---

## 3. Suggested sequencing

Front-loads the exposure and data-corruption defects, then the missing side
effects and sweeps, then governance/policy:

1. **R9** — add the graph/route/attribution tests (red) first.
2. **R1** — gate `links`/`sponsorships`/`attribute` (exposure; smallest, most
   urgent; pairs with GR1/M3/AC11/J5).
3. **R2** — scope `attributeConversion`'s prospect flip to the actual converter
   (funnel-corruption fix).
4. **R3** — make `join_group` decisions create/request membership + emit a
   journey signal (the conversion→integration hinge; sequence with Journey J-series).
5. **R4** — follow-up-due + temperature-decay scheduled command (shares cron home
   with M5/M6, J1, AC1, C4/C5).
6. **R5** — route contact→user promotion through Identity; harden dedup; kill
   fabricated emails (feeds M2 merge).
7. **R6** — reject/opt-out/consent-withdrawal/erase transitions (data governance).
8. **R7** — consume account M1/M2 signals to reconcile the sponsorship graph.
9. **R8** — decide fraud enforcement on capture/attribution. Lower urgency.

## 4. Cross-review couplings (explicit)

- **R1 ↔ Groups GR1 / Membership M3 / ACL AC11 / Journey J5:** the recurring
  missing-auth/`authorize:`/`webcsrf` pattern — most severe here (fully open).
- **R3 ↔ Journey (J-series) + Membership "integration":** a `join_group` decision
  must become a real belonging + a journey signal.
- **R5/R7 ↔ Membership M1/M2:** contact→user promotion seeds duplicate
  identities; the graph must react to deactivation/merge — same signals ACL AC3
  and Journey J4 require.
- **R4 ↔ Membership M5/M6, Journey J1, ACL AC1, Contributions C4/C5:** the same
  "sweep/decay with no scheduled runner" pattern.

No code was changed in the course of this review.

---

## 5. Delivered — integration decisions (the standalone dated decisions) ✅ **2026-09-22** · amended **2026-09-23**

**Status:** ✅ Implemented · **default OFF** (capability `referrals.integration_decisions`).
**Design record:** `docs/INTEGRATION_DECISIONS.md`.

R3's follow-through was the `join_group` hinge; this closes the *other* half of the
SRS onboarding requirement — the **major dated decisions** of the integration
lifecycle, **each standing alone**: **salvation, water baptism, Holy Spirit
baptism, foundation course** (the baptisms were originally bundled as one group
and were **separated 2026-09-23** so each is its own required decision), all
recordable at an invitation, an event, or staff/member-assisted registration, on
one day or months apart.

- `prospect_decisions` extended (migration `000091`): nullable `user_id` (prospect
  XOR user), `status`/`source`/`source_ref`/`decided_by`/`decided_at`, plus two
  UNIQUE keys so derived rows are idempotent while hand rows stay append-only.
- `IntegrationService`: self-declaration (`pending` → owner/sponsor confirms),
  derivation from foundation-category enrolment/completion (via an
  `IntegrationDecisionsPort` seam on `EnrollmentService`), the checklist verdict,
  and the `gate()`.
- **Journey gate**: `JourneyService::transition()` consults `IntegrationGatePort`
  before any write — a non-integrated member cannot enter `in_foundation` /
  `established` (config-gated, default OFF).
- Assisted path hardened: `recordDecision` now validates the shared catalog and
  refuses future dates.
- Surfaces: `my/integration` (self-service, menu item `overview.integration`,
  unmasked) + `me/integration-decisions` (mentor's confirmation queue). No new
  permission bits.
- Tests: support 34/0, service 55/0, Journey gate 11/0, views/wiring 40/0.
  Full suite **252 files / 16,195 assertions / 0 failed**; OpenAPI **588 ops / 508 paths**.
