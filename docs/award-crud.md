# Award & award-configuration CRUD

Audit + gap-fill for every award-related configuration surface in the
Gamification module, so each has a consistent, hierarchy-aware CRUD story
matching the W-B-S activity catalog work (see `configurable-wbs-activities.md`
§7c for the shared group-scope rules this builds on).

## Surfaces and coverage (after this change)

| Surface | Create | List | Read-one | Update | Delete | Group-scope |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Point rules (earning activities) | ✓ | ✓ | ✓ | ✓ versioned | ✓ disable | code-unique (org-wide) |
| Ranks (tier ladders) | ✓ `define` | ✓ `rank-definitions` | ✓ **`show` (new)** | ✓ upsert | ✓ disable | ✓ resolver |
| Achievements | ✓ `define` | ✓ `achievements` | ✓ **`show` (new)** | ✓ upsert | ✓ disable | ✓ resolver |
| Streaks | ✓ `define` | ✓ `streak-definitions` | ✓ **`show` (new)** | ✓ upsert | ✓ disable | ✓ resolver |
| **Badges** | ✓ **new** | ✓ **new** | ✓ **new** | ✓ **new upsert** | ✓ **new disable** | ✓ resolver |
| Campaigns | ✓ | ✓ | ✓ | ✓ | ✓ cancel | ✓ |
| Award approvals (held awards) | — | ✓ `pending` **(now scoped)** | — | approve / reject **(now scoped)** | — | ✓ per-subject-group |
| Point ledger (granted points) | ✓ `award` | ✓ `balance` | — | — | ✓ `reverse` | n/a (immutable) |
| Badge awards (granted badges) | ✓ **`grant`** | ✓ **`subjects/{id}/badges`** | — | — | ✓ **`revoke`** (soft) | via badge scope |

"Delete" is always a **soft-delete** (definition → `status=inactive`; badge award
→ `state=revoked`; held point award → `reversed`). Nothing is hard-deleted:
already-granted awards keep their historical meaning, consistent with the
immutable point ledger.

## What was missing before

1. **Badges had no CRUD at all** — the `badges` table could only be seeded. No
   service, no endpoints, and the table lacked the lifecycle columns (`status`,
   `include_descendants`, `description`, `icon`, `sort_order`, `updated_at`) the
   platform's soft-delete + group-inheritance conventions require.
2. **Ranks / achievements / streaks had no read-one getter** — you could list
   (mostly) and upsert, but not fetch a single record to populate an edit form.
3. **Rank list wasn't exposed over HTTP** (only the member-facing `GET ranks`).
4. **The approval queue ignored group scope** — `pending`/`approve`/`reject`
   used a flat `gamification.manage` gate, so a group-scoped approver saw and
   could action awards outside their branch.

## Changes

### Migration `000044_BadgeCatalogCrud`
Additive/backward-compatible: adds `include_descendants`, `description`, `icon`,
`sort_order`, `status`, `updated_at` to `badges`, and widens the unique key to
`(organization_id, group_id, code)` so a subgroup can override an org-wide badge
code (matching ranks/achievements/streaks after `000042`).

### `BadgeService` (new)
`define` (create/update upsert on scope+code), `list` (org-wide or group-resolved
most-specific-wins), `show` (read-one, ancestor-chain resolution), `disable`
(soft), plus the award lifecycle: `grant` (idempotent on
`(badge_id, subject_id, season_id)`, reinstates a revoked award), `revoke`
(soft, keeps the row), and `awardsForSubject`. Uses the shared
`GroupScopeResolver` exactly like the activity catalog / follow-up types.

### Read-one getters
`RankService::show`, `AchievementService::show`, `StreakService::show` (+
`RankService::listDefinitions`) — all hierarchy-aware, all returning
`RANK_NOT_FOUND` / `ACHIEVEMENT_NOT_FOUND` / `STREAK_NOT_FOUND` when absent.

### Group-scoped authorization (parity with §7c)
All group-scoped writes (rank/achievement/streak/badge define+disable, badge
grant/revoke) use the coarse `authorize:gamification.manage,any` route gate plus
an in-controller `authorizeGroupScope()` per-group PDP check. Read endpoints keep
the plain gate.

**Approval queue** now scopes to the award subject's group(s):
`PointsEngine::subjectGroupIds()` resolves a user subject to their active group
memberships (a group/team subject is its own group); the controller filters the
`pending` list and authorizes each `approve`/`reject` with
`canManageGroupScope()`. An award whose subject has no group is treated as
org-wide (only an org-wide grant covers it). `point_ledger` has no `group_id`, so
this is enforced in the controller rather than the query.

## HTTP surface (added, under the `gamification/` group)

```
GET    rank-definitions                 listRankDefinitions
GET    ranks/{code}                      showRank
GET    achievements/{code}               showAchievement
GET    streak-definitions/{code}         showStreak

GET    badges                            listBadges
POST   badges                            defineBadge          (manage,any)
GET    badges/{code}                     showBadge
POST   badges/{code}/disable             disableBadge         (manage,any)
POST   badges/{code}/grant               grantBadge           (manage,any)
POST   badges/{code}/revoke              revokeBadge          (manage,any)
GET    subjects/{id}/badges              subjectBadges
```

Static segments (`/disable`, `/grant`, `/revoke`) are registered BEFORE the
catch-all `{code}` read route so they resolve correctly.

## Tests
`tests/integration/BadgeAndAwardConfigCrudTest.php` — badge define(create+update)
/list/show/disable, grant idempotency + soft revoke + reinstate, inactive-badge
grant rejection, and the rank/achievement/streak `show` getters incl. NOT_FOUND.
Self-skips without a DB.
