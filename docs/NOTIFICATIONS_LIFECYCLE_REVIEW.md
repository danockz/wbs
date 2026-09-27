# Notifications (Delivery / Consent / Campaigns) Lifecycle Review

_A code-grounded gap analysis of the **notification delivery lifecycle** — how a
transactional or campaign message is gated by consent/preferences, rendered,
recorded as a delivery, dispatched through a channel transport with retry and a
circuit breaker, and how its terminal outcomes (sent/delivered/bounced/
complained/failed/suppressed/deferred) feed suppression and reliability — plus
how the consent, quiet-hours, frequency-cap and campaign maker-checker rules hold,
and how these interact with every module that emits through the outbox._

Prepared 2026-09-15, as the eighth review in the sequence after membership,
groups, access control, journey/activities, contributions, referrals, and
gamification. Method: read the migration, the four services (Notification,
RetentionPolicyGate, TemplateRenderer, Campaign), the seven transport classes
(Dispatcher, Email/InApp transports, Registry, Message/Result), the two
controllers, the routes, and the JobRouter dispatch wiring; every finding cites
the code it rests on. **No code was changed** — this is the review that precedes
any fixes.

Scope note: this review is about a **delivery and a campaign as first-class
objects** — their state machines, the consent/suppression invariants, and the
housekeeping (deferred release, digest build, bounce→suppression, retry) that
keeps sending both effective and lawful. Template *content* i18n is treated as
correct background (covered by the translation-merge work) and appears only where
a delivery lifecycle depends on it.

---

## 1. The notifications model at a glance

**Tables** (`000011` CreateNotifications — one migration):

- **`notification_templates`** — versioned by
  `(org, key_name, channel, locale, version)`, `status` `draft|active|retired`.
- **`notification_preferences`** — per `(org, user, channel, category)`:
  `opted_in`, `stopped` (hard STOP / legal opt-out), quiet-hours window,
  `digest_frequency` `instant|daily|weekly|off`.
- **`notification_channels`** — verified endpoints (`endpoint_hash`,
  `verified_at`); a channel is usable only after verification.
- **`notification_campaigns`** — `status` `draft|pending_approval|approved|
  queued|sent|cancelled`, `priority` `essential|high|normal|low`,
  `audience_filter` (JSON tree), `approved_by` (≠ `requested_by`, SoD),
  `audience_count`.
- **`notification_deliveries`** — the per-message record. `status` `queued|sent|
  delivered|bounced|failed|suppressed|deferred`, `suppression_reason`,
  `dedupe_key` (UNIQUE — idempotent send), immutable `recipient_snapshot` +
  `content_fingerprint`, `template_version`, `provider_request_id`.
- **`notification_suppressions`** — `scope` `all|category|channel`, `reason`
  `do_not_contact|complaint|hard_bounce|stop`.

**Delivery state machine:**

```
send() --gate--> queued     (dispatch staged on outbox) --transport--> sent --> delivered
             \-> suppressed (opt-out / unverified / digest-off / do-not-contact; terminal)
             \-> deferred   (quiet hours / frequency cap; defer_until set)
queued/sent --> bounced | complained | failed (terminal outcomes)
```

**Campaign state machine:** `draft → pending_approval → approved → queued → sent`
(+ `cancelled`), SoD at approve.

**The good news up front.** The consent gate and the transport reliability model
are genuinely well built and should be protected:

1. **The consent gate is fail-closed and lawful-basis aware** — `RetentionPolicyGate`
   checks, in order: hard do-not-contact/complaint/STOP suppression, verified-channel
   requirement, per-category opt-out / hard STOP (which "group policy can never
   override"), digest-off, quiet-hours defer, and frequency-cap defer. Crucially,
   **essential** categories (security/legal) carve through suppression/verification
   with a documented lawful basis, while everything else is denied by default.
2. **Sends are idempotent** — `dedupe_key` UNIQUE with a lookup-first path; a
   redelivered outbox event returns `deduplicated` rather than double-sending.
3. **The transport layer has a correct retry + circuit-breaker taxonomy** —
   `NotificationDispatcher` distinguishes *accepted* (→ sent, breaker success),
   *permanent rejection* (→ terminal `failed` via `recordTerminalRejection`,
   breaker untouched — a bad address must not trip the channel), and *transient
   fault* (throw → queue retries with backoff, breaker failure). Recipient-side
   outcomes (bounced/complained) explicitly never blame the provider's breaker.
4. **Campaigns are maker-checker with an immutable audience snapshot** — SoD
   (`approver ≠ requester`), audience count frozen at submit, and per-delivery
   `recipient_snapshot` + `content_fingerprint` so what was sent is auditable.
5. **Only intended sends queue provider work** — a suppressed/deferred delivery
   is recorded but does **not** stage an outbox dispatch job, so suppressed
   messages cost nothing downstream.

The findings below are the gaps around the edges of that solid core — and the
first two are HIGH because they mean whole classes of message silently never
arrive.

---

## 2. Findings, ranked

### N1 — `deferred` deliveries are never released — quiet-hours and frequency-capped messages are dropped forever (HIGH)

The gate correctly defers a message for **quiet hours** (→ `deferred`, with a
`defer_until` at the end of the user's quiet window) or a **frequency cap** (→
`deferred`, `defer_until` +24h). But **nothing ever releases a deferred
delivery**: there is no command, no runner, and no re-enqueue path (grep for
`deferred`/`defer_until` finds only the gate that sets it, the service that
records it, and unrelated Streaming files — no releaser). A deferred delivery
never stages an outbox dispatch job (only `queued` does), so it sits at
`deferred` permanently.

Consequence: **every message a user would receive just after quiet hours, or
just after a frequency window resets, is silently lost.** The `defer_until`
timestamp — the entire point of "defer rather than drop" — is written and never
read. For a user with quiet hours configured, this can mean they receive *nothing*
non-essential, ever.

This is the notifications instance of the recurring "sweep/queue with no
scheduled runner" pattern (ACL AC4/AC5, Journey J6, Contributions C5, Referrals
R4, Gamification G2) — but here the dropped items are user-facing messages, so
the impact is immediate and visible-by-absence.

**Fix direction:** add a scheduled `notifications:release-deferred` command that
finds `status='deferred'` deliveries with `defer_until <= now`, re-evaluates the
gate (the cap/quiet window may still apply → re-defer), and on pass flips them to
`queued` + stages the dispatch job. Idempotent, bounded, shares the cron home
with the other sweeps.

### N2 — Campaigns have no fan-out: `approved → queued` flips a status but nothing resolves the audience or sends (HIGH)

The campaign lifecycle is `draft → pending_approval → approved → queued → sent`,
but `CampaignService` implements only the status flips: `create`,
`submitForApproval`, `approve` (SoD), and `markQueued`. There is **no method and
no runner** that (a) resolves the `audience_filter` JSON tree into a recipient
set, (b) creates a `notification_deliveries` row per recipient (via `send()` so
the consent gate applies), or (c) advances the campaign to `sent`. `markQueued`
sets `status='queued'` and returns — the audience is never materialised and no
message is ever dispatched.

Consequence: **an approved campaign produces zero deliveries.** The whole
campaign feature terminates at a status column; `sent` is defined but unreachable
by any code path. The immutable `recipient_snapshot`/`audience_count` machinery
has nothing to populate it.

**Fix direction:** implement a campaign fan-out (a `notifications:run-campaigns`
command or a `dispatchCampaign` service method) that, for each `queued` campaign,
resolves `audience_filter` to recipients, calls `send()` per recipient with the
campaign_id + priority + frozen snapshot (so per-recipient consent still gates
it), and marks the campaign `sent` when the batch is enqueued. Bound the batch
and make it resumable so a large audience doesn't run unboundedly.

### N3 — Bounces and complaints don't create suppressions — the same bad address is retried indefinitely (HIGH)

`updateDeliveryStatus` accepts `bounced` and `complained` and correctly keeps
them off the circuit breaker (they're recipient-side, not provider faults) — but
it does **nothing else**: it does not write a `notification_suppressions` row.
The gate suppresses future sends only if a suppression exists, and the only thing
that reads `hard_bounce`/`complaint` is the gate — so a hard bounce or a spam
complaint has **no effect on future sends**. The next message to that address is
gated as if nothing happened, re-queued, and bounces/complains again.

Consequences: (a) **deliverability and sender reputation degrade** — repeatedly
mailing hard-bounced addresses and ignoring complaints is exactly what gets a
sending domain blocklisted; (b) a user who hit "this is spam" (a `complained`
outcome) keeps receiving mail, which is a compliance problem, not just a
reputation one. The `notification_suppressions` table has purpose-built
`hard_bounce` and `complaint` reasons that nothing ever writes.

**Fix direction:** on `bounced` (hard) → insert a `notification_suppressions`
row (`reason=hard_bounce`, `scope=channel`); on `complained` → insert
(`reason=complaint`, `scope=all`, and set `stopped=1` on the preference). Decide
soft-vs-hard bounce handling (soft bounce → retry N times then suppress). This
closes the loop the gate already expects to be closed.

### N4 — Digest preferences (`daily`/`weekly`) are honoured only as on/off, with no digest builder (MED) — ✅ RESOLVED 2026-09-17

**Resolution (option a — digest builder).** The gate now distinguishes
daily/weekly from instant/off:

- `RetentionPolicyGate::evaluate()` returns a new `digest` action (reason
  `digest_daily`/`digest_weekly`) for a verified, opted-in, non-essential
  recipient whose preference is `daily`/`weekly`, with `defer_until` at the next
  window boundary — next **local midnight** (daily) or next **local Monday 00:00**
  (weekly), computed in the user's timezone via `digestDefer()`. Digest
  supersedes quiet-hours/frequency-cap (the message won't go out until the window
  regardless); essential categories never digest.
- `NotificationService::send()` maps `digest` to a distinct `digest_pending`
  status (NOT `deferred`, so the N1 release sweep never instant-sends it) and
  stores the `defer_until` watermark.
- `NotificationService::buildDigests(?org, limit)` (bounded, idempotent) finds
  `digest_pending` rows whose window has closed, groups them by
  `(organization_id, user_id, channel)`, stages ONE `notification.digest.dispatch`
  job per group bundling every due member (delivery ids + categories + count), and
  flips each member to terminal `digested` (guarded so a re-run bundles each row
  exactly once). Legacy NULL-`defer_until` rows are skipped.
- Runs as sweep `notifications.build-digests` (registered in the shared registry).
  No migration — `digest_pending`/`digested` fit the existing `status VARCHAR(20)`.

Tests: `build_digests_test.php` (24), `digest_gate_test.php` (10),
`sweep_registration_test.php` (86).

**Original finding (for reference):**

### N4 — Digest preferences (`daily`/`weekly`) are honoured only as on/off, with no digest builder (MED)

`digest_frequency` supports `instant|daily|weekly|off`, and the gate suppresses
`off` — but there is **no digest builder** (grep finds no digest command). So a
user who selects `daily` or `weekly` is treated exactly like `instant`: each
message sends immediately. The batched-digest promise ("we'll bundle these into a
daily summary") is unfulfilled — the preference is effectively a lie for two of
its four values.

**Fix direction:** either (a) implement a digest builder that holds `daily`/
`weekly` messages (as `deferred` with a digest window) and a scheduled command
that renders + sends the bundled digest, reusing the N1 deferred-release
machinery; or (b) if digests are out of scope for now, remove `daily`/`weekly`
from the accepted values and document `instant|off` only, so the UI doesn't
promise behaviour that doesn't exist.

### N5 — The `notifications/send` endpoint is unauthenticated and trusts a caller-supplied `user_id` (MED)

`POST notifications/send` carries only `ratelimit:notification.broadcast` — no
`auth`, no `authorize:`, no `webcsrf` — and the controller reads `user_id`,
`channel`, `category`, `body`, and `context` straight from input with no guard.
So an unauthenticated caller can send a notification to **any** `user_id` with
**arbitrary body content** in any non-essential category (subject to the consent
gate and rate limit).

Two concrete risks: (a) **content injection / phishing** — arbitrary `body` +
`context` rendered and delivered to a chosen user under the org's trusted sender
identity; (b) **category laundering** — while `essential` categories carve
through consent, a caller choosing a category is at least a nuisance vector. The
consent gate limits *who can be reached*, but not *who can trigger a send*.

This is the same missing-auth pattern as Referrals R1 (fully open) — less severe
here because the gate + rate-limit constrain impact, but still an open trigger for
outbound messaging.

**Fix direction:** require `auth` (+ an `authorize:` capability for on-behalf-of
sends) on `notifications/send`, and only trust `user_id` when the caller is
authorised to message that user; otherwise restrict to self. Transactional sends
from other modules already go through the service directly (not this route), so
gating the HTTP endpoint shouldn't disrupt them. Validate/whitelist `category`.

### N6 — Retired/updated templates and unversioned sends: no guarantee a delivery pins an active template version (MED)

Templates are versioned with a `draft|active|retired` status, and deliveries
carry a nullable `template_version` — but `send()` accepts a raw `body` string
and an optional `template_version` from the caller rather than resolving the
active template itself. So (a) a delivery can be recorded with
`template_version = null` (no link to what was sent beyond the fingerprint), and
(b) nothing prevents sending from a `retired`/`draft` template if a caller passes
its body/version. The versioning machinery exists but the send path doesn't
enforce "render from the currently-active version and pin it."

**Fix direction:** have `send()` (for template-keyed sends) resolve the active
template for `(org, key, channel, locale)`, render from it, and pin its
`version`, refusing `retired`/`draft`. Keep the raw-body path only for system/
internal messages, clearly separated.

### N7 — No lifecycle reaction to account deactivation / channel loss (MED — cross-review coupling)

Nothing stops queued/deferred deliveries or preferences when a user's account is
deactivated/suspended (membership M1) or merged (M2): deliveries key on `user_id`
and the gate never checks account status. A deactivated user can still be the
target of deferred releases (once N1 exists) and campaign fan-out (once N2
exists), and a merged user's preferences/suppressions/verified channels aren't
carried to the survivor.

**Fix direction:** on account deactivate/suspend → cancel pending/deferred
deliveries and treat the account as suppressed; on merge → move preferences,
suppressions and verified channels to the survivor (union, most-restrictive
wins). Consume the same M1/M2 signals ACL AC3, Journey J4, Contributions C8 and
Referrals R7 all require.

### N8 — Thin test coverage; the delivery/campaign lifecycle edges are untested (MED)

There is no `Services/tests/` directory for Notifications (the module's tests, if
any, live under Views/Controllers). The consent gate's fail-closed branches, the
dispatcher's retry/rejection taxonomy, deferred release (N1), campaign fan-out
(N2), and bounce→suppression (N3) are exactly the safety- and
compliance-critical paths that deserve unit pinning — and the absence of N1/N2/N3
suggests no test exercises those flows end-to-end.

**Fix direction:** add service tests for the gate decision matrix (each
suppress/defer/send branch incl. the essential carve-out), the dispatcher
outcomes, and — before implementing them — red tests for deferred release,
campaign fan-out, and bounce/complaint suppression.

---

## 3. Suggested sequencing

Front-loads the "messages silently never arrive" defects and the compliance loop,
then the preference honesty and couplings:

1. **N8** — add gate/dispatcher tests + red tests for N1/N2/N3.
2. **N1** — deferred-release command (quiet-hours/frequency messages currently
   dropped forever; highest user-visible impact).
3. **N2** — campaign fan-out (approved campaigns currently send nothing).
4. **N3** — bounce/complaint → suppression (deliverability + compliance loop).
5. **N5** — gate the `notifications/send` route + validate `user_id`/`category`.
6. **N6** — resolve + pin the active template version on template-keyed sends.
7. **N4** — implement digests or trim the preference to honest values.
8. **N7** — consume account M1/M2 signals (cancel/suppress/merge preferences).

## 4. Cross-review couplings (explicit)

- **N1/N4 ↔ ACL AC4/AC5, Journey J6, Contributions C5, Referrals R4, Gamification
  G2:** the recurring "queue/sweep with no scheduled runner" pattern — here it
  drops user-facing messages.
- **N5 ↔ Referrals R1 / Groups GR1 / Membership M3 / ACL AC11 / Journey J5 /
  Gamification G6:** the recurring missing-auth pattern on a mutating/trigger
  route.
- **N7 ↔ Membership M1/M2, ACL AC3, Journey J4, Contributions C8, Referrals R7:**
  account deactivate/suspend/merge must fan out into the notifications state.
- **N3 ↔ the broader reliability model:** bounces/complaints already stay off the
  circuit breaker correctly; suppression is the missing recipient-side half.

No code was changed in the course of this review.
