# Apply pack — Default `membership`-facet rules for a fresh org (Option-C) + E2E assessment

Date: 2026-09-11
Zip: `wbs-fixes-2026-09-11-membership-rules-seed.zip`

## The gap this closes

The Option-C rule-driven journey pipeline is fully built and wired end to end:
the four domain emitters (events check-in, follow-ups, course completion, verified
contribution) call `JourneySignalService::ingest()`, which asks the shared RuBAC
`RuleEngine` which **`membership`-facet** rules match, and applies/proposes the
resulting stage move.

**But no seeder ever created any `membership` rules.** An end-to-end assessment
confirmed that on a FRESH org `RuleEngine::evaluate('membership', …)` returns
`matched: 0` for every signal — so the entire auto-progression path was a silent
no-op until an admin hand-authored rules. This pack ships a sensible, fully
editable default rule set so the signals have something to match out of the box,
plus a test that proves the whole chain works.

## What's in the pack (4 files)

| File | Type | Change |
|---|---|---|
| `app/Modules/Journey/Database/Seeds/MembershipRuleSeeder.php` | NEW seeder | Seeds 6 default `membership` rules per org (idempotent). |
| `app/Modules/Journey/Database/Seeds/tests/membership_rule_seeder_e2e_test.php` | NEW test (29) | End-to-end assessment (see below). |
| `composer.json` | edited | Adds `seed:membership-rules` and includes it in `seed:all`. |
| `APPLY-README-2026-09-11-membership-rules-seed.md` | doc | This file. |

No migration, no new dependencies, no route/controller changes. Rules are plain
`rules` rows — editable/deletable through the standard AccessControl rule UI with
full revision history; nothing bespoke, no parallel table.

## The seeded default rules (org-wide, `scope_mode = self` → cover every group)

Each rule maps a signal action + the member's **current stage** (an ABAC
condition on `current_stage`) to a destination stage in `effect_params.to_stage`.
Stage codes match `JourneyStageSeeder`'s default ladder. Effects use the journey
facet vocabulary the engine already validates — `adjust` = auto-apply,
`require_review` = queue a proposal for a leader.

| # | Signal | From → To | Effect |
|---|---|---|---|
| 1 | `event.attended` | prospect → first_timer | **adjust** (auto) |
| 2 | `event.attended` | first_timer → new_believer | require_review |
| 3 | `course.completed` | new_believer → in_foundation | **adjust** (auto) |
| 4 | `course.completed` | in_foundation → established | require_review |
| 5 | `follow_up.recorded` | prospect → first_timer | require_review |
| 6 | `contribution.verified` | established → worker | require_review |

Conservative by design: only the two lowest-risk steps auto-apply; everything
else is proposed for human confirmation. All are editable; leaders can add
narrower group-scoped rules alongside these org-wide defaults.

## The end-to-end assessment (`membership_rule_seeder_e2e_test.php`, 29 assertions)

Drives the **real** chain — the real `MembershipRuleSeeder`, the real
`RuleEngine` + `AbacConditionEvaluator` + `GroupScopeResolver`, and the real
`JourneySignalService` + `JourneyService` — over an in-memory fake DB (the only
fake). It pins:

0. **Baseline:** a fresh org with the seeder NOT run matches nothing and never
   progresses a member (proves the gap).
1. The seeder writes exactly 6 org-wide, enabled `membership` rules with
   conditions the write-time evaluator accepts and a `to_stage` on each.
2. `event.attended` on a prospect **auto-advances** to first_timer (source=rule).
3. `course.completed` on a new_believer **auto-advances** to in_foundation.
4. `event.attended` on a first_timer **proposes** new_believer (no auto-move;
   pending proposal queued).
5. `follow_up.recorded` on a prospect **proposes** first_timer.
6. `contribution.verified` on an established member **proposes** worker (proposal
   carries `project_code`).
7. **current_stage gating:** the same signal for a member at the wrong stage
   matches nothing (the ABAC condition really is evaluated).
8. **Idempotency:** running the seeder twice creates no duplicates.
9. A group-scoped member (group WITH an ancestor) is still covered by the
   org-wide (`scope_mode=self`) default rules.

## Apply

```
unzip -o wbs-fixes-2026-09-11-membership-rules-seed.zip -d /path/to/wbs-platform
composer seed:membership-rules      # or: php spark db:seed 'WBS\Journey\Database\Seeds\MembershipRuleSeeder'
php tests/run-standalone.php         # expect: == STANDALONE SUITE GREEN ==
```

`seed:membership-rules` is also folded into `composer seed:all` (after
`seed:journey`, before `seed:demo`) so a fresh install gets it automatically.
Requires the journey ladder to exist first (`seed:journey`).

## Verify
- Standalone suite: **79 files / 3258 assertions / 0 failed**.
- The E2E test alone:
  ```
  php app/Modules/Journey/Database/Seeds/tests/membership_rule_seeder_e2e_test.php   # 29 passed, 0 failed
  ```

## Rollback
- The seeder is idempotent and additive; to remove the seeded rules delete the
  `membership`-facet rows whose `code` starts with `mbr.` (they are ordinary
  editable rules). Revert `composer.json` and delete the two new files.

## Note on scope
This is a standalone add-on and is intentionally NOT folded into
`wbs-fixes-2026-09-11-combined.zip` (which is already finalized). Apply both; they
touch disjoint files.
