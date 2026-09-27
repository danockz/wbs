# Identity (Account / Auth / Session / Merge) Lifecycle Review

_A code-grounded gap analysis of the **account lifecycle** — how a user moves
through prospect → pending_verification → active → suspended/locked → deactivated →
anonymized/merged (terminal), how sessions/tokens/credentials are issued and
revoked, and how a duplicate account is merged into a survivor under maker-checker
— plus how these interact with EVERY other module reviewed, because Identity is
the **emitter half of the platform-wide teardown cascade (Theme B).**_

Prepared 2026-09-15. Method: read `AccountLifecycleService` in full, plus the
session/credential/token services, the migrations, the routes, and the existing
`SessionPruneCommand`; every finding cites the code it rests on. **No code was
changed.**

Why this review is load-bearing: eight earlier reviews (M1/M2, AC3, J4, C8, R7,
N7, and the Courses/Meetings/Events passes) each said "this module must react when
an account is deactivated/suspended/merged." All of those consumer fixes assume
Identity **emits** a signal. This review checks whether it does. **It does not** —
that is the headline finding (ID1).

---

## 1. The account model at a glance

**States** (`AccountLifecycleService::TRANSITIONS`, a real state matrix):

```
prospect ──▶ pending_verification ──▶ active ──▶ suspended ⇄ active
                                        │           │
                                        ├──▶ locked ⇄ active/suspended
                                        ├──▶ deactivated ──▶ active (reactivate)
                                        └──▶ anonymized (terminal) / merged (terminal)
```

- `anonymized` and `merged` are **terminal** (empty transition lists — cannot be
  resurrected).
- Unknown legacy free-string states are handled defensively: forward moves
  permitted, terminal resurrection forbidden.

**Merge** is a separate maker-checker flow: `submitMerge` (pending) →
`approveMerge`/`rejectMerge`/`cancelMerge`, with an append-only
`identity_merge_requests` + review trail.

**Supporting lifecycles:** `SessionService` (absolute TTL + idle TTL, prune
command), `CredentialSetupService` (single-use hashed expiring tokens),
`TokenService` (refresh tokens), `MfaService`, `SocialAuthService`.

**The good news up front — protect these:**

1. **`transition` is a proper guarded state machine** — mandatory reason,
   membership-in-`STATES` check, per-from allowed-`to` matrix, `NO_CHANGE` guard,
   terminal-state protection. This is the reference the unguarded status setters
   elsewhere (Journey J3, Membership M9/M10, Meetings MT1) should adopt.
2. **Transitions are transactional + fully audited** — the `users` update, the
   `account_state_transitions` row (from/to/reason/actor/approval_ref/evidence),
   and the security revocation all run in one `transStart/transComplete`, then an
   `audit->record`. There is a real, queryable transition history.
3. **Security revocation cascades immediately** — on `suspended|locked|
   deactivated|anonymized|merged` (`REVOKE_ON`), all sessions AND all refresh
   tokens for the user are revoked in-transaction, so a hijacked session can't
   outlive the state change. Accounting/audit rows are deliberately left intact.
4. **Merge is maker-checker with real SoD** — `approveMerge` calls the PDP
   (`access.request.approve`) AND independently rejects self-approval
   (`requested_by === actor`). It refuses to merge terminal accounts. The
   duplicate is retired **through the state machine** (`transition(...,'merged',
   merged_into_id=...)`), inheriting the audit + revocation — not a raw UPDATE.
5. **`anonymized` scrubs PII in the same transaction** (`scrubPii`) and stamps
   `anonymized_at` — a real GDPR-erasure path, not a soft flag.
6. **Sessions are swept** — `SessionService` has an absolute+idle TTL and
   `SessionPruneCommand` hard-deletes long-revoked/expired rows. Credential-setup
   tokens are single-use, SHA-256-hashed, expiring, and rotate-revoke sessions on
   use. This module is *ahead* of most on Theme C.

So Identity is one of the best-built modules. Its gaps are concentrated in exactly
two places — and the first is the keystone of the entire remediation plan.

---

## 2. Findings, ranked

### ID1 — Account lifecycle transitions emit NO domain event: the Theme B cascade has no source (HIGH — platform keystone)

`transition` updates `users`, writes `account_state_transitions`, revokes
sessions/tokens, and records an audit entry — but it stages **nothing on the
outbox** (grep confirms: no `outbox`, `stageEvent`, or domain-event write anywhere
in `AccountLifecycleService`). The audit log is a *record*, not a *signal*: no
other module subscribes to it, and it carries no delivery/retry machinery.

Consequence — this is the missing half that **blocks every teardown-cascade fix
already written up across the platform**:

- Membership M1/M2 (belongings on deactivate/merge), AC3 (cascade-revoke grants +
  delegations), J4 (pause/reassign journeys), C8 (pause commitments), R7 (re-point
  sponsorships), N7 (cancel deferred sends + merge prefs/suppressions), Courses
  CO6 (withdraw/re-point enrollments), Meetings MT5 (revoke grants), Events E-B2
  (release seats/re-point registrations) — **all of these consume an event that is
  never produced.**

Within Identity itself the local revocation (sessions/tokens) fires, but nothing
*outside* Identity is told. So today a deactivated user keeps active group
memberships, live grants, running journeys, open commitments, event seats, and
course enrollments; a merged duplicate's belongings are never moved to the
survivor.

**Fix direction (this is plan Theme B's emitter, Phase 2 prerequisite):** stage
canonical events on the existing outbox inside the `transition` transaction —
`account.deactivated`, `account.suspended`, `account.merged{loser,survivor}`,
`account.anonymized` — emitted **once**, with `organization_id`, `user_id`,
`from`/`to`, `actor_id`, and (for merge) `merged_into_id`. Emitting inside the
transaction gives exactly-once-with-the-state-change semantics. Everything else in
Theme B is already specified against these names; this is the unblock.

### ID2 — `approveMerge` retires the duplicate but never moves its belongings (HIGH)

`approveMerge` does the hard governance part correctly (SoD, PDP, terminal-state
guard) and flips the duplicate to `merged` with `merged_into_id` set — but it
**does not re-point any of the duplicate's data to the survivor.** No memberships,
grants, contributions, enrollments, registrations, referrals, points, journeys, or
notification prefs are moved. After a merge the survivor gains nothing and the
duplicate's history is stranded on a terminal, session-revoked account.

This is the natural consumer of ID1's `account.merged` event, but it's worth
calling out separately because it's a **correctness** gap in the merge feature
itself, not just a missing signal: a "merge" that merges nothing is misleading to
the operator who approved it.

**Fix direction:** on `account.merged`, each owning module re-points the loser's
belongings to the survivor **through its authorized path** (maker-checker where the
original write had it) — never a blind `UPDATE ... SET user_id = survivor`, which
would launder authority/PII onto a different identity (the plan already states
this rule). Dedupe where the survivor already has the equivalent record (e.g.
group membership UNIQUE, enrollment `enr_uq`). Until the buses exist, at minimum
document in the approve response that belongings are NOT yet moved, so operators
aren't misled.

### ID3 — No outbox reaction path for `anonymized` PII scrub across other modules (MED)

`scrubPii` erases PII on the `users` row, but PII copied into other modules
(notification channels, contribution donor names, event attendee names, referral
captured-prospect contact details, follow-up notes) is untouched. A GDPR erasure
that only scrubs the identity row leaves personal data scattered across the
platform.

**Fix direction:** `account.anonymized` (ID1) should fan out an erasure/scrub
policy per module (delete or tokenize PII fields, preserving aggregates/accounting
as `users` already does). Sequence with Theme B.

### ID4 — Auth/MFA/session sub-lifecycles: strong, with two smaller seams (LOW–MED)

The auth stack is largely solid (rate-limited MFA routes, single-use hashed
credential tokens, session prune command). Two smaller gaps:

- **Credential-setup / refresh tokens have no active expiry sweep** — expiry is
  enforced lazily at redemption (`expires_at <= now`), like the Events ticket-hold
  and Meetings token cases; lapsed rows linger. Fold a token-prune into the
  unified sweep runner (Theme C) alongside `SessionPruneCommand`.
- **MFA/social-link lifecycle on account state change** — when an account is
  suspended/locked, sessions+tokens are revoked, but it's worth confirming MFA
  enrolments and linked social identities are re-validated on reactivation (didn't
  see explicit handling). Low risk; verify during Phase 3.

### ID5 — No `Services/tests/` for the account lifecycle (MED)

There is no test directory for Identity's services. The state matrix (ID1's host),
the merge SoD/PDP path (ID2), terminal-state protection, and the revocation
cascade are all untested — high-value paths to cover, especially before wiring the
outbox emission (ID1), which must be asserted exactly-once.

**Fix direction:** add tests for the transition matrix (legal/illegal/terminal),
merge maker-checker (self-approval denied, terminal refused), the revocation
cascade, and — once ID1 lands — the outbox emission. Red before the emitter fix.

---

## 3. Suggested sequencing

1. **ID5** — add lifecycle tests (red), especially transition matrix + merge SoD.
2. **ID1** — emit the canonical teardown events on the outbox (unblocks Theme B
   for the *entire* platform; this is the single highest-leverage Identity fix).
3. **ID2** — implement belonging re-point on `account.merged` per owning module
   (with ID1 in place, this is "subscribe + re-point through authorized path").
4. **ID3** — anonymization scrub fan-out.
5. **ID4** — token-prune into the sweep runner; verify MFA/social on reactivate.

## 4. Cross-review couplings (explicit)

- **ID1 is the emitter for Theme B** and is a hard prerequisite for: M1/M2, AC3,
  J4, C8, R7, N7, CO6, MT5, E-B2. **None of those can be correct until ID1 ships.**
- **ID2 ↔ the merge consumers** in every module (re-point loser → survivor).
- **ID4 ↔ Notifications N1 / Meetings MT-tokens / Events E-C1:** shared
  lazy-expiry-with-no-sweep pattern → unified runner (Theme C).
- **Identity is the reference for guarded transitions** (Journey J3, Membership
  M9/M10, Meetings MT1 should copy its state-matrix pattern) and, with Events,
  a reference for Theme C sweeps.

## 5. Impact on the remediation plan

**ID1 must be promoted in the plan.** The current roadmap sequences Theme B at
Phase 2 and notes "the consumer fix is blocked until the emitter exists" — ID1
**is** that emitter, and it's a small, local, transactional change with no new
infra. Recommendation: pull **ID1 (+ ID5 tests) into Phase 0/early-Phase-1** so
the emitter is live and asserted before the Phase 2 consumers are built, rather
than discovering at Phase 2 that the source doesn't exist. ID2's re-point logic
stays in Phase 2 with the rest of the module subscribers.

No code was changed in the course of this review.
