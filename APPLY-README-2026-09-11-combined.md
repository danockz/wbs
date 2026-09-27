# Apply pack — 2026-09-11 COMBINED overlay (Phase 4 + Journey, ALL fixes & tests)

Date: 2026-09-11
Zip: `wbs-fixes-2026-09-11-combined.zip`

**This ONE pack supersedes and replaces every interim 2026-09-11 pack.** Apply
this alone — do not also apply the older ones. It is the union of, and folds in:

- ~~`wbs-fixes-2026-09-11-phase4-admin.zip`~~ (162 files — the accumulating Phase-4
  console-replacement + landing pages; itself a superset of
  `admin-views`, `phase4-identity-streaming`, `phase4-contrib-referrals`)
- ~~`wbs-fixes-2026-09-11-scope-writer-test.zip`~~ (GrantScopeWriter unit test)
- ~~`wbs-fixes-2026-09-11-locale-switch-csrf.zip`~~ (locale switcher fatal + 403 fix)
- ~~`wbs-fixes-2026-09-11-journey-combined.zip`~~ (membership-journey assessment +
  Option-C signal wiring + 3 defect fixes)

**177 files, no overlaps between the merged packs.** Every path was re-packaged
straight from the live workspace, so the contents are exactly what the green test
suite runs against.

---

## Contents at a glance

| Area | Files | What |
|---|---:|---|
| AccessControl | 40 | Bespoke localized read views + GrantScopeWriter test |
| Gamification | 32 | Bespoke views, journey `include_descendants` migration + RuleService |
| Events | 20 | Bespoke views + landing pages (create/checkin/expenses) |
| Groups | 17 | Bespoke views + landing pages (create/move) |
| Streaming | 14 | Bespoke views |
| Identity | 14 | Bespoke views + members/admin-settings landing pages |
| Contributions | 10 | Bespoke views |
| Referrals | 9 | Bespoke views |
| Admin | 9 | Bespoke views + admin/providers, reports/export |
| Shared | 6 | JobRouter + tests, BaseController import guard, locale controller |
| Journey | 4 | 4 service assessment tests |
| `app/Views/layouts/app.php` | 1 | CSP-safe language switcher form |
| `app/Config/Filters.php` | 1 | CSRF exclusion for the locale switch route |

- **1 database migration:** `000067_AddIncludeDescendantsToGamificationRules`.
- **29 standalone tests** included.
- No new dependencies. No route changes beyond what the locale fix requires.

---

## What's inside (by theme)

### 1. Phase 4 — admin console → bespoke localized read views (COMPLETE)
Every high-traffic browser-facing read endpoint (AccessControl, Gamification,
Events, Groups, Identity, Streaming, Contributions, Referrals, Admin) now returns
a **bespoke, self-contained, localized view** instead of the generic
`respondAdmin` key/value console. A repo-wide check confirms **no `respondAdmin`
call is reachable by a browser** — every remaining call sits inside an
`if ($this->wantsJson())` branch (API/JSON clients only), so the JSON contract and
OpenAPI spec are unchanged.

Plus the **~10 missing landing pages** (real GET endpoints + views): members,
admin settings, admin/providers, reports/export, events create/checkin/expenses,
courses/create, groups create/move.

Each view: self-contained `<html>` with the module `_locale.php` (locale-aware
`lang/dir`, **RTL for Arabic**); `lang('…')` with English fallback; PHP
singular/plural counts; fixed vocabularies localized with a **raw-value fallback**;
inline styles only (in-app-preview safe); all six locales (en/fr/es/pt/zh/ar) at
full key parity.

### 2. Locale switcher fix (fatal + 403)
- `LocaleController` was missing `use WBS\Shared\Http\BaseController;` → fatal
  class-not-found the first time `/prefs/locale` was hit. Import added.
- The CSP-safe language-switcher form (baked into the shared layout, rendered on
  every page) 403'd on every switch → CSRF exclusion for the locale route.
- **Regression guard:** a static test scans every module controller that
  `extends BaseController` and fails if any (outside `WBS\Shared\Http`) is missing
  that import — `php -l` can't catch runtime name resolution.

### 3. GrantScopeWriter unit test
Pins the shared writer's contract independently of any caller: all four scope
modes (`self`, `self_and_descendants`, `descendants_only`, `groups`), legacy
`include_descendants` mapping, `include_crosscut` default-OFF, validation
failures, `columns()` shape, and `syncGroupSet()` round-trip/isolation/replace.

### 4. Membership-journey assessment + Option-C signal wiring (+ 3 defect fixes)
Full unit coverage of the four core Journey services (recommendation,
attribution, transition spine, rule-driven signal/proposal engine) and the async
emitter wiring in `JobRouter`. Three latent defects found and fixed:

- **Fix #1 (crash):** `gamification_rules` lacked the `include_descendants` column
  its sibling catalogs have, so recommending for a member in a group with
  ancestors threw `Unknown column`. Migration **000067** adds it (idempotent,
  reversible, `NOT NULL DEFAULT 1`); `RuleService` now persists it.
- **Fix #2:** contribution journey signals were never scoped to the cause's group
  (`JobRouter` read a key the payload never carried). Now resolves the cause's
  owning group, fault-isolated to org-wide.
- **Fix #3:** a points-less course never advanced the journey (early return
  skipped the signal). Points award and journey progression are now independent.

---

## Apply

1. Unzip into the project root (overwrites the same paths; a strict superset of
   any interim pack you may already have applied):
   ```
   unzip -o wbs-fixes-2026-09-11-combined.zip -d /path/to/wbs-platform
   ```
2. Run the migration (idempotent; safe to re-run):
   ```
   php spark migrate
   ```
3. Run the suite:
   ```
   php tests/run-standalone.php     # expect: == STANDALONE SUITE GREEN ==
   ```

## Verify
- Standalone suite: **78 files / 3229 assertions / 0 failed**.
- Real dev host reminder: `baseURL = https://public.test/`,
  `forceGlobalSecureRequests = true`, `CSPEnabled = true`.

## Rollback
- `php spark migrate:rollback` (000067's `down()` drops the column), then restore
  the previous copies of the edited files (`RuleService.php`, `JobRouter.php`,
  `LocaleController.php`, `app/Views/layouts/app.php`, `app/Config/Filters.php`).
  All view and test files are additive and can be deleted.

---

*This combined README replaces the seven per-slice APPLY-READMEs from today, which
have been removed to avoid confusion.*
