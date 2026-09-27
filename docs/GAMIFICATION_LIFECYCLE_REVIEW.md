# Gamification (Points / Awards / Seasons) Lifecycle Review

_A code-grounded gap analysis of the **points & recognition lifecycle** — how a
verified activity becomes a ledger entry (award → held/final → reversed), how
anti-gaming caps/cooldowns/limits and fraud review gate it, how group attribution
and ancestor rollups are maintained, how badges/achievements/ranks/streaks are
minted and revoked, and how a season is opened, closed and rolled over — plus how
these interact with the contribution, referrals, journey and group lifecycles
that feed this module through the outbox._

Prepared 2026-09-15, as the seventh review in the sequence after membership,
groups, access control, journey/activities, contributions, and referrals. Method:
read the eight migrations, the fourteen services (PointsEngine, SeasonService,
RollupService, Achievement/Badge/Rank/Streak, Campaign, FollowUp, Leaderboard,
Rule/ActivityCatalog/Config), the six controllers, the routes, the two commands
(RebuildRollup, SeasonRollover), and the tests; every finding cites the code it
rests on. **No code was changed** — this is the review that precedes any fixes.

Scope note: this review is about a **ledger entry and a season as first-class
objects** — their state machines, the idempotency/attribution invariants, and the
housekeeping (rollup rebuild, season rollover, fraud review) that keeps the
standings honest. The activity *catalog* and *stage links* were partly covered in
the Journey review (J7); here they appear only where an award lifecycle depends on
them.

---

## 1. The gamification model at a glance

**Tables** (`000012` core, `000030` achievements/ranks, `000031` streaks,
`000042` group-scoped awards, `000043` configurable activities, `000044` badge
CRUD, `000045` ranking/rollup/attribution, `000067` include_descendants):

- **`point_ledger`** — append-only. `entry_type` `award|reversal|adjustment`,
  `state` `final|held|reversed`, `subject_type` `user|group`, attribution
  columns `group_id`/`category_code`/`project_code`/`phase`/`amount_minor`,
  `rule_id`+`rule_version`. Idempotency UNIQUE **`(rule_id, subject_id,
  source_ref, entry_type, group_id)`** (widened to include `group_id` in `000045`).
- **`gamification_rules`** — versioned (`UNIQUE(org, code, version)`),
  `point_mode` fixed/variable/formula, `per_period_cap`+`period`, daily/weekly/
  monthly limits, `cooldown_seconds`, `requires_review`+`approval_role_code`,
  `status`, `phase`, `include_descendants`.
- **`group_point_rollup`** — rebuildable cache; PK
  `(org, season, group_id, category, project, phase)` with `ALL` sentinels for
  the wildcard cells.
- **`gamification_seasons`** — `status` `active|closed`, one active per org
  (`active_key` UNIQUE), `season_year` unique.
- **`season_transitions`** — `status` `running|completed|failed`, UNIQUE
  `transition_key` (`org:from:to`) for lock-protected idempotent rollover.
- **`season_balance_snapshots`** — closing balances/ranks per subject.
- **`badges`/`badge_awards`** (state `awarded|revoked`, idem UNIQUE
  `(badge, subject, season)`), achievements, ranks, `user_streaks`,
  `fraud_reviews` (`status` `open|cleared|rejected`).

**Award state machine:**

```
(verified activity) --award--> final   (spendable; rolls up immediately)
                            \-> held    (requires_review; NO rollup) --approve/clearHeld--> final(rolls up)
                                                                       \-reject--> reversal(never spendable)
final|held --reverse(source_ref)--> reversed (+ compensating rollup delta if was final)
```

**Season:** `active --rollover(lock)--> closed` (snapshot + archive ledger) →
open next at zero.

**The good news up front.** This is the strongest financial-grade ledger in the
platform and several properties should be fiercely protected:

1. **Duplicate awards are structurally impossible** — the idempotency UNIQUE
   (widened to include `group_id`) means a redelivered outbox event returns
   `deduplicated`, and the same UNIQUE lets a multi-group check-in write one row
   per credited group while de-duping per group on redelivery. `reverse()` on a
   shared `source_ref` compensates all credited groups at once.
2. **Pending points never inflate standings** — a `requires_review` award lands
   `held`, writes a `fraud_review`, and is **excluded from rollup and achievement
   evaluation** until `approveAward`/`clearHeld` promotes it; only then does it
   roll up. `reverse` correctly compensates the rollup **only** for entries that
   were `final` (so reversing a held entry can't double-subtract).
3. **Anti-gaming is layered and enforced pre-insert** — per-period season cap,
   cooldown, and independent rolling daily/weekly/monthly limits, with a
   `suppress_limits` escape used only for the 2nd..Nth group of one logical
   check-in so a single action isn't rejected as a repeat.
4. **Rollup maintenance is race-safe and rebuildable** — `applyDelta` uses
   `INSERT … ON DUPLICATE KEY UPDATE points = points + VALUES(points)` (atomic
   increment, not read-modify-write) across the ancestor-or-self closure set, and
   `group_point_rollup` is a pure cache with a `gamification:rebuild-rollup`
   command that recomputes it from the ledger.
5. **Season rollover is lock-protected and idempotent** — a UNIQUE
   `transition_key` claims the transition; a re-run detects `completed` and
   returns `already_completed`; snapshot + ledger-archive + close + open-next run
   in one transaction.
6. **Routes are well-gated** — unlike some sibling modules, the mutating
   gamification routes carry `authorize:gamification.manage[,any]` (+ `webcsrf`
   on POSTs). Award approval/rejection are SoD-style maker-checker.

The findings below are the gaps around the edges of that strong core.

### The three-way award/clear/reject/reverse paths — a subtle consistency map

Four methods promote or unwind a held entry: `approveAward` (approver promotes,
rolls up, evaluates achievements), `clearHeld` (fraud pass, rolls up, does **not**
evaluate achievements), `rejectAward` (compensating reversal), and `reverse`
(external unwind). This is mostly coherent, but the asymmetries below (G3) are
worth pinning down.

---

## 2. Findings, ranked

### G1 — A `failed` (or crashed `running`) season rollover can never be retried — it wedges permanently (HIGH)

`rollover` claims the transition by inserting `status='running'`. On transaction
failure it sets `status='failed'`. But the **only** path that tolerates an
existing transition row is the insert-collision catch, which returns
`already_completed` **only** when the existing status is `completed`; for any
other status (`failed` or a `running` row left by a crashed worker) it returns
`ROLLOVER_IN_PROGRESS` and **stops**.

So once a rollover fails or a worker dies mid-transition, the `transition_key`
row is stuck at `failed`/`running` forever, and **every future rollover attempt
for that year boundary returns `ROLLOVER_IN_PROGRESS`** — the season can never be
closed. There is no reclaim of a stale `running`, no retry of a `failed`, and no
command to clear the row (grep confirms nothing ever deletes or re-runs a
transition).

Because the transaction is atomic (nothing partial is committed on failure),
retry is actually *safe* — the guard just refuses to allow it.

**Fix direction:** allow retry when the existing row is `failed` (or `running`
older than a lease/timeout) — update it back to `running` and proceed, rather than
returning `ROLLOVER_IN_PROGRESS`. Add a stale-lease timeout so a crashed
`running` self-heals, and expose a `--force`/reclaim option on
`SeasonRolloverCommand`. This is the highest-risk lifecycle gap here: an annual
operation that can permanently wedge with no recovery path.

### G2 — Held awards have no aging/escalation and no sweep; fraud reviews can sit open forever (MED) — ✅ RESOLVED 2026-09-16

**Resolution:** `FraudReviewAgingService::sweepOpenReviews()` + sweep
`gamification.held-review-aging` (unified runner) run a uniform, watermark-guarded
**remind → escalate → optional timeout** lifecycle over open `fraud_reviews`
(new `reminded_at`/`reminder_count`/`escalated_at` columns, `fr_aging_idx`).
Per-org SLAs via `gamification_config` (`held_review_remind_hours` 24,
`held_review_escalate_hours` 72, `held_review_timeout_days` 0=never). Reminders go
to the approver role holders (`role_assignments → roles`), escalation once to the
escalation/approver role; timeout auto-rejects via `PointsEngine::rejectAward` so
held points never become spendable. Idempotent (re-run in the same window is a
no-op). See `docs/TODO_REMAINING_BACKLOG.md` §B.3.


A `requires_review` award creates a `fraud_reviews` row (`status='open'`) and
holds the points — but nothing ages, reminds, or auto-resolves it. There is **no
command** that sweeps stale held entries or open fraud reviews (grep confirms no
held/fraud sweep in `Commands/` or config), and no reminder to the
`approval_role_code` holder. A held award therefore depends entirely on a human
noticing the `pending` queue; if they don't, the points are stranded
indefinitely (never spendable, never rejected), and — combined with a season
rollover (G1) — a held entry from a closing season has undefined disposition (see
G4).

This is the same "queue/sweep with no scheduled runner" pattern as ACL AC4/AC5
(pending approvals), Journey J6 (proposals), Contributions C5 (dead-letter),
Referrals R4 (follow-ups).

**Fix direction:** add a scheduled pass that reminds/escalates open fraud reviews
past an SLA and reports a stale-held count; decide an auto-disposition policy for
very old held entries. Share the cron home with the other queue sweeps.

### G3 — Award promotion paths are asymmetric: `clearHeld` skips achievement evaluation that `approveAward` runs (MED)

`approveAward` promotes a held entry to final, rolls it up, **and** calls
`achievements->evaluateForSubject`. `clearHeld` does the identical promotion +
rollup but **omits the achievement evaluation**. Both routes make the same points
spendable, so a subject whose held award is released via the fraud-clear path
(rather than the approval path) can be **denied an achievement they would have
unlocked** had the same entry been released via approval. The two "held → final"
doors should be behaviourally identical for everything downstream of "points are
now spendable."

Separately, `clearHeld` doesn't require/record an actor, whereas `approveAward`
records the approver on the review row — so the fraud-clear path has a weaker
audit trail for an equivalent money-adjacent action.

**Fix direction:** factor the "entry became final" side effects (rollup +
achievement eval + campaign feed) into one shared method both `approveAward` and
`clearHeld` call, and record an actor on `clearHeld`. Add a test asserting both
doors unlock the same achievements.

### G4 — Held entries and in-flight caps interact with season rollover undefinedly (MED) — ✅ RESOLVED 2026-09-16

**Resolution:** `SeasonService` rollover now applies a config-driven
`held_rollover_policy` (default `carry_forward`) to OPEN held entries in the
closing season: *carry_forward* re-points them (and their open reviews) to the
NEW season and leaves them un-archived, so a later approve/clear rolls up into the
active season instead of the frozen closed one; *reject_on_close* reverses them +
rejects their reviews; *resolve_before_close* refuses rollover
(`HELD_ENTRIES_OPEN`) until the queue is cleared. The next season is opened before
disposal so carry-forward can target it; final entries are snapshotted/archived
unchanged (held points were never counted in frozen standings). Window/period
anti-gaming counts remain season/rolling-window scoped as before. See
`docs/TODO_REMAINING_BACKLOG.md` §B.3.


`rollover` snapshots balances, archives the closing season's ledger
(`archived=1`), closes it, and opens the next — but it does not address **held
entries in the closing season**. After rollover, a `held` entry sits in an
archived, closed season:

- If it is later approved/cleared, `rollUpEntry` applies a delta to the
  **closed** season's rollup (which has already been snapshotted for final
  standings) — potentially mutating a season whose ranks were supposed to be
  frozen, or rolling up into a season no leaderboard reads anymore. Either way the
  points effectively vanish from the subject's spendable balance (the active
  season is the new one).
- Anti-gaming period/window counts (`periodCount`, `windowCount`) are scoped to
  the active season / rolling windows; their behaviour across the boundary (e.g. a
  weekly limit spanning Dec→Jan) isn't obviously defined.

**Fix direction:** decide + implement a rollover policy for open held entries
(resolve-before-close, or carry-forward to the new season, or reject on close),
and document the window-count behaviour across the boundary. Pair with G1/G2.

### G5 — Rollup and attribution read `group_closure` without a `groups.status` filter (MED — cross-review coupling to GR3)

`RollupService::ancestorsOrSelf` reads `group_closure` with no join to
`groups.status` (confirmed — only `gm.status='active'` is filtered, for
membership, not the group node). This is the exact issue the Groups review logged
as **GR3**: because dissolve/merge never prune `group_closure` and resolvers
ignore `groups.status`, points credited to (or rolled up through) an
archived/dissolved group still accumulate into `group_point_rollup`, and a
terminal group keeps appearing in group-wide standings.

`resolveGroupId`'s membership fallback (`primaryMembershipGroup`) has the same
exposure — it can attribute a member's points to a group that is no longer live.

**Fix direction:** primarily fixed on the Groups side (GR2 cascade + GR3
status-aware resolvers + closure pruning). On the gamification side, when GR3
lands, `rebuild-rollup` should exclude terminal groups, and `resolveGroupId`
should skip non-active groups in the fallback. Sequence after GR2/GR3.

### G6 — ✅ RESOLVED 2026-09-17
All config **PATCH** routes (activity-categories, follow-up-types,
follow-up-methods, ranks, achievements, streak-definitions, badges, campaigns,
campaigns/teams) now carry `webcsrf`, as do the config/campaign **DELETE**
routes and the campaign lifecycle POSTs (activate/cancel/progress/close).
`webcsrf` is header-exempt for Bearer / API callers, so the RESTful verbs stay
usable by token clients while any browser PATCH/DELETE is CSRF-protected.

The `follow-ups/(:segment)` PATCH/cancel were the subtler case: follow-ups are
member self-service (the follower records them and EARNS points), so a coarse
`authorize:gamification.manage` cap would wrongly lock members out. The real
defect was an **IDOR** — `updateRecord`/`cancelRecord` load by org+id only, so
any authenticated caller could mutate anyone's record by id. Fixed with an
ownership-OR-leader-scope guard (`FollowUpsController::guardFollowUpWrite()`):
the actor must BE the record's `follower_user_id` or hold `gamification.manage`
over the record's group. New `forbiddenFlash` lang key across all 6 locales.
17 guard-test assertions cover the config routes; suite green.

--- original finding below ---

### G6 — PATCH config routes omit `webcsrf` (MED)

The POST mutation routes carry `webcsrf`, but nearly every **PATCH** route does
not: `activity-categories/(:segment)`, `follow-up-types/(:segment)`,
`follow-up-methods/(:segment)`, `ranks/(:segment)`, `achievements/(:segment)`,
`streak-definitions/(:segment)`, `badges/(:segment)`, `campaigns/(:segment)`,
`campaigns/(:segment)/teams/(:segment)` all carry only `authorize:` (one,
`follow-ups/(:segment)`, has `webcsrf` but — notably — **no** `authorize:`). For a
browser cookie-auth session these PATCH endpoints mutate scoring config (rank
thresholds, achievement definitions, campaign parameters) and are CSRF-exposable.

This is the same missing-`webcsrf` pattern as GR1/M3/AC11/J5/R1, narrowed here to
the PATCH verb.

**Fix direction:** add `webcsrf` to every state-changing PATCH route (and an
`authorize:` code to `follow-ups/(:segment)`). Add a route-filter test asserting
every mutating gamification route carries both an `authorize:` code and `webcsrf`.

### G7 — Adjustments and negative-point penalties bypass the review/audit rigor of awards (MED)

`entry_type` includes `adjustment`, and rules may carry negative `points`
("penalties, rare"), but there is no dedicated, audited **manual adjustment**
path with maker-checker: an adjustment is just another ledger insert. For a
points economy that gates ranks/badges/leaderboards (and, via the journey,
disciple-making credit), the ability to add/subtract points should have at least
the SoD + audit rigor that refunds (Contributions) and role grants (ACL) have.
There's also no guard that a subject's spendable balance can't go negative via
penalties/reversals in a way that breaks rank computation.

**Fix direction:** add a maker-checker manual-adjustment method (reason required,
approver ≠ requester, audit row), and decide the floor/handling for negative
balances.

### G8 — Thin service-level test coverage for the ledger/season invariants (MED)

The module has only one service test in `Services/tests/`
(`followup_due_enrichment_test`). The most safety-critical logic in the platform
— idempotent award, held→final rollup exclusion, `reverse` compensating only
final entries, multi-group per-group idempotency, season rollover lock/retry
(G1), and the `approveAward`/`clearHeld` asymmetry (G3) — has no direct service
test here. (There may be coverage via View/Controller tests, but the invariants
deserve unit-level pinning.)

**Fix direction:** add service tests for each ledger/season invariant listed
above, prioritising the ones that back G1/G3/G4 so those fixes land red→green.

### G9 — Badge/achievement/rank revocation and re-computation on reversal is unclear (LOW)

`reverse()` unwinds points and the rollup, but it is not evident that a
**reversal cascades to derived recognition** — e.g. if points that unlocked an
achievement or crossed a rank threshold are later reversed, is the badge/rank
revoked or recomputed? `badge_awards` has a `revoked` state, but nothing in the
reverse path appears to trigger it. This can leave a subject holding a badge/rank
they no longer qualify for after a reversal or a rejected held award.

**Fix direction:** decide the policy (recognition is "earned once, kept" vs
"recomputed on balance change"). If recomputation is wanted, have `reverse`/
`rejectAward` re-evaluate derived recognition and revoke what no longer holds.

---

## 3. Suggested sequencing

Front-loads the wedging season-rollover defect and the promotion asymmetry, then
the sweeps and couplings:

1. **G8** — add ledger/season invariant tests (red) first.
2. **G1** — make season rollover retryable after `failed` / stale `running`
   (highest risk: permanent wedge, no recovery).
3. **G3** — unify the held→final side effects across `approveAward`/`clearHeld`
   (+ record actor on clear).
4. **G4** — define + implement the rollover policy for open held entries and
   cross-boundary window counts.
5. **G6** — add `webcsrf` to PATCH routes (+ `authorize:` on `follow-ups`).
6. **G2** — held/fraud-review aging + reminder sweep (shares cron home).
7. **G7** — maker-checker manual adjustments + negative-balance policy.
8. **G5** — status-aware rollup/attribution once Groups GR2/GR3 land.
9. **G9** — decide recognition revocation-on-reversal policy. Lowest urgency.

## 4. Cross-review couplings (explicit)

- **G5 ↔ Groups GR2/GR3:** rollup/attribution read a closure that isn't pruned on
  dissolve/merge and ignore `groups.status`; fix follows the group-side work.
- **G2 ↔ ACL AC4/AC5, Journey J6, Contributions C5, Referrals R4:** the recurring
  "review/queue with no scheduled runner" pattern.
- **G6 ↔ Groups GR1 / Membership M3 / ACL AC11 / Journey J5 / Referrals R1:** the
  recurring missing-`webcsrf`/`authorize:` pattern (here on PATCH).
- **G7 ↔ Contributions (refund maker-checker) / ACL (grant maker-checker):** a
  points adjustment should meet the same SoD+audit bar as those money/authority
  mutations.

No code was changed in the course of this review.
