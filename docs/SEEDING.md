# Seeding — fire up the application

One command seeds a runnable deployment. The master seeder
`App\Database\Seeds\DatabaseSeeder` runs every module seeder in dependency order.

## Quick start

```bash
# 1. Install deps + create the schema (once):
composer install
php spark migrate --all

# 2. Seed foundation data (production-safe):
php spark db:seed "App\\Database\\Seeds\\DatabaseSeeder"

# 3. (Optional) include demo/sample content — NEVER in production:
wbs.seedDemo=1 php spark db:seed "App\\Database\\Seeds\\DatabaseSeeder"
#   …or:
php spark db:seed "App\\Database\\Seeds\\DatabaseSeeder" --demo
```

Every seeder is **idempotent** (upsert on natural keys), so re-running converges
the database to the seeded baseline without duplicating rows.

## What gets seeded

### Foundation (always — safe for production)
| Order | Seeder | Seeds |
| --- | --- | --- |
| 1 | `AccessControl\…\RbacBootstrapSeeder` | Default `wbs` organization, permission catalogue, system roles, default identity policy |
| 2 | `AccessControl\…\AdminAccountSeeder` | **Admin login** + org-wide `org_admin` assignment + current gamification season |
| 2b | `Admin\…\AdminConfigSeeder` | **All administrative configuration** — see below |
| 3 | `Geo\…\GeoReferenceSeeder` | Regions, subregions, countries (Ghana + neighbours fully populated) |
| 4 | `Groups\…\GroupKindSeeder` | Group-kind taxonomy |
| 5 | `Journey\…\JourneyStageSeeder` | Journey stages |
| 6 | `Journey\…\MembershipRuleSeeder` | Membership/integration rules (reference stages) |
| 7 | `Journey\…\JourneyGamificationSeeder` | Journey-linked gamification rules |
| 8 | `Gamification\…\WbsActivityCatalogSeeder` | Win-Build-Send activity catalogue |
| 9 | `Notifications\…\NotificationTemplateSeeder` | Templates for every category the platform sends |
| 10 | `Integrations\…\AdapterCatalogSeeder` | Provider adapter catalogue |
| 11 | `Events\…\CertificateTemplateSeeder` | Certificate templates |

#### `AdminConfigSeeder` — administrative configuration (all scopes)

Production-safe defaults for every configuration surface, plus the scope anchors
everything hangs off. Fully idempotent (safe to re-run / run under
`migrate:refresh`). It seeds:

- **Canonical group hierarchy** — the full 7-level ladder with correct
  closure/path/depth: `WBS National → Greater Accra Region → Accra Central Area →
  Ridge Local Assembly → Ridge Fellowship → Ridge Senior Cell → Ridge Cell 1`.
- **`platform_settings`** — org-wide admin defaults (locale, timezone, currency,
  session idle, MFA-for-admins, location-consent, ranking measure, check-in
  credit mode, …).
- **`feature_flags`** — org-wide defaults, all **OFF** (gating is opt-in per the
  hierarchical-config model), plus one group-scoped override to demonstrate that
  a group row wins over the org default.
- **`gamification_config`** — extra org-level keys (season rollover, group/
  individual ranking scope).
- **`group_configurations`** — capability configs across levels exercising every
  inheritance mode (`ancestor_default_child_override`, `inherit_only`,
  `child_owned`, `not_inheritable`).
- **`payment_provider_configs`** — org-wide (MTN MoMo, Stripe) + one group-scoped
  provider. Only NON-secret metadata; real credentials stay external.
- **`stream_giving_configs`** — a sample stream with giving config (giving OFF by
  default; opt-in).
- **`role_assignments` in all four RuBAC scope modes** — `self`,
  `self_and_descendants`, `descendants_only`, and `groups` (with the hand-picked
  set recorded in `grant_scope_groups`). These use non-admin roles on the seeded
  admin as subject, so they demonstrate scope without widening real authority.

> These grants are demonstrative. Review/remove them before production if you do
> not want the admin account carrying the sample event-organizer / moderator /
> analyst / finance grants.

### Demo (only with `wbs.seedDemo=1` / `--demo`)
Sample users, groups, posts, campaigns, outreach prospects and a chained referral
tree — illustrative content for a dev/staging walkthrough.

## First login

`AdminAccountSeeder` creates an administrator you can sign in with immediately:

| Env var | Default (dev only) | Purpose |
| --- | --- | --- |
| `wbs.adminEmail` | `admin@wbs.local` | Admin login email |
| `wbs.adminPassword` | `ChangeMe!Admin1` | Initial password — **set this and rotate on first login** |
| `wbs.adminName` | `Platform Administrator` | Display name |

Related bootstrap env (read by `RbacBootstrapSeeder`):
`wbs.organizationId`, `wbs.organizationTimezone` (default `Africa/Accra`),
`wbs.maxGroupDepth` (default 9), `wbs.defaultPhoneRegion` (default `GH`).

> ⚠️ If `wbs.adminPassword` is unset, the seeder logs a warning and uses the
> default password. Never leave that in a real deployment.

## Running a single seeder

```bash
php spark db:seed "WBS\\Geo\\Database\\Seeds\\GeoReferenceSeeder"
```

## Notes
- The `GeoReferenceSeeder` ships a pragmatic starter country set. To load the full
  ISO dataset, replace/extend it — the schema and lookups are unchanged.
- `NotificationTemplateSeeder` seeds English (`en`) templates; the translation-merge
  layer falls back to English for locales without an override, so this is the
  correct baseline. Add locale rows to override per language.
- Logic is covered by `app/Database/Seeds/tests/bootstrap_seeders_test.php`
  (idempotency, admin/role/season, templates, geo, orchestration order, demo gate).

## Troubleshooting: `Unknown column …` on seed or on `/admin`

If `db:seed` fatals with `Unknown column 'stage_code' in 'field list'` (INSERT into
`activity_categories`), or `/admin` fatals with `Unknown column 'ra.scope_mode'`
(SELECT on `role_assignments`), the schema is behind the code: a migration that
should have added the column silently no-op'd.

**Root cause (fixed 2026-09-16).** CI4's `BaseConnection` caches the table/column
list per connection (`$dataCache`). Raw `query('CREATE …')` / `query('ALTER …')`
never invalidate it. On a `migrate:refresh` (down + up in one process) the
down-phase primed the cache, the up-phase recreated the table, and a guarded
`fieldExists()` then read the **stale** list and skipped its `ADD COLUMN`. The
migration ORDER was never the problem (CI4 sorts all namespaces globally by
timestamp) — the guard was reading cached schema.

**Fix.** Every migration that branches on `tableExists()` / `fieldExists()` now
calls `$this->db->resetDataCache()` at the top of `up()` and `down()`, so its
guards read live schema. If you write a new guarded migration, do the same — it
is enforced by `app/Modules/Shared/Database/tests/migration_schema_conformance_test.php`,
which also reconstructs the column set from the migration corpus (DB-free) and
asserts every column the service/seeder layer references actually exists.

**Recovering an already-drifted database.** The `resetDataCache()` fix only helps
future migrations; a DB that already ran the buggy version is missing the columns.
Re-run the affected migrations against it:

```bash
php spark migrate:refresh        # dev: safe, rebuilds the whole schema
# or, to avoid data loss, add the columns by hand, then re-seed:
#   ALTER TABLE activity_categories  ADD COLUMN stage_code VARCHAR(60) NULL AFTER phase;
#   ALTER TABLE gamification_rules   ADD COLUMN stage_code VARCHAR(60) NULL AFTER phase;
#   ALTER TABLE follow_up_types      ADD COLUMN stage_code VARCHAR(60) NULL AFTER phase;
#   ALTER TABLE role_assignments      ADD COLUMN scope_mode VARCHAR(24) NOT NULL DEFAULT 'self' AFTER scope_group_id, ADD COLUMN include_crosscut TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_mode;
#   ALTER TABLE access_requests       ADD COLUMN scope_mode VARCHAR(24) NOT NULL DEFAULT 'self' AFTER scope_group_id, ADD COLUMN include_crosscut TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_mode;
#   ALTER TABLE delegations           ADD COLUMN scope_mode VARCHAR(24) NOT NULL DEFAULT 'self' AFTER scope_group_id, ADD COLUMN include_crosscut TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_mode;
#   ALTER TABLE break_glass_sessions  ADD COLUMN scope_mode VARCHAR(24) NOT NULL DEFAULT 'self' AFTER scope_group_id, ADD COLUMN include_crosscut TINYINT(1) NOT NULL DEFAULT 0 AFTER scope_mode;
```
