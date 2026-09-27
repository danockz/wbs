# TO-DO: Involvement-based triage for the Membership Journey pipeline

**Status:** ✅ COMPLETE — engine + service integration + UI surfacing + admin
config console + batch-recompute action, all with dedicated wiring tests and the
full suite green (149 files / 11,287 assertions / 0 failed). Supersedes the
placeholder time-in-stage triage. Delivery order chosen by the user: **core
first, then UI** — both delivered.

## What is DONE (config console, final pass)

- **Admin config console** — `GET /journey/involvement/config` (scope-checked,
  read) renders the self-contained, no-JS, CSP-safe `involvement_config` view:
  enable toggle + window/activity-target inputs (with min/max clamp hints) +
  looped band-threshold and quantum-weight number fields, each with a default
  hint, plus a not-writable banner and a back-to-pipeline link. `POST
  /journey/involvement/config` (`authorize:gamification.manage,any` + `webcsrf`)
  clamps every value to the reader's bounds and persists through the single
  hierarchical config store via `EffectiveConfigWriteAdapter` (no parallel
  config), PRG'ing back with `savedFlash`. Reached from a "Triage settings" link
  on the pipeline board (both triage modes). `InvolvementService` gained
  `configView()` / `saveConfig()` + a narrow `ConfigWriterPort`.
- **i18n** — `Journey.admin.involvementConfig.*` (30 keys) + top-level
  `Journey.involvementSettings` link label across all 6 locales, parity-verified.
- **Menu coverage** — `journey/involvement/config` added to
  `MenuCoverage::EXCLUSIONS` (a secondary settings surface, not primary nav).
- **Batch-recompute entry point** — `POST /journey/involvement/recompute`
  (`recomputeInvolvement`, scope-checked + webcsrf) drives
  `recomputeContext()` for a context and PRGs back with a count flash. Satisfies
  the "admin action / scheduled job" requirement.
- **Tests** — `involvement_config_console_test.php` (52/0: configView defaults,
  saveConfig clamp + round-trip + no-writer guard, controller/route/view/i18n
  wiring); `service_keys_test` stays green (factory key `involvement`); OpenAPI
  regenerated (441 paths / 514 operations).

## What was DONE earlier (core + UI surfacing)

## What is DONE (core, this pass)

- **Migration** `2026-09-14-000068_CreateMemberInvolvementSnapshots.php` — the
  materialized per-member snapshot (one row per org+user+group context) carrying
  the three inputs' figures + the classified `band` + the current
  `stage_code`/`stage_phase` (so the board splits stage×band in ONE grouped
  query, no join, no per-member fan-out).
- **`InvolvementService`** — the engine: `classify()` pure ORDERED RULE BANDS
  (no eval), `compose()` (participation_bps = activities/target capped 100%;
  own_quantum = configurable weighted sum of points + sponsorships +
  giving-major; downline_quantum = configurable share of disciples' own quantum;
  quantum = own + downline), hierarchical+configurable `effectiveConfig()`
  (window clamped ≥30 days, thresholds + weights merged over defaults), the
  `enabled` gate (default OFF), `refreshForMember()`/`recomputeContext()` write
  path, and the read side `bandCountsByStage()`/`snapshotsAtStage()`. Implements
  `JourneyTransitionListener` (refresh the moved member) + `InvolvementTriagePort`.
- **Ports + adapters** — `ConfigResolverPort` → `EffectiveConfigAdapter` (over
  the existing `EffectiveConfigResolver`, no parallel config store);
  `InvolvementSourcePort` → `InvolvementSourceAdapter` (Referrals sponsorships +
  Contributions giving + gamification points + event_attendance/enrollments/
  follow_ups activity; the ONLY cross-module fan-out, write-path only);
  `InvolvementTriagePort` (narrow read contract JourneyService depends on).
- **JourneyService integration** — `pipeline()` + `membersAtStage()` switch to
  snapshot-based bands + surface per-member quantum figures WHEN wired AND
  enabled for the context; otherwise the legacy time-in-stage path is unchanged.
  Both now report `triage_mode` (`involvement`|`time_in_stage`). Involvement mode
  ranks least-involved (lowest quantum) first. `Journey/Config/Services.php`
  wires it (shared key `involvement`), also registered as a transition listener.
- **Tests** — `involvement_service_test.php` (33: every band branch, config
  weights/target/window clamp, enabled gate, upsert, read side, listener,
  no-eval) + `journey_involvement_triage_test.php` (21: pipeline/roster branch
  on/off, snapshot bands override time-in-stage, quantum ordering, band filter,
  backward compat). Existing Journey tests still green. Full suite green.

## What is PENDING

- ✅ Nothing outstanding. All items below shipped:
  - ✅ Surface quantum-of-work figures + band in the pipeline board + roster.
  - ✅ 6-locale i18n parity; no-JS-safe, CSP-safe.
  - ✅ Admin config surface for enabling + tuning window/target/thresholds/weights
    (dedicated `involvement_config` console over capability keys
    `journey.involvement.{enabled,window_days,activity_target,band_thresholds,quantum_weights}`).
  - ✅ A wired batch-recompute entry point (`POST /journey/involvement/recompute`).

---

**(original spec below — retained for reference)**

## Why

The hot / warm / cold split on the journey pipeline (`JourneyService::pipeline`)
and the per-stage roster (`JourneyService::membersAtStage`) currently classify a
member purely by **time in current stage** (`stage_entered_at`). That was a
placeholder. Per the user, the split MUST instead reflect a member's
**involvement**.

## Definition of "involvement" (three inputs)

1. **Last activity** — recency of the member's most recent activity /
   participation.
2. **Participation rate** — count of activities participated in, out of the
   **total allowed** within a specified duration (**at least a month**).
3. **Quantum of work done** — composed of:
   - registration **sponsorship count** (Referrals),
   - **amount / volume of money given** (Contributions),
   - **points accumulated** (gamification `point_ledger`).
   - **May also include the effort/impact of the member's mentees / downline**
     (their disciples' involvement rolls up to the discipler).

The quantum-of-work figures must ALSO be **surfaced somewhere in the pipeline**
(visible to the leader, not just used internally).

## Decisions (from the user)

- **Classification method = RULE / PRIORITY BANDS** (not a weighted score).
  Classify by explicit, ordered rules. Indicative shape (to be finalised):
  - **COLD** if no activity in the window **OR** participation rate below the
    configured floor;
  - **HOT** if recent activity **AND** high participation **AND/OR** high quantum
    of work (including downline effort);
  - **WARM** otherwise.
  Downline/mentee effort can lift a member's band (a fruitful discipler is
  "involved" even if their own direct activity is moderate).
- **Window & baseline = CONFIGURABLE per org/group** via
  `EffectiveConfigResolver` (hierarchical config, default OFF / sensible
  default). Default window **90 days**; minimum honoured is **30 days** (≥ a
  month).
- **"Total allowed" = a CONFIGURED TARGET per stage/group** (e.g. expected N
  activities per window). participation_rate = actual ÷ target.
- **Performance = "the best and reliable way"** → honour the standing
  resource-light rule. Recommended: a **materialized per-member involvement
  snapshot** (updated on activity / giving / points / sponsorship events, or
  version-stamped + batch-recomputed), so the pipeline hot path stays cheap and
  does NOT fan out per-member across Events + Referrals + Contributions +
  point_ledger on every render. Cutoffs/thresholds read from effective config.

## Grounding (verified sources for each input)

- **Points accumulated** — `PointsEngine::balance(org, subjectId, ?seasonId)`
  over `point_ledger`.
- **Money given** — Contributions `MetricsService::forSubject(org, subjectId)`
  / `refreshForSubject` (VBCS metrics; amount & volume).
- **Sponsorship count** — Referrals `SponsorshipService::directRecruits(sponsorId)`
  (direct) and `downline(sponsorId, maxLevels)` (for mentee/downline rollup).
- **Activity participation / last activity** — Events `CheckinService` /
  `RegistrationService` and Courses `EnrollmentService`; follow-ups from
  Gamification `FollowUpService`. (Exact per-member aggregate read to be chosen;
  prefer an existing activity/attendance projection if one exists, else a
  bounded batch read into the snapshot.)
- **Config** — `app/Modules/Admin/Services/EffectiveConfigResolver.php` for
  window, per-stage/group activity target, and band thresholds.

## Acceptance criteria

- [x] Triage band (hot/warm/cold) derives from involvement per the rule bands
      above, NOT from `stage_entered_at` alone. *(config-gated; legacy remains
      the default until enabled per context.)*
- [x] Window, activity target, band thresholds AND quantum weights are
      hierarchical config (`EffectiveConfigResolver` via `EffectiveConfigAdapter`),
      default-safe. *(User chose configurable weights.)*
- [x] Quantum-of-work figures (sponsorships, giving, points) are shown in the
      pipeline / roster UI. *(SHIPPED: the pipeline shows a basis badge
      (time vs involvement) + an involvement-specific triage caption + a gated,
      webcsrf-guarded "Recompute involvement" form; the stage roster, in
      involvement mode, OVERRIDES the generic PageSpec columns at runtime to add
      participation / sponsors / giving / points / quantum / last-activity —
      flattening each row's `involvement.*` bundle to top-level keys the shared
      presenter reads, via a new backward-safe `subOverride` presenter hook. No
      presenter fork; the static spec stays legacy-safe.)*
- [x] Downline/mentee effort contributes to a discipler's involvement.
- [x] Resource-light: no per-member fan-out across 4 modules on the render hot
      path — materialized snapshot; cross-module reads run only on the write path
      (transition refresh / batch recompute).
- [x] 6-locale i18n parity, no-JS-safe, CSP-safe (existing pattern). *(SHIPPED:
      `Pages.common` +6 column labels and `Journey` +7 keys
      (triageSubInvolvement / basisLabel / basisTime / basisInvolvement /
      rosterInvolvementNote / recomputeBtn / recomputedFlash) across all 6
      locales; `recomputedFlash` carries the `{0}` count placeholder. The
      pipeline view stays fully self-contained, no-JS, CSP-clean (no <script>,
      no inline on* handlers, fixed form action + hidden `_csrf`). Wiring test:
      `Journey/Views/tests/journey_involvement_surfacing_test.php` 116/0.)*
- [x] Unit tests for the band rules (each branch), config-driven thresholds +
      weights, and the snapshot/aggregation; full suite stays green.

## Notes

- The existing time-in-stage helpers (`triageCutoffs`, `temperatureOf`) may be
  retained as ONE secondary signal (recency proxy) but must not be the sole
  determinant.
- Keep point computation SAFE (no eval) per standing constraint.
