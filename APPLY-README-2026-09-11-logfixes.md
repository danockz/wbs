# Apply pack — fixes for issues in `log-2026-09-11.md`

Date: 2026-09-11
Zip: `wbs-fixes-2026-09-11-logfixes.zip`

The attached log surfaced three distinct problems. This pack fixes the two that
live in our code and documents the third (a stray file on your machine).

---

## Issue 1 — CRITICAL: `MembershipRuleSeeder` crashed — "Unknown column 'include_crosscut'"

```
ERROR --> Unknown column 'include_crosscut' in 'field list'
… MembershipRuleSeeder.php(169): CodeIgniter\Database\BaseBuilder->insert([...])
```

**Cause.** The membership-rule seeder (shipped in
`wbs-fixes-2026-09-11-membership-rules-seed.zip`) inserted `scope_mode` and
`include_crosscut` unconditionally. Those columns are added to the `rules` table
by RuBAC migrations **000054** (`scope_mode`) and **000056** (`include_crosscut`).
On your DB those migrations had not been applied, so the `rules` table has
neither column and the INSERT failed. A seeder must never hard-depend on an
optional/late-added column.

**Fix.** `MembershipRuleSeeder` is now **column-defensive**: it checks
`fieldExists('scope_mode','rules')` / `fieldExists('include_crosscut','rules')`
and includes each field only when present (the DB defaults — `self` / `0` — apply
otherwise). The seeded rules are still org-wide and behave identically once the
migrations catch up.

> **Recommended:** also run `php spark migrate` — the two columns exist in our
> migration set; your schema is simply a couple of migrations behind. The seeder
> fix means it will succeed either way, but a fully-migrated schema is the
> intended target.

**New regression case** (folded into the existing e2e test): seeding against a
`rules` table with `include_crosscut` / `scope_mode` absent now (a) does not
throw, (b) still writes all 6 rules, (c) omits the missing columns, and (d) the
full progression chain still works (the engine defaults `scope_mode=self`).

---

## Issue 2 — PHP 8.2+ dynamic-property deprecation on every request

```
WARNING --> [DEPRECATED] Creation of dynamic property
  WBS\Shared\Http\WbsIncomingRequest::$wbsLocaleDir is deprecated … LocaleFilter.php:75
WARNING --> [DEPRECATED] Creation of dynamic property
  WBS\Shared\Http\WbsIncomingRequest::$wbsCsrf is deprecated … WebCsrfIssueFilter.php:62
```

**Cause.** `WbsIncomingRequest` exists precisely to *declare* the request-scoped
context so PHP 8.2+ doesn't warn about dynamic properties. It declared the six
auth props (`wbsUserId`, `wbsOrgId`, …) but `LocaleFilter` and
`WebCsrfIssueFilter` later began assigning four more — `wbsLocale`,
`wbsLocaleDir`, `wbsLocaleWrite`, `wbsCsrf` — that were never declared, so each
fired a deprecation on every server-rendered page.

**Fix.** Declared all four missing properties on `WbsIncomingRequest` with
docblocks. No behaviour change; the warnings stop.

**New regression guard** (`app/Modules/Shared/Http/tests/incoming_request_props_test.php`):
statically scans the whole app for `$request->wbs* =` assignments and fails if
any assigned name lacks a declaration on `WbsIncomingRequest`. `php -l` can't
catch this (runtime notice, not syntax), so this guard prevents the drift from
recurring. Verified it fails when a declaration is removed.

---

## Issue 3 — (your machine) duplicate test class: `Cannot redeclare Tests\Unit\CustomAdapterSdkTest`

```
CRITICAL --> ErrorException: Cannot redeclare class Tests\Unit\CustomAdapterSdkTest
  (previously declared in …\tests\CustomAdapterSdkTest.php:33)
  in …\tests\unit\CustomAdapterSdkTest.php on line 33
```

**Not a code defect — nothing to apply.** This test class exists in our repo at
exactly ONE path: `tests/unit/CustomAdapterSdkTest.php`. Your working copy also
has a **stray duplicate** at the `tests/` root (`tests/CustomAdapterSdkTest.php`)
declaring the same `Tests\Unit\CustomAdapterSdkTest`. Because `phpunit.dist.xml`
scans `./tests` recursively, both files load and PHP fatals on the redeclare.
(It also showed up on a plain `GET /` because the CRITICAL is the shutdown
handler reporting the earlier CLI fatal.)

**Action (manual, on your machine):** delete the stray root-level copy —
```
del tests\CustomAdapterSdkTest.php        # keep tests\unit\CustomAdapterSdkTest.php
```
Likely an accidental copy/move; nothing in the repo references the root path. If
you have other such stray duplicates, this one-liner finds them:
```
# PowerShell: list test files whose basename appears more than once under tests\
Get-ChildItem -Recurse tests -Filter *Test.php | Group-Object Name | Where-Object Count -gt 1
```

---

## Files in this pack (5)

| File | Change |
|---|---|
| `app/Modules/Journey/Database/Seeds/MembershipRuleSeeder.php` | Column-defensive insert (Issue 1). |
| `app/Modules/Journey/Database/Seeds/tests/membership_rule_seeder_e2e_test.php` | +regression case 8b (Issue 1); now 34 assertions. |
| `app/Modules/Shared/Http/WbsIncomingRequest.php` | Declares wbsLocale / wbsLocaleDir / wbsLocaleWrite / wbsCsrf (Issue 2). |
| `app/Modules/Shared/Http/tests/incoming_request_props_test.php` | NEW static guard (Issue 2). |
| `APPLY-README-2026-09-11-logfixes.md` | This file. |

No migration, no dependency, no route changes.

## Apply

```
unzip -o wbs-fixes-2026-09-11-logfixes.zip -d /path/to/wbs-platform
php spark migrate                     # recommended: brings rules table to scope_mode + include_crosscut
composer seed:membership-rules        # now succeeds regardless
php tests/run-standalone.php          # expect: == STANDALONE SUITE GREEN ==
# then, on your machine, delete the stray tests\CustomAdapterSdkTest.php (Issue 3)
```

## Verify
- Standalone suite: **80 files / 3270 assertions / 0 failed**.
- Targeted:
  ```
  php app/Modules/Journey/Database/Seeds/tests/membership_rule_seeder_e2e_test.php  # 34 passed, 0 failed
  php app/Modules/Shared/Http/tests/incoming_request_props_test.php                 # 8 passed, 0 failed
  ```

## Relationship to other packs
- **Supersedes** `wbs-fixes-2026-09-11-membership-rules-seed.zip` for the two
  files it also touched (`MembershipRuleSeeder.php`, the e2e test) — this pack has
  the newer, column-defensive versions. If you have not yet applied that pack,
  apply it first (for `composer.json`'s `seed:membership-rules` wiring), then this
  one on top; or just take the newer files here.
- Independent of `wbs-fixes-2026-09-11-combined.zip` (disjoint files).
