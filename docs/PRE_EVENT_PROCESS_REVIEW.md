# Pre-Event Process Review — Events module

_Reviewed 2026-09-14. Scope: everything that happens **before** an event runs —
from creation through publication, mobilization, registration, and operational
readiness (logistics/budget/staffing). Post-event surfaces (feedback,
certificates, mobilization report, media) are explicitly out of scope here and
are already ✅ DONE per `SRS_FR_COVERAGE_AUDIT.md`._

This is a **review + gap analysis**, followed by an incremental build. It grades
each pre-event process against what the code actually does today (verified by
reading the services/controllers/routes/migrations), and proposes a prioritized
backlog.

> **Build status (updated 2026-09-14):** **G1 (event edit) + G2 (shared
> validation) are ✅ SHIPPED.** See §5 for the delivered scope. Remaining gaps
> G3–G8 are still open, in the sequencing of §4. Full suite green (156 files /
> 11,706 assertions); OpenAPI 450 paths / 524 ops.

---

## 1. The pre-event lifecycle, as built

```
create (draft) ──publish──▶ published ──(registration/RSVP, mobilization,
   │                             │        logistics, budgeting, staffing)──▶ [event runs]
   │                             │
   └──────────── cancel ─────────┘
```

Event states (from `events.status`): `draft | published | cancelled |
completed | completed_no_attendance`. The pre-event window is everything in
`draft` and `published` before start.

---

## 2. What already works (strengths)

| Process | Status | Evidence |
|---|---|---|
| **Create event** | ✅ | `EventService::create()` — title/start required, sensible defaults (mode=physical, tz=UTC, policy=open, status=draft), audience JSON, consent wording. Bespoke no-JS form `create.php`. |
| **Publish gate** | ✅ (basic) | `publish()` enforces `draft → published` only; `BAD_STATE` otherwise. |
| **Cancel** | ✅ (see gap G7) | `cancel()` sets status. |
| **Registration + RSVP** | ✅ strong | `register()` is capacity-aware, atomic (`FOR UPDATE` over confirmed + live holds), idempotent, auto-waitlists at capacity, and lets a member change RSVP intent without re-queuing. Self-service funnel on `show.php`. |
| **Paid ticketing (pre-sale)** | ✅ | `TicketingService` — ticket types, promo codes, atomic holds, one-step purchase, free-order auto-confirm. Console + buyer funnel. |
| **Waitlist** | ✅ | `waitlist_entries`, positional, promotable within remaining capacity. |
| **Expected-attendance / planning projection** | ✅ | `expectedAttendance()` — invitations/responses/confirmed/positive-RSVP/waitlist, point estimate + uncertainty band, seating/materials guidance. (But see gap G4.) |
| **Logistics plan** | ✅ broad | `LogisticsService` — resources + ordering, seating areas, staff roster, suppliers, accessibility needs, catering aggregates, projection refresh. Console `logistics_plan.php`. |
| **Budget & pre-event expenses** | ✅ | `ExpenseService::setBudget()`, `submit/approve/reject/reimburse`, reconcile. Approvals console. |
| **Calendar / discovery / subscription** | ✅ | Index, month calendar, org+personal `.ics` feeds, per-event `.ics`, "My events" hub, analytics dashboard, lazy menu badge. |
| **Virtual access** | ✅ | `access_url` stored and surfaced; ICS `LOCATION`. |

**Bottom line:** the module is genuinely deep. The gaps below are mostly about
**editing/curating an event once it exists**, **enforcing the invite policy**,
and **closing the loop with registrants before the event** — not about missing
foundational tables.

---

## 3. Gaps found (prioritized)

### G1 — No way to EDIT an event after creation 🔴 **HIGH** — ✅ SHIPPED (see §5)
There is **no update path at all**: no `EventService::update()`, no
`EventController::edit/update`, no route. Once created, an event's title, time,
venue, capacity, description, mode, and policy are frozen — the only mutations
are `publish`/`cancel`/`complete`.

- **Impact:** the single most common pre-event activity (fixing a typo, moving
  the start time, raising capacity, swapping the venue) is impossible without a
  direct DB edit. This is a correctness/operability gap, not a nicety.
- **Interactions:** a time/venue change should (a) bump `updated_at` so the ICS
  `SEQUENCE` advances and subscribers' calendars update (the ICS builder already
  keys `SEQUENCE` off `updated_at` — so this is wired to work the moment edits
  land), and (b) ideally notify registrants (see G3).
- **Proposed:** `EventService::update()` (whitelist of editable columns, same
  validation as G2), `editForm`/`update` controller actions, `GET/POST
  events/{id}/edit` gated `event.create,any` (or a new `event.update`), a
  bespoke edit view mirroring `create.php`, PRG + i18n, wiring test.

### G2 — Thin create/update validation 🟠 **MEDIUM** — ✅ SHIPPED (see §5)
`create()` checks only that `title` and `starts_at` are non-empty. Not validated:
- `ends_at >= starts_at` (an event can end before it starts);
- `starts_at` sanity (far past dates silently accepted);
- `mode ∈ {virtual,physical,hybrid}`, `registration_policy ∈
  {open,invite,closed}`, `attendance_policy ∈ {checkin,streaming,manual}` — free
  text is accepted and later compared by string;
- `capacity >= 0`;
- `virtual`/`hybrid` mode with an empty `access_url` (a virtual event with no
  join link).
- **Proposed:** a shared `validate()` used by both create and update, returning
  field-keyed `Result::fail` messages; enum guards; i18n error keys.

### G3 — No pre-event reminders / change notifications 🟠 **MEDIUM**
There is no scheduled "your event is tomorrow" reminder and no "event details
changed / event cancelled" notification to registrants. `JobRouter` only handles
`event.certificate.render` and `event.media.scan` (both post-event). The RSVP
funnel captures intent but nothing closes the loop before the day.
- **Impact:** show-rate depends on reminders; a silent cancel (G7) leaves
  registrants turning up to nothing.
- **Proposed:** an `event.reminder` outbox topic scheduled at T-24h/T-1h over the
  confirmed roster, and a `event.updated`/`event.cancelled` fan-out. Reuses the
  existing outbox/JobRouter + notification templates (resource-light, batched).
  Respects the translation-merge + per-locale template rules already in place.

### G4 — `expectedAttendance` "invitations" is not event-scoped 🟠 **MEDIUM**
The `invitations` count is `COUNT(*)` of **all org prospects**, not prospects
invited to *this event*:
```php
$invitations = (int) $this->db->table('prospects')
    ->where('organization_id', $event['organization_id'])->countAllResults();
```
The code comment already flags this ("drill-down by group/link is an authorized
extension"). So the invitations figure — and every ratio derived from it in the
mobilization view — is inflated for any org that runs more than one event.
- **Proposed:** scope invitations to the event, which requires G5 (an actual
  event-invitation linkage). Until then, consider surfacing it as
  "org prospects" rather than "invitations" to avoid a misleading label.

### G5 — `registration_policy = 'invite'` is declared but NOT enforced 🟠 **MEDIUM**
`register()` handles `closed` (reject) and treats everything else as open —
**`invite` behaves exactly like `open`.** There is no `event_invitations` table,
no invite issuance/redemption, and the create form doesn't even offer the
`invite` option. The enum value, the `invitations` i18n label, and the
`source_ref` "invite attribution" column are all present but inert.
- **Impact:** an organizer who selects "invite-only" (via API) gets a fully open
  event — a silent access-expectation violation.
- **Proposed:** either (a) implement invitations properly (`event_invitations`
  with token/redemption, gating `register()` when policy=invite, feeding G4), or
  (b) if invite-only is out of near-term scope, make `create/update` **reject**
  `policy=invite` with a "not yet supported" error rather than silently
  downgrading to open. (a) is the real fix; (b) is the honesty stopgap.

### G6 — No publish READINESS checklist 🟡 **LOW/MEDIUM**
`publish()` only checks state == draft. It does not verify the event is actually
ready: has a start in the future, has a venue or access_url appropriate to its
mode, has capacity set (or explicitly unlimited), has a description. The
mobilization "funnel" and logistics projection assume a coherent event.
- **Proposed:** a soft readiness report (non-blocking warnings) shown on the
  event page + optionally a hard gate on a couple of critical fields (future
  start; virtual ⇒ access_url). Cheap once G2's validator exists.

### G7 — Cancel is silent and unguarded 🟡 **LOW**
`cancel()` sets status with **no state guard** (a `completed` event can be
"cancelled"), **no reason captured**, and **no registrant notification** (ties to
G3). Published→cancelled is the consequential transition and deserves at least a
reason + a fan-out.
- **Proposed:** guard valid transitions, capture `cancellation_reason`, emit
  `event.cancelled` to the roster, and reflect `STATUS:CANCELLED` in the ICS
  feeds (the ICS builder already emits `STATUS:CANCELLED` for cancelled rows, so
  subscribers auto-drop it — another thing already wired to work).

### G8 — Capacity increase doesn't auto-promote the waitlist 🟡 **LOW**
Raising capacity (once G1 exists) should promote waitlisted entries into
confirmed seats in position order. Today there is no promotion-on-capacity-change
path (promotion happens only implicitly via the register flow).
- **Proposed:** `RegistrationService::promoteWaitlist(eventId, upTo)` invoked when
  capacity rises, atomic, position-ordered, idempotent.

---

## 4. Suggested sequencing

1. **G1 (edit) + G2 (validation)** — one coherent workstream; edit is the
   headline gap and needs the validator anyway. Unblocks G6/G8.
2. **G7 (safe cancel + reason)** — small, high safety value, pairs with G3.
3. **G3 (reminders + change/cancel notifications)** — the mobilization loop;
   larger (outbox topics + templates) but high show-rate value.
4. **G5 (invite policy)** — decide (a) implement vs (b) honesty stopgap; (b) is
   ~an hour, (a) is a proper sub-feature that also fixes **G4**.
5. **G6 (readiness) + G8 (waitlist promotion)** — polish once the above land.

## 5. Delivered — G1 + G2 (event edit + shared validation)

Shipped following the module's bespoke/no-JS/CSP-safe/6-locale/wiring-tested
pattern. No schema change was needed (the `events` table already carries every
editable column).

**New / changed:**
- **`EventValidator`** (`app/Modules/Events/Services/EventValidator.php`) — a
  pure, DB-free validator: the single source of truth for a well-formed event.
  Guards required fields, `ends_at >= starts_at`, the three enum sets
  (`mode` / `registration_policy` / `attendance_policy`, mirroring the schema),
  non-negative integer capacity, and virtual/hybrid ⇒ `access_url`. Runs in
  **full** mode (create) or **partial** mode (patch update). Returns stable
  `Events.validate.*` lang keys.
- **`EventService::create()`** now routes through `EventValidator::validate(…,
  false)` (replacing the ad-hoc title/start checks).
- **`EventService::update()`** — the previously-missing edit path. Whitelisted
  **partial** update (`EDITABLE` columns only — never id/org/status/created_*);
  freezes terminal events (`cancelled`/`completed*` → `BAD_STATE`); validates the
  **merged** stored+incoming view so cross-field rules see the effective event;
  normalizes `audience`/`capacity`; bumps `updated_at` (so ICS `SEQUENCE`
  advances and subscribers' calendars refresh automatically).
- **`EventController::editForm()` / `update()`** — bespoke edit view + JSON;
  404 unknown, refuses terminal events, PRG with a localized flash, re-renders
  the form with sticky `$old` + a translated error banner (`humanizeError`).
- **Routes** — `GET`/`POST events/(:segment)/edit`, gated `event.create,any`
  (+`webcsrf` on POST). Segment routes ⇒ structurally non-page-like, so no menu
  entry / MenuCoverage exclusion is required.
- **View `edit.php`** — self-contained, CSP-clean, no-JS, 6-locale. Notably it
  surfaces the fields the create form omitted: `registration_policy` (incl. the
  `invite` option), `attendance_policy`, `access_url`, and `timezone`. An **Edit**
  link was added to `show.php` (draft/published only).
- **i18n** — `Events.editForm.*` + `Events.validate.*` added across all 6 locales
  (full key parity).
- **Test** — `Services/tests/event_edit_validation_test.php` (99 assertions):
  exhaustive validator cases (both modes), the `update()` contract, controller,
  routes, i18n parity, CSP/self-contained view, fr+ar render with sticky values.
- **OpenAPI** regenerated (450 paths / 524 ops).

**Deferred by design (still open):** G2's original note about hard-gating far-past
`starts_at` at publish is folded into **G6** (readiness), not the field validator,
so drafting a backdated event stays possible. G5 (invite enforcement) remains
open — the edit form now *offers* `invite`, but `register()` still treats it as
open until G5 lands.

---

## 6. Non-gaps (checked, and fine as-is)
- Registration concurrency/oversell — solid (`FOR UPDATE` + holds).
- Ticketing pre-sale, promo codes, transfers — complete.
- Logistics/budget breadth — complete; the gap there is only that the
  projection's *input* (expected attendance) inherits G4's invitation inflation.
- Calendar/discovery/subscription — complete (this session's work).

---

### Recommendation
Start with **G1 + G2** (event edit + a shared validator). It's the highest-impact
pre-event gap, is self-contained, and every other gap (readiness, waitlist
promotion, change-notifications) either depends on it or composes cleanly with
it. I can begin there on your go-ahead, following the same
bespoke/no-JS/CSP-safe/6-locale/wiring-tested pattern as the rest of the module.

---

## 7. Delivered — the event committee (pre-event project management)

✅ **2026-09-21.** The pre-event phase now has an accountable body: an optional
**event committee** per event — chairperson plus members each holding a specific
responsibility — with a full work breakdown (workstreams → tasks with owners, due
dates, dependencies, blockers → milestones, derived progress, late/at-risk
detection, a no-JS board) and a configurable, default-OFF oversight trail
(maker-checker queue, no expiry) under the group leader.

Authority is **time-bounded delegation of existing permission bits** (leader →
chair → member, bounded to the event window + grace, auto-expiring at close); no
new permission bits were added, no approval bit is ever delegated, and a seat
gives reach but not authority — the platform's decision point answers, with the
group.

Full design, schema, routes, vocabulary and test inventory:
**`docs/EVENT_COMMITTEE_PROJECT_MANAGEMENT.md`** (lifecycle-facing summary in
`EVENT_LIFECYCLE_REVIEW.md` §14).
