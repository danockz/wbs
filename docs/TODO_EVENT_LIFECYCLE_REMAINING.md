# TO-DO: Remaining event-lifecycle gaps (parked)

**Status:** ▶️ **L6 SHIPPED 2026-09-18** (walk-in reconcile — see item 4 below).
This CLOSES the event-lifecycle gap backlog (L1–L6, G3–G8 all shipped).
Previously L5 (subtree roll-up), L4 (event soft-archive), G8 (waitlist promotion),
L2 (refund / order-cancel) and L3 (close automation) shipped. Full suite green:
**222 files / 13,816 assertions / 0 failed**; OpenAPI **544 operations / 466
paths** (L6 added one route). No open event-lifecycle items remain.

See `docs/EVENT_LIFECYCLE_REVIEW.md` (§13 for the last-shipped work, §11 for the
sequencing) for full context. Every item below must follow the standing bespoke
pattern already used across §6–§13: **no-JS / CSP-safe views, resource-light
(version-stamped cache or one bounded indexed query, gate resolved once),
hierarchical-config gating default-OFF, 6-locale parity, wiring-tested (standalone
`*_test.php`), OpenAPI regenerated, full suite green.**

## Open items (in recommended order)

1. ~~**G8 — Waitlist promotion on capacity raise.**~~ ✅ **SHIPPED 2026-09-18.**
   `EventService::update()` now detects a capacity RAISE (a higher finite cap OR
   a finite cap lifted to unlimited) and, for a PUBLISHED event, promotes
   `waitlisted` registrations FIFO into the freed seats via the new
   `RegistrationService::promoteWaitlistToCapacity()`. That primitive reuses the
   EXACT promote transition from `cancel()`/`releaseActiveForSubject()`
   (`waitlist_entries` waiting→promoted, `event_registrations` waitlisted→
   registered) — no fork — computing headroom the same way `register()` gates
   capacity: `capacity − (confirmed registered + live held)`; a null capacity =
   unlimited promotes everyone. Runs in a row-locking transaction (FOR UPDATE on
   the counts) so a concurrent register()/cancel() can't oversell; the
   registration update is guarded on `status = waitlisted` so a racing move is a
   no-op (idempotent). Promoted registrants are told SPECIFICALLY (not the whole
   roster) via a new `notifyPromoted()` on the G3 notifier port — same
   DEFAULT-OFF hierarchical-config gate, high priority, deduped per
   (event,user,promotion-instant), new `event_promoted` category/template
   (seeded EN) + `Events.notify.promoted*` copy across all 6 locales. Wired as an
   optional `?RegistrationService` seam on EventService (null in the pure
   state-machine guard tests). New test
   `app/Modules/Events/Services/tests/waitlist_promotion_test.php` (22 passed);
   `event_notifications_test` extended (+G8 fan-out + source/factory wiring).
   Full suite: **219 files / 13,636 assertions / 0 failed**. No route change (no
   OpenAPI delta).

2. ~~**L4 — Archive.**~~ ✅ **SHIPPED 2026-09-18.** A settled event
   (draft / cancelled / completed / completed_no_attendance) can be archived so
   it drops out of the active index, calendar feed and analytics WITHOUT being
   deleted — attendance, orders, certificates and the audit trail all survive.
   Implemented as a soft FLAG (`archived_at` / `archived_by`) ORTHOGONAL to
   `status` (migration `2026-09-18-000074_AddEventArchival`, nullable additive
   columns + `ev_active_idx (organization_id, archived_at, starts_at)`), so the
   terminal status is preserved (an archived event is still "completed", just
   filed away) and unarchive is a clean, reversible clear. A PUBLISHED (live)
   event is refused (409 `BAD_STATE` → cancel/complete first);
   `EventService::archive()`/`unarchive()` are both idempotent (`deduplicated`).
   Active reads exclude archived by default — `listForOrg()` (new 3rd arg
   `active|archived|all`), `listInRange()`, `feedInRange()`, plus
   `AnalyticsService` dashboard counts and `RegistrationService` upcoming badge.
   Bespoke no-JS/CSP-safe UI: archive/restore control on `show.php` (gated to
   settled events, `confirm()` guard) and active/archived/all filter tabs +
   per-row Archived badge/dimming + archive-empty message on `index.php`. Guarded
   routes POST `/events/{id}/archive` + `/unarchive`
   (`auth,authorize:event.create,any,webcsrf` — no new permission bit, frozen at
   41). 6-locale parity (`Events.lifecycle.archivedFlash/unarchivedFlash/`
   `errArchiveBadState` + `Events.archive.*`). New test
   `app/Modules/Events/Services/tests/event_archival_test.php` (71 passed —
   behaviour over a DB fake + source/route/migration inspection + i18n parity +
   show/index view smoke). OpenAPI regenerated **541→543 ops** (465 paths). Full
   suite: **220 files / 13,707 assertions / 0 failed**.

3. ~~**L5 — Subtree rollup.**~~ ✅ **SHIPPED 2026-09-18.**
   `ReportService::groupRollup()` previously summed the latest snapshot per event
   for EXACTLY ONE `group_id`, so an ancestor's roll-up under-reported vs the
   platform's "contributions accumulate to EVERY ancestor" model. It now takes a
   `scope`: `subtree` (the new default) resolves the group's whole subtree
   (self + descendants) via `GroupScopeResolver::descendants()` — the SAME
   hierarchy read every other group-scoped surface uses, so terminal/archived
   descendants are already pruned (GR2/GR3) — and sums the latest snapshot per
   event across a `group_id IN (...)` set (each event owned by one group, so no
   double-count); `self` keeps the legacy single-group view. The payload now
   reports `scope` + `groups_counted`. Wired as an OPTIONAL `?GroupScopeResolver`
   seam on `ReportService` (null → subtree degrades safely to single-group, so
   the pure snapshot tests need no resolver); the factory injects
   `SharedServices::groupScope()`. Aggregate-only, read-only — no new
   route/permission/OpenAPI delta (same `GET /event-reports/groups/{id}/rollup`,
   now honouring `?scope=self|subtree`). Bespoke no-JS/CSP-safe UI: self/subtree
   toggle tabs + a "spanning N group(s)" note on `report_rollup.php`. 6-locale
   parity (`Events.rollup.scopeSelf/scopeSubtree/scopeSelfHint/scopeSubtreeHint/`
   `groupsCounted`, the last carrying a `:count` placeholder). New test
   `app/Modules/Events/Services/tests/subtree_rollup_test.php` (55 passed —
   self vs subtree sums over a DB+resolver fake, latest-snapshot/no-double-count,
   mid-tier ancestor, invalid-scope + no-resolver degrade, source/factory/
   controller wiring, i18n parity, view smoke); `report_views_test` still green
   (back-compat defaulting). Full suite: **221 files / 13,761 assertions / 0
   failed**.

   > Note: award MINTING on roll-up (`rollup_awards`, default off) stays with the
   > gamification rollup engine (already shipped there); L5 is the event-report
   > read that was single-group, now subtree-correct and aggregate-only.

4. ~~**L6 — Walk-in reconcile.**~~ ✅ **SHIPPED 2026-09-18.** A `manual` /
   `streaming` check-in with NO active registration is a legitimate walk-in, but
   it was previously indistinguishable from a matched check-in, so attendance and
   registration silently drifted (a walk-in had no confirmed seat and never
   appeared in registration-derived figures; there was no way to reconcile who
   attended vs who registered/paid). Two parts, both audit-preserving:
   (a) `CheckinService::recordAttendance()` now STAMPS a `walk_in` flag
   (migration `2026-09-18-000075_AddAttendanceWalkIn` — additive
   `walk_in TINYINT(1) NOT NULL DEFAULT 0` + `ea_walkin_idx (event_id, walk_in)`)
   — a FLAG, NOT a synthesized fake registration row (which would corrupt
   capacity/waitlist accounting + the audit trail); `walk_in = (reg === null)`.
   (b) `CheckinService::reconcile($eventId)` — aggregate-only, read-only,
   non-destructive — splits present attendance into **matched vs walk-in**, and
   surfaces **no-shows** (registered, never present) and **paid-but-absent**
   (distinct payer of a paid order, never present) via two bounded NOT-EXISTS
   anti-joins + DISTINCT-payer counts, with a present-by-method breakdown and a
   `reconciled` boolean (green only when walk-ins + no-shows + paid-absent are all
   zero). Voided attendance excluded; unknown event → 404. Bespoke no-JS/CSP-safe
   view `attendance_reconcile.php` (self-contained, RTL-aware, method vocab with
   raw fallback, not-found panel). Guarded route
   `GET /events/{id}/attendance/reconcile` (`auth,authorize:attendance.check_in,
   any` — no new permission bit, frozen at 41). 6-locale parity
   (`Events.attReconcile.*`, 23 keys incl. `method.{qr,manual,streaming}`). New
   test `app/Modules/Events/Services/tests/walkin_reconcile_test.php` (55 passed —
   walk-in flag stamping + idempotent dedup + no-fake-registration, all four
   reconcile buckets over a DB fake, clean-event reconciled=true, 404,
   source/route/migration wiring, i18n parity, view smoke incl. RTL). OpenAPI
   **543→544 ops** (466 paths). Full suite: **222 files / 13,816 assertions / 0
   failed**.

## Definition of done (per item)

- Bespoke no-JS/CSP-safe UI where a surface is needed; resource-light.
- Hierarchical-group-config gating (`EffectiveConfigResolver`), default OFF.
- 6-locale i18n parity (en, fr, es, pt, zh, ar) with real UTF-8.
- Dedicated standalone wiring test; **full suite green**.
- OpenAPI regenerated if any route changes.
- No new permission bits unless unavoidable (the `PermissionBits` map is frozen at
  41 entries; menu_stub_test asserts `count()===41`).
