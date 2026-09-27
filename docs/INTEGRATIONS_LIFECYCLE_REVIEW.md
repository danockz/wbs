# Integrations (Connections / Credential Vault / Provider Reliability) Lifecycle Review

_A code-grounded gap analysis of the **integration-connection lifecycle** — how a
provider connection moves draft → tested → pending_approval → active →
disabled/revoked, how secrets are vaulted (encrypted, never returned) and rotated,
how descendant groups are granted capability-limited use without seeing
credentials, and how outbound provider calls are protected by a circuit breaker +
quota gate — plus how connection teardown must cascade to the Meetings and
Streaming modules that depend on it._

Prepared 2026-09-15. Method: read `ConnectionService`, `CredentialVault`,
`ProviderReliabilityService`, the migrations, the routes; every finding cites code.
**No code was changed.**

Why it matters: Integrations is the credential/adapter substrate under Meetings
(provider meetings), Streaming (relay/OAuth), Contributions (payment providers),
and Notifications (transport). A connection going away, or a credential rotating,
is a lifecycle event those modules must respect.

---

## 1. The connection model at a glance

**State machine** (`ConnectionService`, documented + enforced):
`draft → tested → pending_approval → active → disabled/revoked`.

- `create` → `draft`; `recordTest` (pass) promotes draft → `tested`;
  `submitForApproval` requires `tested` → `pending_approval`; `activate` requires
  `pending_approval` (or `tested` in a fast path) + an `approverId` → `active`.
- `grantCapability` lets a **descendant group** use an `active` connection under a
  time-bound, capability-limited grant — WITHOUT exposing credentials (FR-INT-007).
  The grant's reach is a `ScopeMode` — `self`, `self_and_descendants`,
  `descendants_only`, or a hand-picked `groups` set — with cross-cut coverage
  opt-in, and a grantee outside the account **owner's** subtree is refused
  (`GRANT_OUT_OF_SCOPE`, 403); `revokeGrant` sets the grant `revoked`. Leaders now
  manage their own body's accounts and shares at `/notifications/credentials`
  (Notifications module, same `provider.configure` capability, every write routed
  through this service so there is one writer — `docs/NOTIFICATIONS_SMS_MNOTIFY.md` §10).

**Credential vault** (`CredentialVault`): secrets encrypted with SecretBox on
submission, **never** returned by any method; access only via `useSecret(...,
$callback)` which decrypts inside the callback scope and `sodium_memzero`s the
plaintext after. Rotation stores a new `version`; history remains decryptable.

**Reliability** (`ProviderReliabilityService`): per-scope circuit breaker
(CLOSED → OPEN after N consecutive failures → HALF_OPEN probe after cooldown →
CLOSED on probe success / re-OPEN on failure) plus a quota gate; `call()` runs a
provider callback through both.

**The good news up front — protect these (this module's crypto/reliability core is
excellent):**

1. **Credentials are genuinely write-only** — no public method returns a decrypted
   secret; `useSecret` scopes decryption to a callback and memzeros after. AAD-bound
   ciphertext, fingerprint-only responses. This is textbook.
2. **Connection promotion is a guarded state machine with approval** — each
   transition checks the prior state and `activate` records an approver
   (maker-checker seam). No free-form status jumps.
3. **The circuit breaker is correct** — proper CLOSED/OPEN/HALF_OPEN with a single
   probe and quota accounting; Meetings and Notifications already lean on it to
   fail fast rather than hammer a broken provider.
4. **Capability delegation is credential-safe** — descendants get scoped,
   time-bound use via `capability_grants`, never the secret itself.

The gaps are at the lifecycle edges — teardown and rotation cascade.

---

## 2. Findings, ranked

### IN1 — Disable/revoke emits no signal and doesn't cascade to dependents (HIGH)

`disable()` sets `status='disabled'` (and the documented `revoked` terminal
exists), but:

- it stages **no domain event** (grep: no outbox/`connection.disabled`/
  `connection.revoked` anywhere in the module), and
- nothing reacts. A disabled/revoked connection still has live **Meetings**
  (`meetings.connection_id`) and **Streaming** relays pointing at it, and live
  **`capability_grants`** to descendant groups that are not auto-revoked.

Consequence: disabling a compromised or expired connection is a *local flag flip*.
Downstream meetings keep their `join_url`/tokens, streams keep relaying, and
grantee groups keep their capability grants — none are told the credential behind
them is gone. For a credential-bearing resource, "disable doesn't cut off
dependents" is a security-relevant gap.

**Fix direction:** on disable/revoke, stage `connection.disabled` /
`connection.revoked` on the outbox (plan Theme B family, provider-side), and
cascade: auto-revoke this connection's `capability_grants`, and have Meetings/
Streaming subscribe (cancel/degrade sessions bound to the connection, expire
tokens — ties to Meetings MT5). Also add a real `revoke()` method — the state is
documented but only `disable()` exists.

### IN2 — `disable()` has no state guard and no reason/actor/audit (MED)

Unlike the forward transitions (which check prior state) and unlike Identity's
`transition` (reason + actor + audit + transition row), `disable()` does a bare
`UPDATE status='disabled'` with **no** prior-state guard, no reason, no actor, and
no audit record. A connection can be "disabled" from any state, silently, with no
trail of who did it or why — for a credential-bearing resource that governs money
and comms providers, that's a weak governance seam.

**Fix direction:** require a reason + actor, record an audit entry (mirror
`AccountLifecycleService.transition`), and guard legal source states. Consider
maker-checker on `revoke` of an `active` connection, symmetric with `activate`.

### IN3 — Rotated credential versions are never pruned or deactivated (MED)

`CredentialVault.put` versions credentials (v1, v2, …) and rotation keeps **all**
historical versions decryptable "for in-flight operations." But nothing ever
marks an old version inactive or prunes it: every superseded secret stays
decryptable forever. After a rotation prompted by a *compromise*, the compromised
secret is still live in the vault. There's also no "active version" pointer —
`useSecret` takes the max version by ordering, so there's no way to pin/rollback or
to disable a specific leaked version.

**Fix direction:** add an `active`/`retired` marker per version (or an
`active_version` pointer), stop decrypting retired versions after a grace window,
and add a prune of versions older than N/older than a grace period (fold into the
Theme C sweep runner). On a compromise-driven rotation, retire the old version
immediately.

### IN4 — No reaction to owning-group teardown (MED — cross-review coupling)

A connection carries a `group_id` and grants flow to descendant groups, but
nothing reacts to `group.archived/dissolved/merged` (grep: none). A dissolved
group can leave an orphaned active connection and live grants to a subtree that no
longer exists (compounds Groups GR2/GR3 closure-prune).

**Fix direction:** subscribe to the group-teardown signals — disable/reassign
connections owned by a dissolved group; revoke grants to archived grantee groups.

### IN5 — No `Services/tests/` for the connection lifecycle (MED)

No test directory. The state machine, the approval gate, the credential
write-only guarantee (the security-critical `useSecret`/no-return property), the
breaker transitions, and grant revocation are untested.

**Fix direction:** add tests: state-machine legality, activate-requires-approver,
vault never returns plaintext + memzero, breaker CLOSED/OPEN/HALF_OPEN, grant
revoke. Red before IN1–IN3.

---

## 3. Suggested sequencing

1. **IN5** — lifecycle + vault tests (red).
2. **IN1** — disable/revoke emits signal + cascades to grants/meetings/streams.
3. **IN2** — guard + reason + actor + audit on disable/revoke; add `revoke()`.
4. **IN3** — credential version retire/prune + active-version pointer.
5. **IN4** — consume group-teardown signals.

## 4. Cross-review couplings (explicit)

- **IN1 ↔ Meetings MT5 / Streaming:** connection teardown must cut off dependent
  sessions/tokens — Meetings is the first subscriber.
- **IN2 ↔ Identity (reference):** `AccountLifecycleService.transition` is the
  guarded-transition-with-audit pattern `disable()` should copy.
- **IN3 ↔ Identity ID4 / Notifications N1 / Events E-C1:** the platform-wide
  "expiry/retirement exists in concept but nothing sweeps it" theme → unified
  runner (Theme C).
- **IN4 ↔ Groups GR2/GR3:** blocked on the same closure-prune foundation as the
  other group-teardown consumers.

No code was changed in the course of this review.
