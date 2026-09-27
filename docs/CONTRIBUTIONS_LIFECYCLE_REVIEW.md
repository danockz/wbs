# Contributions (Giving / Money-Path) Lifecycle Review

_A code-grounded gap analysis of the **contribution lifecycle** — how money
enters (intent → checkout → succeeded, or manual maker-checker → posted), how it
is recorded in the double-entry ledger and attributed, how it leaves (refund
request → approve → execute; provider refund; dispute/chargeback), how recurring
commitments advance, and how provider webhooks and reconciliation keep the record
honest — plus how these interact with the group, journey and access-control
lifecycles reviewed earlier._

Prepared 2026-09-15, as the fifth review in the sequence after membership, groups,
access control, and journey/activities. Method: read the two migrations, the
eleven services (Contribution, Refund, ManualContribution, Ledger, Commitment,
WebhookInbox, WebhookSignatureVerifier, Cause, Partnership, Metrics,
RewardCoordinator), the five controllers, the routes, and the `CommitmentDue`
command; every finding cites the code it rests on. **No code was changed** — this
is the review that precedes any fixes.

Scope note: this review is about a **contribution as a first-class financial
object** — its state machine, the balanced ledger behind it, and the housekeeping
(webhooks, reconciliation, commitment sweep) that keeps stored state matching the
provider's truth. Points/VBCS metrics are treated as correct downstream
consumers (they run off the outbox) and appear only where a money transition
should have propagated to them.

---

## 1. The money model at a glance

**Tables** (`000016` CreateContributions, `000032` VbcsMetricsAndPartnership):

- **`causes`** — the giving target. `status` `draft|active|closed`, `visibility`
  `public|group|private`, `target_minor`, owning `group_id`.
- **`contribution_intents`** — the donor's declared intent. `status`
  `intended|pending|succeeded|failed|cancelled`, `idempotency_key`, `checkout_ref`.
- **`contributions`** — the recorded gift. `state`
  `pending|succeeded|failed|refunded|reversed|disputed`, `amount_minor` /
  `fee_minor` / `net_minor`, `source` `online|manual`, `recognition`.
- **`payment_transactions`** — signed money movements (`charge|refund|fee|
  adjustment`).
- **`journal_entries` + `journal_lines`** — the double-entry ledger. Entry
  `state`, `source_ref` (idempotency for posting); lines carry `account` +
  `direction` `debit|credit`.
- **`manual_contribution_records`** — offline giving with maker-checker. `status`
  `submitted|approved|cleared|posted|rejected`, `approved_by` (≠ submitter).
- **`refund_requests`** — `status` `requested|approved|executed|rejected|failed`,
  `approved_by` (≠ requester).
- **`webhook_inbox`** — `status` `received|processed|quarantined|failed`,
  dedup on `(provider, provider_event_id)`.
- **`reconciliation_cases`** — `kind` `missing_ledger|amount_mismatch|
  orphan_webhook|…`, `status` `open|resolved`.
- **`giving_commitments`** (recurring) + partnership/metrics tables.

**Money-in state machine:**

```
intent: intended --attachCheckout--> pending --webhook--> succeeded | failed | cancelled
contribution: (created succeeded on markSucceeded) --> refunded | reversed | disputed
manual:  submitted --approve(SoD)--> posted   (creates a succeeded contribution)
         submitted --reject--> rejected
refund:  requested --approve(SoD)--> approved --execute--> executed | failed
                  \--reject--> rejected
commitment: active --processDue--> active(next) | completed ; active --cancel--> cancelled
```

**The good news up front.** This is a genuinely strong financial module and
several properties should be protected by any later change:

1. **Idempotency is everywhere on the happy path** — `createIntent`
   (idempotency_key), `markSucceeded` (one contribution per intent),
   `refund.execute` (re-execute is a no-op), and `webhook_inbox`
   (`(provider, provider_event_id)` dedup with a race-safe insert-catch). A
   redelivered provider event cannot double-charge or double-award.
2. **Every money mutation is transactional + double-entry balanced** —
   `markSucceeded`, manual `approve`, and `refund.execute` all wrap
   contribution + payment_transaction + ledger post in one `transStart/
   transComplete`, and the ledger posts balanced debit/credit lines (with a
   `fees` line when a fee is present).
3. **Maker-checker with SoD is enforced on both manual posting and refunds** —
   `ManualContributionService::approve` and `RefundService::approve` both reject
   self-approval, and the routes gate `contribution.refund.request` separately
   from `contribution.refund.approve`.
4. **Webhooks fail closed** — HMAC-SHA256 constant-time verification before any
   state change (`WebhookSignatureVerifier`), and unverified/unknown events are
   **quarantined, never silently applied**.
5. **The ledger is append-only and causes are never hard-deleted** — refunds post
   a *compensating* reversal entry rather than mutating the original, and
   `CauseService::delete` closes rather than deletes (ledger integrity).

The findings below are the gaps around the edges of that solid core — but three
of them are HIGH because they are on the money path.

---

## 2. Findings, ranked

### C1 — Refunding a **manual** contribution posts NO compensating ledger entry (HIGH)

Manual contributions post their ledger entry with `source_ref = 'manual:' . $conId`
(`ManualContributionService` line 166). But `RefundService::execute` looks up the
original entry to reverse by `source_ref = 'contribution:' . $contribution_id`
(RefundService line 134) — the *online* format. For a manual contribution that
lookup returns `null`, so the `reverseEntry` call is skipped entirely.

Consequence: executing a refund of a manually-posted gift **updates the
contribution to `refunded`, inserts a negative `payment_transaction`, and stages
the point reversal — but leaves `cause_funds` overstated in the ledger**, because
the compensating journal entry is never written. The books silently go out of
balance for exactly the offline-giving path where reconciliation is hardest.

This is a data-integrity defect in the one place the double-entry invariant most
needs to hold. Note it is invisible in tests unless a test refunds a *manual*
contribution specifically — and there is no such test.

**Fix direction:** make the reversal lookup provider-agnostic — either try both
`contribution:` and `manual:` source_refs, or (better) store the canonical
`source_ref` on the `contributions` row at creation and have `execute` read it
back. Add a refund-of-manual test asserting the ledger nets to zero.

### C2 — `refund.succeeded` and `charge.dispute.created` are known but never applied (HIGH)

`WebhookInboxService::KNOWN_TYPES` lists `payment.succeeded`, `payment.failed`,
`refund.succeeded`, and `charge.dispute.created` — so all four pass the
quarantine gate, get inserted as `received`, and are `markProcessed`. But
`WebhookController::apply()`'s `match` only handles `payment.succeeded` and
`payment.failed`; **`refund.succeeded` and `charge.dispute.created` fall through
to `default => ignored`** and are acknowledged (HTTP 200) and marked processed
without touching any state.

Consequences:

- **Provider-initiated refunds** (a refund issued in the provider dashboard, or a
  provider-side reversal) are received, marked processed, and dropped — the
  contribution stays `succeeded`, the ledger keeps the funds, and points are
  never reversed. The `reversed` state exists in the schema but nothing ever sets
  it.
- **Disputes / chargebacks** are likewise swallowed. The `disputed` state is
  defined and `charge.dispute.created` is a KNOWN type, but no code path moves a
  contribution to `disputed`, freezes it, or opens a case. A chargeback is a
  money-out event with regulatory timelines; silently 200-ing it is the worst
  outcome.

Because these are marked `processed` (not `failed`/`quarantined`), there is **no
operational signal** that they were dropped.

**Fix direction:** add `refund.succeeded` (reconcile against an existing refund
request or record a provider-originated reversal → `reversed`, compensating
ledger entry, point reversal) and `charge.dispute.created` (→ `disputed`, freeze
further refunds, open a reconciliation case, alert) handlers. Until a handler
exists, a KNOWN type with no handler should **quarantine**, not silently process.

### C3 — No cumulative-refund guard: partial refunds can sum past the original (HIGH)

`RefundService::request` validates `amountMinor > 0 && amountMinor <=
con.amount_minor` — but only against the *original* amount, never against the sum
of prior refund requests/executions for that contribution (grep confirms no
`SUM`/aggregate anywhere in the service). So two or more partial refunds can each
pass validation and cumulatively **exceed the original gift**.

Combined with C2 (a provider-side refund the system never recorded) the exposure
widens: the system could approve+execute a full internal refund of a gift the
provider had already partially refunded out of band.

There is also no guard that a contribution isn't *already* fully refunded before
a new request (the state check allows `succeeded` only, but a partially-refunded
contribution is still `succeeded` under the current model — there's no
`partially_refunded` state and no refunded-to-date column).

**Fix direction:** in `request`, compute refunded-to-date = SUM of
`refund_requests` in `approved|executed` for the contribution, and require
`amountMinor <= original - refundedToDate`. Consider a `refunded_minor` column on
`contributions` (kept in step by `execute`) so the check is O(1) and the state
can distinguish partial vs full refund.

### C4 — `reconciliation_cases` is defined but never written or read — reconciliation is unimplemented (HIGH)

The `reconciliation_cases` table (with purpose-built `kind` values
`missing_ledger|amount_mismatch|orphan_webhook` and a `status` `open|resolved`)
is **never referenced anywhere outside its migration** (grep confirms). There is
no service that detects discrepancies, opens cases, or resolves them, and no
command that periodically reconciles the ledger against `payment_transactions` or
the provider.

So the safety net the schema clearly anticipates does not exist. Every other gap
here (C1's out-of-balance ledger, C2's dropped refunds/disputes, C3's
over-refund) is precisely the kind of drift a reconciliation pass would catch —
and nothing catches it. The module trusts that every webhook is delivered exactly
once and every handler succeeds, with no compensating detection.

**Fix direction:** implement a `ReconciliationService` + scheduled command that
(a) finds `succeeded` contributions with no balanced ledger entry
(`missing_ledger`), (b) finds ledger/`payment_transactions` amount mismatches
(`amount_mismatch`), (c) finds `webhook_inbox` rows in `quarantined`/`failed` or
KNOWN-but-unhandled (`orphan_webhook`), opens cases, and surfaces an open-case
count. This is the natural home for detecting C1/C2/C3 in production.

### C5 — Quarantined and failed webhooks are a dead-letter queue with no processor (MED)

`webhook_inbox` rows can land in `quarantined` (unverified or unknown type) or
`failed`, and the docstring says they are "flagged for alerting" — but nothing
consumes them: there is no retry, no alert emission, and no admin surface listing
them (grep shows `webhook_inbox` is touched only by `WebhookInboxService` itself
and the controller's `markProcessed`). A transient verification failure or a
temporarily-unknown event type is therefore lost silently.

This is the webhook analogue of the recurring "sweep with no runner" pattern seen
across the other reviews (membership M5/M6, journey J1, ACL AC1).

**Fix direction:** add a dead-letter processor (retry `failed`, re-evaluate
`quarantined` after config/type updates, alert on age) and an admin read surface.
Fold into the reconciliation command (C4).

### C6 — Closing (or drafting) a cause does not stop new contributions (MED)

`CauseService::setStatus` can move a cause to `closed`, and `create` starts it in
`draft` — but `ContributionService::createIntent` never checks the cause's
`status` (or even that the cause exists / belongs to the org). So a donor can
create an intent — and, on webhook, a *succeeded contribution* — against a
`closed` or still-`draft` cause, or against a `cause_id` that doesn't exist.

Consequences: money can land on a cause that has been deliberately closed
(target met, campaign ended, or wound down as part of a group dissolve — couples
to Groups GR2), with no guard and no reconciliation to flag it.

**Fix direction:** in `createIntent`, load the cause and require
`status='active'` (and matching `organization_id`), returning a clear
`CAUSE_NOT_ACCEPTING` error otherwise. Decide policy for in-flight intents when a
cause closes mid-checkout (allow settle vs cancel).

### C7 — Public giving endpoint is unauthenticated by design but thin on abuse controls beyond rate-limiting (MED)

`POST causes/(:segment)/contribute` carries only `ratelimit:contribution.checkout`
(no `auth`, correctly — it's the public donor path). Rate-limiting is the right
first control, but `createIntent` accepts a caller-supplied `user_id`,
`provider`, `provider_config_id`, and `recognition` with no validation that the
`user_id` belongs to the org or that the `provider_config_id` is an active config
for this cause's group. An intent is low-stakes until settled, but a spoofed
`user_id` on a public endpoint means **giving credit / VBCS metrics / journey
signals can be attributed to an arbitrary member** once the contribution
succeeds.

**Fix direction:** on the public path, ignore or verify caller-supplied
`user_id` (only trust it when the request is authenticated; otherwise treat the
gift as anonymous/unlinked and let a later authenticated claim attach it),
validate `provider_config_id` against the cause's group, and clamp `recognition`.

### C8 — Recurring commitments only remind; they never initiate a charge, and cancellation isn't leader-reachable (MED / partly by-design)

`CommitmentService::processDue` is explicitly reminder-only ("Charges nothing")
— it notifies and advances `next_due_at`. That's a defensible MVP, but it means
the "recurring giving" feature does not actually recur money; it recurs emails.
Worth confirming this matches intent, and documenting it, because "monthly
partnership" implies auto-charge to most users. Separately, `cancel` requires
`subjectId === row.subject_id` (only the giver can cancel) — a leader/admin
cannot cancel a commitment on a member's behalf, which will be needed for
offboarding (couples to membership M1 account deactivation: a deactivated
member's active commitments keep reminding forever).

**Fix direction:** decide + document whether commitments auto-charge (if so,
route through the same intent→webhook path with idempotency per due-date). Allow
scope-authorized leaders to cancel, and cancel/pause a subject's active
commitments when their account is deactivated (consume the M1 signal).

### C9 — No refund/manual state has an audit-trail row; lifecycle changes rely on status columns only (MED)

Unlike the Journey and ACL modules (append-only transition/revision tables), the
money module records lifecycle changes only as status-column updates plus the
ledger. There is no append-only audit of *who* moved a refund request through
requested→approved→executed, or who rejected a manual record and why (the
`reason` is stored but the actor/time of a reject is only partially captured).
For a financial module this is the surface auditors will ask about first.

**Fix direction:** either emit `AuditService` records on every money-lifecycle
transition (approve/reject/execute/dispute) or add an append-only
`contribution_events` trail. The ledger captures the money; this captures the
*decisions*.

### C10 — Thin negative/edge test coverage for the money-path failure modes (MED)

The happy paths are covered, but the defects above are exactly the untested
edges: refund-of-manual ledger balance (C1), unhandled `refund.succeeded`/dispute
webhooks (C2), cumulative over-refund (C3), contributing to a closed cause (C6),
spoofed `user_id` attribution (C7). Adding these as red tests pins the intended
behaviour before the fixes.

**Fix direction:** add service tests asserting: manual-refund nets the ledger to
zero; a `refund.succeeded`/`charge.dispute.created` webhook changes state; two
partial refunds summing past the original are rejected; an intent on a
`closed`/`draft`/foreign cause is refused.

---

## 3. Suggested sequencing

Front-loads the ledger-integrity and dropped-money-event defects, then the
missing safety net, then policy items:

1. **C10** — add the money-path negative tests first (red), so C1–C3/C6 land green.
2. **C1** — fix the manual-refund ledger source_ref mismatch (silent
   out-of-balance; smallest, highest-integrity fix).
3. **C2** — implement `refund.succeeded` + `charge.dispute.created` handlers;
   until then, quarantine KNOWN-but-unhandled types instead of processing them.
4. **C3** — cumulative-refund guard (+ `refunded_minor` column / partial state).
5. **C6** — enforce `active` cause on `createIntent`.
6. **C4 + C5** — build the reconciliation service + dead-letter processor as one
   scheduled command (the safety net that would have caught C1/C2/C3).
7. **C7** — harden the public giving endpoint's attribution inputs.
8. **C8** — decide/document commitment auto-charge; leader-cancel + M1 cascade.
9. **C9** — append-only decision audit for money lifecycle. Lower urgency
   (ledger + partial status history give interim coverage).

## 4. Cross-review couplings (explicit)

- **C6 ↔ Groups GR2:** a dissolved/archived group's causes should stop accepting
  gifts — needs the group lifecycle signal.
- **C8 ↔ Membership M1:** a deactivated member's active commitments should
  pause/cancel — needs the account-status signal already required by M1/AC3/J4.
- **C4/C5 ↔ Membership M5/M6, Journey J1, ACL AC1:** the same "sweep/detector
  with no scheduled runner" pattern; share a cron home.
- **C9 ↔ Journey/ACL:** those modules' append-only trails are the template a
  financial module should meet or exceed.

No code was changed in the course of this review.
