# WBS Platform — Add-on: Chained Leadership Seeder (2026-09-10)

**Scope:** one additive seeder (plus its test). No schema changes, no new
dependencies, no edits to existing files. Builds on `wbs-fixes-2026-10-14.zip`;
overlay onto that tree with `unzip -o`.

This delivers a way to bootstrap the organization's **leadership spine** so the
"every member has a sponsor / upline" rule (shipped 2026-10-14) has a leader to
resolve to at every level of the group hierarchy from day one.

---

## What it does

`ChainedAccountSeeder` walks the system's configured group hierarchy **top-down**
and creates exactly one leader account per level, **chaining** the sponsorships so
each group's leader is sponsored by the leader of the group directly above it:

```
National leader                 (root — intentionally sponsor-less)
  └─ Region leader              sponsored by National leader
      └─ Area leader            sponsored by Region leader
          └─ Assembly leader    sponsored by Area leader
              └─ Fellowship leader      sponsored by Assembly leader
                  └─ Senior Cell leader sponsored by Fellowship leader
                      └─ Cell leader    sponsored by Senior Cell leader
```

The result is a fully-connected upline from any cell leader up to the national
leader. The instant a real member self-registers under any group,
`SponsorResolver` finds a leader and the sponsorship graph is already connected.

The chain honours the WBS model already used by `OutreachDemoSeeder`:
**National → Region → Area → Local Assembly → Fellowship → Senior Cell → Cell**
(fellowship and senior_cell come **before** cell).

## How it stays consistent with the running system (no shortcuts around invariants)

- **Groups** are created through `GroupService::create()` — so `depth`, `path`,
  and `group_closure` stay correct and the org's `max_group_depth` is respected.
- **Leader accounts** are created through the real `AccountService::register()`
  path, exercising its automatic-sponsor linking: the parent group's leader is
  passed as the explicit `sponsor_id` together with the `group_id`, and
  `SponsorshipService::assign()` writes the **acyclic**, **single-active** edge.
  The national (root) leader has no parent, so it is the one intentionally
  sponsor-less account.
- Each leader is recorded as `groups.leader_user_id`, given a `leader`
  group membership via `GroupMembershipService` (`type=leader`, `source=system`),
  and assigned the org-leader role **scoped to that group** with
  `include_descendants=1` — so leadership scope matches the tree. That insert is
  guarded by `tableExists('role_assignments')` and uses exactly the columns the
  ACL migrations create (`status`, `include_descendants`, `source='seed'`,
  `effective_from/to`).

## Idempotent & configurable

- Idempotent: keyed off a sentinel slug (`leadership-national`) on the root node;
  safe to re-run.
- Password for the seeded leaders: `ChainSeed!2026` (meets the ≥12-char +
  upper/lower/digit password policy).
- Leader emails: `<local>.leader@wbs.test`, unique per level.
- Override the chain with the `wbs.leadershipChain` env — a comma list of
  `type:Name` pairs — when a deployment uses different level names.
- `verifyAndReport()` prints, per node, the resolved sponsor and the deepest
  leader's full upline depth, so a run is self-checking.

## Run order

After migrations and the RBAC bootstrap (so roles/org exist):

```
php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
php spark db:seed 'WBS\Referrals\Database\Seeds\ChainedAccountSeeder'
```

---

## Files

- `app/Modules/Referrals/Database/Seeds/ChainedAccountSeeder.php` — the seeder.
- `app/Modules/Referrals/Database/Seeds/tests/chained_account_seeder_test.php` —
  standalone test (12 assertions).

## Test

`chained_account_seeder_test.php` proves the two things that make the seeder
correct, in isolation (pure — no DB, no framework boot):

1. **Chain shape + ordering** — the configured chain is well-formed (four fields
   per level), national is the root, leader emails are unique, and fellowship
   precedes senior_cell precedes cell.
2. **The chaining algorithm** — driven through the *real* `SponsorResolver` over a
   faithful in-memory DB, it reproduces exactly what
   `register(sponsor_id=parent_leader, group_id)` does per level and asserts: the
   root is sponsor-less; each leader is sponsored by the one above; the deepest
   leader's full upline spans every ancestor nearest-first; and a brand-new member
   under the deepest group still resolves that group's leader (the chain
   self-heals when an explicit sponsor is absent).

Runs in the standalone suite:

```
php tests/run-standalone.php
```

Full suite after this add-on: **33 files, 1419 assertions, 0 failed.**
