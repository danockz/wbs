# Meetings (Provider Sessions / Join Access / Attendance Evidence) Lifecycle Review

_A code-grounded gap analysis of the **meeting lifecycle** — how a provider
meeting/webinar (Zoom/Meet/Teams/Jitsi/link) is created against a scoped
integration connection, how per-user join access is granted via short-lived
tokens, how the session transitions scheduled→live→ended→canceled, and how
provider-reported presence is captured as candidate attendance evidence — plus how
these interact with the events, integrations and journey lifecycles._

Prepared 2026-09-15, as the tenth review in the sequence. Method: read the
migration, the single service (Meeting), the controller, and the routes; every
finding cites the code it rests on. **No code was changed.**

Scope note: Meetings is the smallest module (one migration, one service). Its job
is to be a thin, secure bridge to third-party conferencing and to feed *candidate*
attendance to the Events verification policy — it deliberately does **not** decide
attendance itself.

---

## 1. The meetings model at a glance

**Tables** (`000020` CreateMeetings):

- **`meetings`** — `provider`, `mode` `meeting|webinar`, `event_id` (linked or
  standalone), `connection_id` (scoped integration creds), `external_ref`
  (provider id, not a secret), `join_url`, `access_policy` `public|restricted`,
  `status` `scheduled|live|ended|canceled`.
- **`meeting_participants`** — per user, `role` `host|cohost|attendee`,
  `join_token_hash` (SHA-256 of a short-lived per-user token), `token_expires_at`.
  UNIQUE `(meeting_id, user_id)`.
- **`meeting_attendance_evidence`** — provider-reported presence: `user_id`
  (nullable if unmatched), `provider_ref`, join/leave/duration, `applied` flag
  (became platform attendance? — "event policy decides").

**State machine:** `scheduled → live → ended` (+ `canceled`).

**The good news up front.** The security-sensitive parts are well built and
should be protected:

1. **Join-token handling is correct** — `grantAccess` mints a 32-byte random
   token, stores only its SHA-256 hash with a TTL, and returns the plaintext
   **once**; `verifyJoinToken` checks existence, expiry, then `hash_equals`
   (constant-time). No plaintext token is ever persisted. This mirrors the
   credential-setup token mechanism used elsewhere.
2. **Secrets stay out of the row** — `external_ref`/`join_url` are documented as
   authorized references, not public secrets; provider credentials live behind the
   `connection_id` integration scope, not in the meetings table.
3. **Provider calls respect the circuit breaker** — `create` fails fast (FR-INT-012)
   when the provider's circuit is open rather than retrying a broken provider, and
   a provider failure surfaces as an error rather than silently storing a
   half-created meeting.
4. **Attendance is candidate-only by design** — `recordAttendanceEvidence` writes
   `applied=0` and explicitly leaves the become-real-attendance decision to the
   linked event's verification policy (correct separation of concerns).
5. **Routes are fully gated** — every mutation carries
   `authorize:meeting.manage` (+ `webcsrf`); the index is `meeting.manage,any`.

The findings below are the gaps around that solid core.

---

## 2. Findings, ranked

### MT1 — `transition` has no state-machine guard: any status → any status (MED)

`transition` validates only that the target string is one of `scheduled|live|
ended|canceled`, then writes it — with **no legal-move matrix**. So a `canceled`
meeting can be revived to `live`, an `ended` meeting can go back to `scheduled`,
and a meeting can skip straight from `scheduled` to `ended`. There is no guard,
no reason captured, and no audit/transition record of who changed the status.

This is the same unguarded-`setStatus` pattern flagged in Journey J3 and
Membership M9/M10 — a status column mutated freely with no state machine and no
trail. For a session that gates join access and feeds attendance, "un-cancel" and
"un-end" are semantically dangerous (evidence recorded after `ended`, tokens
usable after `canceled`).

**Fix direction:** enforce an allowed-transition matrix (`scheduled→live→ended`;
`scheduled|live→canceled`; terminal `ended`/`canceled`), require a reason on
`canceled`, and record the transition (audit row). Decide whether reaching
`ended`/`canceled` should invalidate outstanding join tokens (see MT3).

### MT2 — Attendance evidence is never applied: the `applied` flag has no reconciler (MED)

`recordAttendanceEvidence` writes rows with `applied=0` and the docstring promises
the linked event's verification policy applies them "separately." But **nothing
ever sets `applied=1`** — grep confirms `meeting_attendance_evidence` is written
only by this service and read by no reconciler (the `applied` hits elsewhere are
KioskService's unrelated scan flow). So provider-reported presence is captured and
then stranded: it never becomes platform attendance, never feeds the event's
attendance verification, and therefore never drives the downstream
attendance→points→journey signals.

This is the "candidate queue with no processor" instance of the platform-wide
missing-runner theme (cf. Notifications N1 deferred, Gamification G2 held,
Contributions C4 reconciliation) — here the stranded items are attendance records
for virtual/hybrid events.

**Fix direction:** implement the evidence→attendance reconciler the design
anticipates — a server-side pass (per the event's verification policy: min
duration, matched user, dedupe) that promotes qualifying evidence to real
`event_attendance` and sets `applied=1`, staging the same attendance signal a
physical check-in produces. Register it in the unified sweep runner. Also handle
**unmatched evidence** (`user_id IS NULL`, provider email/ref only) — resolve it
against verified `notification_channels`/user emails or queue it for manual match,
rather than dropping it.

### MT3 — Join tokens and grants aren't invalidated on cancel/end or access-policy change (MED)

`verifyJoinToken` checks only the token's own existence/expiry/hash — it does
**not** check the meeting's `status` or `access_policy`. So a granted token
remains valid (until its TTL) even after the meeting is `canceled` or `ended`, and
even if `access_policy` is tightened from `public` to `restricted`. A participant
granted access to a since-canceled meeting can still pass the platform's
join-token check.

**Fix direction:** in `verifyJoinToken`, additionally require the meeting be in a
joinable status (`scheduled`/`live`) and re-check `access_policy`; on
`transition` to `ended`/`canceled`, expire outstanding participant tokens
(null the hash or set `token_expires_at = now`).

### MT4 — `create` doesn't validate the linked event's status/scope, and standalone meetings are unbounded (LOW–MED)

`create` validates the provider and (good) respects the circuit breaker, but when
`event_id` is supplied it doesn't appear to verify the event exists, is not
canceled, or is in the creator's group scope; and a standalone meeting
(`event_id` NULL) has no lifecycle tie at all — nothing ends or cleans it up. So a
meeting can be created against a canceled/foreign event, or created standalone and
left `scheduled` forever.

**Fix direction:** when `event_id` is set, verify the event is live and in scope
(reuse the events scope check); for standalone meetings, define an expiry/cleanup
so abandoned `scheduled` meetings don't accumulate (fold into MT2's sweep).

### MT5 — No reaction to linked-event or account teardown (MED — cross-review coupling)

Nothing reacts when the linked event is canceled/archived (the meeting stays
`scheduled`/`live`, tokens stay valid — see MT3) or when a participant's account
is deactivated/merged (their `meeting_participants` grant persists; a merged
user's evidence isn't re-pointed). Same lifecycle-cascade gap as the rest of the
platform.

**Fix direction:** consume the event-cancel/archive signal (cancel the linked
meeting, expire tokens) and the account teardown signals (revoke grants, re-point
evidence). Sequence with the platform lifecycle-signal bus.

### MT6 — No tests for the meeting lifecycle (LOW)

There is no `Services/tests/` directory for Meetings. Token verification (the
security-critical path), the transition guard (MT1), and evidence reconciliation
(MT2) are untested.

**Fix direction:** add tests for token grant/verify (incl. expiry + wrong token),
the transition matrix, and evidence→attendance application — red before MT1/MT2.

---

## 3. Suggested sequencing

1. **MT6** — add token/transition/evidence tests (red).
2. **MT2** — implement the evidence→attendance reconciler (the stranded-data fix;
   unblocks virtual-event attendance → points → journey) and register it in the
   sweep runner.
3. **MT1** — transition state-machine guard + audit.
4. **MT3** — invalidate tokens/grants on cancel/end + access-policy re-check.
5. **MT4** — validate linked-event status/scope on create; standalone cleanup.
6. **MT5** — consume event-cancel + account teardown signals.

## 4. Cross-review couplings (explicit)

- **MT2 ↔ Notifications N1 / Gamification G2 / Contributions C4 / Journey J1:**
  the recurring "candidate/queue with no scheduled runner" theme — here it strands
  virtual-event attendance evidence.
- **MT1 ↔ Journey J3 / Membership M9/M10:** unguarded status transition with no
  legal-move matrix or trail.
- **MT5 ↔ Events (cancel/archive) + Membership M1/M2:** teardown of the linked
  event or a participant account must fan out to the meeting.
- **MT2/MT3 ↔ Events attendance verification:** meetings feed candidate evidence;
  the event policy is the intended (but currently absent) applier.

No code was changed in the course of this review.
