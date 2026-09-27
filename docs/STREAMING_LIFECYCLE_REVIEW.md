# Streaming (Broadcast / Destinations / Relay Health / Stream Giving) Lifecycle Review

_A code-grounded gap analysis of the **stream lifecycle** — how a broadcast moves
draft → live → ended, how multi-destination relays are provisioned/monitored, and
(critically) how **live giving** on a stream is captured — plus how the giving
path relates to the Contributions money lifecycle and how connection/stream
teardown must cascade._

Prepared 2026-09-15. Method: read `StreamService`, `StreamGivingService`,
`StreamRelayService`, the migrations, the routes; every finding cites code. **No
code was changed.**

Special attention: `StreamGivingService` is a **money path**. The earlier
Contributions review (C1–C10) established the ledger/idempotency/refund discipline
money must meet; this review checks whether stream giving honours it or forks a
parallel one.

---

## 1. The streaming model at a glance

- **`streams`** — `status` draft → live → ended; `access_policy` public|restricted;
  `scheduled_at`; created via `create` (→ draft), `goLive` (→ live +
  destination dispatch), `end` (→ ended).
- **`stream_destinations`** — per-provider relay targets: pending → ready/active /
  error, provisioned via `provisionDestinations` (uses the Integrations
  credential vault; falls back to a hosted link on provider error).
- **`stream_relay_health`** — heartbeat ingest; repeated `down`/degraded signals
  flagged.
- **Giving** — `stream_giving_config` (widget, suggested amounts, min/max, currency,
  anonymity + acknowledgement policy) and `stream_giving_intents` (links a stream
  to a Contributions intent).
- **Overlays / viewers / reactions / engagement** — presentation + analytics.

**The good news up front — protect these:**

1. **Stream giving REUSES the Contributions money path — it does NOT fork a ledger.**
   `StreamGivingService::give` validates amount/min/max/currency + anonymity policy,
   then delegates to `ContributionService::createIntent(...)` and only records a
   thin `stream_giving_intents` **link** row. This is exactly the "extend, don't
   fork" discipline the platform requires for money — the Contributions ledger,
   provider reconciliation, and refund path (C-series) all apply automatically.
   **This is the single most important thing to protect in this module.**
2. **Idempotency is deterministic** — `give` passes an `idempotency_key` through to
   `createIntent` (double-tap → one intent), and the link row is idempotent on
   `intent_id`. No double-charge from a jittery widget.
3. **Privacy defaults are correct** — recognition/anonymity resolves
   conservatively: an anonymous/none recognition or absent user forces anonymous
   display; **amount reveal requires BOTH the giver's opt-in AND the organizer's
   policy gate** (`ack_show_amount_allowed`). The widget "NEVER exposes anything"
   beyond authorized progress. This matches the platform's private-by-default rule.
4. **Destinations degrade gracefully** — a provider provisioning error falls back
   to a hosted link rather than failing the broadcast, and relay health is tracked.
5. **Credentials stay in the vault** — destinations use the Integrations vault, not
   inline secrets.

The gaps: an unguarded stream state machine, giving that isn't tied to stream
state, and the usual teardown/sweep seams.

---

## 2. Findings, ranked

### ST1 — `give()` doesn't check the stream is live: giving works on draft/ended streams (MED — money-adjacent)

`give` gates on the giving **config** (enabled + widget_enabled + cause_id) but
never checks the **stream's** `status`. So a contribution can be initiated against
a stream that is still `draft` or already `ended` — the widget/config outlives the
broadcast. Because it flows into a real Contributions intent, that's money captured
outside the live window the giver believed they were supporting.

**Fix direction:** in `give`, require `stream.status === 'live'` (or an explicit
configurable pre/post-roll window); on `end`, disable the giving widget so the
post-stream widget can't keep taking intents.

### ST2 — `transition()` is unguarded: any status → any status, no matrix, no audit (MED)

The private `transition` (used by `goLive`/`end`) does a bare `UPDATE status=?`
with **no legal-move matrix** — an `ended` stream can be flipped back to `live`, a
`draft` can jump straight to `ended`, etc. No reason, no actor, no audit/transition
record. Same unguarded-setter pattern as Meetings MT1 and Journey J3 — and here it
compounds ST1 (re-opening an ended stream re-enables giving).

**Fix direction:** enforce `draft → live → ended` (terminal `ended`), record the
transition, and adopt the Identity `AccountLifecycleService.transition` pattern
(reason/actor/audit).

### ST3 — Ending a stream doesn't cascade: destinations keep relaying, giving stays open (MED)

`end` sets status and dispatches to destinations, but there's no guaranteed
teardown: `ready/active` destinations aren't torn down, the giving widget isn't
disabled (ST1), and no `stream.ended` signal is emitted for other modules
(analytics rollup, gamification for the streamer, journey). A stream that "ended"
can leave live relays and an open giving surface.

**Fix direction:** on `end`, deprovision/mark destinations, disable giving, and
stage `stream.ended` on the outbox for downstream consumers.

### ST4 — Relay health is ingested but never swept: no stale-stream/stuck-relay runner (MED) — ✅ RESOLVED 2026-09-16

**Resolution:** `StreamRelayService::sweepStaleStreams()` + `RelayHealthSweep`
(key `streaming.relay-health`) folded into the unified sweep runner. Bounded,
idempotent, config-gated (`streaming.relay_health_sweep` flag, default OFF,
org-wide + per-group override via `SettingsService`): auto-ends `live` streams
whose last heartbeat (or `started_at`, when none) is older than a staleness
window (default 5 min), opening a monitor incident once and stamping
`relay_state=down`. Auto-failover of a persistently-down relay to a bypass
destination remains out of scope (observability + auto-end shipped). See
`docs/TODO_REMAINING_BACKLOG.md` §A.1.


`StreamRelayService.heartbeat` records health and flags repeated `down`/degraded,
but there is **no command/runner** (no `Commands/` dir) that acts on it — nothing
auto-fails-over a persistently-down relay, auto-ends a stream whose relays all
died, or closes streams stuck `live` with no heartbeat. Health is observed, not
acted upon. Same "signal captured, no processor" theme as Meetings MT2 / Events.

**Fix direction:** add a relay/stream health sweep (fold into the Theme C unified
runner): fail over or mark down-too-long destinations, and auto-end streams
`live` with no heartbeat past a threshold.

### ST5 — No reaction to Integrations connection teardown or account/group teardown (MED — cross-review coupling)

Destinations depend on an Integrations connection, but nothing reacts when that
connection is disabled/revoked (Integrations IN1) — the stream keeps trying to
relay through dead credentials. Likewise no reaction to `account.*`/`group.*`
teardown for the streamer/owning group.

**Fix direction:** subscribe to `connection.disabled/revoked` (IN1) → mark affected
destinations unusable / fall back to hosted link; subscribe to account/group
teardown per Theme B.

### ST6 — No `Services/tests/` for streaming or stream giving (MED)

No test directory. The money-adjacent `give` path (privacy resolution,
min/max/currency validation, idempotency-key passthrough, and — once fixed —
live-only gating) is **untested**, as is the transition guard. Given giving touches
money, this is the highest-value coverage gap here.

**Fix direction:** add tests for `give` (privacy/anonymity resolution, amount
bounds, idempotency dedupe, live-only), the transition matrix, and destination
provisioning fallback. Red before ST1/ST2.

---

## 3. Suggested sequencing

1. **ST6** — tests (red), giving path first (money-adjacent).
2. **ST1** — gate `give` on stream `live` (+ disable widget on `end`).
3. **ST2** — guard the transition matrix + audit.
4. **ST3** — `end` cascades (deprovision destinations, disable giving, emit signal).
5. **ST4** — relay/stream health sweep into the Theme C runner.
6. **ST5** — consume connection + account/group teardown signals.

## 4. Cross-review couplings (explicit)

- **Stream giving ↔ Contributions (C1–C10):** giving reuses the Contributions
  ledger/intent/refund path — protect this; it means C-series fixes cover stream
  giving automatically. **Do not let anyone add a parallel stream ledger.**
- **ST2 ↔ Meetings MT1 / Journey J3 / Streaming:** shared unguarded-transition
  pattern; adopt Identity's guarded transition.
- **ST4 ↔ Meetings MT2 / Events / Notifications N1:** "signal captured, no
  processor" → unified sweep runner (Theme C).
- **ST5 ↔ Integrations IN1:** Streaming is a first consumer of the
  `connection.disabled/revoked` signal.

No code was changed in the course of this review.
