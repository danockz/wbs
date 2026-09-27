# WBS Platform — Fix Pack 2026-10-14

**Scope:** three related member/API gaps, closed end-to-end.

1. **Every new registration gets a sponsor (upline).** The hierarchical group
   leader — or their delegate — is now the automatic sponsor for a new
   prospect/member, on **all** account-creation paths.
2. **Member profile photo.** Added an optional photo plus a resource-light,
   network-free fallback avatar. (There was no photo field anywhere before.)
3. **`/openapi.json` rebuilt.** The committed spec was badly stale
   (**244 paths vs 375 real routes**); it now reflects the true route table
   (**378 paths / 435 operations**, incl. the new endpoints).

Builds on `wbs-fixes-2026-10-13.zip`. Apply by overlaying onto that tree
(`unzip -o`). **One additive migration**; no new dependencies.

> Apply order: unzip over your working copy, run `php spark migrate`, then (if the
> PHP toolchain is present) `php spark openapi:generate` — though the committed
> `public/openapi.json` is already up to date and a freshness test enforces it.

---

## 1 — Sponsor on every registration (FR-MEM-001)

**The gap.** Only public group self-join recorded a sponsor. The main
`POST /register` path (`AccountService::register()`) created **no** sponsorship at
all, so ordinary registrations were sponsor-less.

**The rule (single source of truth: `Referrals\Services\SponsorResolver`).** For a
new member, resolve the sponsor by the first non-empty of:

1. an **explicit** `sponsor_id` — a referral referrer, or `?sponsor=` on the
   registration link — when it is a valid, non-self user in the org;
2. the **target group's leader** (`groups.leader_user_id`);
3. the group's earliest active **`leader` / `admin` / `coordinator`** member
   (the "delegate");
4. the **nearest ancestor** group (walking up the closure table, then the
   materialized `path` as a fallback) that has such a leader;
5. the **organization root leader** (earliest active leadership member) as a last
   resort.

It never returns the new member themselves, never an invalid/foreign explicit
sponsor, and returns `null` only when the org has no leader at all (a brand-new
org's very first account). The edge is written through
`SponsorshipService::assign()`, so the existing **acyclic** + **single-active**
invariants hold.

**Wired into every path that mints a member:**

- `AccountService::register()` — best-effort, **after** the account transaction
  commits (a sponsor hiccup never rolls back a valid account). `AuthController`
  now forwards `sponsor_id` / `group_id` (body or `?sponsor=` / `?group=`). The
  result payload gained `sponsor_id`.
- `ContactBookService::ensureContactUser()` (staff bulk / contact promotion) —
  prefers the contact **owner**, else the **assigned group's** leader; **never
  overrides** an existing active sponsor.
- Public group self-join already recorded a sponsor; the doc now points to the
  same rule.

No new tables/roles — it reuses `groups.leader_user_id`, `group_members.role`,
`group_closure`, and the existing `sponsorships` graph. Identity → Referrals is a
one-way dependency (no cycle); the collaborators are injected as **optional**
nullables, so isolated contexts still construct `AccountService` fine.

**Files:** `app/Modules/Referrals/Services/SponsorResolver.php` (new),
`app/Modules/Referrals/Config/Services.php` (new `sponsorResolver` binding;
`contactBook` now wired), `app/Modules/Identity/Services/AccountService.php`,
`app/Modules/Identity/Config/Services.php`,
`app/Modules/Identity/Controllers/AuthController.php`,
`app/Modules/Referrals/Services/ContactBookService.php`.

**Test:** `app/Modules/Referrals/Services/tests/sponsor_resolver_test.php`
(11 assertions, in-memory DB fake) — explicit-wins, leader, delegate, ancestor
climb, path fallback, org-root, no-leader → null, and self-exclusion.

---

## 2 — Member profile photo (resource-light)

**The gap.** No `photo`/`avatar` field existed on `users` or anywhere else.

**What was added:**

- **Migration** `2026-09-13-000066_AddUserProfilePhoto` — nullable
  `profile_photo_url`, `profile_photo_source` (`url|upload`, forward-compatible
  with a later upload pipeline), `profile_photo_updated_at`. Idempotent
  add-if-absent (mirrors `AddGroupLocation`).
- **`Shared\Support\Avatar`** — a **deterministic, dependency-free** fallback:
  a tiny inline **SVG initials** avatar (up to two initials from the display name
  / email; a stable palette colour hashed from the user id). No DB, no GD, no
  network, so it renders even in the network-less in-app preview and is
  cacheable/ETag-able. Hostile display names are XML-escaped — an avatar can never
  inject markup.
- **Self-service endpoints** (`WebSessionController`, CSRF-guarded):
  - `POST /me/photo` — set the photo URL (a hosted `http(s)` image or a `data:`
    image URI; validated, ≤512 chars). **No upload pipeline** by design.
  - `POST /me/photo/remove` — clear it (renders fall back to initials).
  - `GET /me/avatar` — always returns an image: the photo (302 redirect) when
    set, else the initials SVG (`image/svg+xml`, `Cache-Control: private,
    max-age=300`). Gives clients one stable `<img src="/me/avatar">`.
- The signed-in **`/me`** page now shows the resolved avatar; a new
  `Identity.me.avatarAlt` string is translated across all six locales
  (`en, fr, es, pt, zh, ar`) — catalog parity stays green.

**Files:** `app/Modules/Shared/Support/Avatar.php` (new),
`.../Identity/Services/AccountService.php` (`setProfilePhoto` / `removeProfilePhoto`),
`.../Identity/Controllers/WebSessionController.php` (`setPhoto` / `removePhoto` /
`avatar`), `.../Identity/Views/me.php`, the six `Identity` language files,
`app/Config/Routes.php`, and `Shared/Navigation/MenuCoverage.php` (excludes the
image endpoint from menu-coverage triage).

**Test:** `app/Modules/Shared/Support/tests/avatar_test.php` (28 assertions).

---

## 3 — `/openapi.json` rebuilt

**The gap.** The committed spec had **244 paths** but the route table has **375**
(≈130 endpoints missing), and several handlers had moved (Gamification split into
`AwardsController`). It was stale.

**Rebuilt** from the canonical route table → **378 paths / 435 operations**
(includes the 3 new profile-photo routes). `spark openapi:generate` can't run in a
`vendor/`-less environment, so the bundled **DB-free** `tools/gen_openapi.py`
(the documented fallback) produced the artifact — and it was **realigned to be
byte-identical to the canonical PHP generator**:

- bare app handlers (`Home::index`) are qualified to `App\Controllers\...` and
  tagged **`System`** (not `General`), matching `spark`;
- **array-form filters** (`'filter' => ['auth','webcsrf']`) are now parsed, so
  13 previously-misclassified routes correctly show `security` / permissions.

Every remaining difference vs the old spec is a **real** route/handler change, not
a formatting drift.

**Files:** `public/openapi.json` (regenerated), `tools/gen_openapi.py` (parity
fixes).

**Test:** `app/Modules/Shared/Support/tests/openapi_fresh_test.php` (10
assertions) — regenerates the spec and **fails if the committed file is stale**,
so this can't rot again; also spot-checks the new endpoints, the `System` tag, and
that no `General`-tagged operations remain.

---

## Verification

- `php tests/run-standalone.php` — **32 files, 1407 assertions, 0 failed**
  (was 1357; +3 new test files, +1 fixed autoload in the Identity view test).
- `php -l` clean across every touched PHP file and all six language files.
- Catalog parity (`catalog_parity_test.php`) and menu coverage
  (`menu_coverage_test.php`) both green with the new key + excluded route.

## Delivery

`wbs-fixes-2026-10-14.zip` — cumulative overlay on `wbs-fixes-2026-10-13.zip`
(**380 files**; **10 added**, the rest updated in place). Apply with `unzip -o`.
