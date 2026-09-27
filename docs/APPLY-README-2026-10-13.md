# WBS Platform — Fix Pack 2026-10-13

**Scope:** Lock in the coverage that already exists. A pre-flight audit found that
the platform's **standalone `*_test.php` suite — 29 scripts, 1357 assertions** (all
i18n tests, the menu anti-drift/floor/signed-word/headers guards, service-key
collision checks, and both new catalog-parity sweeps) — **was never run by any
gate**: `composer test` and CI both run `vendor/bin/phpunit`, and none of these
scripts are PHPUnit `TestCase`s. This pack makes that suite first-class and gates
it in `composer qa`, the pre-commit hook, and CI.

Builds on `wbs-fixes-2026-10-12.zip` (menu language-awareness). No schema changes,
no new dependencies, no runtime/app-code changes — **tooling and CI only**.

---

## Why this, now

Everything shipped over the last two weeks (view i18n for 11 modules, the merge
layer, menu language-awareness) is verified by these standalone scripts. Until now
they only ran when someone typed each path by hand. That means a future change
could break i18n parity, menu↔route drift, or a service-key collision and **still
merge green**, because the automated gates never saw those tests. Wiring them in is
the highest-leverage, lowest-risk step available: it protects all prior work and
costs nothing at runtime.

---

## What changed

### New — aggregating runner
```
tests/run-standalone.php
```
Discovers every `*_test.php` under `app/` (excluding `*bench.php`), runs each in its
**own isolated php subprocess** (they redefine global harness functions like
`chk()`/`lang()` and call `exit()`, so they cannot share a process), parses each
script's `N passed, M failed` summary, and aggregates one repo-wide total. Exits
non-zero if **any** script fails or emits no parsable summary. Flags: `--filter=<substr>`,
`--quiet`, `--list`.

```
Files: 29 run, 0 failed · Assertions: 1357 passed, 0 failed · ~0.9s
```

### Wired into every gate
- **`composer.json`** — new scripts `test:standalone` and `i18n:parity`; the
  `qa` script now ends with `@test:standalone` (so `composer qa` =
  lint → menu:check → PHPStan → PHPUnit → standalone suite).
- **`.githooks/pre-commit`** — now, in addition to the menu-coverage check:
  runs the **i18n parity** sweep when any `Language/` file is staged, and runs the
  **standalone tests scoped to the touched module(s)** (fast commits, real
  coverage). Full suite still runs in CI.
- **`.github/workflows/ci.yml`** — adds a "Standalone test suite (framework-free)"
  step to the lint/static-analysis job. No DB/Redis needed — these checks are pure.

### Docs
- `tests/README.md` — new section explaining the standalone suite, the runner, its
  flags, where it runs automatically, and how to add a new standalone test
  (drop a `*_test.php` that prints a summary and exits non-zero; discovery is
  automatic).

---

## How to apply

```bash
unzip -o wbs-fixes-2026-10-13.zip -d /path/to/project-root

# one-time per clone, to activate the pre-commit hook:
git config core.hooksPath .githooks
```
No migrations, no `composer install` needed (no new deps), no env changes.

---

## Verification

```bash
# the whole standalone suite in one command
composer test:standalone         # or: php tests/run-standalone.php
# => Files: 29 run, 0 failed · Assertions: 1357 passed, 0 failed

# scoped runs
php tests/run-standalone.php --filter=i18n
php tests/run-standalone.php --list

# the full local gate now includes it
composer qa
```

The runner was validated to **fail (exit 1) on an injected assertion** and return
to **green (exit 0)** once reverted — i.e. it is a real gate, not a rubber stamp.

---

## Notes / non-goals

- No application code changed: this pack is runner + composer scripts + hook + CI +
  docs. Runtime behaviour is identical.
- The PHPUnit suites (`tests/unit`, `tests/integration`) are unchanged and still run
  in the CI `tests` job against MariaDB/Redis; the standalone runner complements
  them, it does not replace them.
- Adding a module or a translation key without translating every locale, or letting
  the menu drift from the route table, now **fails a gate** instead of merging
  silently.
