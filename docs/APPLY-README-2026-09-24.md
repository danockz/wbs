# WBS Platform — fixes & features (through 2026-09-24)

Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-24) — No browser ever sees raw JSON again (platform-wide safety net)
================================================================================
Part A — UNIVERSAL FALLBACK (covers 100% of endpoints, one place):
  BaseController::respondWith() previously served raw JSON whenever an endpoint
  supplied no HTML view (`$htmlView === null`) — so ANY browser GET to a view-less
  endpoint showed raw JSON. Fixed the negotiation so:
    1. API clients (Accept: application/json, ?format=json, X-Requested-With) -> JSON (unchanged).
    2. A browser WITH a bespoke view -> that view.
    3. A browser with NO view -> a generic, readable "data page" (NOT raw JSON).
  The data page renders any Result shape (list-of-rows -> table, map -> key/value,
  scalars -> list, problem+errors -> message) inside the shared app layout with the
  universal menu. CSP-safe (no external assets). Failure redirects still win when a
  redirect target was passed. API JSON behaviour is completely unchanged.

  Files: app/Modules/Shared/Http/BaseController.php  (respondWith negotiation +
           FALLBACK_HTML_VIEW constant + fallbackTitle()),
         app/Views/shared/data_page.php  (NEW — generic recursive renderer),
         app/Modules/Shared/Http/tests/negotiation_test.php  (NEW — 35 assertions).

Part B — BESPOKE READ VIEWS (incremental upgrade of high-traffic member pages):
  Real server-rendered views replace the generic fallback where it matters most.
  This release adds:
    - Groups: GET groups/{id} -> app/Modules/Groups/Views/detail.php
        (ancestry chain + descendant subtree; PII-free).
  (Gamification's read pages were wired in the 2026-09-23 release; more modules
   will be upgraded in later releases — every other endpoint is already safe via
   Part A's fallback in the meantime.)

VERIFIED: php -l clean on all changed files; data page renders 8 Result shapes +
Groups detail renders full HTML with the universal menu; suite now 251 assertions
green (menu 216 + negotiation 35). JSON path unchanged for API clients.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-23) — Gamification read pages render HTML in the browser
================================================================================
WHY: BaseController::respondWith() serves JSON when the caller wants JSON OR when
no HTML view was supplied (`$htmlView === null`). GamificationController's read
endpoints (/gamification/achievements, /gamification/ranks, /gamification/leaderboard,
subject achievements/streaks/standing/balance, and the dimension/group/membership
boards) all called respondWith(Result::ok(...)) with NO view, so a browser hitting
those URLs got raw JSON. The module also had no Views/ directory at all.

FIX: wired a real server-rendered view to every read endpoint. Each still returns
the SAME payload as JSON to API clients (Accept: application/json, ?format=json, or
X-Requested-With) — only the browser (HTML-preferring) representation changed.

New views (app/Modules/Gamification/Views/, all extend layouts/app, PII-free,
CSP-safe — inline styles from the shared layout, no external assets):
  - achievements.php        catalogue of achievement definitions  (GET gamification/achievements)
  - user_achievements.php   a subject's unlocked + in-progress     (GET gamification/subjects/{id}/achievements)
  - ranks.php               rank-tier ladder                       (GET gamification/ranks)
  - streaks.php             a subject's streaks                    (GET gamification/subjects/{id}/streaks)
  - standing.php            a subject's season points/position/rank(GET gamification/subjects/{id}/standing)
  - balance.php             a subject's points balance             (GET gamification/subjects/{id}/balance)
  - group_standing.php      a group's subtree standing             (GET gamification/leaderboards/groups/{id}/standing)
  - _board_subjects.php     shared INDIVIDUAL leaderboard body     (leaderboard, leaderboards/individuals)
  - _board_groups.php       shared GROUP leaderboard body          (leaderboards/groups, by/*, membership/*)

Changed: app/Modules/Gamification/Controllers/GamificationController.php
  Every read action now passes htmlView + viewData to respondWith(); write/admin
  surfaces (AwardsController, ConfigController, etc.) are untouched.

VERIFIED: php -l clean on controller + 9 views; all 11 render scenarios produce a
full HTML document (DOCTYPE..</html>) with the universal menu present, incl. the
empty-state and no-active-season paths; menu suite still 216/216 green. JSON path
unchanged — API clients keep receiving Result::toArray().

================================================================================
FEATURE (2026-09-22) — Responsive menu: pinned on desktop, drawer on mobile
================================================================================
Still ONE component (MenuFragment, single source of truth), now responsive:
  - Desktop (>=1024px): drawer PINNED OPEN as a left sidebar; launcher/backdrop
    hidden; page gets a 260px left inset (body{padding-left:260px}).
  - Mobile / narrow (<1024px): unchanged fixed top-left launcher opens the same
    slide-out drawer (CSS-only toggle, no JS).
Files: app/Modules/Shared/Navigation/MenuFragment.php,
       app/Modules/Shared/Navigation/tests/menu_wiring_test.php (28/28),
       docs/menu_preview.html.
Suite: 216 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-22) — Responsive menu: pinned on desktop, drawer on mobile
================================================================================
Still ONE component (MenuFragment, single source of truth), now responsive:

  - Desktop (>=1024px): the drawer is PINNED OPEN as a left sidebar. The fixed
    launcher and backdrop are hidden, and the page gets a 260px left inset
    (body{padding-left:260px}) so content sits beside it.
  - Mobile / narrow (<1024px): unchanged — a fixed top-left launcher opens the same
    slide-out drawer over the page (CSS-only toggle, no JS).

It is the SAME markup on every page (layout pages and filter-injected self-contained
pages alike); only CSS responds to width. CSP-safe as before (inline <style>, one
same-origin script). Menu remains display-only.

Files: app/Modules/Shared/Navigation/MenuFragment.php (edit — responsive @media rules),
       app/Modules/Shared/Navigation/tests/menu_wiring_test.php (edit, 28/28 — asserts
         the desktop media query, page inset, and hidden launcher),
       docs/menu_preview.html (regenerated — side-by-side desktop + mobile).
Suite: stub 30 · floor 18 · integration 18 · antidrift 70 · signedword 20 ·
       headers 14 · wiring 28 · inject 14 · coverage 4  = 216 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-21) — Universal menu in a SINGLE location on every page
================================================================================
Problem: the menu rendered in TWO different places. Pages that extend
layouts/app.php showed it as an inline sticky sidebar column; self-contained pages
(injected by MenuInjectionFilter) showed a fixed slide-out. Two locations, two code
copies that could drift.

Fix: one canonical fragment, one source of truth, same location everywhere.

  app/Modules/Shared/Navigation/MenuFragment.php (new):
    - The single definition of the menu chrome: a FIXED top-left launcher that opens
      one slide-out DRAWER (.wbs-menu--drawer), positioned identically on every page
      regardless of that page's own layout. CSP-safe: inline <style>, a CSS-only
      checkbox toggle (no inline JS), and the one same-origin <script>
      /assets/js/menu.js. Exposes html() and style().

  app/Views/layouts/app.php (edit):
    - Removed the bespoke inline sidebar (.shell / .wbs-menu column and its CSS).
    - Now echoes WBS\Shared\Navigation\MenuFragment::html() — the SAME fragment the
      filter uses. The main content returns to a normal single-column .wrap.

  app/Modules/Shared/Filters/MenuInjectionFilter.php (edit):
    - fragment() now returns MenuFragment::html() instead of its own hardcoded copy,
      so layout pages and self-contained pages are byte-identical.

Net: every authenticated page — whether it extends the layout or not — shows the
exact same menu, opened from the same fixed launcher, in one location. The zero-
burden client/edge delivery and "display only" security model are unchanged.

Tests: menu_wiring_test rewritten to assert the layout delegates to MenuFragment and
has no bespoke sidebar; menu_inject_test asserts the injected markup equals the
MenuFragment source and uses the single drawer. docs/menu_preview.html regenerated
(drawer shown open).

Files: app/Modules/Shared/Navigation/MenuFragment.php (new),
       app/Views/layouts/app.php (edit),
       app/Modules/Shared/Filters/MenuInjectionFilter.php (edit),
       app/Modules/Shared/Navigation/tests/menu_wiring_test.php (edit, 25/25),
       app/Modules/Shared/Navigation/tests/menu_inject_test.php (edit, 14/14),
       docs/menu_preview.html (regenerated).
Suite: stub 30 · floor 18 · integration 18 · antidrift 70 · signedword 20 ·
       headers 14 · wiring 25 · inject 14 · coverage 4  = 213 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-20) — Wire menu:scaffold into CI, composer, and a git hook
================================================================================
The menu coverage guard now runs automatically so navigation can never silently fall
behind the route table.

1) NEW CI STEP — .github/workflows/ci.yml (static-analysis job)
   `php spark menu:scaffold --check` runs right after the OpenAPI drift guard (same
   "derived-from-routes" family). It exits non-zero — failing the build — if any
   page-like GET route is neither linked in CoreMenuProvider nor listed in
   MenuCoverage::EXCLUSIONS.

2) NEW --check MODE — app/Modules/Shared/Commands/MenuScaffoldCommand.php
   - `php spark menu:scaffold`         prints paste-ready MenuItem stubs (unchanged).
   - `php spark menu:scaffold --check` CI mode: no stubs, non-zero exit on untriaged
     routes OR stale exclusions.

3) SINGLE SOURCE OF TRUTH — app/Modules/Shared/Navigation/MenuCoverage.php
   The reviewed exclusion list is now the constant MenuCoverage::EXCLUSIONS, plus a
   MenuCoverage::report() helper. Both the CLI command and menu_coverage_test.php
   read it, so the test and CI can never disagree about what "triaged" means.

4) COMPOSER — composer.json
   - New script "menu:check": "php spark menu:scaffold --check".
   - Added @menu:check to the "qa" group (runs with `composer qa`, after @lint).

5) OPTIONAL GIT HOOK — .githooks/pre-commit
   Runs the check before a commit, but only when Routes.php or the Navigation module
   changed. Enable per clone with:  git config core.hooksPath .githooks

Verified: the guard fails (exit 1) when a new untriaged page-like route is added, and
passes once it is linked or excluded.

Files: .github/workflows/ci.yml (edit), composer.json (edit),
       app/Modules/Shared/Commands/MenuScaffoldCommand.php (edit),
       app/Modules/Shared/Navigation/MenuCoverage.php (edit),
       app/Modules/Shared/Navigation/tests/menu_coverage_test.php (edit),
       .githooks/pre-commit (new).
Suite: stub 30 · floor 18 · integration 18 · antidrift 70 · signedword 20 ·
       headers 14 · wiring 20 · inject 12 · coverage 4  = 206 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-19) — Auto-detect new routes for the menu (coverage guard +
                       assisted scaffolder)
================================================================================
Answer to "can it automatically link new routes?": full auto-linking is deliberately
NOT done — a route has no label, category, icon, or display order, cannot tell a
landing page from a (:segment)/POST/action route, and blindly linking an auth-only
route into a privileged category would leak that category to everyone. Instead the
menu now DETECTS unlinked routes automatically, so nothing goes missing silently,
while a human still assigns label/category/icon.

Two pieces:

1) COVERAGE GUARD (test) — app/Modules/Shared/Navigation/tests/menu_coverage_test.php
   Static-parses Config/Routes.php, classifies every GET route as page-like or not
   (drops non-GET, (:segment) param routes, and API/system/auth endpoints), and
   FAILS if any page-like route is neither in the catalog nor in a reviewed
   EXCLUSIONS list. So a newly added page-like route breaks CI until someone links
   it or records why it is not navigation. Also flags STALE exclusions (route
   renamed/removed). Analyzer lib: app/Modules/Shared/Navigation/MenuCoverage.php.

2) ASSISTED SCAFFOLDER (command) — php spark menu:scaffold
   Prints a ready-to-paste MenuItem stub for each unlinked page-like route, with the
   category guessed from the path prefix and the permission read straight from the
   route's authorize: filter. You paste, tweak the label/icon/order, done.

While wiring the guard it caught 6 real gamification config pages that were unlinked;
all are gamification.manage surfaces and are now in Administration:
  Activity categories, Follow-up types, Follow-up methods, Rank definitions,
  Streak definitions, Pending point awards.
Catalog is now 55 items across all 11 categories.

Reviewed intentional EXCLUSIONS (page-like but not navigation): integrations/circuits,
integrations/custom-adapters(+/allowlist), integrations/fallback-matrix, venues/nearby,
and 'me' (the signed-in shell that redirects to /me/dashboard).

Files: app/Modules/Shared/Navigation/MenuCoverage.php (new),
       app/Modules/Shared/Commands/MenuScaffoldCommand.php (new),
       app/Modules/Shared/Navigation/tests/menu_coverage_test.php (new, 4/4),
       app/Modules/Shared/Navigation/CoreMenuProvider.php (49 -> 55 items),
       docs/menu_preview.html (regenerated).
Suite: stub 30 · floor 18 · integration 18 · antidrift 70 · signedword 20 ·
       headers 14 · wiring 20 · inject 12 · coverage 4  = 206 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-18) — Link Integrations (gate its landing route)
================================================================================
Follow-up to 2026-09-17. The integrations catalogue GET was auth-only, so it could not
be linked into Administration without leaking that category to every member. Fixed at
the source:

  app/Config/Routes.php (edit):
    - GET integrations/catalog now carries ['filter' => 'authorize:provider.configure']
      (consistent with every write in that group, which was already provider.configure-
      gated). The catalogue is a configuration/admin surface, so gating the read is
      correct — not just a menu convenience.

  app/Modules/Shared/Navigation/CoreMenuProvider.php (edit):
    - Administration + Integrations -> integrations/catalog (provider.configure).
    - Catalog now 49 items across all 11 categories.

Menu stays a projection of enforcement: the item appears only for holders of
provider.configure, and the route itself now enforces the same code.

Files: app/Config/Routes.php (edit),
       app/Modules/Shared/Navigation/CoreMenuProvider.php (48 -> 49 items),
       docs/menu_preview.html (regenerated).
Suite: stub 30 · floor 18 · integration 18 · antidrift 57 · signedword 20 ·
       headers 14 · wiring 20 · inject 12  = 189 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-17) — Menu catalog: link ALL built module surfaces
================================================================================
Expanded the catalog from 27 to 48 items, still across all 11 categories, every item
route-verified against Config/Routes.php with permissions aligned to each route's
authorize: filter (anti-drift now 55 assertions, all green).

Newly linked (grouped):
  Overview      : My follow-ups due, Approvals awaiting me (personal approver queue)
  People        : Journey stages, Journey proposals, Discipler leaderboard,
                  Membership conflicts, Pending account merges, Identity policies,
                  Sponsor reassignments
  Groups        : Group kinds, Venues, Venue stats
  Reports       : Top individuals, Top groups, Ranks, Achievements
  Access        : ABAC policies (+ Roles, Rules, Break-glass now point at the REAL
                  routes: roles, rules, abac-policies, break-glass/pending-reviews —
                  replacing the earlier placeholder access/* paths)
  Administration: Points & rewards settings, Activity catalog, Badges,
                  Gamification rules  (all gamification.manage-gated)

Two deliberate NON-additions (documented inline in CoreMenuProvider.php), because
linking them would leak a privileged CATEGORY header to plain members — the menu is a
projection of enforcement, so a category only appears when the user holds a real
permission for something in it:
  - access-requests/pending -> placed in OVERVIEW (its GET is auth-only/personal;
    only the approve/reject actions carry access.request.approve), NOT in Access.
  - integrations/* -> NOT linked: every integrations route is auth-only in Routes.php,
    so an "Integrations" admin item would show to everyone. Add it once the route
    carries an authorize: code (e.g. provider.configure) — a Routes.php change.

Icons: added inbox, git-branch, list, award, shuffle, layers, book, flag (and filled
in several that were previously falling back to the neutral dot: user-plus,
calendar-plus, qr-code, receipt, sliders, download, move, folder-plus, plug,
alert-triangle) to BOTH _menu_nav.php and menu.js.

Still-a-gap (feature PAGE not built; permissioned so no category leak; in anti-drift
KNOWN_GAPS): events/create, events/checkin, events/expenses, courses/create, members,
reports/export, groups/create, groups/move, admin, admin/providers.

Files: app/Modules/Shared/Navigation/CoreMenuProvider.php (27 -> 48 items),
       app/Modules/Shared/Views/_menu_nav.php (+icons),
       public/assets/js/menu.js (+icons),
       app/Modules/Shared/Navigation/tests/menu_antidrift_test.php (KNOWN_GAPS),
       docs/menu_preview.html (regenerated: 48 items / 11 categories).
Suite: stub 30 · floor 18 · integration 18 · antidrift 55 · signedword 20 ·
       headers 14 · wiring 20 · inject 12  = 187 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-16) — Menu catalog expanded to cover the module surfaces
================================================================================
You were right: the catalog was a seed set. MenuCategory declared 11 categories but
CoreMenuProvider only populated 8 — Giving, Communications and Streaming were EMPTY —
and several real module landing pages weren't linked. Expanded to 27 items across all
11 categories, every item route-verified against Config/Routes.php (anti-drift green).

Added (all point at real routes; permissions match each route's authorize: filter):
  People         + Disciple-making pipeline      -> journey/pipeline        (auth)
  Giving         + Partnership tiers             -> vbcs/partnership/tiers  (auth)
  Giving         + Manual contributions review   -> vbcs/manual/pending     (contribution.manage)
  Communications + Community feed                -> community/feed          (auth)
  Streaming      + Live streams                  -> streams                 (stream.moderate)
  Reports        + Leaderboards                  -> gamification/leaderboard(auth)
  Reports        + Funnel dashboard              -> reports/funnel          (report.view)
                   (replaces the old stale 'reports' item that had no route)

Icons: added route, hand-heart, message-square, video, trophy to BOTH the SSR partial
(_menu_nav.php) and the client renderer (menu.js) so the new items render an icon
rather than the neutral fallback dot.

NOTE ON WHAT'S STILL A "GAP": items whose feature pages aren't built yet remain listed
in the anti-drift KNOWN_GAPS (events/create, courses/create, members, groups/create,
groups/move, reports/export, access/*, admin*) — they show in the catalog but the test
reports them as not-yet-routed instead of failing. As each page ships, drop it from
KNOWN_GAPS. Per-module providers can later replace this central seed provider.

Files: app/Modules/Shared/Navigation/CoreMenuProvider.php (edit, 21 -> 27 items),
       app/Modules/Shared/Views/_menu_nav.php (edit, +5 icons),
       public/assets/js/menu.js (edit, +5 icons),
       app/Modules/Shared/Navigation/tests/menu_antidrift_test.php (edit, KNOWN_GAPS),
       docs/menu_preview.html (regenerated, 27 items / 11 categories).
Suite: stub 30 · floor 18 · integration 18 · antidrift 18 · signedword 20 ·
       headers 14 · wiring 20 · inject 12  = 140 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-15) — Dynamic menu now shows on ALL authenticated pages
                   (global injector filter, not per-page edits)
================================================================================
Symptom: the menu only appeared on pages that extend layouts/app.php. Several
authenticated pages are self-contained HTML documents and don't extend the layout,
so they had no menu:
  - /me           (Identity/Views/me.php)
  - /me/sessions  (Identity/Views/sessions.php)
  - /me/contacts  (Referrals/Views/contacts_index.php)
...and any future standalone page would miss it too.

FIX: a global `after` filter injects the menu once into every authenticated HTML
response that doesn't already carry it — so coverage is automatic and future-proof,
with no per-page edits.

  app/Modules/Shared/Filters/MenuInjectionFilter.php (new):
    - Runs only on 2xx text/html responses for requests carrying the wbs_session
      cookie (browser pages). API/token/JSON callers and redirects are skipped.
    - Skips any page that already contains id="wbs-menu" (layout pages), so it
      never double-injects.
    - Splices a self-contained fragment before </body>: a fixed slide-out panel
      opened by a CSS-only toggle (hidden checkbox + label) — NO inline JS — plus
      <script src="/assets/js/menu.js">. CSP-safe (script-src 'self',
      style-src 'self' 'unsafe-inline'). Same renderer + endpoint as the layout,
      so data/behaviour are identical.

  app/Config/Filters.php (edit):
    - Alias 'menuinject' => MenuInjectionFilter::class
    - Added to $globals['after'] (runs on every response; the filter self-limits
      to the pages that need it).

RESOURCE COST: origin-light. Per response it does a cookie check, a content-type
check, and (only for qualifying HTML) two substring scans + one splice. The menu
DATA is still fetched client/edge-side (GET /me/menu, 304-revalidated) — nothing
is derived in the filter. Pages built on layouts/app.php are untouched (they
already have the mount).

Files: app/Modules/Shared/Filters/MenuInjectionFilter.php (new),
       app/Config/Filters.php (edit),
       app/Modules/Shared/Navigation/tests/menu_inject_test.php (new, 12/12).
Suite: stub 30 · floor 18 · integration 18 · antidrift 8 · signedword 20 ·
       headers 14 · wiring 20 · inject 12  = 140 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-14) — Dynamic menu mounted in the layout, the zero-burden way
                       (client/edge fetch, not per-request SSR)
================================================================================
The universal dynamic menu is now visible on every server-rendered page. It is wired
as a CLIENT/EDGE-fetched resource (NOT re-rendered inside each page), so the origin
does ~no menu work between authority changes — the resource-light option you asked for.

WHERE IT LIVES
  app/Views/layouts/app.php  (the shared layout every module page extends)
    - The old hardcoded <div class="nav"> ... </div> is replaced by a sidebar mount:
        <nav id="wbs-menu" data-endpoint="/me/menu"> ... </nav>
    - Inline menu styles added (CSP style-src 'self' 'unsafe-inline' — allowed).
    - Loads ONE external script: <script src="/assets/js/menu.js" defer>
      (no inline script body — CSP script-src 'self').

HOW IT STAYS RESOURCE-LIGHT
  public/assets/js/menu.js (new):
    1) Instant paint from localStorage — no network, no empty-nav flash.
    2) Conditional GET /me/menu with If-None-Match + X-Menu-Word (the signed word).
       - Valid signed word -> origin skips DB role reads AND derivation (fast path).
       - Unchanged menu    -> origin/edge answers 304, nothing re-rendered.
    3) Caches the returned tree + etag + signed word for next time.
  Same-origin fetch (connect-src 'self'); labels via textContent (XSS-safe); no eval.
  It renders the SAME tree shape MenuService::render() emits, so it matches the SSR
  partial (_menu_nav.php) exactly — you can switch delivery modes without changing
  the catalog.

WHY CLIENT/EDGE INSTEAD OF SSR
  An SSR include would re-run MenuService::render() on every page load (per-request
  origin work on every navigation). The client/edge model makes the menu ONE
  cacheable resource served from browser/edge cache with 304s — origin navigation
  work collapses to ~zero. See docs/DYNAMIC-MENU-EDGE.md for the CDN configs.

NOTE: menu = DISPLAY only. Every route keeps its authorize: filter, so a stale or
tampered cached menu can never grant access — at worst it shows a dead link the PDP
then denies.

Files: app/Views/layouts/app.php (edit), public/assets/js/menu.js (new),
       app/Modules/Shared/Navigation/tests/menu_wiring_test.php (new, 20/20).
Suite: stub 30 · floor 18 · integration 18 · antidrift 8 · signedword 20 ·
       headers 14 · wiring 20  = 128 assertions green.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-13) — Keep DB sessions, but configured correctly
                   (Config/Session.php + ci_sessions migration)
================================================================================
Root cause recap: Config\Session used the DatabaseHandler while $savePath pointed
at a FILESYSTEM path (WRITEPATH.'session' -> C:\laragon\...\writable\session).
With the database driver $savePath must be a TABLE NAME, so CI4 built
  SELECT `data` FROM `C:\laragon\...\writable\session`
and every session read threw "table doesn't exist".

You asked to KEEP database sessions, so this patch configures them properly:

  app/Config/Session.php (patched):
    - $driver   = DatabaseHandler::class   (explicit; keeps DB sessions)
    - $savePath = 'ci_sessions'            (a TABLE NAME, was a directory path)
    - $matchIP  = false  (unchanged -> PRIMARY KEY is `id` alone; see below)

  app/Modules/Shared/Database/Migrations/2026-09-12-000065_CreateCiSessions.php (new):
    - Creates the `ci_sessions` table per the CI4 manual (MySQL/MariaDB):
      id VARCHAR(128) PK, ip_address VARCHAR(45), timestamp, data BLOB,
      KEY on timestamp. InnoDB / utf8mb4.

APPLY STEPS (on your Laragon box):
  1) Extract this archive over the project root.
  2) php spark migrate            # creates ci_sessions
  3) Restart PHP (clear opcache).

IMPORTANT — $matchIP: it is left FALSE, so the PK is `id` only. If you later set
$matchIP = true, CI4 requires the PK to become (id, ip_address); update the
migration/table accordingly or session reads will silently fail. The migration
file documents this inline.

Note: the menu endpoints themselves remain session-free (see the 2026-09-12 fix),
so /me/menu does NOT depend on any of this — this patch just makes the rest of the
app's DB-backed sessions work as intended.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FIX (2026-09-12) — /me/menu no longer touches the CI4 session (was crashing +
                   defeating the zero-burden design)
================================================================================
Symptom (log-2026-09-09): GET /me/menu threw
  DatabaseException: Table 'wbs...c:\laragon\www\wbs\writable\session' doesn't exist
from MenuController::activeScope() -> session('wbs_menu_scope').

Two issues, one fix:
  1) MenuController::activeScope() read the CI4 session, which forces session_start()
     — a DB read under the DatabaseHandler — on EVERY /me/menu request. That both
     crashed here AND defeated the endpoint's stateless / edge-cacheable design.
     FIX: active scope is now read from ?scope= or a plain `wbs_menu_scope` COOKIE
     (a non-secret UI preference); the controller makes NO session call at all.
     Guarded by menu_headers_test ("controller does NOT call session()").
  2) Your app's Config\Session uses DatabaseHandler but `savePath` is a filesystem
     path (C:\laragon\...\writable\session) instead of a TABLE NAME, so CI4 built
     SELECT ... FROM `C:\laragon\...\session`. This is a pre-existing app-config
     issue our code merely tripped. If you keep DatabaseHandler, set
     $savePath = 'ci_sessions' and create that table (php spark session:migration
     then migrate); or switch $driver to FileHandler with a real writable path.
     The menu no longer depends on any of this either way.

Changed: app/Modules/Shared/Controllers/MenuController.php,
         app/Modules/Shared/Navigation/tests/menu_headers_test.php (14/14).
Menu suite still green: stub 30 · floor 18 · integration 18 · antidrift 8 ·
signedword 20 · headers 14.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-11) — Dynamic menu: zero-server-burden delivery
                       (signed client-held word + edge cache)
================================================================================
Completes the "does not burden the servers" option for the universal dynamic menu:
the app tier does essentially NOTHING between authority changes. See
docs/DYNAMIC-MENU-EDGE.md for the full contract and copy-paste CDN configs.

Two mechanisms (both implemented + tested):

1) SIGNED CLIENT-HELD WORD (app/Modules/Shared/Navigation/SignedWord.php)
   - The 8-byte capability word is handed to the client as a tamper-evident token:
       v1.<keyId>.<payload_b64url>.<mac_b64url>
     mac = truncated HMAC-SHA256 over "word|sub|org|scope|grantVer|roleTblVer|catVer|exp".
   - The client echoes it back (X-Menu-Word header or ?word=). On a valid, fresh,
     context-matching token the origin SKIPS the DB role reads AND derivation and
     renders in memory — the fast path verifies BEFORE roleCodesForSubject().
   - Fail-closed: any bad MAC / expiry / version drift / context mismatch -> null ->
     origin re-derives from roles and re-signs. Word is masked to known bits on the
     way out (defense in depth).
   - Keying reuses the platform KeyProvider seam (env today; KMS/Vault switchable),
     so rotation is a keyId change with no format break — old tokens verify against
     their embedded keyId until they expire.
   - Wiring: Shared/Config/Services.php gains menuWordSigner(); MenuController mints
     the token into every response (X-Menu-Word + JSON signedWord) and trusts a
     returned one on the fast path.
   - SECURITY: the word drives DISPLAY only. Every route keeps its authorize: filter,
     so a forged/stale word can at most surface a dead link the PDP then denies.

2) EDGE CACHE CONTRACT (MenuController response headers + docs/DYNAMIC-MENU-EDGE.md)
   - GET /me/menu (+?bundle=1): Cache-Control: private, max-age=0, must-revalidate;
     ETag "m<grantVer>.<scope>.<catVer>.<roleTblVer>"; Vary includes X-Menu-Word;
     Surrogate-Key "menu-user-<id> menu-org-<org>". Browser/edge answer If-None-Match
     with 304 (empty body) until a version moves — origin renders nothing on the
     common navigation.
   - GET /me/menu/authority: tenant-wide + NON-SECRET, so Cache-Control: public,
     max-age=60, s-maxage=86400, stale-while-revalidate=86400; Surrogate-Key
     "menu-authority org-<org>". One ~123 B object per tenant served to every user
     from the CDN. This removes navigation traffic from the origin at 500k scale.
   - docs/DYNAMIC-MENU-EDGE.md ships copy-paste rules for Cloudflare, Varnish, Nginx,
     and Fastly, plus the client loop and an O(1) invalidation table (grant / role-
     permission / deploy / token drift — all version-compare, no cache scans).

New/changed files:
  - Navigation/SignedWord.php                     (new) HMAC signer/verifier.
  - Controllers/MenuController.php                 (edit) signed-word fast path + edge headers.
  - Config/Services.php                            (edit) menuWordSigner() binding.
  - docs/DYNAMIC-MENU-EDGE.md                      (new) contract + CDN configs.
  - Navigation/tests/menu_signedword_test.php      (new) 20/20 mint/verify/tamper/expiry/rotation.
  - Navigation/tests/menu_headers_test.php         (new) 12/12 edge header contract.

Verification (sandbox: php -l + harnesses; no MySQL/framework runtime):
  menu_stub_test 30/30 · menu_floor_test 18/18 · menu_integration_test 18/18 ·
  menu_antidrift_test 8/8 · menu_signedword_test 20/20 · menu_headers_test 12/12.
Cost at 500k concurrent: origin requests for navigation ~= 0 between authority
changes; when origin does run it verifies one HMAC or ORs a few role-words; per-user
server state = 0 bytes; edge stores one ~123 B authority object per tenant.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-10) — Universal dynamic menu (per-user / per-scope, bitset core)
================================================================================
A single categorised navigation computed from access rights: items appear only if
the current user can reach them in the current scope. Built for very high concurrency
(≈500k) with a best-case-obsessed design — see docs/DYNAMIC-MENU-*.md for the full
reasoning, complexity analysis, and benchmarks.

Core idea (derive-don't-store):
  - The platform has P=41 permission codes, so a subject's ENTIRE capability set
    fits in ONE 64-bit integer ("capability word"). Item visibility is then a single
    bitwise AND + compare — Theta(1), no per-item PDP call.
  - A word is a function of ROLE-SET, not identity. We keep a tiny, versioned
    role->word table (~123 bytes for a whole tenant) and DERIVE any user's word by
    OR-ing their roles. 500k users collapse to a handful of distinct menus; per-user
    stored state = 0 bytes.
  - Three-tier read cascade: Tier 0 = If-None-Match -> 304 (zero render); Tier 1 =
    cached/derived word -> AND-fold; Tier 2 = compute word once. All invalidation is
    O(1) via stateless version stamps (grant-set hash + role-table fingerprint +
    catalog fingerprint) — no counters, no cache scans, correct across a rolling fleet.

New code (all under app/Modules/Shared, pure + framework-light):
  - Navigation/PermissionBits.php   — FROZEN append-only code->bit map (P=41);
    fail-closed UNSATISFIABLE sentinel for unknown codes; selfCheck() invariants.
  - Navigation/MenuItem.php         — item DTO; precomputes its requiredMask;
    visibleTo(word) = (word & mask)===mask.
  - Navigation/MenuCategory.php     — fixed, ordered, relabelable category buckets.
  - Navigation/CoreMenuProvider.php — starter catalog (Overview/People/Groups/Events/
    Learning/Reports/Access/Admin).
  - Navigation/MenuCatalog.php      — boot-frozen, process-shared structure + parallel
    masks[]; xxh128 structure fingerprint (catalogVersion()).
  - Navigation/RoleWordTable.php    — role->word table; fromRolePermissions(),
    deriveWord(roles), content version().
  - Navigation/MenuBundle.php       — client/edge handoff: assemble() (per-user render
    bundle) + authorityBundle() (tenant role->word table so the client derives words).
  - Navigation/MenuService.php      — render() AND-fold + bucket + prune; deriveWord()/
    buildFromRoles(); ETag composition; three-tier word resolution.
  - Navigation/MenuWordProvider.php — DB bridge (read-only): roleWordTable(org),
    roleCodesForSubject(org,user,scope), stateless grantVersion(org,user). Pure
    helpers unit-tested without a DB.
  - Controllers/MenuController.php   — GET /me/menu (+?bundle=1,?scope=), GET
    /me/menu/authority; If-None-Match -> 304 handshake.
  - Views/_menu_nav.php             — self-contained SSR nav partial (inline styles +
    inline SVG; CSP-safe). Renders the SAME tree; works with zero JS; progressive
    enhancement can revalidate via ETag / re-fetch ?bundle=1 on scope switch.
  - Config/Services.php             — menuCatalog() (shared), menuWordProvider()
    (shared), menu(org) (per-tenant, wired with that org's role->word table).

New routes (behind `auth`):
  GET /me/menu                 -> menu tree for the active scope (ETag/304)
  GET /me/menu?bundle=1        -> client render bundle (word + item masks)
  GET /me/menu?scope=<groupId> -> menu for that scope
  GET /me/menu/authority       -> tenant role->word table + catalog

Verification (sandbox: php -l + stub/render harnesses; no MySQL/framework runtime):
  php app/Modules/Shared/Navigation/tests/menu_stub_test.php         # 30/30
  php app/Modules/Shared/Navigation/tests/menu_floor_test.php        # 18/18 (derive; 500k->11 menus)
  php app/Modules/Shared/Navigation/tests/menu_integration_test.php  # 18/18 (DB bridge + ETag/304)
  php app/Modules/Shared/Navigation/tests/menu_antidrift_test.php    # 8/8  (catalog<->routes alignment)
  php app/Modules/Shared/Navigation/tests/menu_bench.php             # throughput + footprint
Measured: ~3M full-menu renders/sec/core; role->word table ~123 B; per-user state 0 B.

NOTE: a few starter catalog items (events/create, members, admin, ...) point at
feature routes not built yet. Harmless — the menu drives DISPLAY only and each real
route keeps its own authorize: filter. The anti-drift test lists these as KNOWN_GAPS
and will enforce alignment automatically as those routes land.


Drop this archive's contents over your project **root** (the folder that contains `app/`,
`public/`, `spark`). It only adds/overwrites the files listed below; nothing else is touched.

================================================================================
FEATURE (2026-09-09) — Location-based group directory + address book / outreach
================================================================================
Adds a location-organised /g directory and a member/staff ADDRESS BOOK for
managing a downline (contacts) with follow-up, consent-gated GPS, invite context,
and dated decisions. Built by EXTENDING existing tables (Referrals `prospects`,
Geo `addresses`/`location_consents`, Groups) — no parallel/forked tables.

New migrations (062–064; run `php spark migrate --all`):
  - Referrals 000062 GrowProspectsToContacts — enriches `prospects` into a
    contact/address-book record: owner_user_id, assigned_group_id, linked_user_id
    (-> platform users, for event/course registrations), created_by,
    source, full_name/phone/email, address_id (-> Geo addresses), notes,
    journey_stage, temperature (hot|warm|cold), last/next follow-up + count,
    invite_context_type/id (cause|course|event|group), and the TWO-PART coord
    consent flags (coords_consent_verbal + coords_consent_confirmed + _at).
    Idempotent add-if-absent columns + indexes.
  - Referrals 000063 CreateProspectDecisions — dated `prospect_decisions`
    (append-only): decision_type, decision_date, target_group_id (for join_group),
    note, recorded_by. Seeded decision types: salvation, rededication,
    water_baptism, holy_spirit_baptism, join_group (org-configurable — codes, no
    enum lock-in).
  - Groups 000064 AddGroupLocation — group location for the directory: address_id,
    location_group_key (directory bucket), region_label, city_label, latitude,
    longitude. contact_email/contact_phone reused from 000047.

New/changed code:
  - Referrals/Services/ContactBookService.php — CRUD + birds-eye of the downline
    (listForOwner, listForGroupSubtree via group_closure, summary tiles by
    temperature/stage + follow-ups due), recordFollowUp, recordDecision, staff
    bulkCreate. CONSENT GATE: precise coordinates are persisted ONLY when BOTH the
    prospect's verbal agreement AND the member's checkbox confirmation are present;
    otherwise no coords are stored. Coordinates live on the Geo `addresses` row via
    LocationService (precision coarsened without consent). SAFE: no eval,
    parameterized. Ownership enforced (a member only touches contacts they own).
      * SCOPE-BOUNDED STAFF BULK: bulkCreate() now enforces that the assigned
        group falls within the CALLER'S OWN leadership scope. It reads the caller's
        active role_assignments (scope_group_id + scope_mode, incl. GROUPS mode via
        grant_scope_groups and include_crosscut) and expands them over the
        hierarchy with the shared GroupScopeResolver — the SAME containment model
        used by Delegation/AccessRequest/BreakGlass. An org-wide holder (NULL scope)
        is unbounded; everyone else may only bulk-add on behalf of groups they
        actually hold. No global `identity.manage` capability is used any more.
        (New helpers: groupsInScopeForUser(), userScopeCovers().)
      * ATTENDANCE WITHIN FOLLOW-UPS: recordAttendance() registers a contact for an
        EVENT or COURSE as part of following them up, reusing the platform's OWN
        registration tables (Events `event_registrations`, Courses `enrollments`) —
        NO fork. If the contact has no platform user yet it links one (reusing an
        existing user by email, else minting a lightweight pending_verification
        user) and stores it as prospects.linked_user_id. Idempotent per
        (event|course, user); event rows carry group_attribution (defaults to the
        contact's assigned group) and source_ref=contact:{id}; each registration
        counts as a follow-up touch. attendanceFor() lists a contact's event +
        course registrations for the follow-up view.
  - Referrals/Config/Services.php — contactBook() binding (injects Geo location +
    shared GroupScopeResolver for scope-bounded bulk).
  - Referrals/Controllers/ContactBookController.php + Views/contacts_index.php —
    dashboard address book (birds-eye tiles, triage filters, add-contact form with
    consent-gated GPS, per-contact follow-up + decision + REGISTER-ATTENDANCE
    forms). Sets the wbs_csrf cookie for its double-submit forms. New action
    attend() -> POST /me/contacts/{id}/attend.
  - Groups/Services/GroupPublicService.php — directoryByLocation(): buckets active
    groups by location_group_key (fallback region/city/location_text, else
    "Other" which always sinks last), each carrying location + contact details.
  - Groups/Controllers/GroupPublicController.php + Views/public_directory.php — /g
    now renders as a directory BY LOCATION (sections + jump-to TOC + contact block
    per card). JSON clients get { sections, count }.
  - Identity/Views/me.php — dashboard links to "Groups by location" and
    "My contacts & follow-ups".

New routes (all behind `auth` + CSRF):
  GET  /me/contacts                         -> address book (birds-eye)
  POST /me/contacts                         -> create a contact
  POST /me/contacts/{id}/follow-up          -> log a follow-up
  POST /me/contacts/{id}/decision           -> record a dated decision
  POST /me/contacts/{id}/attend             -> register the contact for an event/course
  POST /me/contacts/bulk                    -> staff bulk on behalf of a group;
                                               NOT gated by identity.manage — the
                                               SERVICE enforces leadership-scope
                                               containment on the assigned group.

Seeder (run AFTER RbacBootstrapSeeder):
  php spark db:seed 'WBS\Referrals\Database\Seeds\OutreachDemoSeeder'
    Seeds the FULL hierarchical chain — Ghana National -> Greater Accra Region ->
    Accra East Area -> Legon Local Assembly -> Legon Central Fellowship ->
    Legon Hall Senior Cell -> Legon Hall Cell (fellowship + senior cell now sit
    BEFORE the cell), plus an Ashanti branch — with per-group location + contact
    details, a member address book of contacts at varied stages/temperatures (two
    with consented GPS), dated decisions incl. join_group, and (when a demo
    event/course exists) example event/course attendance registrations recorded
    through the follow-up path. Idempotent (skips if the chain exists).

Group hierarchy: the placement chain is National -> Region -> Area ->
Local Assembly -> Fellowship -> Senior Cell -> Cell. This is data (group `type`
labels + closure placement), well within `wbs.maxGroupDepth`; it does not change
scope evaluation, which continues to work over group_closure regardless of level
names.

Verification (this sandbox has no MySQL/framework runtime): `php -l` clean on all
files; ContactBookService stub harness 23/23 (consent gate: coords persisted only
with BOTH consents; ownership; filters; summary; decisions incl. join_group
target; bulk) PLUS 21/21 for the new scope-bounded bulk + attendance harness
(scope resolves self+descendants and GROUPS mode; out-of-scope bulk denied 403;
org-wide unbounded; event/course registration idempotent, links a user, carries
group_attribution, bumps follow-up count; bad type / missing target / non-owner
rejected); directoryByLocation bucketing 5/5; directory + contacts view renders
9/9 + 5/5. Requires framework+DB to exercise end to end; smoke-test after migrate
+ seed.

--------------------------------------------------------------------------------
Everything below is the prior (through 2026-09-08) changelog, unchanged.
--------------------------------------------------------------------------------

# WBS Platform — migration & seeding fixes (2026-09-07)

## How to apply

From your project root:

    unzip -o wbs-fixes-2026-09-07.zip

(`-o` overwrites the 4 edited migrations; the two `.gitkeep` files create the missing dirs.)

Then re-run:

    php spark migrate --all
    php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
    # ...remaining seeders per RUNBOOK

On Windows `cmd.exe`, use double quotes for the seeder arg:
    php spark db:seed "WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder"

## What each file fixes

1. **app/Modules/AccessControl/Database/Migrations/2026-09-01-000006_CreateAccessControl.php**
   `abac_policies.condition` is a MySQL reserved word — now backticked (`` `condition` ``).
   Was: MySQL error 1064 during `CREATE TABLE abac_policies`.

2. **app/Modules/Groups/Database/Migrations/2026-09-01-000007_CreateGroups.php**
   `groups` is a MySQL reserved word — now backticked in the `CREATE` and `DROP`.
   Was: "error in your SQL syntax near 'groups ('".

3. **app/Modules/Geo/Database/Migrations/2026-09-01-000010_CreateGeo.php**
   `geo_point` handling rewritten as an idempotent `addGeoPoint()` helper:
     - adds the nullable `POINT SRID 4326` column only if absent (`fieldExists`);
     - drops any stale `SPATIAL` index left by an earlier partial run.
   Rationale: a SPATIAL INDEX requires a NOT NULL column, but `geo_point` must stay
   nullable (LocationService sets it to NULL when a location is cleared). The index is
   removed; proximity queries should use `ST_Distance_Sphere(...)` guarded by
   `geo_point IS NOT NULL`. No app code referenced the index.
   Was: "Duplicate column name 'geo_point'" on re-run (and it never succeeded on a clean
   DB either, because of the NOT NULL requirement).

4. **app/Modules/Gamification/Database/Migrations/2026-09-05-000042_GroupScopedAwardsAndCampaigns.php**
   Removed an apostrophe inside a single-quoted SQL comment ("member's") that was
   terminating the PHP string.
   Was: PHP ParseError "unexpected identifier 's'" at line 142.

5. **app/Modules/Gamification/Config/Services.php** and
   **app/Modules/Notifications/Config/Services.php**
   Both modules expose a `campaigns()` service, and both called
   `getSharedInstance('campaigns')`. CI4's shared-instance registry is keyed by a
   single GLOBAL string across all modules, and it rebuilds via service discovery
   (`AppServices::campaigns()`), which returns whichever module's method is found
   first. Result: Gamification's factory could hand back a
   `WBS\Notifications\Services\CampaignService`.
   Fix: each module now caches its own instance in a private static
   `$sharedCampaigns` property instead of the colliding global key.
   Was: TypeError "Return value must be of type
   WBS\Gamification\Services\CampaignService, WBS\Notifications\Services\CampaignService
   returned" at Gamification\Config\Services.php:67.

6. **app/Modules/Gamification/Services/CampaignService.php**
   Line ~1337 used `($cond ? $a : $b)[] = $x;` — appending to the result of a
   ternary. PHP 8.4 makes this a compile-time fatal ("Cannot use temporary
   expression in write context"), which took down ANY seeder/request that autoloads
   CampaignService (e.g. WbsActivityCatalogSeeder). Rewritten as a plain if/else.
   Was: ErrorException "Cannot use temporary expression in write context" at
   CampaignService.php:1337. (All 354 app PHP files now pass `php -l` on PHP 8.4.)

7. **app/Modules/Admin/Database/Seeds/DemoDataSeeder.php**
   `completion_rule` (a JSON column) was seeded with a bare string
   'all_required_lessons'. MySQL 9.x validates JSON on write and rejected it
   ("Invalid JSON text ... at position 0"). Now seeds
   `json_encode(['required_lessons' => 'all'])`.
   NOTE: this seeder writes a sentinel community post early, THEN failed later on
   the course insert. Its idempotency guard keys off that sentinel, so a plain
   re-run will SKIP the whole seeder and leave demo data incomplete. For a clean
   demo dataset, reset first:
       php spark migrate:refresh
   then re-run RbacBootstrapSeeder, WbsActivityCatalogSeeder, AdapterCatalogSeeder,
   DemoDataSeeder (in that order).

8a. **app/Modules/Shared/Http/WbsIncomingRequest.php** (NEW),
    **app/Config/Services.php**, and
    **app/Modules/Shared/Filters/CorrelationIdFilter.php**
    PHP 8.2+ deprecation "Creation of dynamic property is deprecated", fired on
    every request. Two sources:
      - AuthFilter attached wbsUserId/wbsOrgId/wbsMfaLevel/wbsScopes/wbsTokenId/
        wbsSessionId as undeclared (dynamic) properties on IncomingRequest. Fixed
        by a new WbsIncomingRequest subclass that DECLARES these properties, wired
        via a Config\Services::incomingrequest() override. Kept as request
        properties (NOT headers) on purpose: auth context must be server-set and
        non-spoofable. Zero call-site churn — every $request->wbsX still works.
      - CorrelationIdFilter stored the id as a dynamic property; now stored/read as
        the X-Correlation-Id request header (it is meant to be inbound-suppliable
        and is sanitized).
    Was: WARNING [DEPRECATED] Creation of dynamic property
    IncomingRequest::$wbsCorrelationId (+ the wbs* auth props) — non-fatal now, but
    will become fatal in a future PHP. All 355 app PHP files pass php -l.

FEATURE — Group-specific public landing pages + public self-join + login page
    Public routes (no auth): GET /g (directory), GET /g/{slug} (landing page),
    GET|POST /g/{slug}/join (self-join form + submit), GET /login (sign-in page).
    Management: POST /groups/{id}/profile (auth + authorize:group.change.approve)
    to set the theme and public/contact/join fields.
    Files:
      - Migration 000047 AddGroupPublicProfile (groups: tagline, description,
        location_text, contact_email/phone, website_url, announcement, hero_theme,
        cover_image_url, public_join, join_policy) — idempotent.
      - Services: GroupPublicService (public read model + selfJoin: find-or-create
        user, delegate to GroupMembershipService, leader as sponsor, honors
        join_policy open|approval), GroupService::updatePublicProfile (validated
        theme/join_policy + whitelisted fields).
      - Controllers: GroupPublicController (directory/page/joinForm/join),
        GroupController::updateProfile, Identity LoginPageController.
      - Views: public_page (4 themes: aurora|sunrise|forest|slate),
        public_directory, public_not_found, public_join, public_join_done,
        Identity/login.
      - DemoDataSeeder: the demo group now has a full public profile.
      - Config: Groups/Config/Services (groupPublic binding), Routes.php.
    Self-join is rate-limited (ratelimit:auth.register) and only exposes
    public-safe data. All app PHP passes php -l.

FEATURE — Browser web-session flow (cookie sign-in + MFA + protected /me)
    Cookie-based HTML auth layered on the existing JSON auth services (NO parallel
    auth logic). Routes: GET/POST /login, GET/POST /mfa (TOTP step-up),
    GET /me (auth-filtered), POST /logout. State-changing POSTs are CSRF-guarded.
    Files:
      - Services/WebAuthService (+ Config/Services webAuth binding): stateless,
        SecretBox-signed CSRF tokens and short-lived MFA step-up tickets (the
        pending principal travels in a signed cookie because no session exists
        between the password and TOTP steps).
      - Filters/WebCsrfFilter (alias 'webcsrf' in Config/Filters): double-submit
        CSRF check for cookie-authenticated browser POSTs; header/token API
        callers are exempt.
      - Filters/AuthFilter: now also resolves a session from the HttpOnly
        'wbs_session' cookie (headers still win for API callers).
      - Controllers/WebSessionController + Views/{login,mfa,me}: the pages.
      - Cookies (all HttpOnly, SameSite=Lax, Secure auto-set behind a
        TLS-terminating proxy via X-Forwarded-Proto): wbs_session, wbs_csrf,
        wbs_mfa.
      - Routes.php + RUNBOOK updated. Replaces the earlier interim
        LoginPageController (removed).
    RATE LIMITING + DYNAMIC MFA (added):
      - POST /login carries ['webcsrf','ratelimit:auth.login'] (ip-keyed 5/60s,
        fail-closed).
      - POST /mfa calls the limiter DIRECTLY with policy 'auth.mfa_verify'
        (6/300s) keyed on the TICKET's user_id — because the standard ratelimit
        filter keys on the CI session user, which this platform never populates,
        so at /mfa it would degrade to a single global bucket (one attacker could
        lock everyone out). 429 re-renders the challenge with Retry-After.
      - verifyMfa now honors ADAPTIVE MFA: derives the ACTUAL achieved assurance
        (TOTP or one-time recovery code, both 'high'), then gates session
        creation on StepUpPolicy::isSatisfied(achieved, ticket.risk_score) — the
        same policy the login path uses — and records the real assurance on the
        session (no hardcoded 'high'). The /mfa screen adapts its copy to the
        required assurance and offers the recovery-code fallback.
    OPTIONAL GET /mfa CHALLENGE-PAGE RATE LIMIT (group-config gated):
      - New policy 'auth.mfa_challenge' (12/300s, fail-closed) in
        Shared/Config/RateLimitPolicies.
      - Enforced ONLY when a group in the signing-in user's ancestry enables the
        capability 'security.mfa_challenge_ratelimit' via the inheritance-aware
        EffectiveConfigResolver (default OFF => no-op). Set it with:
          POST /admin/groups/{groupId}/config/security.mfa_challenge_ratelimit
          { "value": true, "inheritance_mode": "ancestor_default_child_override" }
      - The user's group is resolved with the new
        GroupScopeResolver::primaryMembershipGroup (Shared) — mirrors the
        gamification attribution membership-fallback, no cross-module dependency.
      - Files: Shared/Config/RateLimitPolicies.php,
        Shared/Support/GroupScopeResolver.php,
        Identity/Controllers/WebSessionController.php (+ RUNBOOK).

    NOTE: requires a running framework + DB to exercise end to end; this sandbox
    has neither, so verification was php -l (all pass) + static route/wiring
    checks. Smoke-test after `composer install` + migrate/seed.

SECURITY — Session lifetime, id rotation, password rehash (SRS FR-ID)
    Server-side sessions had NO lifetime (active() checked only revoked_at), so a
    leaked session id was valid forever. Hardened:
      - Migration 000049 AddSessionExpiry: adds sessions.expires_at (+ index),
        backfills live rows to created_at + 14d. Idempotent.
      - SessionService rewritten: absolute TTL (default 14d) + idle timeout
        (default 8h) BOTH enforced in active() (fail-closed, lazily revokes an
        expired/idle row). TTLs configurable via env session.absoluteTtl /
        session.idleTtl (Identity/Config/Services). New: listForUser() (session
        review, metadata only) and prune() (housekeeping).
      - elevate() now ROTATES the session id on MFA step-up (anti session-
        fixation): inserts a new elevated session, revokes the old id, returns the
        new one. MfaController.verify surfaces the rotated session_id +
        session_rotated=true so API clients swap it. (The web /mfa flow already
        creates a fresh session, so it was safe; unchanged.)
      - AuthenticationService: successful login transparently re-hashes passwords
        that need stronger params (password_needs_rehash).
      - New CLI: `php spark identity:prune-sessions [--retention-days=30]`
        (Identity/Commands/SessionPruneCommand) — revoke expired, delete old
        revoked rows. Run on a schedule.
    Migration count 48 -> 49. Requires framework+DB to exercise; verified via
    php -l (372 pass) + static wiring checks.

8c. **app/Modules/Shared/Http/BaseController.php**
    Redeclared `protected RequestInterface $request;`, but the parent
    CodeIgniter\Controller::$request is UNTYPED. PHP 8 forbids a subclass adding a
    type to an inherited property ("Type of ... $request must be omitted to match
    the parent definition"). Removed the property type (kept a @var docblock).
    Only surfaced once a route resolved to a controller extending BaseController.
    Was: CRITICAL ErrorException at BaseController.php:23.

8b. **app/Config/Routes.php**
    The '/' and 'health' routes named the handler with the full namespace but NO
    leading backslash ('App\Controllers\Home::index'). CI4 prepends
    defaultNamespace ('App\Controllers') to any handler that does not start with
    '\', producing 'App\Controllers\App\Controllers\Home' -> 404. Fixed to the
    short form 'Home::index' (Home is in the default namespace). All module routes
    already use the correct leading-backslash fully-qualified form.
    Was: 404 "Controller or its method is not found:
    \App\Controllers\App\Controllers\Home::index".

8. **RUNBOOK.md** — added section 10 "MySQL 9.x notes & migration/seeding
   troubleshooting" documenting every gotcha fixed here (auth plugin, reserved-word
   backticking, JSON columns, spatial index NOT NULL, PHP 8.4 temporary-expression
   fatal, shared-service key collisions, base seeds dir, Windows quoting, partial
   migration/seed recovery).

9. **app/Database/Seeds/.gitkeep** and **app/Database/Migrations/.gitkeep**
   Creates the base seeds directory. CI4's `Seeder::__construct()` requires
   `Config\Database::filesPath . '/Seeds/'` (= `app/Database/Seeds/`) to exist even when
   you run a fully-qualified module seeder class.
   Was: "Unable to locate the seeds directory. Please check Config\Database::filesPath".

FEATURE — Active-session review & self-service revoke (SRS FR-ID)
    Lets a signed-in member see and terminate their own live sessions.
    Routes: GET /me/sessions (auth), POST /me/sessions/{id}/revoke (auth+webcsrf).
      - WebSessionController::sessions() returns JSON when wantsJson(), else an HTML
        page (Views/sessions.php). Lists metadata only — id, mfa_level, risk_score,
        created/last-seen/expiry — and flags the caller's CURRENT session via
        hash_equals on the wbs_session cookie. No token/hash material is exposed.
      - WebSessionController::revokeSession($id) revokes ONLY a session the caller
        owns (ownership checked against SessionService::listForUser before revoke);
        revoking the current session also clears cookies and redirects to /login.
      - Views/me.php gains a "Manage active sessions" link; Routes.php updated.
    Files: Identity/Controllers/WebSessionController.php, Identity/Views/sessions.php,
    Identity/Views/me.php, app/Config/Routes.php.

FEATURE — Set-password / accept-invite for passwordless members (SRS FR-ID)
    Members created without a password (public group self-join, or admin invite)
    can now set a first password and sign in.
    Routes: GET /set-password?token=… (public; validates token, renders form),
    POST /set-password (webcsrf + ratelimit:auth.password_reset; consumes token).
    Files:
      - Migration 000050 CreateCredentialSetupTokens: credential_setup_tokens
        (hashed, single-use, expiring invite|password_reset tokens). Idempotent
        (CREATE TABLE IF NOT EXISTS). Only the SHA-256 hash is stored.
      - Services/CredentialSetupService (+ Identity/Config/Services credentialSetup
        binding, env invite.ttl, default 7d): issue()/inspect()/consume(). consume()
        sets the password via AccountService::setPassword (shared policy: 12+ chars,
        mixed case + digit); for an invite activates the account (status=active,
        email_verified=1); revokes all existing sessions on success. issue() retires
        prior unused tokens of the same purpose. Plaintext token (wbsinv_+64hex)
        travels only in the link.
      - Controllers/SetPasswordController + Views/{set_password,set_password_done,
        set_password_invalid}.
      - Groups/Services/GroupPublicService: selfJoin() now issues an invite for a
        newly-created passwordless user (nullable CredentialSetupService injected —
        acyclic, Groups depends on Identity here) and returns is_new_user +
        invite_token; findOrCreateUser now creates the user as
        'pending_verification'. Groups/Config/Services wires the dependency;
        GroupPublicController surfaces the one-time set-password link on the join
        confirmation page (Views/public_join_done.php) — normally emailed.
    Migration count 49 -> 50. Requires framework+DB to exercise end to end; verified
    via php -l (all 379 app files pass) + static wiring checks.

DESIGN + FEATURE — Gamification CRUD audit & ranking/roll-up (leaf-inclusive milestones)
    Combined design/audit document included: docs/gamification_crud_audit_and_ranking_design.md
    (Part A CRUD gap-audit of every group-scoped config entity; Part B group
    attribution + ancestor roll-up ranking model — schema + engine). It documents
    the as-built state (migrations 000042–000045, RollupService, LeaderboardService,
    PointsEngine multi-group award, group_point_rollup) and is accurate against the
    code, not aspirational.
    Change in this build:
      - app/Modules/Gamification/Services/CampaignService.php — the OPT-IN ancestor
        award roll-up (rollup_awards=1) is now LEAF-INCLUSIVE. A member's verified
        contribution mints a single milestone award at EVERY group on the member's
        own ancestor-or-self path within the campaign owner's subtree — from the
        member's most-specific (leaf) group up to and including the owner — not just
        the groups ABOVE the direct-child team. New pure helper
        CampaignService::milestoneChain() (set math, unit-tested) + DB-backed
        milestoneAwardChain(); falls back to the previous ancestorAwardChain() when
        the member's leaf group is unknown. rollup_awards=0 is unchanged.
      - tests/unit/CampaignAwardsDeservedTest.php — 4 new cases lock milestoneChain
        (leaf→owner inclusive, excludes the direct-child team, stops at the owner /
        never mints above the campaign subtree).
    All Gamification + tests PHP pass php -l.

FEATURE — Group lifecycle: archive / merge / dissolve with evidence (SRS FR-GRP-005)
    A group state machine mirroring the identity account lifecycle:
    active ⇄ archived; active|archived → dissolved (terminal); active|archived →
    merged (terminal). Every transition demands a stated reason and writes both an
    append-only evidence row and an immutable audit-log entry.
    Files:
      - Migration 000051 CreateGroupLifecycle: widens groups.status to VARCHAR(24);
        adds status_reason/status_changed_at/status_changed_by/archived_at/
        dissolved_at/merged_into_id; creates append-only group_lifecycle_transitions
        (from/to status, reason, actor, approval_ref, merged_into_id, evidence JSON).
        Idempotent (guards each ADD COLUMN; CREATE TABLE IF NOT EXISTS).
      - Services/GroupLifecycleService (+ Groups/Config/Services groupLifecycle
        binding): archive/reactivate/dissolve/merge + transition() + history().
        Dissolve is refused (409) while the group has any active child group or
        active member. Merge re-parents the merged group's children under the
        survivor via GroupService::move (closure/paths kept correct) and transfers
        active members, preserving one-active-per (user,group,type) — a duplicate is
        ended rather than doubled; rejects self-merge, terminal survivor, and a
        survivor inside the merged subtree (cycle). Records group.lifecycle.{state}
        audit entries. canTransition() is a pure static seam (unit-tested).
      - Controllers/GroupLifecycleController + routes: POST /groups/{id}/archive,
        /reactivate, /dissolve, /merge (body survivor_id+reason) and
        GET /groups/{id}/lifecycle. Gated by authorize:group.change.approve at the
        route AND re-checked per-group in the controller (merge checks BOTH groups).
      - tests/unit/GroupLifecycleTransitionTest.php — locks the transition matrix
        (terminal states, reversible archive, no-op/unknown rejection, legacy-status
        forward-only).
    Migration count 50 -> 51. Requires framework+DB to exercise end to end; verified
    via php -l (all app files pass) + the pure transition unit test.

SECURITY — Password policy hardening: Argon2id calibration + breach-list (FR-ID-003)
    Completes the SRS password policy (≥12 chars + complexity were already there).
    Code-only (no migration).
    Files:
      - Security/PasswordHasher.php — Argon2id cost params now CALIBRATABLE via env
        (identity.argon.memoryCost KiB / identity.argon.timeCost / identity.argon.threads;
        bcrypt fallback identity.bcrypt.cost), each clamped to a safe floor. The
        active params feed needsRehash(), so raising cost transparently re-hashes
        on next successful login (no bulk migration). An explicit options array can
        be passed for calibration/tests.
      - Security/BreachChecker.php (interface) + LocalListBreachChecker (offline
        default: embedded common/breached corpus + leet/repeat/sequence structural
        rules + optional org-context banned words), PwnedPasswordsBreachChecker
        (opt-in HIBP k-anonymity range API — sends only a 5-char SHA-1 prefix,
        fail-OPEN to the local corpus on any network error), NullBreachChecker
        (explicit off). Swappable seam mirroring KeyProvider.
      - Services/AccountService.php — passwordPolicy() now also rejects breached
        passwords (new identity.password_breached message); BreachChecker injected
        (nullable → backward compatible, skipped when absent).
      - Config/Services.php — breachChecker() factory selects the provider via
        identity.breachCheck=local|pwned|off (+ identity.breachExtraWords); wired
        into accounts().
      - Controllers/SetPasswordController.php — friendly copy for a breached password.
      - tests/unit/BreachCheckerTest.php — local corpus, leet/repeat/sequence,
        extra words, strong-password pass-through, HIBP suffix match / padding-row
        ignore / prefix-only privacy / fail-open-to-fallback, and Null no-op.
    Verified via php -l (all app + tests pass) + the breach-checker unit test.

BUGFIX — POST /login production issues (rate-limit filter + Redis diagnostics)
    Two issues reported from live testing of the browser sign-in form.

    (1) 500 on POST /login — RateLimitFilter crashed reading the webhook/provider
        key dimension with `$uri->getSegment(3)`. CI4's URI::getSegment() THROWS
        HTTPException when the segment index is out of range (a trailing `?? ''`
        does NOT protect against it), so any /login POST with fewer than 3 path
        segments 500'd before the limiter even ran.
        Fix (app/Modules/Shared/Filters/RateLimitFilter.php): guard with
        getTotalSegments() — the provider dimension is now
        `$uri->getTotalSegments() >= 3 ? (string) $uri->getSegment(3) : ''`.

    (2) 429 "rate.limited" when Redis appears to be running — the `auth.login`
        policy fails CLOSED (failOpen=false, 5/60s), which is correct for an auth
        endpoint: if the limiter's backing store is unavailable it BLOCKS rather
        than allowing unlimited attempts. The store was coming back null even
        though redis-server was up, because RedisFactory::connect() gates on the
        phpredis PHP EXTENSION (`class_exists(Redis::class)`), not on the server.
        A running redis-server with the extension not enabled => null store =>
        fail-closed 429. (predis, a pure-PHP client, does NOT satisfy this gate.)
        ACTION REQUIRED on the affected host (e.g. Laragon): enable the phpredis
        extension — PHP → Extensions → redis, or add `extension=redis` to php.ini —
        restart PHP, then verify with `php -m | findstr redis` (Windows) /
        `php -m | grep redis`. No prod policy was weakened.
        Diagnostics added so this is self-evident next time (no client-facing
        change; FR-RL-006 non-disclosure preserved):
          - app/Modules/Shared/Support/RedisFactory.php — logs a distinct
            `critical` message for each failure mode: extension missing vs.
            connect() failure vs. thrown exception (with host:port).
          - app/Modules/Shared/RateLimiting/RateLimiter.php — when a policy has no
            usable store or the Lua eval throws, logs `critical` for fail-CLOSED
            policies (naming the policy + a Redis hint) and `warning` for fail-open;
            allow/block semantics are byte-for-byte unchanged.

    (3) Raw JSON shown in the browser on a 429 — RateLimitFilter's block response
        was JSON-only, so a browser form POST that got rate-limited saw a raw
        problem+json blob dumped in the viewport instead of a human page.
        Fix (RateLimitFilter): the block path now content-negotiates via
        WBS\Shared\Http\RequestNegotiator::wantsJson() (honors ?format, XHR header,
        and Accept). API/XHR callers still get the 429 problem+json envelope;
        browsers get a minimal self-contained HTML "Too many attempts" page
        (inline styles, no external assets) with the Retry-After seconds and a
        "go back" link. Same 429 status + Retry-After header either way.
        Verified with a stub harness: 7/7 (browser→HTML, api/xhr/?format→JSON,
        status/headers/Retry-After correct). All touched files pass `php -l`.

BUGFIX — POST /login returns 303 then the browser BLOCKS the sign-in (CSP)
    Symptom: login POST returns 303 (See Other) and the browser console shows the
    sign-in request "blocked"; the user never lands on /me.
    NOT an auth/rate-limit failure. 303 is the CORRECT success redirect for a form
    POST. WebSessionController::establishSession() returns
    `redirect()->to($return)`, and CI4 builds that Location: as an ABSOLUTE URL from
    Config\App::$baseURL. baseURL was hardcoded to `http://localhost:8080/`, but the
    app is reached via a different origin (Laragon .test vhost). CSP is enabled
    (app.CSPEnabled=true) with `form-action 'self'`; form-action governs the
    redirect target of a form submission, so a cross-origin Location: is refused by
    the browser -> "blocked". A bare scheme mismatch (http vs https) triggers it too.
    Fix (host confirmed from the deployment's env = https://public.test/):
      - app/Config/App.php — baseURL set to `https://public.test/`; allowedHostnames
        = ['public.test'] so URL generation stays same-origin as the browsed page.
      - .env (dev-local, NOT shipped in this archive) — app.baseURL matches, with a
        comment explaining the exact-match requirement.
    ROOT CAUSE (why an earlier baseURL guess did NOT fix it): the redirect target
    origin must EQUAL the origin of the page the form was submitted from. Setting
    baseURL to any other host (e.g. localhost:8080, or a wrong .test vhost) still
    trips CSP form-action 'self'. It must be the EXACT scheme+host+port in the
    address bar — here https://public.test.
    NOTE on HTTPS: this deployment runs forceGlobalSecureRequests=true behind a TLS
    terminator. Because the GET /login page loads without a redirect loop, TLS is
    reaching PHP and isHttps() (checks X-Forwarded-Proto) resolves true, so the
    wbs_session cookie gets Secure=true and returns over https. If you ever move the
    app behind a proxy that hides the scheme, populate Config\App::$proxyIPs and
    ensure the proxy forwards X-Forwarded-Proto=https.

BUGFIX — Login/web pages render UNSTYLED (CSP blocks inline CSS)
    Symptom: the sign-in and other web pages load but with no styling; the console
    reports style/CSP violations. This is a SEPARATE CSP directive from the earlier
    form-action redirect fix.
    Cause: Config\ContentSecurityPolicy had style-src / style-src-elem /
    style-src-attr = 'self'. 'self' does NOT permit inline CSS, and the web views
    are deliberately self-contained: 12 view files carry a <style> block AND there
    are 46 dynamic style="..." attributes (status-colour badges in me.php, mfa.php,
    sessions.php). All of it was blocked. Two constraints:
      - a nonce/hash can whitelist <style> ELEMENTS but CANNOT cover style=""
        ATTRIBUTES — so nonces alone can't fix these views;
      - autoNonce=true makes browsers IGNORE 'unsafe-inline' whenever CI4 injects a
        style nonce, so simply adding 'unsafe-inline' would have no effect while
        autoNonce is on.
    Fix (app/Config/ContentSecurityPolicy.php):
      - styleSrc / styleSrcElem / styleSrcAttr = ['self', 'unsafe-inline'].
      - autoNonce = false (otherwise the injected nonce voids 'unsafe-inline').
      - scriptSrc stays 'self' (there are ZERO inline scripts), so this is
        style-only and does not widen the script/XSS surface.
    Hardening path (optional, later): migrate every <style>/<script> to carry the
    {csp-style-nonce}/{csp-script-nonce} placeholder, refactor the 46 style=""
    attributes into classes, then set autoNonce=true and drop 'unsafe-inline'.

UX — "Login does nothing" on the public group page (it worked; it just looked
     like it didn't) + auth-aware public page
    Diagnosis: login was SUCCEEDING (303 + Set-Cookie: wbs_session; Secure;
    HttpOnly). The group page's "Member login" link was
    href="/login?return=/g/{slug}", so after sign-in the user was redirected BACK
    to the same public group page — which rendered identically whether or not you
    were logged in, so it looked like nothing happened.
    Two changes:
      A. app/Modules/Groups/Views/public_page.php — the logged-OUT login link now
         points at /login?return=/me/dashboard, so a fresh sign-in lands on the
         member dashboard instead of bouncing back to the public page.
      B. The public group page is now AUTH-AWARE. GroupPublicController::page()
         resolves the wbs_session cookie (via IdentityServices::sessions()->active
         + accounts()->findById — Groups already depends on Identity, acyclic) and
         passes a $viewer to the view. When signed in, the CTA shows
         "Signed in as <name>", a "My dashboard" button, and a "Log out" form
         (double-submit CSRF: the controller mints issueCsrf() and sets the
         matching wbs_csrf cookie on the response, HttpOnly/SameSite=Lax/Secure-
         over-HTTPS, so POST /logout passes the webcsrf filter). When signed out it
         shows "Member login" as before.
    Verified: php -l clean on both files; a view-render harness passes 10/10
    (logged-out shows Member login -> /me/dashboard, no logout controls; logged-in
    shows the name, My dashboard link, /logout form with the CSRF field, and hides
    Member login while keeping the Join button).
    FOLLOW-UP: auth-awareness now extends to ALL public group pages, not just the
    landing page. GroupPublicController resolves the viewer ONCE (cached) and a new
    htmlWithViewer() helper merges $viewer/$csrf into every browser view and
    attaches the logout CSRF cookie. A shared partial
    app/Modules/Groups/Views/_public_nav.php renders the auth-aware nav bar
    ("Groups" brand + either "Member login" or "Signed in as <name> / My dashboard /
    Log out") and is included at the top of:
      - public_directory.php  (GET /g)
      - public_join.php       (GET|POST /g/{slug}/join, incl. validation re-render)
      - public_join_done.php  (self-join confirmation)
    The landing page (public_page.php) keeps its themed in-hero controls (same
    variables). Logged-out login links all use /login?return=/me/dashboard so a
    fresh sign-in lands on the dashboard. JSON clients are unaffected (they short-
    circuit before HTML rendering). Verified: php -l clean on all 6 files; a render
    harness passes 32/32 (all four views x logged-in/out).

## Notes
- All 51 migrations and the seeders pass `php -l` (386 app PHP files total).
- The migrations are safe to re-run against a partially-applied DB (every CREATE uses
  IF NOT EXISTS; the geo_point step is idempotent). No manual DB cleanup required.
- docs/ contains the design/audit records (not applied to the DB); the Gamification
  ranking/roll-up runbook is docs/runbooks/ranking-rollup-enable.md.
