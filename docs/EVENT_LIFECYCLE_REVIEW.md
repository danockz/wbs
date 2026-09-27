# Event Lifecycle Review — end-to-end (Events module)

_Reviewed 2026-09-14. Scope: the **whole** event lifecycle — from creation
through publication, mobilization, registration, the live event (check-in,
streaming, kiosks), payment, and post-event close (attendance finalization,
certificates, feedback, mobilization report, media, expenses reconciliation).
This supersedes `PRE_EVENT_PROCESS_REVIEW.md` in scope; that document's pre-event
gaps are folded in here as the G-series, with G1+G2 already shipped._

Grounded in the code as it stands today (services, controllers, routes,
migrations read directly).

> **Build status (updated 2026-09-14):** the **lifecycle-guards trio — L1
> (complete guard) + G7 (safe cancel) + G6 (publish readiness) — is ✅ SHIPPED**
> (§6), and **G3 (pre-event reminders + change/cancel notifications) is ✅
> SHIPPED** (§7), completing G7's notify side. **Follow-ups are now first-class
> in the pre-event phase** (§8): outreach follow-up registrations route through
> the platform registrar/enroller, so they obey the capacity/waitlist/published
> gates and are picked up by G3 notifications. Alongside the earlier **G1 (edit)
> + G2 (validation)**. **G5 (invite-policy enforcement) is ✅ SHIPPED** (§9), and
> **G4 (event-scoped invitation counts) is ✅ SHIPPED** (§10) on top of it.
> **L2 (ticket refund / order-cancel) is ✅ SHIPPED** (§12) and **L3 (close
> automation) is ✅ SHIPPED** (§13). Remaining open: G8, L4, L5, L6. Full suite
> green (163 files / 12,015 assertions); OpenAPI 461 paths / 536 ops (L3 is
> CLI-only — no route change).

---

## 1. The lifecycle as built

```
        ┌──────────────────────── PRE-EVENT ─────────────────────────┐
create ─┤ draft ──edit(G1)──▶ draft ──publish──▶ published            │
(draft) │   │  validate(G2)                   │  (register/RSVP,       │
        │   └───────────── cancel ────────────┤   waitlist, ticketing,│
        └────────────────────────────────────┤   logistics, budget)  │
                                              │                        │
        ┌──────────── DURING EVENT ───────────┼────────────────────────┘
        │ check-in (QR nonce / manual / kiosk-offline), streaming,      │
        │ attendance + group attribution, points, journey signals       │
        └───────────────────────────┬───────────────────────────────────┘
                                     ▼
        ┌──────────── POST-EVENT (close) ─────────────────────────────┐
        │ complete → completed | completed_no_attendance               │
        │ certificates (request→render→issue→verify/revoke),           │
        │ feedback/quiz, mobilization report + snapshot + group rollup, │
        │ media review, expense reconcile                               │
        └──────────────────────────────────────────────────────────────┘
```

`events.status`: `draft | published | cancelled | completed |
completed_no_attendance`. `event_orders.status`: `pending | paid | cancelled |
refunded | expired`. `ticket_transfers`/order-item: `active | transferred |
refunded | cancelled`. `event_certificates`: `pending → issued → revoked`.

---

## 2. Phase-by-phase health

| Phase | Area | Status | Evidence / note |
|---|---|---|---|
| **Pre** | Create | ✅ | `create()` now validates via shared `EventValidator` (G2). |
| **Pre** | Edit | ✅ **(new)** | `update()` — whitelisted partial update, freezes terminal events, merged-view validation (G1). |
| **Pre** | Publish gate | ✅ **(new)** | `publish()` now gates on `EventReadiness` blockers; soft warnings shown on the page (G6). |
| **Pre** | Cancel | ✅ **(new)** | `cancel()` — guarded (draft/published only), reason required, actor+time recorded, ICS auto-drops (G7). |
| **Pre** | Registration/RSVP | ✅ strong | Atomic capacity (confirmed + live holds `FOR UPDATE`), idempotent, waitlist, RSVP-intent change. |
| **Pre** | Invite policy | ✅ | `registration_policy='invite'` enforced fail-closed: group-subtree / direct invite / reusable shareable link / assisted; guest sign-ups become sponsor prospects (**G5**, §9). |
| **Pre** | Reminders/notifications | ✅ | Pre-event reminder sweep + change/cancel roster fan-out shipped, feature-gated default-off (**G3**, §7). |
| **During** | QR check-in | ✅ strong | Signed, TTL, single-use nonce; replay-proof; one active attendance per person. |
| **During** | Manual check-in | ✅ | Requires staff + reason; idempotent. |
| **During** | Kiosk / offline | ✅ | `KioskService` — device manifests + `offline_checkin_queue` reconcile. |
| **During** | Group attribution | ✅ | Separate table; no headcount double-count; multi-group award via `awardMultiGroup`. |
| **During** | Points / journey | ✅ | Best-effort, fault-isolated; never fails a check-in. |
| **During** | Streaming link | ✅ | `streams.event_id`; qualified-watch aggregation in the report. |
| **Close** | Complete gate | ✅ **(new)** | `complete()` now requires `published`; re-complete idempotent; zero-attendance still recorded (L1). |
| **Close** | Certificates | ✅ | Two-phase request→render→issue; zero-attendance yields none; public verify + revoke. |
| **Close** | Feedback / quiz | ✅ | Forms/questions, privacy-safe aggregate, auto/reviewed scoring. |
| **Close** | Mobilization report | ✅ | Live build + immutable snapshot + group rollup (aggregate-only). |
| **Close** | Media review | ✅ | Register→scan(queue)→approve/reject; visibility states. |
| **Close** | Expense reconcile | ✅ | Budget vs committed, per-status; approvals workflow. |
| **X-cut** | Ticket refund/cancel | ❌ | Order schema has `refunded`/`cancelled` states but **no method sets them** (**L2**). |
| **X-cut** | Automated close | ⚠️ | `complete` is fully manual; no auto-complete after end, no auto certificate/snapshot (**L3**). |
| **X-cut** | Delete / archive | ⚠️ | No delete/archive/soft-delete path; test data & mistakes are permanent (**L4**). |

Legend: ✅ solid · ⚠️ works but has a gap · ❌ missing.

**Bottom line:** the module is broad and, in the hot paths (registration
concurrency, check-in integrity, certificate issuance, aggregate reporting),
genuinely robust. The remaining gaps cluster at the **lifecycle seams** —
publish/complete/cancel guards, the notification loop, and the ticket
money-reversal path — rather than in the core mechanics.

---

## 3. Gaps — pre-event (G-series, carried from the earlier review)

- **G1 — Event edit** 🔴 → ✅ **SHIPPED** (`EventService::update()` + form + routes + validator).
- **G2 — Shared validation** 🟠 → ✅ **SHIPPED** (`EventValidator`, create + update).
- **G3 — Pre-event reminders / change / cancel notifications** 🟠 → ✅ **SHIPPED** (§7). Reuses the Notifications module (idempotent send + `notification.dispatch` outbox) — no forked transport. Cancel/update on a *published* event fan out to registered+waitlisted; a reminder sweep (`php spark events:reminders-due`) notifies confirmed registrants of events starting within N hours, watermarked by `events.last_reminded_at`. Feature-gated via `EffectiveConfigResolver` (`events.notifications.enabled`), **default off**.
- **G4 — `expectedAttendance` "invitations" counts ALL org prospects** 🟠 → ✅ **SHIPPED** (§10). Both `expectedAttendance()` and `ReportService::mobilization()` now count EVENT-SCOPED invitations from `event_invitations` (direct, excluding revoked) + `event_invite_links.redeemed_count` (shareable-link sign-ups), with a direct/link breakdown surfaced in the report; `converted_prospects` re-based on the event's `contact:` follow-up registrations.
- **G5 — `registration_policy='invite'` enforced** 🟠 → ✅ **SHIPPED** (§9). New `event_invitations` allow-list + `InvitationService` eligibility gate wired into `register()`, fail-closed: eligible only when assisted (staff/leader/follow-up), a member of the event's group subtree, directly invited (user/email/phone), or opening the reusable, mode-bounded shareable link (broadcast on social media + enclosed in direct email/SMS invites); guest sign-ups via the link become the sponsor's prospects.
- **G6 — No publish READINESS checklist** 🟡 → ✅ **SHIPPED** (§6). `publish()` now refuses on `EventReadiness` blockers; the draft page shows blockers + soft warnings.
- **G7 — Cancel is silent & unguarded** 🟡 → ✅ **SHIPPED** (§6). Guarded (draft/published only), reason required, actor+time recorded; ICS `STATUS:CANCELLED` auto-propagates. Roster notification on cancel now shipped in **G3** (§7).
- **G8 — Capacity increase doesn't auto-promote waitlist** ✅ RESOLVED 2026-09-18. `EventService::update()` detects a capacity raise (higher finite cap or lift to unlimited) and promotes waitlisted registrations FIFO into the freed seats via `RegistrationService::promoteWaitlistToCapacity()` — reusing the promote-on-cancel transition, row-locked against oversell, idempotent. Promoted registrants get a dedicated `event_promoted` notification (default-OFF G3 gate, 6-locale copy). Test `waitlist_promotion_test.php` (22 passed).

---

## 4. Gaps — newly found across the full lifecycle (L-series)

### L1 — `complete()` has NO state guard 🔴 **HIGH (integrity)** — ✅ SHIPPED (§6)
`EventService::complete()` sets `completed`/`completed_no_attendance` from any
current status — including `draft`, `cancelled`, or an already-`completed` event.
```php
public function complete(string $eventId): Result {
    $e = $this->find($eventId);            // only a null-check
    ...                                    // no status guard at all
}
```
- **Impact:** a draft that never ran can be marked "completed"; a cancelled event
  can be silently resurrected to "completed"; re-completing flip-flops the
  no-attendance suffix. This corrupts the very state the report/certificate flows
  key off.
- **Proposed:** guard `published → completed*` only (mirror `publish()`'s
  `BAD_STATE`); make re-complete idempotent. Small, high value; pairs with G7 so
  all three lifecycle writes (publish/cancel/complete) are guarded consistently.

### L2 — No ticket refund / order-cancel path 🟠 **MEDIUM**
`event_orders.status` enumerates `cancelled|refunded` and the order-item/transfer
tables enumerate `refunded|cancelled`, but **no service method ever sets them** —
`TicketingService` has `checkout/purchase/markPaid/transferTicket` only. Once
paid, a ticket can be transferred but never refunded or cancelled, and a
`pending` order is only ever expired implicitly via its hold.
- **Impact:** money cannot be reversed in-system; a cancelled event (G7) leaves
  paid orders stranded as `paid`. Sold-count/capacity aren't released.
- **Proposed:** `cancelOrder()` (pending→cancelled, release hold) and
  `refund(orderId, reason)` (paid→refunded: decrement `quantity_sold`, cancel the
  attendee registration, emit a VBCS reversal intent mirroring the add-on
  pattern). Tie event-cancel (G7) to bulk-refund of the roster.

### L3 — Close is entirely manual; no automation seam 🟠 **MEDIUM**
`complete()` is only reachable via the organizer button. There is no scheduled
"auto-complete after `ends_at`", and completing does **not** trigger certificate
`requestForEvent()` or a report `snapshot()` — those are separate manual actions.
- **Impact:** events silently linger in `published` after they end; certificates
  and the mobilization snapshot depend on someone remembering to click. Rollup
  figures are only as fresh as the last manual snapshot.
- **Proposed:** an `event.completed` domain hook that (optionally, per group
  config) enqueues certificate requests + a report snapshot; and a scheduled
  sweep that auto-completes events past `ends_at` + grace. Reuses the existing
  queue/JobRouter (resource-light, batched) and the hierarchical
  `EffectiveConfigResolver` gate (default off).

### L4 — No delete / archive / soft-delete 🟡 **LOW/MEDIUM**
There is no way to remove or archive an event (no `deleted_at`, no controller
action). Mistaken/test events accumulate permanently in the index, calendar, and
analytics.
- **Proposed:** soft-delete/archive (`status='archived'` or `deleted_at`) gated to
  `draft`/`cancelled` only, excluded from index/calendar/feeds. Never hard-delete
  an event with attendance/orders/certificates (history integrity).

### L5 — Report/rollup uses "latest snapshot per event" but rollup is single-group 🟡 **LOW**
`ReportService::groupRollup($groupId)` sums the latest snapshot per event for
**exactly one** `group_id`. The platform-wide rule is "contributions accumulate
to EVERY ancestor"; here a parent group's rollup does not transitively include
descendant groups' events unless each event was snapshotted against the ancestor.
- **Impact:** an ancestor's rollup under-reports vs the platform's
  credit-every-ancestor model.
- **Proposed:** accept a resolved subtree (self + descendants) and sum across it,
  consistent with `GroupScopeResolver`; keep aggregate-only. Lower priority — the
  per-event report itself is correct.

### L6 — Manual check-in doesn't require an active registration 🟡 **LOW (by design?)**
`recordAttendance()` enforces "must be a registrant" **only for `qr`**; a `manual`
check-in with no registration is allowed (walk-ins). Reasonable, but it means a
manual check-in silently has no confirmed seat and won't appear in
registration-derived expected figures.
- **Proposed:** on manual walk-in, optionally auto-create a `registered` row (or
  flag `walk_in=true`) so attendance and registration stay reconcilable. Confirm
  intent before changing — this may be deliberate.

---

## 5. Non-gaps (checked, and fine as-is)
- **Registration concurrency / oversell** — solid (`FOR UPDATE` over confirmed +
  live holds; waitlist on capacity).
- **Check-in integrity** — signed TTL single-use nonces, replay-proof, one active
  attendance per person, separate group attribution.
- **Certificate issuance** — two-phase, idempotent, zero-attendance yields none,
  opaque public verification, revoke path.
- **Privacy** — feedback aggregates suppressed below a threshold; certificate
  download is self-scoped with 404 (no existence disclosure); QR carries no PII.
- **Ticketing pre-sale** — types, promo codes, atomic holds, one-step purchase,
  free-order auto-confirm, controlled transfer.
- **Points/journey on attendance** — multi-group, idempotent, fault-isolated.

---

## 6. Delivered — lifecycle-guards trio (L1 + G7 + G6)

Shipped following the module's bespoke/no-JS/CSP-safe/6-locale/wiring-tested
conventions. All three event state-writes (publish/cancel/complete) are now
consistently guarded.

**New / changed:**
- **`EventReadiness`** (`Services/EventReadiness.php`) — pure, DB-free assessment
  of an event row → `{ready, blockers[], warnings[]}` with stable
  `Events.readiness.*` keys. Blockers: missing title/start, past start, inverted
  time range, virtual/hybrid with no access URL. Warnings (non-blocking): no
  description, no capacity, no end time, physical event with no venue.
- **`EventService::readiness()`** — read-only report (powers the page checklist).
- **`EventService::publish()`** — now refuses with `NOT_READY` +
  `meta.blockers` when the readiness check has blockers (still draft-only). (G6)
- **`EventService::cancel($id, $reason, $cancelledBy)`** — guarded to
  draft/published, **reason required**, records `cancellation_reason` /
  `cancelled_at` / `cancelled_by`, bumps `updated_at` so the ICS `SEQUENCE`
  advances and subscribers auto-drop the (already `STATUS:CANCELLED`) event. (G7)
- **`EventService::complete()`** — now requires `published`; re-completing an
  already-completed event is idempotent (`meta.deduplicated`); a
  draft/cancelled event is rejected `BAD_STATE`. Zero-attendance still yields
  `completed_no_attendance`. (L1)
- **`EventController::cancel()`** + route `POST events/(:segment)/cancel`
  (`auth` + `authorize:event.create,any` + `webcsrf`); `show()` computes
  readiness for drafts and passes it to the view; `respondLifecycle()` now
  translates lang-key error messages via `humanizeError`.
- **Migration** `2026-09-14-000069_AddEventCancellation` — nullable
  `cancellation_reason` / `cancelled_at` / `cancelled_by` on `events`.
- **View `show.php`** — a draft readiness checklist (blockers red / warnings
  amber) and a guarded cancel form (reason input + confirm) for draft/published.
- **i18n** — new `Events.lifecycle.*` (cancel + error keys) and a full
  `Events.readiness.*` block across all 6 locales.
- **Test** — `Services/tests/event_lifecycle_guards_test.php` (65 assertions):
  pure readiness cases, the three service guards, controller/route/migration
  wiring, i18n parity, and show.php render smoke.
- **OpenAPI** regenerated (451 paths / 525 ops).

**Deliberately still open (post-trio):** the cancel notification is now
delivered in **G3** (§7). Still open: **L2** (refund / order-cancel) — money
reversal is a prerequisite before an event-cancel can safely fan out to a *paid*
roster; the G3 cancel notice informs but does not itself refund.

---

## 7. Delivered — G3 (pre-event reminders + change/cancel notifications)

Completes G7's notify side and adds the pre-event reminder loop. Follows the
module conventions (bespoke / no-JS / CSP-safe / 6-locale / wiring-tested) and
the standing constraints: **reuse the Notifications module + outbox + a spark
sweep — no fork**; **feature-gated via hierarchical group config, default off**;
**resource-light** (one bulk roster read per event, one bounded range read per
sweep, gate resolved once per event — never per recipient).

**Architecture (ports + adapters, mirroring the Journey precedent):**
- **`EventNotifier`** (`Services/EventNotifier.php`) — the coordinator. Three
  flows: `notifyCancelled()`, `notifyUpdated()`, `processDueReminders()`.
  Categories `event_cancelled` / `event_updated` / `event_reminder`. The feature
  gate (`events.notifications.enabled`, **default off**) is resolved once per
  event; an org-wide (group-less) event resolves against the org-root group so an
  org-level default can enable it everywhere.
- **Ports** — `EventRosterPort` (roster read + reminder watermark + org-root
  group), `EventConfigPort` (hierarchical config read), `NotificationSenderPort`
  (send seam), `EventNotifierPort` (the seam EventService depends on). Keeps the
  coordinator unit-testable with trivial in-memory doubles and the heavy deps in
  the composition root only.
- **Adapters** — `EventRosterDbAdapter` (one indexed query per read),
  `EffectiveConfigAdapter` (wraps `Admin\EffectiveConfigResolver`),
  `NotificationSenderAdapter` (forwards to idempotent `NotificationService::send`
  → RetentionPolicyGate + `notification.dispatch` outbox for queued sends).

**Behaviour:**
- **Cancel** — a *published* event that is cancelled notifies **registered +
  waitlisted**; high priority; context carries the reason (never private ids).
  Dedupe key `event_cancelled:{event}:{user}:{cancelled_at}`.
- **Update** — only a **material** change (`starts_at`, `ends_at`, `timezone`,
  `venue_id`, `mode`, `access_url`) on a *published* event notifies; a cosmetic
  edit (title copy, description, capacity, policies…) is **silent**. Dedupe key
  fingerprints the changed fields + instant, so a later distinct edit re-notifies.
- **Reminder** — `EventNotifier::processDueReminders()` drives the sweep:
  published events starting within N hours (default 24) with
  `last_reminded_at IS NULL`, reminding **confirmed** registrants only, then
  advancing the watermark (a re-run is a no-op). A **gated-off** org still
  advances the watermark (so the sweep never rescans) but stages nothing.

**New / changed:**
- **`EventService`** — ctor takes an optional `EventNotifierPort` (null in the
  pure guard tests → records but does not notify, exactly the pre-G3 behaviour).
  `cancel()` fans out only when the event *was* published; `update()` fans out
  only for a published event and only on a material change.
- **Factory** `Events\Config\Services::eventNotifier()` — wires the coordinator
  from `NotificationServices::notifications(false)`, `AdminServices::effectiveConfig()`,
  and `EventRosterDbAdapter`; injected into `events()`.
- **Command** `Commands/EventRemindersDueCommand` — `php spark events:reminders-due
  [--org=UUID] [--all] [--hours=N]` (group `WBS`), mirroring `CommitmentDueCommand`.
- **Migration** `2026-09-14-000070_AddEventReminderWatermark` — nullable
  `events.last_reminded_at` + `ev_reminder_idx (status, starts_at, last_reminded_at)`.
- **i18n** — new `Events.notify.*` (cancelled/updated/reminder × subject/body)
  across all 6 locales, using `:name` placeholders (registry supports `{0}` and
  `:name`). `Events.lifecycle.cancelHint` updated in all locales to state the
  gated roster-notification behaviour.
- **Test** — `Services/tests/event_notifications_test.php` (50 assertions):
  default-off gate, cancel/update/reminder behaviour, material-vs-cosmetic,
  dedupe-key stability/distinctness, watermark idempotency, org-root gate
  resolution, EventService/factory/command/migration wiring, and i18n parity.
- **OpenAPI** unchanged (451 paths / 525 ops — no new HTTP routes).

**Deliberately still open:** **L2** (refund / order-cancel) — the G3 cancel
notice informs the roster but does not reverse money; a paid-roster cancel still
needs the refund path first.

---

## 8. Delivered — follow-ups first-class in the pre-event phase

The standing constraint is that **follow-ups (by staff, leaders and members) also
manage the contact's event/course attendance registrations, reusing the
Events/Courses tables — no fork.** That linkage existed
(`Referrals\ContactBookService::recordAttendance()`), but it wrote **straight
into `event_registrations`/`enrollments`**, bypassing the pre-event gates and the
new G3 notifications. This workstream makes those follow-up registrations
**first-class** in the pre-event phase.

**New / changed:**
- **Ports** `EventRegistrarPort` / `CourseEnrollerPort` + adapters
  `EventRegistrarAdapter` / `CourseEnrollerAdapter` (in `Referrals\Services`) —
  the narrow seams the outreach module uses to reach the platform's own
  `Events\RegistrationService::register()` and `Courses\EnrollmentService::enroll()`
  (mirrors the Journey port/adapter precedent, so Referrals doesn't hard-couple
  to those modules and stays unit-testable).
- **`ContactBookService`** — `recordAttendance()` now routes event/course
  registration **through the registrar/enroller** (extracted `registerForEvent()`
  / `enrollInCourse()` with a legacy direct-write fallback only for unwired unit
  tests). Result: a follow-up registration now obeys **published-only + ATOMIC
  capacity + live-hold + ordered waitlist** gates, a **full event waitlists** the
  contact (surfaced to the follower), and a **failed gate** (draft/closed event)
  is **not** recorded as a follow-up touch. Provenance `source_ref=contact:{id}`
  and group attribution are preserved.
- **First-class in G3** — because the registration is now a normal roster row, an
  event's **change/cancel fan-out and pre-event reminders (§7)** automatically
  reach follow-up-sourced registrants; no extra wiring needed (covered by test).
- **Analytics** — `Events\AnalyticsService::dashboard()` adds a resource-light
  `people.follow_up_sourced` metric (ONE indexed `source_ref LIKE 'contact:%'`
  count) surfaced as a KPI on the analytics page, so organizers see how much of
  the roster came from outreach follow-ups. New `Events.analytics.kFollowUpSourced`
  across all 6 locales.
- **Factory** `Referrals\Config\Services::contactBook()` wires the real adapters
  from `EventServices::eventRegistrations()` + `CourseServices::enrollments()`
  (no dependency cycle — Events/Courses do not import Referrals). Seeder
  `OutreachDemoSeeder` now selects a **published** event (the gate refuses drafts).
- **Test** — `Referrals/Services/tests/followup_pre_event_registration_test.php`
  (34 assertions): routing through the ports, gate-failure short-circuit,
  waitlist surfacing, course enrolment, provenance, service/factory/analytics
  wiring, i18n parity.

**Who:** members (owner-scoped), leaders (subtree via `GroupScopeResolver`), and
staff (`bulkCreate` bounded to the leader's scope) all reach this same path.

**Now enforced:** the registrar previously enforced published/capacity/waitlist
but not the invite policy — **G5 (§9) closes that** behind this same seam, so a
follow-up (assisted) or a group member registers fine while a stranger is turned
away from an invite-only event.

---

## 9. Delivered — G5 (invite-policy enforcement)

`registration_policy = 'invite'` was declared but never enforced — an invite-only
event behaved exactly like an open one. G5 makes it real and **fail-closed**.

**Valid-invite sources (any one grants eligibility):**
1. **Assisted** — a staff/leader/organizer (or a follow-up) registers a person
   on their behalf; the authorized caller vouches (`assisted_by`).
2. **Group eligibility** — the registrant is an active member of the event's
   hierarchical group **or any descendant** (the event was created for that part
   of the tree). One closure-backed membership read.
3. **Direct invite** — an `event_invitations` row names the user, or matches
   their **email** / **E.164 phone** (matched to the user's stored identity). An
   email/phone invite is ALSO delivered over Notifications carrying the shareable
   link (below), so the invitee receives the same URL that is broadcast publicly.
4. **Shareable link** — the ONE cloaked, per-event URL (`event_invite_links`).
   Unlike a direct invite it is **reusable and broadcast** — posted on social
   media *and* enclosed in direct email/SMS invites. Its reach is bounded by a
   configurable **mode**: `expiry` (usable until a date, or until manually
   disabled), `max_redemptions` (usable up to a redemption cap), or `capacity`
   (usable while seats remain — the registrar's atomic capacity gate is
   authoritative). The token is unguessable but, being published on purpose,
   **not secret**; only its hash is the indexed lookup key. Exactly one active
   link per event; `rotate` mints a fresh token, and the link can be toggled off
   without touching direct invites.

Anyone else is rejected `INVITE_REQUIRED` (403). Open events stay always-eligible;
closed stay always-blocked.

**Signed-in vs guest.** A signed-in visitor who opens the link registers through
the normal self-service `register` (their session identity + the link token). A
**not-signed-in** visitor uses `register-guest`: they submit name/contact, are
captured as a **prospect owned by the link's creator (the sponsor)**, and are
registered through the assisted follow-up path — turning a broadcast-link sign-up
into a real outreach lead.

**New / changed:**
- **Migration** `2026-09-14-000071_CreateEventInvitations` — two additive,
  nullable tables: `event_invitations` (direct allow-list: channel
  user|email|phone, status, notified_at, expiry; indexed by event+identity) and
  `event_invite_links` (one cloaked link per event: token + token_hash, mode,
  max_redemptions/redeemed_count, active, expiry, created_by).
- **`InvitationService`** — `issue()` (direct), `generateLink()` /
  `setLinkActive()` / `getLink()` (shareable link), `eligibility()` (the gate),
  `consumeFor()` (marks a direct invite accepted; increments a link's redemption
  counter), `resolveInviteeUserId()`, `markNotified()`, `revoke()`,
  `listForEvent()`. Resource-light: bounded indexed reads, cheapest signal first;
  never a per-row scan.
- **`RegistrationService`** — ctor takes an optional `InvitationService`;
  `register()` gates invite-only events (fail-closed even when the gate is unwired
  and the call isn't assisted) and consumes the winning invite after commit.
  `assisted_by` / `invite_token` pass-through opts.
- **Controller + routes** — organizer **invitations console**
  (`GET /events/{id}/invitations`), direct issue (`POST …/invitations`), link
  generate/rotate (`POST …/invitations/link`), link enable/disable
  (`POST …/invitations/link/toggle`), revoke (`POST …/invitations/{iid}/revoke`) —
  all `authorize:event.create,any` (+webcsrf); plus public **guest sign-up**
  (`POST …/register-guest`, webcsrf + ratelimit) and self-register forwarding the
  `?invite=` token. New bespoke, CSP-safe, no-JS `invitations.php` view (link
  panel + direct-invite form + table).
- **Delivery** — direct email/phone invites go out over the Notifications module
  (`eventInviteSender` → the same `NotificationSenderAdapter` the EventNotifier
  uses) carrying the shareable link; recorded via `markNotified`.
- **Guest→prospect** — `ContactBookService::captureGuestFromInvite()` creates the
  sponsor-owned prospect and registers via the assisted path.
- **i18n** — reworked `Events.invite.*` block (direct-invite + shareable-link +
  guest copy) across all 6 locales, 43 keys at parity.
- **Test** — `Services/tests/event_invite_policy_test.php` (67 assertions):
  open/closed short-circuit, fail-closed stranger, all eligibility sources,
  link reusability + all three modes + manual disable + rotate, direct-invite
  revoke, and service/factory/route/controller/i18n + guest-capture wiring.
  **OpenAPI** regenerated (456 paths / 531 ops).

---

## 10. Delivered — G4 (event-scoped invitation counts)

The mobilization funnel reported **"invitations"** as the count of *every prospect
in the organization* — so each event's report was inflated with the entire
address book, identical for every event regardless of who was actually invited.
G5 gave events a real invitation linkage; G4 re-points the counts at it.

**What changed:**
- **`InvitationService::countInvitationsFor($eventId)`** — one place returning
  `{direct, redemptions, total}`: `direct` = direct invitations issued for the
  event (`event_invitations`, excluding revoked); `redemptions` = sign-ups that
  came through the shareable link (`event_invite_links.redeemed_count`); `total`
  = their sum. Bounded, indexed reads.
- **`EventService::expectedAttendance()`** — `invitations` is now the event-scoped
  total, and the payload adds `invitations_direct` / `invitations_link` so the
  funnel can show the split. The old `prospects WHERE organization_id` scan is
  gone.
- **`ReportService::mobilization()`** — same event-scoped `invitations`, plus a
  re-based `converted_prospects` = registrations for the event that originated
  from an outreach follow-up (`source_ref LIKE 'contact:%'`), the event-scoped
  analogue of the old org conversion figure. Roll-up (`subtreeRollup`) sums the
  corrected per-event `mobilization.invitations`, so ancestor totals are now
  meaningful rather than `events × whole-address-book`.
- **View + i18n** — `attendance.php` shows a muted "Direct N · Via link N"
  breakdown under the invitations stat; new `Events.invitationsDirect` /
  `Events.invitationsLink` keys across all 6 locales.
- **Test** — `Services/tests/event_invitation_counts_test.php` (14 assertions):
  direct excludes revoked, link redemptions counted, other events don't bleed in,
  the 500-prospect address book no longer inflates the figure, and both call
  sites are re-pointed. No route/OpenAPI change.

A broadcast link has no fixed "invited population", so counting its *redemptions*
(actual sign-ups) rather than an impossible "invited" number is the honest figure;
direct invitations remain a true invited count. The two are reported separately
and summed.

---

## 11. Suggested sequencing (updated)

1. ~~**L1 + G7 + G6** (lifecycle-guards)~~ — ✅ **DONE** (§6).
2. ~~**G3 (reminders + change/cancel notifications)**~~ — ✅ **DONE** (§7).
2b. ~~**Follow-ups first-class in pre-event** (route through registrar/enroller)~~ — ✅ **DONE** (§8).
2c. ~~**G5 (invite-policy enforcement)**~~ — ✅ **DONE** (§9).
2d. ~~**G4 (event-scoped invitation counts)**~~ — ✅ **DONE** (§10).
3. ~~**L2 (refund / order-cancel)**~~ — ✅ **DONE** (§12). Maker-checker money
   reversal; a paid-event cancel now auto-requests refunds for every paid order.
4. ~~**L3 (close automation)**~~ — ✅ **DONE** (§13). `event.completed` domain
   event + config-gated auto-complete sweep (`events:close-due`), default off.
5. **G8 (waitlist promotion on capacity raise) + L5 (subtree rollup) + L4
   (archive) + L6 (walk-in reconcile)** — polish.

### Recommendation
The **lifecycle-guards trio (§6)**, **G3 (§7)**, **follow-ups-first-class (§8)**,
**G5 invite enforcement (§9)** and **G4 invitation counts (§10)** are shipped, so
the pre-event phase is coherent end-to-end: publish is gated, cancel/update notify
the roster, reminders drive show-rate, follow-up registrations obey the same
gates, invite-only events are fail-closed, and the mobilization report is now
event-honest. With **L2 (refund / order-cancel)** shipped (§12), an event-cancel
also *reverses money* — a paid-event cancel auto-requests a refund for every paid
order under a maker-checker workflow. And with **L3 (close automation)** shipped
(§13), a finished event no longer lingers in `published`: a config-gated sweep
auto-completes it through the same `complete()` path and announces an
`event.completed` domain event, so the post-event phase (reports, certificates)
runs off one honest terminal state. The next step is **G8 (waitlist promotion on
capacity raise)**.

---

## 12. L2 — Ticket refund / order-cancel ✅ **SHIPPED**

**Gap.** Paid ticketing could take money (`event_orders`/`event_order_items`,
provider checkout) and the order schema even had `refunded`/`cancelled` states,
but **no code ever set them** — so a paid-event cancel (G3) notified the roster
without reversing a cent, and there was no organizer path to refund a single
order. L2 closes that.

**Design — mirrors the VBCS contribution refund** (`Contributions/…/RefundService`):

- **Maker-checker.** `request` → `approve` → `execute`, with **segregation of
  duties**: the approver must differ from the requester (`SOD_SELF_APPROVAL`,
  403). Reject is available from `requested`.
- **Money reversal is out-of-band.** `execute` does not call a gateway inline; it
  stages an **`order.refund` outbox** message (`aggregate_type=event_order`) that
  the provider worker consumes and posts back a `provider_refund_id`. Execution
  is **idempotent** (re-executing an executed refund is a no-op; `failed` is
  retryable) and runs in one transaction that also flips order + items to
  `refunded`, restores per-ticket-type inventory
  (`quantity_sold = GREATEST(quantity_sold - qty, 0)`), and releases each
  attendee's registration via `RegistrationService::cancel` (which **promotes the
  waitlist**).
- **Paid-event cancel fan-out.** `EventService::cancel()` now calls
  `requestForCancelledEvent`, which auto-creates a refund **request** for every
  *paid* order of the event (idempotent; pending/other-event orders ignored).
  Approval + execution stay manual. `cancel()`'s payload gains
  `refunds_requested`.
- **Organizer-only.** No attendee self-refund. The **request** step is authorized
  by `event.tickets.manage` (organizer); **approve/reject/execute** and the
  pending console reuse `contribution.refund.approve` (finance). **Zero new
  permission bits** — the frozen 41-entry `PermissionBits` map is untouched.

**Delivered:**

- Migration `2026-09-14-000072_CreateEventOrderRefunds.php` — table
  `event_order_refunds` (status `requested|approved|executed|rejected|failed`,
  `source manual|event_cancel`, `provider_refund_id`, 3 indexes).
- `Events/Services/OrderRefundService.php` — request / approve / reject / execute
  / requestForCancelledEvent / pending.
- Wiring: `EventService` cancel fan-out; `Events/Config/Services.php`
  `orderRefunds()` factory; `TicketingController` 5 actions + PRG/JSON console;
  routes `orders/{id}/refunds` (POST) and the `event-refunds` group
  (`pending` GET, `{id}/approve|reject|execute` POST); bespoke no-JS CSP-safe
  `Views/refunds.php`; `Events.refund.*` i18n across all 6 locales; a
  `events.refunds` menu item (reuses `contribution.refund.approve`).
- Tests: `order_refund_test.php` (28 assertions — refundability, idempotent
  request, SOD, execute reversal effects, execute idempotency + guards, reject,
  event-cancel fan-out, pending console). Full suite green (**162 files /
  11,974 assertions**); OpenAPI regenerated to **461 paths / 536 ops**.

---

## 13. L3 — Close automation ✅ **SHIPPED**

**Gap.** A published event that had already ended stayed `published` forever
unless an organizer remembered to press **Complete**. That stale state is
dishonest — the attendance/mobilization reports and the certificate flows key off
the terminal status (`completed` / `completed_no_attendance`) — and it blocks the
post-event phase. There was also no domain event announcing a completion for
downstream workers to react to.

**Design — mirrors the G3 reminder-sweep architecture** (same roster port, same
hierarchical-config gate, same command shape):

- **One `complete()` path.** The manual button and the sweep both call
  `EventService::complete()`. It now stamps `completed_at` and stages **exactly
  one `event.completed`** outbox domain event. The terminal-status write is the
  idempotency guard — re-completing an already-terminal event short-circuits and
  stages nothing — so there is no separate dedupe key to manage. The
  attended-vs-`completed_no_attendance` decision is unchanged (still driven by
  real `event_attendance`).
- **`EventCloser` sweep** (`processDueClosures`) finds published events whose
  scheduled finish (`ends_at`, falling back to `starts_at` when open-ended) is at
  least `--grace` hours in the past and closes each through `complete()` — never
  a fork. A running-late event inside the grace window is left alone.
- **Feature gate (standing rule).** HIERARCHICAL GROUP CONFIG via
  `EventConfigPort`, capability **`events.autoclose.enabled`**, **DEFAULT OFF**.
  A gated-off (or config-less) org is *skipped*, not closed. The manual
  `complete()` button is unaffected by the gate — an organizer can always close
  their own event by hand. The gate is resolved **once per event**, not per
  anything, and an org-wide (group-less) event resolves against the org-root
  group so an org-level default can enable it everywhere.
- **`event.completed` is a recognised topic.** `JobRouter` now routes it to a
  light, fault-isolated `eventCompleted()` handler (avoiding unknown-topic
  warnings) — the seam for report finalisation / certificate artefacts. It does
  **not** re-award: attendance-driven points + journey advance already fire per
  check-in (`journey.signal.event.attended`), so completion never double-counts.
- **Resource discipline.** ONE bounded, indexed range read per run
  (`dueForClose` over the new `ev_close_idx (status, ends_at, starts_at)`),
  `COALESCE(ends_at, starts_at)` keeping it a single query with no UNION, and no
  per-event round-trip beyond the `complete()` call. Idempotent → safe on a cron.

**Delivered:**

- Migration `2026-09-14-000073_AddEventCompletionAudit.php` — `completed_at`
  audit column + `ev_close_idx` sweep index.
- `Events/Services/EventCloser.php` — config-gated close sweep.
- `EventService::complete()` — `completed_at` stamp + `event.completed` domain
  event via a new optional `?OutboxService` seam (null → pre-L3 behaviour).
- `EventRosterPort::dueForClose` + `EventRosterDbAdapter` implementation.
- `Events/Commands/EventCloseDueCommand.php` — `php spark events:close-due
  [--org=UUID | --all] [--grace=6]`.
- Wiring: `Events/Config/Services.php` `eventCloser()` factory + outbox injected
  into `events()`; `Shared/Messaging/JobRouter.php` `event.completed` topic +
  handler.
- Tests: `event_close_automation_test.php` (39 assertions — complete() domain
  event + audit + idempotency + zero-attendance, gate default-OFF, gate-ON close
  through `complete()`, gate resolved once/event, grace-window cutoff, idempotent
  re-run, full wiring). Full suite green (**163 files / 12,015 assertions**);
  OpenAPI unchanged at **461 paths / 536 ops** (L3 is CLI-only).

---

## 14. Delivered — the event committee (pre-event project management)

_A body accountable for delivering the event, with a work breakdown and an
oversight trail. Full design, tables, routes, vocabulary and testing notes live
in **`docs/EVENT_COMMITTEE_PROJECT_MANAGEMENT.md`**; this is the lifecycle-facing
summary._

**Status:** ✅ Implemented 2026-09-21 · **default OFF** (hierarchical group
capability `event_committee`) · optional per event · active from pre-event
(`draft`/`published`) through close-out · an event with no committee behaves
exactly as before.

**Why:** the module had organizers (`event_staff_roster`), a logistics plan and
expense approvals, but no named lanes, no work breakdown with owners and dates,
no late/at-risk view, and no record of who decided what before the event.

**Delivered:**

- Migration `2026-09-21-000090_CreateEventCommittees.php` — 7 tables
  (`event_committees`, `event_committee_members`, `event_workstreams`,
  `event_milestones`, `event_tasks`, `event_task_dependencies`,
  `event_committee_decisions`); one committee per event, one seat per person,
  seat delegation FK → `delegations` **ON DELETE SET NULL**, oversight queue with
  **no expiry column** (pending until a human decides).
- Support (pure): `CommitteeResponsibility` (11 lanes → existing bits, never an
  approval bit), `CommitteeOversight` (3 rungs + the major-decision matrix),
  `CommitteeConfig` (fail-closed), `WorkPlan` (roll-ups, risk, DAG order).
- Services: `CommitteeService` (governance: form/dissolve/members/chair/window),
  `EventWorkService` (plan: workstreams/tasks/deps/milestones + board/myTasks),
  `CommitteeDecisionService` (maker-checker queue + effect vocabulary).
- Authority seam: `CommitteeAuthorityPort` + `DelegationAuthorityAdapter` —
  **reach is not authority**: every governance write asks the platform PDP *with
  the group*, delegations are bounded to `event.ends_at + grace`, revoked on
  removal/dissolution/close, and auto-expired by the registered sweep
  `events.committee-expiry`. No new permission bits (41 frozen).
- Close-out: `EventService` calls `CommitteeService::onEventClosed()` on **both**
  closing paths (complete + cancel) and reports `committee_dissolved`.
- Routes/controllers/pages: 4 self-contained dark pages, no JS, CSP-clean,
  RTL-correct, six locales, every act a PRG form — `events/{id}/committee`
  (console), `events/{id}/plan` (plan + board), `event-committees` (hub),
  `event-committees/decisions` (oversight queue). Menu item `events.committees`
  unmasked (a *personal* workspace, bounded server-side).
- Tests: `committee_support_test.php` (228/0), `event_committee_test.php`
  (245/0), `committee_views_test.php` (172/0), `committee_workflow_test.php`
  (95/0). Full suite green — **248 files / 16,048 assertions / 0 failed**;
  OpenAPI regenerated at **583 operations / 504 paths**.
