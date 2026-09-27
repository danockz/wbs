# Access Control (RuBAC / Scope) Lifecycle Review

_A code-grounded gap analysis of the **access-grant lifecycle** — how authority
enters the system (role assignment, approved access request, delegation,
break-glass emergency session), how it is scoped, renewed, expired, revoked and
reviewed, and how those transitions interact with the PDP that reads them and
with the membership/group lifecycles that sit underneath them._

Prepared 2026-09-15, as the third review in the sequence after
`docs/MEMBERSHIP_LIFECYCLE_REVIEW.md` (the individual-member arc) and
`docs/GROUPS_LIFECYCLE_REVIEW.md` (the group-tree arc). Method: read the
migrations, services, the PDP (`AuthorizationService`), the expiry command,
controllers, routes and existing tests; every finding cites the code it rests
on. **No code was changed** — this is the review that precedes any fixes.

Scope note: this review is about a **grant as a first-class object** — its state
machine, its effective window, its scope, and the housekeeping that keeps the
stored status honest. The *evaluation* logic (the PDP decision order) is treated
as correct background and is only examined where a lifecycle transition should
have propagated into it and did not.

---

## 1. The access-control model at a glance

Four grant sources feed the Policy Decision Point, each its own table with its
own lifecycle:

- **`role_assignments`** (`000006`, lifecycle columns added `000035`) — a subject
  holds a role at a scope. Columns: `status` (`active|revoked|expired`),
  `scope_group_id` + `scope_mode` + `include_descendants` + `include_crosscut`,
  `source`, `issued_by`, `request_id`, `effective_from`, `effective_to`,
  `revoked_at`. `ra_status_idx (subject_id, status)`.
- **`access_requests`** (`000035`) — the approval workflow object. `grant_type`
  `role|permission`, `status` `pending|approved|rejected|revoked|expired|
  cancelled`, `conflict_state` `none|flagged`, `approver_id`, `assignment_id`
  (the role_assignment minted on approval), `effective_from`/`effective_to`.
  Indexed for approver queues (`ar_approver_idx`) and expiry (`ar_expiry_idx
  (status, effective_to)`).
- **`delegations`** (`000045`) — a leader lends a permission they *hold* to a
  delegate, always time-bounded. `status` `active|revoked|expired`, `parent_id`
  (sub-delegation chain), `depth`, `effective_from`/`effective_to` (both NOT
  NULL — always bounded), `revoked_at`/`revoked_by`. `dl_expiry_idx (status,
  effective_to)`.
- **`break_glass_sessions`** (`000045`) — narrow, MFA-gated emergency access.
  `status` `active|expired|closed`, `review_state` `pending|reviewed`, bounded
  window, mandatory `reason` + `mfa_level`. Paired with **`break_glass_reviews`**
  (maker-checker post-use review, `outcome` `justified|unjustified`).
- **`rules`** + **`rule_revisions`** (`000054`) — the general RuBAC engine:
  `enabled`, `priority`, `facet`, `action_pattern`, condition tree, effect. Every
  mutation appends a hash-chained snapshot to `rule_revisions`.

**PDP decision order** (`AuthorizationService::decide()`): MAC → SoD → RuBAC
(access facet, deny-overrides) → ABAC (org-wide) → RBAC → default-deny.

**Scope model** (`ScopeMode` + `GroupScopeResolver`): `self` /
`self_and_descendants` / `descendants_only` (excludes own node) / `groups`
(hand-picked set in `grant_scope_groups`). Org-wide = `scope_group_id NULL` +
`self`. Delegation is bounded by `scopeContains` containment so a delegated
grant can never exceed the authority it derives from.

**The good news up front.** This module is materially more mature than the group
and membership layers. Four things are genuinely well built and should be
protected by any later change:

1. **The PDP filters every grant source by `status` AND effective window at read
   time** (`AuthorizationService` lines ~189–263: `ra.status='active'` with
   `effective_from <= now < effective_to`, same for `access_requests`
   `status='approved'`, `delegations` and `break_glass_sessions`
   `status='active'` + window). Expiry is therefore **enforced lazily at
   decision time regardless of whether any sweep has run** — a lapsed grant
   stops granting the instant its `effective_to` passes. This is the correctness
   gate; the sweep (below) is only housekeeping.
2. **Delegation containment is real** (`delegate()` walks the delegator's own
   authorities and rejects with `DELEGATION_EXCEEDS_AUTHORITY` unless
   `scopeContains` proves the requested scope/duration/depth is equal-or-narrower).
3. **Break-glass lifecycle is complete**: SoD self-review denial
   (`BREAK_GLASS_SELF_REVIEW`), security alert fan-out on `unjustified`, and
   auto-expiry with a system-actor audit row.
4. **Rules are fully versioned** (hash-chained `rule_revisions` on create /
   update / enable / disable / delete, including a terminal revision recorded
   *before* the row is removed).

The findings below are the gaps around the edges of that solid core.

---

## 2. Findings, ranked

### AC1 — Delegation is never swept to `expired`; the sweep silently omits it (HIGH)

`AccessExpireCommand` calls exactly two services: `accessRequests()->expireLapsed()`
(role_assignments + access_requests) and `breakGlass()->expireLapsed()`. It
**never touches `delegations`**, and `DelegationService` has **no `expireLapsed`
method** at all (confirmed: its public surface is `delegate`, `revoke`, `chain`,
`forDelegate`, and privates — no sweep).

Consequences:

- A lapsed delegation keeps `status='active'` **forever**. The `dl_expiry_idx
  (status, effective_to)` index — clearly built for a sweep — is dead weight.
- The PDP still does the right thing at decision time (it filters
  `effective_to > now`), so this is **not** an authorization hole. But every
  *management* surface that reads status is wrong: `forDelegate(...,
  activeOnly:true)` returns expired delegations as active, `chain()` shows them
  active, and any audit/report keyed on `status` overstates live delegated
  authority.
- It is inconsistent with the sibling grants, which *are* swept — a reviewer
  auditing "who currently holds delegated X" cannot trust the column.

**Fix direction:** add `DelegationService::expireLapsed(?org)` mirroring the
break-glass sweep (`status='active' AND effective_to <= now → 'expired'`, with a
system-actor `acl.delegation.expire` audit row), and call it from
`AccessExpireCommand` alongside the other two. Cheap, idempotent, closes the
status-honesty gap.

### AC2 — Revoking or expiring a role/request does NOT cascade to delegations derived from it (HIGH)

`authoritiesFor()` records a delegation's provenance in `parent_id`: a
sub-delegation gets its parent delegation's id, but a delegation derived from a
**role assignment** or an **approved access request** is inserted with
`parent_id = NULL` (the `role_assignment` / `access_request` branches pass
`null` as the delegation id). There is no column linking a delegation back to the
`role_assignment` / `access_request` it was carved from.

Therefore:

- `RoleAssignmentService::revoke()` flips the assignment to `revoked` and audits
  it — **but does nothing to delegations the subject issued from that role.**
  Those delegations have `parent_id = NULL`, so `revoke()`'s `collectSubtree`
  cascade (which walks `parent_id`) can never reach them.
- Same for `AccessRequestService::revoke()` on an approved permission grant, and
  same when a role/request simply **expires** via the sweep.
- Net effect: **a delegate can retain lent authority after the delegator has lost
  the very authority that authorized the loan** — until the delegation hits its
  own (up-to-max-duration) `effective_to`. The design comment on `revoke()`
  explicitly asserts "a child delegation cannot outlive the authority it was
  derived from," but that invariant only holds for delegation→delegation chains,
  not for role→delegation or request→delegation ones.

This is the most consequential *authorization* gap in the module: it is a real
(bounded-duration) privilege-retention window, not just a stale column.

**Fix direction:** give `delegations` a nullable `source_grant_type` +
`source_grant_id` (role_assignment | access_request) captured at grant time, and
have `RoleAssignmentService::revoke` / `AccessRequestService::revoke` (and both
`expireLapsed` paths) cascade-revoke/expire the delegations rooted on that grant
before returning. Alternatively — cheaper but coarser — have the PDP's
delegation lookup additionally require that the delegator *still* holds the
permission at decision time (re-validate provenance lazily, mirroring how the
PDP already re-checks windows). The lazy option removes the retention window
without a schema change, at the cost of a per-decision provenance re-check.

### AC3 — Account deactivation / person-merge does not touch ACL grants (HIGH — cross-review coupling to membership M1/M2)

`Identity`'s account-lifecycle path does **not** reference `role_assignments`,
`delegations` or `break_glass_sessions` (grep confirms none). So when a person is
deactivated, suspended, or merged away:

- Their `role_assignments` stay `active`; their outbound delegations stay
  `active`; any open break-glass session stays `active`.
- The PDP's grant lookups key on `subject_id` and never join to the account's
  status, so **a deactivated user's stored grants remain fully live** until each
  grant's own `effective_to` (and role assignments frequently have
  `effective_to = NULL` → never).

This is the ACL face of membership finding **M1** ("account-status change has no
cascade") and **M2** ("person-merge abandons belongings"). It belongs in both
reviews because the fix has two halves that must agree: Identity must emit a
signal on deactivate/suspend/merge, and AccessControl must consume it (revoke or
suspend the subject's assignments, cascade-revoke their outbound delegations per
AC2, and close any open break-glass session).

**Fix direction:** on account deactivate/suspend → cascade-revoke the subject's
role assignments + outbound delegations + close break-glass; on person-merge →
reassign or revoke the loser's grants under the same maker-checker rules as a
normal revoke (do **not** silently re-point `subject_id`, which would launder a
grant onto a different identity without authorization). Sequence this **after**
membership M1/M2 land the account-status signal, and reuse the Groups-merge
reassignment template already flagged in GR/M2.

### AC4 — `expireLapsed` never expires stale **pending** access requests (MED)

`AccessRequestService::expireLapsed()` expires (1) `role_assignments` with
`status='active'` past `effective_to` and (2) `access_requests` with
`status='approved'` past `effective_to`. It **does not** touch `pending`
requests. The method's own docstring claims it expires "stale pending requests,"
and the migration ships `ar_expiry_idx (status, effective_to)` — but a request
that is submitted with an `effective_to` and never actioned sits in `pending`
indefinitely.

Consequences: approver queues (`pendingForApprover`) accumulate zombie requests
that can still be `approve()`d long after they were relevant, minting a grant
whose `effective_to` may already be in the past (harmless to the PDP, but noisy
and confusing), and there is no "request timed out unactioned" terminal state
distinct from an explicit `reject`.

**Fix direction:** either (a) expire `pending` requests whose `effective_to`
(or a submission-age SLA) has passed → `expired`, with an audit row, or (b) if
"pending never auto-expires" is the intended policy, correct the docstring and
drop the implication. Given `ar_expiry_idx` and the docstring, (a) is clearly
what was intended.

### AC5 — No reminder / escalation for pending approvals; approval queue can stall silently (MED)

A request notifies its reviewer exactly once, at submit time (`notifyReviewer`
called from `submit()`). There is no periodic reminder, no escalation to an
alternate approver, and — combined with AC4 — no timeout. A single unavailable
approver stalls a request forever with no operational signal. For a system that
otherwise takes lifecycle housekeeping seriously (break-glass reviews, expiry
sweeps), this is a conspicuous omission on the one workflow a human is expected
to drive.

**Fix direction:** add a pending-approval reminder pass to the scheduled sweep
(re-notify after N hours; escalate / widen to the approver's peers or manager
after M hours), reusing the notification outbox the rest of the platform emits
through. Pair naturally with AC4's pending-timeout.

### AC6 — Break-glass and delegation are self-service open with no maker-checker at grant time (MED)

Break-glass sessions are opened by a single actor (`open()` requires strong MFA,
a mandatory reason, and a bounded window — all good) and are only *reviewed*
after the fact. Delegations are likewise issued by the delegator alone
(containment-checked, but single-actor). That is a defensible design for
*emergency* access (speed matters; the maker-checker moves to the mandatory
post-use review, which **is** enforced with SoD). But it is worth stating
explicitly as a residual risk and confirming policy: **there is no dual-control
at the moment authority is created**, only at review time for break-glass and not
at all for delegation. Contrast with the membership/group reviews' recurring
"single-actor destructive lifecycle" finding — here it is single-actor
*constructive* (privilege-granting) lifecycle.

**Fix direction:** likely accept-as-designed for break-glass (the post-use SoD
review is the compensating control) but (a) make the mandatory-review SLA
*enforced* — see AC7 — and (b) consider an optional per-org config requiring a
second approver for delegations above a scope/duration threshold, reusing the
access-request approver machinery.

### AC7 — Mandatory break-glass review is mandatory in name only — nothing enforces it (MED)

`review()` is fully correct when called, and `pendingReviews()` lists sessions
awaiting review. But nothing **compels** the review: an expired/closed session
sits at `review_state='pending'` indefinitely with no reminder, no escalation,
and no consequence. The "mandatory post-use review" (FR-ACL-006) is therefore a
reporting surface, not an enforced control.

**Fix direction:** add a pending-review reminder/escalation pass to the sweep
(the data is already there via `pendingReviews` and `bg_review_idx`), and
consider surfacing an org-level "unreviewed emergency access" count that rises
until cleared. Same scheduled-sweep home as AC4/AC5.

### AC8 — Expired grants cannot be renewed; renewal requires `approved` (MED / by-design, flag for UX)

`renew()` hard-requires `status='approved'` and returns `BAD_STATE` otherwise.
Once the sweep (or lazy expiry) has moved a grant to `expired`, the only path
back is a brand-new request through the full approval workflow — there is no
"reinstate" that preserves the request's history/thread. This is arguably
correct (an expired grant *should* be re-justified), but note the interaction
with AC1/AC4: because sweeps are eventually-consistent, whether a just-lapsed
grant is `renew`-able or must be re-requested depends on **timing of the sweep**,
which is a surprising, non-deterministic UX.

**Fix direction:** decide policy explicitly. If renew-after-expiry is disallowed,
make it deterministic (treat "past effective_to" as non-renewable regardless of
whether the sweep has run yet, so the answer doesn't depend on cron timing). If
a grace reinstatement is desired, add an explicit `renew`-from-`expired` window.

### AC9 — Grants reference scope groups that the group lifecycle can archive/dissolve out from under them (MED — cross-review coupling to GR2/GR3)

The Groups review established (GR3) that `GroupScopeResolver` and
`EffectiveConfigResolver` read `group_closure` **without filtering
`groups.status`**, and that dissolve/merge **never prune `group_closure`**. Every
grant here — role assignment, access request, delegation, break-glass, rule —
pins a `scope_group_id` and/or a hand-picked `grant_scope_groups` set. When the
target group is later archived or dissolved:

- The grant's `status` is untouched (nothing in the group lifecycle calls into
  AccessControl), and
- `scopeContains` / `resolveScopeGroups` still resolve the (stale) closure rows,

so **a grant can keep conferring authority over a group that no longer exists as
a live node**, and a delegation's containment check can still "cover" a
dissolved subtree. This is the mirror image of AC3: there, the *subject*
disappears; here, the *object* (scope group) disappears.

**Fix direction:** this is primarily fixed on the Groups side (GR2 cascade + GR3
status-aware resolvers + closure pruning). On the AccessControl side, add a
reaction to a `group.archived` / `group.dissolved` / `group.merged` signal:
expire/revoke grants whose *sole* scope target is the terminal group, and
re-point (or flag) hand-picked sets that reference it. Sequence after GR2/GR3.

### AC10 — Thin service-level test coverage for the lifecycle/sweep/cascade paths (MED)

The module has good **view/controller workflow** tests (access-request workflow,
break-glass workflow, delegation workflow, rule CRUD, plus i18n and the
grant-scope-writer unit test). But there is **no direct unit test of the PDP
(`AuthorizationService::decide`)** — the single most security-critical function
in the codebase — and **no service test** covering: the expiry sweep flipping
statuses, delegation containment rejection, delegation revoke-cascade,
break-glass auto-expiry + SoD review, or SoD self-approval denial. Every gap
above (AC1, AC2, AC4) is exactly the kind of thing a service test would have
caught.

**Fix direction:** add `AuthorizationService` decision-matrix tests (one per PDP
layer + default-deny + window/status filtering) and service tests for each
lifecycle transition and each sweep. Do this **before** the AC1–AC4 fixes so the
fixes land against a red→green harness.

### AC11 — ✅ RESOLVED 2026-09-17
Audited every POST/PUT/PATCH/DELETE across the 7 AC controllers (AbacPolicy,
AccessRequest, BreakGlass, Delegation, RoleAssignment, Role, Rule). Four
mutating routes were `webcsrf`-free and are now hardened:
`POST access-requests` (self-service submit — deliberately auth-only, no cap,
now + `webcsrf`), `POST access-assignments` (assign),
`POST access-assignments/(:segment)/revoke`, and `POST break-glass` (open). All
other AC mutations already carried `authorize:`+`webcsrf`. Four new assertions
in `route_filter_guard_test.php` and the CSRF-EXEMPT freeze entries removed.

--- original finding below ---

### AC11 — Controller mutation surface: confirm `authorize:` + `_csrf` on every grant-mutating route (MED — mirrors GR1/M3)

The Groups review (GR1) and Membership review (M3) both found lifecycle routes
missing `authorize:` filters and/or `webcsrf`/`_csrf` protection, and the
standing project note is that this is a recurring pattern. AccessControl mutates
the most sensitive state in the system (it grants authority), so every mutating
route across the seven controllers (AbacPolicy, AccessRequest, BreakGlass,
Delegation, RoleAssignment, Role, Rule) must be re-verified to carry both a
route-level `authorize:` capability code **and** `_csrf` (note the standing
correction: the filter alias is `_csrf`, **not** `webcsrf`). This review did not
finish walking all seven controllers' route bindings; treat it as an open
verification item rather than a confirmed defect.

**Fix direction:** enumerate every POST/PUT/PATCH/DELETE route in the AC module,
assert each has `authorize:<code>` + `_csrf`, and add a route-filter test (the
same style used to close GR1/M3) so regressions are caught mechanically.

### AC12 — Rules and ABAC policies are versioned; role definitions and their permission sets are not (LOW)

`rules` get hash-chained `rule_revisions` on every mutation — exemplary. By
contrast, changing a **role's** permission set (`role_permissions`) or an
**ABAC policy** body is not obviously captured in an append-only revision trail
the way rules are (the audit log records the action, but there is no full
before/after snapshot chain). Because a role's permission set silently widens the
authority of *every* current holder, it deserves the same revision treatment as
rules.

**Fix direction:** extend the `rule_revisions` pattern (or a shared
`acl_revisions` table) to `roles`/`role_permissions` and `abac_policies`
mutations. Low urgency (audit log gives partial coverage) but high value for
forensic reconstruction.

---

## 3. Suggested sequencing

The dependency order below front-loads the items that are either authorization
holes or that everything else needs (tests), and defers the by-design / low-risk
items:

1. **AC10** — add PDP + lifecycle/sweep service tests first, so the rest lands
   red→green.
2. **AC11** — verify/close route `authorize:` + `_csrf` across all seven
   controllers (mechanical, pairs with GR1/M3, blocks nothing but cheap).
3. **AC1** — add `DelegationService::expireLapsed` + wire into the command
   (small, self-contained, restores status honesty).
4. **AC2** — add delegation→source provenance + cascade on role/request
   revoke+expire (the real privilege-retention fix; the lazy-revalidation
   variant can ship first as a stopgap).
5. **AC4 + AC5 + AC7** — one scheduled-sweep expansion: pending-request timeout,
   pending-approval reminders/escalation, pending-break-glass-review
   reminders/escalation (they share a home and a notification path).
6. **AC3** — consume the account deactivate/suspend/merge signal (**after**
   membership M1/M2 emit it) to cascade-revoke a subject's grants.
7. **AC9** — consume the group archive/dissolve/merge signal (**after** GR2/GR3
   make the group side status-aware) to expire/flag scope-targeted grants.
8. **AC8** — decide + make deterministic the renew-after-expiry policy.
9. **AC6, AC12** — policy decision on grant-time dual-control; extend the
   revision trail to roles/ABAC. Lowest urgency.

## 4. Cross-review couplings (explicit)

- **AC2 ↔ (its own module):** the role→delegation and request→delegation
  provenance gap is internal but is the highest-value authorization fix here.
- **AC3 ↔ Membership M1/M2:** account deactivate/suspend/merge must fan out into
  ACL grant revocation; needs the Identity-side signal first.
- **AC9 ↔ Groups GR2/GR3:** grants pin scope groups the group lifecycle can
  archive/dissolve/merge; needs status-aware resolvers + closure pruning on the
  group side first.
- **AC11 ↔ Groups GR1 / Membership M3:** same missing-`authorize:`/`_csrf`
  pattern on mutating routes; close them with the same route-filter test style.

No code was changed in the course of this review.
