# Gamification Adaptation Plan — porting `awardlib.md` into `WBS\Gamification`

**Status:** IMPLEMENTED — G1–G6 built in the recommended order (migration 000030), plus admin CRUD for ALL configurable entities — point rules, ranks, achievements, and streak definitions (migration 000031). Config caching, team/split awards, outbound webhooks, and batch award remain deferred TODOs (§8).

## Addendum — admin configurability (all entities creatable by an admin)

Every gamification entity is now admin-manageable behind `authorize:gamification.manage`:

- **Point rules** — new `RuleService` (create/update/disable/list) + routes `GET|POST /gamification/rules`, `POST /gamification/rules/{code}`, `POST /gamification/rules/{code}/disable`. Rules stay **versioned/immutable**: an edit *supersedes* the active version (old row → `status=superseded`, `effective_to=now`) so historical awards always reference the exact version that produced them. Declarative `multipliers` validated (positive factor, whitelisted `when` ops — no eval).
- **Ranks** — `RankService::define` (already present) + new `disable`; routes `POST /gamification/ranks`, `POST /gamification/ranks/{code}/disable`.
- **Achievements** — new `AchievementService::define`/`disable` (upsert by code, validates trigger type + threshold, requires a bonus rule when bonus_points>0); routes `POST /gamification/achievements`, `POST /gamification/achievements/{code}/disable`.
- **Streaks** — new **`streak_definitions`** catalog (migration 000031) + `StreakService::define`/`disableDefinition`/`definitions`; routes `GET|POST /gamification/streak-definitions`, `POST /gamification/streak-definitions/{code}/disable`.
- **Points award/approval** already admin-driven (approve/reject/pending, manual unlock, reevaluate).
**Source:** `/home/user/uploads/awardlib.md` (`App\Libraries\AwardLib`, 2532 lines, ~80 methods).
**Target:** `app/Modules/Gamification/` (PointsEngine, SeasonService, migration `000012`).

---

## 1. Executive summary

`awardlib.md` is a large "enterprise" award/points/achievements/streaks library for a different app (`App\Libraries` + `SwissArmyKnifeModel`, integer `user_id`, tables `user_awards`/`user_points_summary`/`achievement_definitions`/`user_achievements`/`user_streaks`/`rank_definitions`/`award_activity_types`/…). It bundles: configurable point calculation, multipliers/formulas, per-period limits + cooldowns, an approval workflow, a Duolingo-style achievements engine, streaks with freeze, ranks, leaderboards, and archival.

The WBS platform already implements the **core, security-critical spine** of this in `WBS\Gamification` — but on very different, deliberately safer foundations: an **immutable append-only `point_ledger`** (not a mutable `user_awards` + denormalized `user_points_summary`), **data-configured versioned rules** (not `@eval()` formulas), **UNIQUE-based idempotency**, held-entry fraud review, and **annual season lifecycle** with idempotent rollover.

So, exactly as with the referrals and streaming adaptations: **adapt capabilities, do not transplant the class.** Keep the ledger/rules/season spine, and selectively add the genuinely missing *features* — achievements engine, streaks service, leaderboards, rank definitions — expressed in WBS conventions.

**Rough split:**
- ~40% already implemented in WBS (award, idempotency, caps, reversal, balance, held/fraud review, seasons + rollover + snapshots + archive).
- ~20% conflicts with WBS invariants and must be **rejected** (`@eval` formulas, `user_points_summary` mutable cache, integer ids, `SwissArmyKnifeModel`, per-request lock-then-eval, GDPR-unsafe leaderboard exposing name+email).
- ~40% is a genuine capability gap worth porting (achievements engine, first-class streak service, leaderboards, ranks, multipliers/limits/cooldowns/approval as *rule config*, activity stats).

---

## 2. Convention reconciliation (spec → WBS)

| Concern | Spec (`AwardLib`) | WBS `Gamification` | Adaptation rule |
|---|---|---|---|
| Base | one 2532-line class + `SwissArmyKnifeModel` | small final services, `BaseConnection`+`Clock`(+`SeasonService`) DI | Split by concern; no God-class, no SwissArmyKnifeModel. |
| IDs / subject | integer `user_id` | `CHAR(36)` UUID `subject_id` (+ `subject_type` user|group) | UUIDs; keep subject_type polymorphism. |
| Points store | mutable `user_awards` rows + denormalized `user_points_summary` recomputed | **immutable append-only `point_ledger`**; balance = `SUM(points) WHERE state=final` | Never introduce a mutable summary/points table; derive balances. Optionally add a *snapshot cache* that is rebuildable, never authoritative. |
| Point formula | `@eval()` of a config string (!!) | fixed `points` per versioned rule | **REJECT `@eval` entirely.** Add multipliers/formulas as *safe, data-declared* operations (whitelisted ops), never evaluated code. |
| Idempotency | app `checkDuplicate` + advisory locks | `UNIQUE(rule_id, subject_id, source_ref, entry_type)` | Keep the UNIQUE; drop the lock-then-check pattern. |
| Limits / caps | `checkLimits` per day/week/month | `per_period_cap` + `period` on the rule | Extend the existing rule cap model; add cooldown_seconds enforcement (column already exists!). |
| Approval | `requires_approval` → pending `user_awards` row | held state exists (`requires_review` → `held` + fraud_review) | Reuse `held`/`clearHeld`; add explicit approve/reject verbs + a distinct "approval" vs "fraud" reason. |
| Achievements | `achievement_definitions` + `user_achievements` + progress + 8 triggers | **none** | New: `AchievementService` + tables (see §5 G1). |
| Streaks | `user_streaks` + freeze/grace | table `user_streaks` EXISTS but **no service** | New: `StreakService` over the existing table (+ minor column adds). |
| Ranks | `rank_definitions` + `determineRank` | none (only ad-hoc rank_position in snapshots) | New: `rank_definitions` table + resolution (G4). |
| Leaderboard | raw SQL join exposing `full_name`,`email` | none | New leaderboard from ledger/snapshots; **IDs + display name only, never email** (G3). |
| Time | `Time::now()` scattered | `Clock` DI | Inject Clock. |
| Return type | `array` with `success`/`error` bags | `Result` envelope | Return `Result`. |
| Events/webhooks | `Events::trigger`, webhooks inline | platform outbox + queues | Emit via the transactional outbox, not inline hooks. |
| Cache | multi-level cache of config, `__destruct` flush | rules read per-award (versioned) | Skip the bespoke cache; rely on rule versioning + normal caching later if needed. |

---

## 3. What is ALREADY covered (no work)

| Spec capability | WBS equivalent |
|---|---|
| `award()` core | `PointsEngine::award()` (data rule, held/final) |
| duplicate prevention | `UNIQUE(rule_id,subject_id,source_ref,entry_type)` |
| per-period caps | `PointsEngine::periodCount()` + rule `per_period_cap`/`period` |
| approval / review hold | `state='held'` + `fraud_reviews` + `clearHeld()` |
| reversal (refunds) | `PointsEngine::reverse()` (compensating entry) |
| balance / summary | `PointsEngine::balance()` (derived from ledger) |
| season lifecycle + rollover | `SeasonService` (idempotent, lock-free UNIQUE transition, snapshots, archive) |
| annual archive | season close marks ledger `archived`; `season_balance_snapshots` |
| event → points bridge | `RewardCoordinator` (outbox consumer, idempotent) |

---

## 4. What must be REJECTED / downgraded (conflicts)

1. **`evaluateFormula()` using `@eval()`** on a DB-stored string. **Hard reject** — arbitrary code execution risk, exactly the "never arbitrary SQL or user code" line WBS's PointsEngine already draws. Multipliers/formulas, if wanted, become a **safe declarative spec** (whitelisted factors, integer math), never evaluated code.
2. **`user_points_summary` as authoritative mutable state.** Reject as source of truth (drift/tamper risk). Balances derive from the immutable ledger; an optional rebuildable cache is fine.
3. **`user_awards` mutable rows + status edits.** Reject — the ledger is append-only; "approve/reject" post state transitions/compensating entries, they don't mutate history.
4. **Leaderboard exposing `full_name` + `email`.** Reject email exposure; leaderboard returns subject_id + display name + points/rank only.
5. **Advisory `acquireLock`/`releaseLock` per award.** Drop — UNIQUE idempotency + transactions already make awards safe and are far cheaper at 50k concurrency.
6. **Integer ids / `SwissArmyKnifeModel` / `__destruct` cache flush / inline `Events`+webhooks.** Replaced by UUIDs, query builder, and the transactional outbox.

---

## 5. Capability GAPS worth porting (each adapted to WBS)

### G1 — Achievements engine (Duolingo-style)
- **New tables** (migration 000030): `achievement_definitions` (org-scoped, code, name, category, icon/color, trigger_type, trigger_config JSON, xp, bonus_points, secret flag, active) + `user_achievements` (unlock rows, idempotent UNIQUE(achievement_id, subject_id[, season_id])) + `user_achievement_progress` (current/required/percentage).
- **New `AchievementService`**: `evaluateForSubject()` (called after an award), `computeProgress()`, `unlock()`, `unlockManually()`, `reevaluateAll()` (retroactive), `getUserAchievements()`, `getProgress()`, `listAll(grouped, includeSecret)`.
- **Triggers** ported: points, count, streak, combo, first_time, cumulative_points, rank_reached, custom — all computed from the **ledger** and streak/rank services, never `user_awards`.
- Bonus XP/points on unlock post a **ledger entry** (source_ref `achievement:{code}`), keeping single-source-of-truth + idempotency.
- Hooked from `PointsEngine::award()` success (optional dependency, like FraudService in Referrals).

### G2 — Streak service (over the existing table)
- **New `StreakService`** on the existing `user_streaks` table: `record()` (extend/reset with yesterday check), `freeze()` (freeze/grace), `get()`, `getAll()`.
- **Schema tweak** (000030): add `freeze_until DATE NULL` and `grace_days TINYINT` to `user_streaks` (spec's freeze support). Existing `current_count`/`best_count`/`last_event_date` reused.
- Season-aware (the table already has `season_id`).

### G3 — Leaderboards
- **New `LeaderboardService`**: `top(period, limit, filters)` from the ledger (current season) or `season_balance_snapshots` (past seasons). Returns subject_id + display name + points + rank + achievement_count. **No email.** Group leaderboards via `subject_type='group'`.
- No schema change (reads existing tables; display name resolved via a join to the identity/profile read model, ID-only if unavailable).

### G4 — Rank definitions
- **New table** `rank_definitions` (000030): org-scoped tiers (code, name, min_points, order, icon). **New `RankService`**: `determineRank(points)`, `nextRank()`, and rank advancement detection feeding the `rank_reached` achievement trigger.

### G5 — Rule-driven multipliers / cooldowns / approval (safe port)
- Extend **`gamification_rules`** (columns mostly exist: `cooldown_seconds`, `per_period_cap`, `period`, `requires_review`). Add optional `multipliers JSON` (declarative: `[{factor, when:{field,op,value}}]`, integer-safe) and enforce `cooldown_seconds` in `PointsEngine::award()` (currently unused).
- **Approval workflow verbs**: `approveAward()`/`rejectAward()` as explicit held→final / held→reversed transitions with an approver id + audit, distinct from fraud clearing.

### G6 — Stats / admin reads + HTTP surface
- **New `GamificationController`** (module currently has NO controller/routes): expose balance, breakdown, user awards (ledger), leaderboard, achievements, streaks, activity stats, pending-approval queue, approve/reject. Auth + `authorize:` filters; sensitive reads rate-limited.
- Activity stats and achievement stats ported as read aggregations over the ledger + achievements tables.

---

## 6. Proposed migration `000030` (only if approved)

`2026-09-01-000030_CreateAchievementsAndRanks.php` — additive, reversible:
- CREATE `achievement_definitions`, `user_achievements`, `user_achievement_progress`, `rank_definitions`.
- ALTER `user_streaks` ADD `freeze_until DATE NULL`, `grace_days TINYINT UNSIGNED NOT NULL DEFAULT 0`.
- ALTER `gamification_rules` ADD `multipliers JSON NULL` (declarative, no eval).

Next free migration number is currently **000030**.

---

## 7. Recommended sequencing

1. **G2 — Streak service** (table exists; small, unblocks streak-triggered achievements).
2. **G4 — Rank definitions + RankService** (unblocks rank_reached trigger).
3. **G1 — Achievements engine** (the biggest, depends on G2/G4) → migration 000030.
4. **G3 — Leaderboards** (reads; privacy-safe).
5. **G5 — cooldown + declarative multipliers + approve/reject verbs** (safe rule extensions).
6. **G6 — Controller + routes + stats** (HTTP surface over everything above).

Each step: service → bind in `Config/Services.php` → controller/route + docs. `php -l` unavailable this session → validate via brace/paren balance; CI lint next run.

---

## 8. Deferred TODOs (noted for later)

- **[TODO] Config caching layer** — the spec's multi-level cache of activity/multiplier/achievement config. Defer until profiling shows rule reads are hot; rule versioning already makes this safe to add later.
- **[TODO] Team/split awards** (`awardWithSplit`) — awarding one event across multiple subjects by percentage. Port as multiple ledger entries sharing a source_ref suffix; defer (needs product rules on rounding/splits).
- **[TODO] Webhooks for external systems** — spec's outbound webhooks on unlock. Route through the existing outbox/notifications later.
- **[TODO] Batch award API** (`beginBatch`/`commitBatch`) — high-volume ingestion; defer, current per-event path suffices at expected volumes.

---

## 9. Open decisions for the user

- **Which G-items to build, and order** (default: G2 → G4 → G1 → G3 → G5 → G6).
- **Balance cache:** keep balances purely derived (recommended), or add a rebuildable `user_points_summary`-style cache for read performance now?
- **Achievement bonus points:** confirm they post as **ledger entries** (recommended, single source of truth) rather than a separate XP column.
- **Leaderboard identity:** confirm subject_id + display name only (no email), and whether group leaderboards are in scope now.
