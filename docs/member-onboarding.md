# Member Onboarding (end-to-end)

_Canonical reference for how a person becomes an active, placed, disciple-tracked
member. Onboarding is **not one wizard** — it is a set of composable steps owned
by different modules (Identity → Referrals → Groups → Journey), each idempotent
and independently auditable. This doc traces the real code paths and the handoffs
between them, and flags the one seam that is intentionally not auto-wired today._

Modules involved: `Identity`, `Referrals`, `Groups`, `Journey`
(+ `Notifications` for the invite/verification messages, `Audit` for evidence).

---

## The five steps at a glance

| # | Step | Owner | Entry point | Result state |
| - | ---- | ----- | ----------- | ------------ |
| 1 | Account created | Identity `AccountService::register()` | `POST /register`, or staff/import | `users.status` = `prospect` \| `pending_verification` \| `active` |
| 2 | Credentials / activation | Identity `CredentialSetupService`, `markEmailVerified()` | `GET/POST /set-password`, email-verify | `active`, `email_verified`, sessions revoked |
| 3 | Referral origin (optional) | Referrals `ReferralService` | link click → `captureProspect()` → `attributeConversion()` | `prospects.state` = `captured` → `converted`; `referral_attributions` row |
| 4 | Group belonging | Groups `GroupMembershipService::add()` / `GroupPublicService::join()` | admin add, or `POST /g/{code}/join` | `group_members` row, `approved` or `pending` |
| 5 | Discipleship journey | Journey `JourneyService` + signal emitters | auto (signals) or leader action | `member_journeys` row at the entry stage, advancing |

Belonging (step 4), account status (steps 1–2) and discipleship stage (step 5)
are **deliberately independent axes** — a person can be `active` without a group,
in a group without being far along the journey, and a `Leader` on the journey
while their login account is merely `active`.

---

## Step 1 — Account creation (Identity)

All roads lead to `AccountService::register($organizationId, $data)`, which runs
one transaction and returns `{ user_id, email, phone, status, is_minor }`.

**Three ways in, three starting states** (`FR-ID-009` lifecycle):

- **Self-registration** — `POST /register` (rate-limited `auth.register`). Starts
  at **`pending_verification`**; promoted to **`active`** when a channel is
  verified (see step 2). To avoid leaking account existence, a uniqueness
  collision returns the **same** generic `pending_verification` response as a
  success.
- **Staff-captured lead** — pass `prospect: true` → starts at **`prospect`**
  (a known contact, no sign-in yet).
- **Social sign-in / trusted import** — `initial_status: active` may start the
  account `active` directly.

What `register()` does in that single transaction:

1. Validates + normalizes: email format; **phone → E.164** via `PhoneNormalizer`
   using the jurisdiction's default region.
2. Resolves the **identity policy** for the country (`IdentityPolicyService`):
   configurable email/phone **uniqueness**, and **minor / age gating** (a
   too-young registration is rejected; a minor is flagged `is_minor`).
3. If a password was supplied, enforces the **password policy** (+ optional
   `BreachChecker`) and hashes it. No password ⇒ the account is passwordless and
   must be activated via an invite token (step 2).
4. Inserts the `users` row, an **append-only `account_state_transitions`**
   evidence row (`from=null → to=status`, reason `registration`), and any
   **consent** records (`user_consents`).
5. **Assigns a sponsor (upline).** Every prospect/member has exactly one active
   sponsor, so `register()` links one **best-effort, after the account
   transaction commits** (a sponsor hiccup never rolls back a valid account):
   - an explicit `sponsor_id` (a referral referrer, or `?sponsor=` on the
     registration link) wins when it is a valid, non-self user in the org;
   - else the **hierarchical group leader — or delegate** — of the target
     `group_id`, resolved by walking **up** the group tree
     (`SponsorResolver`): the group's `leader_user_id`, else its earliest active
     `leader`/`admin`/`coordinator` member, else the nearest **ancestor** group
     with such a leader, else the **organization root leader**;
   - the edge is written through `SponsorshipService::assign()`, so acyclicity
     and the single-active invariant hold; the resolved id is returned as
     `sponsor_id` in the result.

   The same rule backs the other account-creation paths, so an upline is never
   missing: public group self-join (`GroupPublicService`) and staff
   bulk/contact promotion (`ContactBookService::ensureContactUser()`), which
   prefers the contact owner then the assigned group's leader and **never
   overrides an existing active sponsor**. A brand-new org's very first account
   has no leader above it yet, so it is the only case that stays sponsor-less
   until a leader exists.

> A duplicate email/phone is a **soft error, never a silent merge** — deliberate
> duplicates are reconciled later via the merge workflow in
> `AccountLifecycleService` (`submitMerge` / `approveMerge`).

---

## Step 2 — Credentials & activation (Identity)

For **invited / passwordless** members, `CredentialSetupService` runs the
first-password handshake:

1. `issue($org, $userId, purpose = 'invite')` mints a **single-use, expiring**
   token. Only the **SHA-256 hash** is stored; the plaintext is returned **once**
   to embed in the invite link. Issuing a new token invalidates any prior unused
   one of the same purpose, so only the newest link works.
2. The member opens `GET /set-password?token=…` — `inspect()` validates the token
   **without** consuming it, to render the form.
3. `POST /set-password` → `consume($rawToken, $newPassword)`:
   - **atomically claims** the token (guards double-submit / races),
   - sets the password (shared policy enforced; on a weak password the claim is
     rolled back so the user can retry),
   - for an **invite**: flips the account to **`active`** and
     **`email_verified = 1`**,
   - **revokes all existing sessions** (a credential change can't leave a stale
     or hijacked session alive).

For **self-registered** members who set a password up front, activation instead
happens through channel verification: `markEmailVerified($userId)` sets
`email_verified` and, if still `pending_verification`, **promotes to `active`**
with its own append-only transition row (reason `email_verified`).

Optional hardening at/after activation: **MFA** enrolment (`MfaService`,
TOTP/WebAuthn) — not required to be active, but available.

---

## Step 3 — Referral origin (optional, Referrals)

When a member arrives through a referral link, the origin is tracked so credit
and funnels work — this runs alongside steps 1–2, it doesn't replace them:

1. `resolve($code)` → the active link; `recordClick()` logs the visit (with basic
   fraud signals in `FraudService`).
2. `captureProspect($code, $data)` stores a `prospects` row (`state = captured`,
   email stored **hashed**, explicit `consent` required).
3. After the person actually converts (registers / joins),
   `attributeConversion($org, $referrerId, $convertedUserId, $type, $sourceRef)`
   writes a `referral_attributions` row and flips the matching prospect to
   `converted`. It is **idempotent** on `(converted_user_id, conversion_type,
   source_ref)` — a replayed conversion is a no-op.

`conversion` is one of the recognised journey `source` values, which is the
natural bridge into step 5 (see "The one open seam").

---

## Step 4 — Group belonging (Groups)

Placement into the hierarchy is a separate, explicit act via
`GroupMembershipService::add()` (staff/admin) or the public
`GroupPublicService::join()` behind `POST /g/{code}/join`:

- Creates a `group_members` row with a `membership_type`
  (`member` | `leader` | `activity` | `department` | `team` | `guest`) and a
  free-form `role`.
- Honours an optional **approval workflow**: `requires_approval` ⇒ status
  `pending` / `approval_state = pending` until a reviewer approves; otherwise
  `active` / `approved` immediately. Public self-join defaults to `member` and is
  pending when the group requires approval.
- Enforces **one active membership per (user, group, type)** via an `active_key`
  UNIQUE index, and runs **conflict detection** for incompatible types.
- Every mutation appends a `group_membership_events` row **and** an `Audit`
  record; the public join also resolves a **sponsor** (the group's leader, else
  the earliest active leader/admin) — the same upline rule described in step 1.

### Profile photo (optional, any time)

A member may set a **profile photo** (`users.profile_photo_url`) via the
self-service, CSRF-guarded `POST /me/photo` (a hosted image URL or a `data:`
image URI) and clear it with `POST /me/photo/remove`. There is **no upload
pipeline** — it is deliberately resource-light. When no photo is set, the
platform serves a **deterministic inline-SVG initials avatar**
(`Shared\Support\Avatar`): pure, dependency-free, no network call, so it renders
even in a network-less preview and is stable/cacheable per member. `GET
/me/avatar` always returns an image (the photo when set, else the initials SVG),
giving clients one stable `<img src="/me/avatar">`.

Belonging is intentionally decoupled: a member may attend any allowed group's
events and contribute to any group's causes regardless of their own memberships
(see the contribution/attendance attribution rules in the gamification docs).

---

## Step 5 — Discipleship journey (Journey)

The member's **maturity / role progression** (prospect → new believer →
foundation → worker → leader → sender) is tracked by the Journey module, separate
from and above the account/group axes.

- A journey record (`member_journeys`) **opens at the entry stage** (`prospect`
  by default) via `JourneyService::openJourney()`, and advances up the ladder.
- Advancement is largely **automatic**: the wired **signal emitters** feed
  `JourneySignalService` when a course is completed, an event is attended, a
  follow-up is recorded, or a contribution is verified. Membership rules
  (RuBAC facet `membership`) decide whether a signal **auto-advances** the member
  or **queues a proposal** for a leader.
- Leaders can also move members explicitly (`POST /journey/members/{id}/transition`),
  and see **what to do next** via
  `GET /journey/members/{id}/recommendations` (stage-linked activities).
- Forward moves optionally **credit the discipler** through the existing points
  engine (Option D), and the disciple-making leaderboard ranks who has moved the
  most people forward.

See `docs/membership-journey.md` for the full stage model, rules, emitters and
attribution.

---

## The one open seam — ✅ CLOSED 2026-09-18 (M4)

**Resolved via Option 1 (rule-driven).** `AccountService::register()` now emits
`journey.signal.member.registered` and `ReferralService::attributeConversion()`
emits `journey.signal.member.converted`, each through a `JourneySignalPort` seam
(Identity/Referrals stay decoupled from the Journey module). Two default
membership ENTRY rules were seeded: `mbr.register.open_prospect` (opens the
journey at `prospect`) and `mbr.convert.open_first_timer` (opens at
`first_timer`). Both gate on a `has_journey = false` condition (compiled from the
`(new)` sentinel from-stage), so the rule fires exactly ONCE — when no journey
exists yet — and is a harmless no-op on replay. Emission is best-effort +
fault-isolated (a journey hiccup never undoes a valid registration/attribution)
and optional (no port wired ⇒ the write still succeeds). A brand-new member now
has a journey row at the first ladder stage, so first-stage pipeline counts no
longer under-report. Pinned by `register_journey_signal_test.php` (18/0),
`conversion_scope_test.php` (29/0) and `membership_rule_seeder_e2e_test.php`
(54/0).

--- original finding below ---

**No step automatically opens a journey when an account is created or a referral
converts.** Verified in code: `openJourney()` is only ever called from within the
Journey module; neither `AccountService::register()` nor
`ReferralService::attributeConversion()` calls it or emits a journey signal.

This is **not broken**, because `JourneyService::transition()` auto-opens a
journey at the target stage if none exists — so a journey materializes on the
**first real signal or manual move** (e.g. first event attended). The practical
effects are only:

- there is no explicit "new member → journey opened at `prospect`" moment, so a
  brand-new, inactive member has no journey row until they *do* something;
- pipeline counts at the very first stage under-report contacts who have taken no
  tracked action yet.

**To close it** (small, additive, fault-isolated — matches the existing emitter
pattern), the natural options are:

1. Emit `journey.signal.member.registered` from `AccountService::register()` and
   `journey.signal.member.converted` from `ReferralService::attributeConversion()`,
   so a `membership`-facet rule can open the journey at the right entry stage; or
2. Call `openJourney(..., ['source' => 'conversion'])` directly at those points.

Option 1 keeps everything rule-driven and consistent with the rest of Option C.
This is intentionally left as a decision rather than silently wired.

---

## Onboarding sequence (happy path: invited member via referral)

```
Referrer shares link
        │
        ▼
click → captureProspect()                      [Referrals] prospects: captured
        │
staff/admin creates the account
        ▼
AccountService::register(prospect|pending)     [Identity]  users + state transition
        │
CredentialSetupService::issue('invite')        [Identity]  hashed token; link sent (Notifications)
        │
member opens link → consume(password)          [Identity]  status → active, email_verified, sessions revoked
        │
attributeConversion(user, referrer, ...)       [Referrals] referral_attributions; prospect → converted
        │
GroupMembershipService::add()/join()           [Groups]    group_members (approved|pending) + events + audit
        │
first tracked action (event/course/follow-up/gift)
        ▼
signal → JourneySignalService::ingest()        [Journey]   journey opens at entry stage, then advances
```

Every arrow is idempotent and independently retryable; there is no hidden global
transaction spanning modules — each step commits its own evidence.

---

## Where each thing lives (quick index)

| Concern | Code |
| ------- | ---- |
| Register / profile / verify | `Identity/Services/AccountService.php` |
| Account status transitions & merge | `Identity/Services/AccountLifecycleService.php` |
| Invite / set-password tokens | `Identity/Services/CredentialSetupService.php` |
| MFA enrolment | `Identity/Services/MfaService.php` |
| Referral capture / conversion | `Referrals/Services/ReferralService.php` |
| Group add / public join | `Groups/Services/GroupMembershipService.php`, `GroupPublicService.php` |
| Journey open / transition / signals | `Journey/Services/JourneyService.php`, `JourneySignalService.php` |
| Public endpoints | `POST /register`, `GET/POST /set-password`, `POST /g/{code}/join` |
