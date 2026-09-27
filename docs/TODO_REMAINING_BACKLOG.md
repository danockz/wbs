# TO-DO: Remaining lifecycle-remediation backlog (parked)

**Status:** ▶️ IN PROGRESS on 2026-09-17. **ST4 (stream relay/health sweep),
CM4 (community content retention purge), G2+G4 (held/fraud-review aging +
season-rollover held-entry policy), and J6 (stale journey proposals:
supersede-on-move + approve-guard + aging pass) are now SHIPPED** — see
§A.1/§A.2/§B.3 and §B item 4. Also fixed a CSP-related 404 on `POST identity/policies`
(segment-less create route + no-JS form). Full suite green:
**218 files / 13,551 assertions / 0 failed**; **21 sweeps** registered. (Prior
park point was 214 files / 13,440 assertions / 19 sweeps, after closing G2/G4.)
Since: J6 shipped, notification-template seeder audit (all 14 categories),
**ID4 (auth-token prune)** — `identity.session-prune` now also prunes API
access/refresh + credential-setup tokens — **N4 (notification digest
batching)** — new `notifications.build-digests` sweep bundles daily/weekly
preferences into per-recipient digests — and **§C M3/J5/AC11/G6 (route-filter
guard remainder)** — CSRF/authz hardening across identity lifecycle, journey
signals, ACL mutations, and gamification config, each with a mechanical
regression assertion (guard test now 60 passed) and the J5 scope-param mismatch
+ G6 follow-ups IDOR both fixed — and **G8 (event waitlist promotion on
capacity raise)** — `EventService::update()` promotes waitlisted registrations
FIFO into freed seats via a reused promotion primitive, notifying the promoted
people specifically (default-OFF gate; new `event_promoted` template + 6-locale
copy). Full suite now green:
**219 files / 13,636 assertions / 0 failed**; **21 sweeps** registered.

`NotificationTemplateSeeder` audited against EVERY `NotificationService::send()`
category across the codebase and now seeds all 14 (was 7): added the four in-app
aging-sweep categories G2/J6 emit (`gamification_review_reminder`/`_escalation`,
`journey_proposal_reminder`/`_escalation`) plus three others that were also sending
with no backing template and rendering empty — `event_updated` (Events material-change
fan-out), `partnership_commitment_due` (Contributions commitment reminder), and
`outreach_follow_up_due` (Referrals follow-up reminder). Placeholders match each
call site's actual context keys. `bootstrap_seeders_test.php` asserts all 14.

This consolidates every remaining item so nothing is lost. Resume order below.
Each item must follow the standing bespoke pattern already used across the
codebase: **no-JS / CSP-safe views, resource-light (version-stamped cache or one
bounded indexed query, gate resolved once), hierarchical-config gating
default-OFF, 6-locale parity, wiring-tested (standalone `*_test.php`), OpenAPI
regenerated where routes change, full suite green.** New background operations
plug into the unified sweep runner (`SweepContract` / `SweepRegistry` in
`app/Modules/Shared/Sweep/`, registered in `Shared/Config/Services.php`).

---

## A. Theme C — remaining sweeps that need new code (next up)

1. **ST4 — Stream relay/health sweep. ✅ SHIPPED (2026-09-16).** Relay heartbeats
   were ingested but nothing acted on their absence: a stream stuck `live` after
   its relay stopped beating never transitioned. Implemented as a bounded,
   idempotent, config-gated (default OFF) sweep folded into the unified runner:
   - `StreamRelayService::sweepStaleStreams(?org, limit, staleMinutes)` — one
     bounded indexed query over `status='live'` streams (`st_org_idx`); for each,
     the latest heartbeat (`srh_stream_idx`) — or `started_at` when a stream never
     beat — is compared to a staleness window (default 5 min). Stale streams get
     a monitor incident opened once (reusing `ensureIncident`, alerts fire, an
     org is notified once), `relay_state` stamped `down`, and the stream
     transitioned to `ended` (`status='live'` re-asserted in the UPDATE → safe to
     re-run; a stream with neither heartbeat nor `started_at` is left alone).
     Returns `{scanned, ended, skipped_gate, stream_ids}`.
   - Gate: new `streaming.relay_health_sweep` feature flag (default OFF, seeded in
     `AdminConfigSeeder::FLAGS`), resolved per stream's org + optional `group_id`
     override via the existing `SettingsService::isEnabled` behind a narrow
     `StreamFeatureGatePort` (`SettingsFeatureGateAdapter`, fails safe to OFF).
     Ungated when no gate wired → inert.
   - `Streaming/Sweep/RelayHealthSweep.php` (key `streaming.relay-health`)
     registered in `Shared/Config/Services.php sweepRegistry()` (now 17 sweeps).
   - Tests: `Streaming/Services/tests/relay_health_sweep_test.php` (22 assertions:
     stale/no-beat→ended, fresh/unknown/non-live→kept, gate OFF & no-gate→no-op,
     idempotent 2nd pass, per-org scoping, org=null all-orgs, per-group override);
     `sweep_registration_test.php` + `admin_config_seeder_test.php` manifests
     updated. No new routes (command-driven → no OpenAPI regen) and no
     user-facing lang keys. See `docs/STREAMING_LIFECYCLE_REVIEW.md` §ST4.

2. **CM4 — Community content retention purge. ✅ SHIPPED (2026-09-16).**
   Soft-`deleted` posts lingered indefinitely and `archived`/`deleted`/`hidden`
   precedence was ambiguous. Resolved:
   - **Precedence documented + enforced:** status wins over visibility for
     serving — a row is served ONLY when `status='active'`; `hidden`/`deleted`
     are never served regardless of visibility; `archived` is a VISIBILITY (an
     active row kept out of the default feed, reachable directly), orthogonal to
     status and never purged. FeedService already enforced `status='active'`, so
     no serving change was needed.
   - **Retention anchor:** migration `2026-09-16-000082_AddCommunityContentDeletedAt`
     adds `deleted_at DATETIME(6) NULL` to `community_posts` + `community_comments`
     (guarded ADD COLUMN, retention indexes `cp_retention_idx`/`cc_retention_idx`);
     `ModerationService::act('delete')` stamps `deleted_at`, `restore` clears it.
   - **Purge:** `ModerationService::purgeDeletedContent(?org, graceDays=30, limit)`
     — bounded, idempotent hard-delete of `status='deleted'` posts past grace
     (cascading comments/reactions/topic links) + directly-deleted comments; the
     terminal predicate is re-asserted in the DELETE (restore-safe) and the
     append-only `moderation_actions` audit is never touched.
   - `Community/Sweep/RetentionPurgeSweep.php` (key `community.retention-purge`,
     no gate/notifications — mirrors IN3) registered in `sweepRegistry()` (now 18).
   - Tests: `Community/Services/tests/retention_purge_test.php` (23 assertions:
     past-grace purge + cascade, within-grace/active/hidden/archived kept, direct
     comment purge, org scoping, org=null all-orgs, grace=0, idempotent 2nd pass,
     audit intact); manifests updated. See `docs/COMMUNITY_LIFECYCLE_REVIEW.md` §CM4.

## B. Theme C — queue/age/escalate sweeps (uniform remind→escalate→timeout)

These share one behaviour (AC5, G2, J6, R4, N4 all want it); consider a shared
escalation helper.

3. **G2 — Held points / open fraud reviews aging. ✅ SHIPPED (2026-09-16).** Open
   `fraud_reviews` never aged — a review no human noticed stranded the held points
   forever. Paired with **G4** (held-entry disposition at season rollover). Both
   shipped:
   - **G2 aging** — migration `2026-09-16-000083_AddFraudReviewAging` adds
     `reminded_at` / `reminder_count` / `escalated_at` watermarks + `fr_aging_idx`.
     `FraudReviewAgingService::sweepOpenReviews(?org, limit)` runs a uniform,
     watermark-guarded **remind → escalate → (optional) timeout** lifecycle: past
     `held_review_remind_hours` it reminds the approver-role holders (re-remind
     only after the interval), past `held_review_escalate_hours` it escalates once,
     and — only when `held_review_timeout_days > 0` — auto-rejects a very old review
     via `PointsEngine::rejectAward` (compensating reversal; system actor) so held
     points never go spendable. Default timeout=0 (never auto-reject). Recipients
     resolve via `role_assignments → roles`; notifications are best-effort. Wired
     behind narrow `ReviewConfigPort` / `ReviewRejectionPort` (adapters over
     ConfigService / PointsEngine). Sweep `gamification.held-review-aging`
     registered in the unified runner (now 19 sweeps). Thresholds seeded in
     `AdminConfigSeeder::GAMIFICATION_CONFIG`.
   - **G4 rollover policy** — `SeasonService` now consults config
     `held_rollover_policy` (default `carry_forward`) at rollover:
     *carry_forward* re-points open held entries (+ open reviews) to the NEW
     season and leaves them un-archived, so a later approve/clear rolls up into
     the ACTIVE season instead of mutating the frozen closed one; *reject_on_close*
     reverses them and rejects their reviews on close; *resolve_before_close*
     refuses rollover (`HELD_ENTRIES_OPEN`) until the queue is cleared. Next season
     is opened before disposal so carry-forward can target it; final entries are
     still snapshotted/archived unchanged.
   - Tests: `held_review_aging_test.php` (21), `rollover_held_policy_test.php` (20);
     `sweep_registration_test.php` + `admin_config_seeder_test.php` manifests
     updated. No route/OpenAPI/lang-parity changes. See
     `docs/GAMIFICATION_LIFECYCLE_REVIEW.md` §G2/§G4.
4. **J6 — Stale journey proposals.** ✅ SHIPPED 2026-09-17. Three coordinated
   fixes: (a) **supersede-on-move** — `ProposalSupersedeListener` (a transition
   listener) marks any pending proposal now `same`/behind (`regress`) the moved
   member `superseded`, except deliberate-regress proposals; (b) **approveProposal
   guard** re-derives direction vs the member's CURRENT stage and refuses a stale
   `same`/would-regress approval with `PROPOSAL_STALE` (409), closing the proposal
   as `superseded`; (c) **aging pass** — `JourneyProposalAgingService::sweepPendingProposals()`
   runs remind → escalate → optional-timeout over pending proposals, watermark-guarded
   (`reminded_at`/`reminder_count`/`escalated_at`, migration `2026-09-17-000084`),
   reminding the group's active `leader` members and escalating to a configurable
   role. Registered as sweep `journey.proposal-aging`; per-org platform settings
   `journey.proposal_{remind_hours=48,escalate_hours=120,timeout_days=0,timeout_action=rejected}`
   seeded by `AdminConfigSeeder`. Tests: `proposal_aging_test.php` (24) +
   supersede/approve-guard blocks in `journey_signal_test.php` (44);
   `sweep_registration_test.php` (82) + `admin_config_seeder_test.php` (33)
   manifests updated. No route/OpenAPI/lang-parity changes. See
   `docs/JOURNEY_LIFECYCLE_REVIEW.md` §J6.
5. **N4 — Notification digest batching.** ✅ SHIPPED 2026-09-17.
   `digest_frequency=daily|weekly` used to behave exactly like `instant` — no
   builder ever bundled the messages. Now the `RetentionPolicyGate` returns a new
   `digest` decision for a verified, opted-in, non-essential recipient on
   daily/weekly, with `defer_until` set to the next window boundary (next local
   midnight / next local Monday 00:00, computed in the user's timezone). `send()`
   persists those as a distinct `digest_pending` status (kept separate from
   `deferred` so the N1 release sweep never instant-sends them). New
   `NotificationService::buildDigests(?org, limit)` finds due `digest_pending`
   rows, groups by (org, user, channel), stages ONE `notification.digest.dispatch`
   job per group bundling the members, and flips each to terminal `digested`
   (guarded/idempotent). Registered as sweep `notifications.build-digests`. No
   migration — new statuses fit the existing `status VARCHAR(20)`. Tests:
   `build_digests_test.php` (24) + `digest_gate_test.php` (10); sweep manifest
   (86) updated. Essential categories never digest; digest supersedes
   quiet-hours/frequency-cap. See `docs/NOTIFICATIONS_LIFECYCLE_REVIEW.md` §N4.
6. **ID4 — Auth token prune.** ✅ SHIPPED 2026-09-17. Added a two-pass
   `prune($retentionDays)` (EXPIRE stale-but-live rows to a terminal state, then
   hard-DELETE terminal rows past the retention window) to `TokenService` (covers
   both `access_tokens` and `refresh_tokens`) and `CredentialSetupService`
   (`credential_setup_tokens`), mirroring the existing `SessionService::prune()`.
   Extended the existing `identity.session-prune` sweep to fan out to all three
   `prune()` calls (all tables are global, so org scope is ignored) and report a
   per-table breakdown. No migration — rides existing columns
   (`access_tokens.revoked_at/expires_at`, `refresh_tokens.status/expires_at/used_at`,
   `credential_setup_tokens.consumed_at/expires_at`). Refresh-token deletion uses
   `COALESCE(used_at, created_at)` as the age reference. Test:
   `app/Modules/Identity/Services/tests/token_prune_test.php` (21). Idempotent;
   sweep manifest unchanged (same key).

## C. Theme A — route-filter guard remainder (Phase 3, behind CSRF-EXEMPT allowlist)

7. ~~**M3, J5, AC11, G6** — CSRF/authz hardening on the remaining routes.~~
   ✅ **SHIPPED 2026-09-17.** All four closed; each backed by a mechanical
   regression assertion in `route_filter_guard_test.php` (now **60 passed**) and
   the previously-frozen CSRF-EXEMPT entries removed so a regression fails CI.
   `webcsrf` is header-exempt for Bearer / `X-WBS-Session` callers, so adding it
   to API-first PATCH/DELETE routes closes the browser-cookie CSRF gap without
   breaking token/API clients.
   - **M3** — the 7 identity mutations (`merges` submit + lifecycle
     `transition/suspend/lock/reactivate/deactivate/anonymize`) now carry
     `['authorize:identity.manage','webcsrf']` (was authorize-only). 🔴 live
     vuln closed.
   - **J5** — `POST journey/signals` now carries
     `['authorize:gamification.manage,any','webcsrf']` (was ungated inside the
     `auth` group). The scope-param mismatch is also fixed: `signal()` now
     authorizes the EFFECTIVE `scope_group_id` (the rule-firing scope
     `ingest()` actually honours), not just the journey-context `group_id`, so a
     caller can no longer fire rules in a branch they don't manage. Second PDP
     call only when the two scopes differ.
   - **AC11** — audited all mutating routes across the 7 AC controllers; the 4
     that were CSRF-exempt (`access-requests` submit [self-service, auth-only by
     design], `access-assignments` assign + `/revoke`, `break-glass` open) now
     carry `webcsrf`. All others already had `authorize:`+`webcsrf`.
   - **G6** — the 9 config PATCH routes + config/campaign DELETE routes +
     campaign lifecycle POSTs (activate/cancel/progress/close) now carry
     `webcsrf`. Follow-ups PATCH/cancel: rather than a coarse `gamification.manage`
     cap (which would break member self-service — follow-ups earn the follower
     points), fixed the real **IDOR** in `updateFollowUp`/`cancelFollowUp` with
     an ownership-OR-leader-scope guard (`guardFollowUpWrite()`): the actor must
     BE the record's `follower_user_id` or hold `gamification.manage` over the
     record's group. New `forbiddenFlash` lang key added across all 6 locales.
   - Suite: **218 files / 13,595 assertions / 0 failed**; OpenAPI spec regenerated
     (`tools/gen_openapi.py`); campaign-form CRUD test updated (PATCH now asserts
     webcsrf-present).

## D. Integrations — remaining IN items

8. **IN4 — React to owning-group teardown.** A connection carries a `group_id`;
   nothing reacts to `group.archived/dissolved/merged`. Subscribe to the
   group-teardown signals — disable/reassign connections owned by a dissolved
   group, revoke grants to archived grantee groups. Blocked on the same
   closure-prune foundation as other group-teardown consumers (Groups GR2/GR3).
9. **IN5 — Remaining connection-lifecycle tests.** The IN3 + IN1/IN2 tests
   started this dir; still want state-machine legality across the full matrix, the
   vault write-only/`useSecret` no-return guarantee, and breaker
   CLOSED/OPEN/HALF_OPEN transitions.

## E. Events — parked event-lifecycle gaps

See `docs/TODO_EVENT_LIFECYCLE_REMAINING.md` for full detail. Open items:
10. ~~**G8 / E-C2 — Waitlist promotion on capacity raise.**~~ ✅ **SHIPPED
    2026-09-18.** `EventService::update()` promotes `waitlisted` registrations
    FIFO into seats freed by a capacity raise (or a lift to unlimited) via the
    new `RegistrationService::promoteWaitlistToCapacity()`, reusing the
    promote-on-cancel transition (no fork), row-locked against oversell, guarded
    idempotent. Promoted registrants notified specifically via a new
    `notifyPromoted()` on the G3 notifier (default-OFF gate, `event_promoted`
    template + 6-locale copy). Test `waitlist_promotion_test.php` (22 passed).
11. **L4, L5, L6** — remaining event-lifecycle items (see that doc). **Next up:
    L4 (archive).**

## F. Identity — remaining ID items (lower priority)

12. **ID3 — Anonymization scrub fan-out.** `transition()` scrubs the user row's
    PII; a fan-out to other modules holding PII copies is still open.

---

## Cross-cutting note
The recurring theme across B/E is "a table parks work with no scheduled runner."
The unified sweep runner (Theme C foundation) is the home for all of them; prefer
extending it over new one-off commands. Downstream teardown reactions (IN1↔MT5
Meetings/Streaming cut-off, IN4↔group teardown) reuse the JobRouter topic seams
already wired.
