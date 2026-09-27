# Events — Full-Lifecycle Theme Reconciliation Pass

_This is a **complementary** pass over the Events module, not a re-review. The
end-to-end phase-by-phase analysis already lives in
`docs/EVENT_LIFECYCLE_REVIEW.md` (create → publish → register/waitlist → check-in →
complete → close, with the G-series and L-series gaps, several **shipped**: L1
state guard, L2 refunds, L3 close automation, G3/G4/G5/G6/G7 and follow-ups). The
remaining planned work is parked in `docs/TODO_EVENT_LIFECYCLE_REMAINING.md`
(G8 → L4 → L5 → L6)._

Its purpose is to reconcile Events against the **three systemic themes** that
crystallised across the other eight reviews and the consolidation plan — so the
Events module is represented in that platform-wide picture rather than treated as
"already done." Prepared 2026-09-15, closing the lifecycle-review series. **No code
was changed.**

The three themes (from `docs/PLATFORM_LIFECYCLE_REMEDIATION_PLAN.md`):

- **Theme A** — authorization on mutating routes.
- **Theme B** — lifecycle-cascade signals (teardown fan-out).
- **Theme C** — scheduled sweeps / runners for queued/deferred work.

---

## Where Events already leads (and should be the pattern others copy)

Events is the **most mature** lifecycle in the platform and, on all three themes,
is closer to the target than any other module. Protect these:

1. **Theme A — routes are gated.** Event mutations carry `auth` +
   `authorize:` codes; the invite path is fail-closed (G5), invitation counts are
   event-scoped (G4). Events is not a source of the auth-gap theme.
2. **Theme B — cancel already cascades (partially).** `EventService::cancel`
   is a genuine fan-out, not a bare status flip: it guards state
   (`draft|published` only), requires a reason, records `cancelled_at`/`by`,
   **notifies the published roster** (G3/G7), and **auto-creates refund requests
   for every paid order** via `OrderRefundService::requestForCancelledEvent`
   (L2, idempotent per order, maker-checker execution). This is the reference
   implementation for the teardown-cascade the rest of the platform lacks.
3. **Theme C — two real sweep commands exist.** `events:close-due` (idempotent
   close + one `event.completed` code path) and `events:reminders-due` (pre-event
   reminders) are exactly the runner pattern Notifications/Gamification/Contributions
   are missing. The **unified sweep runner** (plan Theme C) should be modelled on
   these, and these two folded into it.

So Events is a *source of solutions* here. The items below are the residual gaps
where even Events doesn't yet fully satisfy a theme.

---

## Residual gaps, mapped to the themes

### E-A1 (Theme A) — none outstanding
No un-gated mutating route found in the Events routes; the earlier review's
auth-adjacent items (G5/G6/G7) are shipped. Events is clean on Theme A.

### E-B1 (Theme B) — cancel does not cascade to linked meetings (MED)
`cancel` fans out to roster notices + refunds, but does **not** touch a linked
provider **meeting**: the `meetings` row stays `scheduled`/`live` and its
join tokens stay valid (see Meetings MT3/MT5). Cancelling an event should cancel
its meeting and expire outstanding join grants.
**Fix:** emit the canonical `event.cancelled` signal (plan Theme B) and have the
Meetings module subscribe (cancel meeting + expire tokens).

### E-B2 (Theme B) — no reaction to account deactivate/merge (MED)
Grep confirms **nothing in Events reacts to `account.deactivated/suspended/merged`.**
A deactivated person keeps `registered` status and a live seat/hold; a merged
person's registrations, orders, tickets, and attendance aren't moved to the
survivor (double-counting attendance/points). Same M1/M2 cascade gap as every
other module.
**Fix:** subscribe to the account-teardown signals — release seats/holds for a
deactivated registrant, re-point registrations/orders/attendance to the survivor
on merge.

### E-B3 (Theme B) — event cancel/complete does not notify the waitlist (LOW)
Cancel notifies the confirmed roster; the **waitlist** (people who never got a
seat) isn't in that fan-out. Minor, but a waitlisted person expecting possible
promotion should be told the event is off.
**Fix:** include `waitlist_entries` in the cancel notice audience.

### E-C1 (Theme C) — ticket-hold expiry is lazy-only; no sweep releases stale held inventory (MED)
Overselling is correctly prevented by re-checking `expires_at > now` at
checkout/read time (holds are honoured only while live). But **nothing actively
expires holds** — a `held` row past `expires_at` is simply ignored on read and
lingers as `held` forever. Under load this (a) leaves `ticket_holds` growing
unbounded and (b) can make capacity math (which counts *live* holds) correct only
because every reader re-filters by time — any code path that counts `status='held'`
without the time filter would under-report availability.
**Fix:** add a `events:expire-holds` sweep (or fold into the unified runner) that
flips lapsed `held` → `expired`, so inventory accounting and cleanup don't depend
on every reader remembering the time filter. Register it in the plan's Theme C
runner alongside `close-due`/`reminders-due`.

### E-C2 (Theme C) — G8 waitlist promotion on capacity raise still parked (MED)
`RegistrationService::cancel` already promotes the earliest waitlisted entry when a
seat frees (the primitive exists). But raising `capacity` via `EventService::update`
does **not** promote from the waitlist into the new headroom — the parked **G8**.
This is a lifecycle-signal gap internal to Events (a capacity change is a signal
that should trigger promotion).
**Fix:** on `update` when `capacity` increases, call the existing promote
primitive FIFO up to the new headroom (do not fork it); notify via the G3 path.
Already captured in `TODO_EVENT_LIFECYCLE_REMAINING.md` #1 — this pass just ties it
to Theme C.

---

## Net for the platform picture

| Theme | Events status |
|---|---|
| A — route authz | ✅ satisfied (reference for others) |
| B — teardown cascade | 🟠 partial: cancel→roster+refunds shipped; **missing** meeting cascade (E-B1), account teardown (E-B2), waitlist notice (E-B3) |
| C — scheduled sweeps | 🟠 partial: close-due + reminders-due shipped; **missing** hold-expiry sweep (E-C1) and capacity-raise promotion (E-C2 / G8) |

**Consequence for the remediation plan.** Two edits to
`docs/PLATFORM_LIFECYCLE_REMEDIATION_PLAN.md` are warranted:

1. **Theme B** should name `event.cancelled` among the canonical teardown signals,
   with the Meetings module as a subscriber (E-B1), and add Events registrations/
   orders/attendance to the account-merge re-point list (E-B2).
2. **Theme C**'s unified runner should absorb `events:close-due` +
   `events:reminders-due` as the **template**, and add `events:expire-holds`
   (E-C1) to the sweep table; G8 (E-C2) rides the same capacity-change seam.

No code was changed in the course of this pass.

---

## Review series — complete

With this pass the lifecycle-review series covers every major module:

Membership · Groups · Access Control · Journey · Contributions · Referrals ·
Gamification · Notifications · **Courses** · **Meetings** · **Events (this pass)**
— consolidated in `docs/PLATFORM_LIFECYCLE_REMEDIATION_PLAN.md`.

Per the standing directive, **no implementation was started**: reviews first. The
next decision point is whether to begin **Phase 0** of the remediation plan
(route-filter test harness + the module-local HIGH bugs, now including Courses
**CO1 IDOR** and **CO2 fail-open enrollment**, and Meetings **MT2** evidence
reconciler).
