# GivingsLibrary → VBCS adaptation plan

**Source:** `/home/user/uploads/givingslibrary.md` — external reference `App\Libraries\GivingsLibrary` (1115 lines, 30 methods). Enterprise Value-Based Contribution System: causes, financial/in-kind givings, recurring partnership pledges, PGV/GGV metrics, partnership tiers, leader reporting.

**Target:** existing `app/Modules/Contributions` (VBCS — the "Build" pillar). Port **only genuine gaps** using WBS conventions, exactly as the streaming / referrals / gamification adaptations did.

Status: **IMPLEMENTED** — V1–V6 built (migration 000032). User approved: all V1–V6, derived-snapshot PGV/GGV, reuse the sponsorship graph.

## Implementation summary (as built)

- **V1 — migration `000032_CreateVbcsMetricsAndPartnership`**: `giving_metrics` (derived PGV/GGV snapshot, multiplier in basis points), `partnership_level_definitions` (admin-config tiers), `user_partnership_status`, `giving_commitments`.
- **V2 — `MetricsService`**: `refreshForSubject` / `cascadeToUpline` / `forSubject`. PGV = subject's succeeded contributions; GGV = downline total (from `SponsorshipService::downline()`, bounded BFS, added this task) × 1.30 at ≥10 direct recruits. All minor units; multiplier stored as bps (no float persisted).
- **V3 — `PartnershipService`**: admin CRUD (`define`/`disable`/`tiers`) + `recomputeStatus` (consecutive-giving-month streak + PGV → tier) + `status`. Level change emitted via outbox (`partnership.level_changed`).
- **V4 — `CommitmentService`** + **`CommitmentDueCommand`** (`contributions:commitments-due`): reminder-only recurring pledges; due processor sends preference-gated, deduped reminders through `NotificationService::send()` and advances `next_due_at`. **No charging.**
- **V5 — `ManualContributionService`**: maker-checker `submit`→`approve`/`reject` over `manual_contribution_records`; in-kind time valued at hourly rate, goods/services appraised; approval creates a verified `contributions` row, posts the ledger, and stages the SAME `contribution.succeeded` event online givings use (so points + PGV/GGV + partnership all run through one path). Approver ≠ submitter.
- **V6 — `VbcsController` + `/vbcs` routes + wiring**: member reads (metrics, partnership status, commitments, cause progress/donors — donors respect anonymous recognition, never expose email), leader `groups/{id}/report`, admin tier config + manual approvals + force-refresh. `RewardCoordinator` now also refreshes metrics + partnership on `contribution.succeeded`/`.refunded` (refund event carries `user_id`). New permission **`contribution.manage`** (org_admin + finance).

**Validation:** all files brace/paren/bracket-balanced + `declare` present; 31 migrations, no dup numbers; every `/vbcs` route resolves to a controller method; every controller→service and Config ctor dependency resolves to a real binding. `php -l` pending toolchain (CI lints next run).

---

## 1. What already exists (do NOT rebuild)

The Contributions module is already substantial (migration 000016):

| Capability | Where | Notes |
|---|---|---|
| Causes (create/activate/find/raised) | `CauseService`, `causes` table | has `visibility`, `target_minor`, `target_count`, group ownership |
| Contribution intents → succeeded/failed | `ContributionService`, `contribution_intents`, `contributions` | integer **minor units** money |
| Double-entry ledger | `LedgerService`, `journal_entries`/`journal_lines` | post / reverse / accountBalance |
| Refunds w/ maker-checker | `RefundService`, `refund_requests` | request/approve/execute, approver≠requester |
| Signed webhooks + inbox | `WebhookController`, `WebhookInboxService` | dedicated rate quota |
| Points bridge | `RewardCoordinator` → Gamification `PointsEngine` | `contribution.succeeded/refunded` via **outbox → JobRouter** |
| Manual records **table** | `manual_contribution_records` | ⚠️ table only, **no service logic yet** |

Money is **BIGINT minor units** platform-wide — we keep that and reject the library's `decimal(14,2)` floats.

---

## 2. Genuine gaps to port (the actual work)

1. **PGV / GGV metrics** — *Personal Giving Value* (lifetime verified givings by a member) and *Group Giving Value* (sum across the member's downline, ×1.3 once they have ≥10 direct recruits). **Absent from the platform.**
2. **Partnership levels** — tiers earned by *consecutive giving months* + PGV threshold; **admin-configurable definitions** (mirrors gamification `rank_definitions`) + per-user status.
3. **Recurring commitments / pledges** — reminder-only (no auto-charge; nothing is vaulted), with a scheduled due-processor.
4. **In-kind valuation + manual-giving service** — the `manual_contribution_records` table has no service; add valuation (time × hourly rate; goods/services appraised) and maker-checker submit/approve.
5. **Leader reporting** — group giving report (totals by state, top causes, monthly trend) + donor list + cause progress.
6. **Cause visibility resolution** — group-ancestor-aware visibility (public/group/private) + org-scoped slug.

---

## 3. Adaptation rules (WBS conventions applied)

| Library pattern | WBS adaptation |
|---|---|
| `SwissArmyKnifeModel` / `model(string $table)` god-model | **REJECT** — dedicated services + query builder, like every other module |
| Integer `user_id`, `sponsor_id`, `cause_id` | **UUIDv7** everywhere |
| `users.pgv` / `users.ggv` mutable columns as source of truth | **REJECT** — PGV/GGV are **derived**; cache in an org-scoped `giving_metrics` snapshot refreshed async (same stance as gamification derived balances) |
| `decimal(14,2)` float money | **BIGINT minor units** (platform convention) |
| Raw `WITH RECURSIVE` on `users.sponsor_id` | Use existing **`SponsorshipService`** graph (`sponsorships` table) — add a bounded `downline()` traversal there |
| Inline `Events::trigger(...)` + direct `AwardLib::award()` | **Transactional outbox** events; points already flow through `RewardCoordinator` |
| `throw` for control flow | **`Result`** returns |
| `date()` / `new DateTimeImmutable` | **`Clock`** DI (UTC) |
| Global-unique slug | **org-scoped** unique slug |
| No org scoping | every table + query **org-scoped**; season-agnostic (VBCS is lifetime, but gamification rollover already handled separately) |

**Explicitly REJECTED (not ported):** SwissArmyKnifeModel, integer IDs, float money, mutable pgv/ggv columns, raw recursive SQL over the identity table, inline Events/webhooks, per-call fresh-model pattern, `throw`-based flow.

---

## 4. Proposed build order (V1–V6)

- **V1 — Migration `000032_CreateVbcsMetricsAndPartnership`**
  - `giving_metrics` (org_id, subject_id, pgv_minor, ggv_minor, direct_recruits, downline_size, computed_at) — derived snapshot, UNIQUE(org_id, subject_id).
  - `partnership_level_definitions` (admin-config tiers: code, name, min_consecutive_months, min_pgv_minor, sort_order, status) — mirrors `rank_definitions`.
  - `user_partnership_status` (subject_id, current_level_code, consecutive_months_given, last_giving_at, computed_at).
  - `giving_commitments` (subject_id, cause_id, amount_minor, currency, frequency, status, next_due_at, started_at, ended_at) — recurring pledges.
  - Extend `manual_contribution_records` only if fields are missing (it already has type=`in_kind`, `valuation_method`, `stated_value_minor`).

- **V2 — `MetricsService`** — derive+cache PGV/GGV; downline via `SponsorshipService::downline()` (new, bounded); ×1.3 multiplier at ≥10 direct recruits; `refreshForSubject()` + `cascadeToUpline()` (bounded) invoked async on `contribution.succeeded`.

- **V3 — `PartnershipService`** — admin CRUD for level definitions (define/disable/list, like `RankService`); `recomputeStatus()` (consecutive-month streak + PGV → tier); `status()` read.

- **V4 — `CommitmentService`** — create/cancel/list + `CommitmentDueCommand` (scheduled) that stages reminder notifications via **outbox** and advances `next_due_at`. **No charging.**

- **V5 — `ManualContributionService`** — submit (with in-kind valuation) → maker-checker approve → creates a verified `contribution` + posts ledger + triggers metrics/points. Approver ≠ submitter (reuse SoD combinator precedent).

- **V6 — Controller + routes + wiring** — read endpoints (my PGV/GGV/partnership status, cause progress, donor list, group giving report) + admin endpoints (partnership levels CRUD, manual approve) behind a new `contribution.manage` permission; wire `RewardCoordinator`/`JobRouter` to also refresh metrics + partnership on succeeded/refunded. Update REBUILD_STATUS + RUNBOOK.

---

## 5. Deferred (call out, don't build now)

- Auto-charge of commitments (needs vaulted payment methods — out of scope; reminders only, matching the library).
- Real-time (synchronous) PGV/GGV — kept async/snapshotted for 50k-concurrent scale.
- Config caching of partnership-level definitions.
