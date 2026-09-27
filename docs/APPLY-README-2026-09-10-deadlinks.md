# WBS Platform — Dead-link & admin-console fixes (2026-09-10, accumulating)

Fixes the two issues you reported, delivered in tested phases that **accumulate
into this single zip**. Apply from the repo root:

```
unzip -o wbs-fixes-2026-09-10-deadlinks.zip
php tests/run-standalone.php     # 49 files, 2092 assertions, 0 failed
```

No schema changes, no new dependencies.

## The two problems

1. **Some menu links 404.** The dynamic menu renders items from the permission
   word alone — it never checked whether the target route is actually routable.
   Ten starter menu items pointed at routes with **no GET handler**, so clicking
   them 404'd. (They were whitelisted in the antidrift test's `KNOWN_GAPS`, which
   is why the suite stayed green.)
2. **The generic admin console shows up on many routes.** ~40 read endpoints call
   `BaseController::respondAdmin()`, which renders the shared
   `WBS\Shared\Views\admin_console` template instead of a bespoke page.

Both are being fixed **build-out style** (real pages + real read endpoints), per
your direction.

## Progress

### ✅ Phase 1 — People → Members (`GET /members`)
- Read-only `AccountService::listMembers()` (roster-safe columns only — never
  `password_hash`), `AccountController::index()`, route
  `['auth','authorize:identity.manage,any']`.
- Bespoke members directory with self-contained avatars
  (`Avatar::resolveUrl` — photo when set, else deterministic inline-SVG initials).

### ✅ Phase 2 — Admin & Reports read pages
- **Admin → Organization settings** (`GET /admin`) — platform settings + feature
  flags in two tables. New `SettingsService::listSettings()` / `listFlags()`,
  `AdminController::index()`.
- **Admin → Providers** (`GET /admin/providers`) — configured integration
  connections. New `ConnectionService::listForOrg()` (NON-secret columns only —
  credentials are never returned), `AdminController::providers()`.
- **Reports → Exports** (`GET /reports/export`) — export history with a download
  link for ready exports (download re-checks auth in the service). New
  `ExportService::listForOrg()` (no artifact/storage keys), `DashboardController::exports()`.

Each Phase-2 page removed its entry from the antidrift `KNOWN_GAPS`.

### ✅ Phase 3a — Collection-level create FORMS (`GET /events/create`, `/courses/create`, `/groups/create`)
These three menu links pointed at **POST-only** routes, so a GET (a click) 404'd.
Each now has a real form page and a proper Post/Redirect/Get flow:

- **New GET form routes** — `GET events/create` (`authorize:event.create,any`),
  `GET courses/create` (`authorize:course.create,any`),
  `GET groups/create` (`authorize:group.create,any`). Each renders a bespoke,
  self-contained form via the new `BaseController::renderForm()`.
- **CSRF for browser forms** — `renderForm()` mints a double-submit token via
  `Services::webAuth()->issueCsrf()`, sets it as an **HttpOnly `wbs_csrf`** cookie
  (7200s, Lax; `Secure` auto-detected, honoring `X-Forwarded-Proto` behind the
  proxy) and passes `$csrf` to the view, which echoes it into a hidden `_csrf`
  field. The three **POST creates are now guarded by `['auth','webcsrf']`**.
- **Post/Redirect/Get** — a browser POST that succeeds redirects to the new
  resource (`/events/{id}`, `/courses/{id}`, `/groups/{id}`); on failure the form
  re-renders with an `$error` banner and sticky `$old` values. JSON/API clients
  are unaffected — they still get `Result` JSON, and `renderForm()` returns the
  minted token in the payload for programmatic clients.
- Group type dropdown uses the standing hierarchy vocabulary
  (National → Region → Area → Local Assembly → Fellowship → Senior Cell → Cell).
- All three entries removed from the antidrift `KNOWN_GAPS`.

### ✅ Phase 3b — Id-scoped action LAUNCHERS (`GET /events/checkin`, `/events/expenses`, `/groups/move`)
These three menu links pointed at **collection-level paths with no GET handler**,
while the real work is an **id-scoped POST** (`events/{id}/checkin/manual`,
`events/{id}/expenses`, `groups/{id}/move`). Each menu link is now a real landing
page that gathers the missing id/context and dispatches to the existing service —
the id-scoped API routes are untouched.

- **New GET launcher + POST dispatcher pairs** (all self-contained, PRG,
  `webcsrf`-guarded on the POST; capability gated by the item's own permission):
  - **Check-in** — `GET events/checkin` (`authorize:attendance.check_in,any`)
    lists the org's events and renders a **manual** check-in form; `POST events/checkin`
    reads the chosen event and delegates to `CheckinService::checkInManual`
    (user_id, staff_id → defaults to the actor, reason, group_attribution parsed
    from a comma/space list). Success → `/events/{id}`.
  - **Expense approvals** — `GET events/expenses` (`authorize:event.expense.approve,any`)
    shows the **org-wide submitted-expense queue** (new
    `ExpenseService::pendingForOrg`, joined to the event title) with an inline
    approve/reject form per row; `POST events/expenses` dispatches to
    `ExpenseService::approve`/`reject` — the **maker-checker (approver ≠ submitter)**
    rule still runs in the service. Re-renders the queue after each decision.
  - **Move / restructure** — `GET groups/move` (`authorize:group.move,any`) lists
    the org's active groups (new `GroupService::listForOrg`, `path`-ordered,
    depth-indented) as both the "group to move" and "new parent" picker;
    `POST groups/move` delegates to `GroupService::move` — the **cycle /
    self-parent / max-depth** guards still run there. Success → `/groups/{id}`.
- The `groups/{id}/move` POST also gained the `webcsrf` filter (was previously
  unguarded).
- All three entries removed from the antidrift `KNOWN_GAPS` — **the whitelist is
  now empty**: every catalog menu item resolves to a real, permission-aligned GET
  route (antidrift: 102 checks, 0 gaps).

### ⏳ Still to come (later phases, same pattern, same zip)
The `respondAdmin` → bespoke-view conversions across Gamification, Events, Groups,
Identity, Streaming, Contributions, and AccessControl detail actions.

## Conventions (every page)

Self-contained `<html>` with a per-module `_locale.php` (locale-aware `lang/dir`,
**RTL for Arabic**); `lang('…')` with English fallback; `$li()` PHP
singular/plural counts; fixed vocabularies (status/category) localized with a
raw-value fallback; inline styles only (in-app-preview safe); all six locales
(en/fr/es/pt/zh/ar) at full key parity. `public/openapi.json` regenerated
(388 paths / 450 operations).

## Files in this zip (79, accumulating)

### Phase 1 & 2
**New views/tests**
- `app/Modules/Identity/Views/members.php` + test
- `app/Modules/Admin/Views/settings.php`, `providers.php` + test, `_locale.php`
- `app/Modules/Reporting/Views/exports.php` + test, `_locale.php`

**Changed controllers/services**
- Identity: `AccountController` (index), `AccountService` (listMembers)
- Admin: `AdminController` (index, providers), `SettingsService` (listSettings, listFlags)
- Integrations: `ConnectionService` (listForOrg)
- Reporting: `DashboardController` (exports), `ExportService` (listForOrg)

**Language (6 locales each):** Identity (`members.*`), Admin
(`settings.*`, `providers.*`), Reporting (`exports.*`)

### Phase 3a
**New form views/tests**
- `app/Modules/Events/Views/create.php` + `Views/tests/create_form_view_test.php` (28/0)
- `app/Modules/Courses/Views/create.php` + `Views/tests/create_form_view_test.php` (27/0)
- `app/Modules/Groups/Views/create.php` + `Views/tests/create_form_view_test.php` (29/0)
- `app/Modules/Events/Views/_locale.php`, `app/Modules/Courses/Views/_locale.php` (Groups already had one)

**Changed controllers**
- `app/Modules/Shared/Http/BaseController.php` (`renderForm()`, `wbs_csrf` cookie, HTTPS/proxy detection)
- Events `EventController` (`createForm` + PRG `create`, prefers `actorId()`)
- Courses `CourseController` (`createForm` + PRG `create`)
- Groups `GroupController` (`createForm` + PRG `create`)

**Language (6 locales each):** Events (`createForm.*`), Courses (`createForm.*`),
Groups (`createForm.*`)

### Phase 3b
**New launcher views/tests**
- `app/Modules/Events/Views/checkin.php` + `Views/tests/checkin_form_view_test.php` (29/0)
- `app/Modules/Events/Views/expenses.php` + `Views/tests/expenses_approvals_view_test.php` (29/0)
- `app/Modules/Groups/Views/move.php` + `Views/tests/move_form_view_test.php` (31/0)
- `app/Modules/Events/Views/tests/view_test_helpers.php` (shared render stubs for the Events view tests)

**Changed controllers/services**
- Events `CheckinController` (`checkinForm` + PRG `checkinDispatch`)
- Events `ExpenseController` (`approvalsForm` + PRG `approvalsDispatch`)
- Events `ExpenseService` (`pendingForOrg` — org-wide queue joined to event title)
- Groups `GroupController` (`moveForm` + PRG `moveDispatch`)
- Groups `GroupService` (`listForOrg` — `path`-ordered active-group picker list)

**Language (6 locales each):** Events (`checkin.*`, `expenses.*`), Groups (`move.*`)

### Shared
- `app/Config/Routes.php` (Phase 1–2: 5 GET read routes; Phase 3a: 3 GET create-form
  routes + `webcsrf` on the 3 POST creates; Phase 3b: 3 GET launcher + 3 POST
  dispatcher routes + `webcsrf` on the `groups/{id}/move` POST)
- `app/Modules/Shared/Navigation/tests/menu_antidrift_test.php`
  (`KNOWN_GAPS` now **empty** — every menu item resolves to a real GET route)
- `public/openapi.json` (regenerated — 388 paths / 450 operations)

## Verify

```
php app/Modules/Identity/Views/tests/members_view_test.php              # 36 / 0
php app/Modules/Admin/Views/tests/admin_settings_view_test.php          # 51 / 0
php app/Modules/Reporting/Views/tests/exports_view_test.php             # 30 / 0
php app/Modules/Events/Views/tests/create_form_view_test.php            # 28 / 0
php app/Modules/Courses/Views/tests/create_form_view_test.php           # 27 / 0
php app/Modules/Groups/Views/tests/create_form_view_test.php            # 29 / 0
php app/Modules/Events/Views/tests/checkin_form_view_test.php           # 29 / 0
php app/Modules/Events/Views/tests/expenses_approvals_view_test.php     # 29 / 0
php app/Modules/Groups/Views/tests/move_form_view_test.php              # 31 / 0
php app/Modules/Shared/Navigation/tests/menu_antidrift_test.php         # 102 / 0, 0 gaps
php tests/run-standalone.php                                             # 49 files, 2092 assertions, 0 failed
```
