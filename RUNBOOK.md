# WBS Platform — Developer Runbook

Operational guide for standing up, seeding, and running the Win–Build–Send
platform locally. Pairs with `README.md` (overview) and `REBUILD_STATUS.md`
(build log / module inventory).

**Stack:** PHP 8.2+ (ext: `sodium`, `mysqli`/`intl`, `redis`) · CodeIgniter 4.7 ·
MySQL 8 / MariaDB (utf8mb4, UTC) · Redis · queue workers.

---

## 1. Prerequisites

```bash
php -v            # 8.2+ with sodium, mysqli, redis extensions
composer -V
mysql --version   # or mariadb
redis-cli ping    # PONG
```

Install PHP dependencies:

```bash
composer install
```

## 2. Configure `.env`

Copy the template and fill in real values (the template has placeholders only,
never real secrets):

```bash
cp .env.example .env
# generate a fresh envelope key:
php -r "echo 'hex2bin:'.bin2hex(sodium_crypto_secretbox_keygen()).PHP_EOL;"
```

Key settings (already scaffolded in `.env`):

| Key | Meaning |
|---|---|
| `database.default.*` | primary DB (`wbs_platform`) |
| `database.tests.*` | test DB (`wbs_platform_test`) |
| `redis.host/port/password/database` | rate-limit + cache store |
| `encryption.key` | libsodium key for the SecretBox envelope (MFA/credentials) |
| `wbs.organizationId` | **fixed single-org UUID** — the whole app resolves this |
| `wbs.organizationTimezone` | org tz for gamification season rollover (e.g. `Africa/Accra`) |
| `wbs.maxGroupDepth` | group hierarchy depth cap (1–9) |
| `session.absoluteTtl` | server-session absolute lifetime, seconds (default `1209600` = 14d) |
| `session.idleTtl` | server-session idle timeout, seconds (default `28800` = 8h) |
| `session.hashSalt` | HMAC salt for session IP/UA hashes (falls back to `encryption.key`) |

> **Important:** the runtime reads `getenv('wbs.organizationId')` as the single-org
> fallback everywhere. The RBAC seeder seeds the org with exactly this id, so the
> two always agree. If you change the id, re-seed a fresh DB.

Signing/secret envs (behaviour differs — do **not** treat both as optional):

| Env | Behaviour if unset | Action |
|---|---|---|
| `CHECKIN_SIGNING_KEY` | **Optional.** Check-in still works, but QR nonces are HMAC-signed with a dev default key that is **public in the source**. | Set a strong random value in production: `php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'` |
| `webhook.secret.<provider>` / `WEBHOOK_SECRET` | **No default; FAIL-CLOSED.** An empty secret is never valid, so **every inbound payment webhook is rejected** (payments won't confirm). | **Required** wherever real provider callbacks are accepted. Value = the exact secret configured on the provider (e.g. Stripe endpoint signing secret) — copy it from the provider, don't invent it. `webhook.secret.stripe` (dotted, per-provider) wins; `WEBHOOK_SECRET` is the global fallback. |

Format in `.env`: `key = value` (dotted keys go in literally, e.g.
`webhook.secret.stripe = whsec_…`); uncomment the line to activate it.

## 3. Create databases

```sql
CREATE DATABASE wbs_platform      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE wbs_platform_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 4. Migrate

Migrations are per-module and discovered via each module namespace. Run all:

```bash
php spark migrate --all
```

There are **51 migrations** (`2026-09-01-000001` … `2026-09-08-000051`, with
`000004` intentionally unused). `000047` adds the group public-profile columns
and `000048` adds `groups.join_policy` — both for the group-specific landing
pages and public self-join (`/g`, `/g/{slug}`, `/g/{slug}/join`); `000049` adds
`sessions.expires_at` (+ index) for absolute session expiry (backfills live rows
to created_at + 14d); `000050` adds `credential_setup_tokens` (hashed, single-use,
expiring invite / password-reset tokens) backing the set-password / accept-invite
flow (`GET|POST /set-password`) that activates passwordless self-joined members;
`000051` adds the group-lifecycle model (FR-GRP-005) — widens `groups.status`,
adds `status_reason/status_changed_at/status_changed_by/archived_at/dissolved_at/
merged_into_id`, and creates the append-only `group_lifecycle_transitions`
evidence table. The core build is `000001`–`000024` (through
Community, Streaming, Meetings, Reporting, Admin, refresh tokens, stream
overlays); the Phase-completion set `000025`–`000040` added SRS features — event
feedback/certificates/reports/media, access-request workflow, identity
uniqueness + account lifecycle, in-stream giving, group memberships, stream
relay-failure response, and provider circuit-breaking/quota
(`000040_CreateProviderReliability`); and the gamification ranking set
`000041`–`000046` added configurable W-B-S activities, achievements/ranks,
ranking roll-up + group attribution (`000045`), and ACL catalog management
(`000046`).

Discovery is by module namespace (each `WBS\<Module>\Database\Migrations`), so
`--all` is required — a plain `php spark migrate` only runs the default
namespace. Count them with:

```bash
find app/Modules -path '*Database/Migrations/*.php' | wc -l   # -> 51
```

> Test DB: DB-dependent test classes use `DatabaseTestTrait` with `$migrate=true`,
> so they provision the tests schema themselves. Avoid `php spark migrate -g tests`
> (it desyncs tracking — see `REBUILD_STATUS.md` › Errors).

## 5. Seed

Order matters — RBAC first (creates the org + roles), then the rest. Module
seeders need the **fully-qualified class name**:

```bash
# 1) Organization, permission catalogue, roles + grants (idempotent).
#    Also seeds the default identity policy ("*" row: min age 13, phone region
#    GH, email+phone unique - SRS FR-ID-002). Override with env
#    wbs.defaultPhoneRegion; tune per country via the /identity/policies API.
php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'
# Configurable Win-Build-Send activity catalog (categories, activities, follow-ups, config)
php spark db:seed 'WBS\Gamification\Database\Seeds\WbsActivityCatalogSeeder'

# 2) UPAF adapter catalogue (11 approved adapters, honest capabilities)
php spark db:seed 'WBS\Integrations\Database\Seeds\AdapterCatalogSeeder'

# 3) OPTIONAL demo content so dashboards render (NOT for production)
php spark db:seed 'WBS\Admin\Database\Seeds\DemoDataSeeder'

# 4) OPTIONAL group-project (campaign) demos — see section 5a
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignBaseDemoSeeder'
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignDemoSeeder'
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignSubtreeDemoSeeder'
```

The demo seeder is idempotent (skips if its sentinel post exists) and populates
users, a Kumasi Central group **and a Youth subgroup**, community posts, an event
with registrations, a cause with verified contributions, a course, and a
gamification season + points.

It also seeds **role assignments** now that the PDP enforces
`role_assignments.scope_group_id` + `include_descendants` (see
`docs/configurable-wbs-activities.md` §7c). These make the demo usable and
exercise every branch of the hierarchy check:

| Demo user | Role | Scope | Descendants | Can do |
|-----------|------|-------|-------------|--------|
| user[0] (Ama)  | `org_admin` | org-wide (NULL) | — | everything, incl. org-wide config/methods |
| user[1] (Kofi) | `moderator` | Kumasi Central | yes | manage chapter **and** Youth subgroup gamification config |
| user[2] (Esi)  | `moderator` | Youth subgroup | no | manage only the Youth subgroup |
| user[3] (Yaw)  | `analyst` | org-wide (NULL) | — | view/export reports |

Because these grants reference the demo groups, they live in `DemoDataSeeder`
(not `RbacBootstrapSeeder`, which only defines the org, roles and permissions and
seeds no subject assignments). A production deployment assigns real users through
the access-request/approval flow instead.

### 5a. Campaign project demos (FR-GAM-009/010/011)

Three optional seeders demonstrate a group **project** (time-boxed campaign). A
project is fundamentally just **a bundle of activities + a target + start/close
dates**; every project is **individual-first** (members compete for their own
award). A **team challenge is an optional overlay** — the base seeder omits it
entirely, the other two add it. All three require `RbacBootstrapSeeder` first
(they attach to the `wbs` org), are idempotent by campaign code, and **share**
the same demo hierarchy + members (Greater Accra Region → Accra Metro / Tema
districts, six members), so they coexist — run any or all.

```bash
# BASE project (no teams): "Q3 Outreach Drive" (code q3-outreach-drive).
#   The core case — activity_scope bundle (event.attended + contribution.verified
#   + course.completed), metric=count, single target 40 → Outreach Champion badge.
#   No team_challenge: just an individual leaderboard toward the target. 2 of 6
#   members reach it.
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignBaseDemoSeeder'

# Ad hoc team project: "Q3 Giving Challenge" (code q3-giving-challenge).
#   team_mode = adhoc — teams are hand-picked ROSTERS (Red vs Blue) that cut
#   ACROSS the hierarchy (team_kind='team'). Each team has its own milestone
#   (GHS 1,900 → Team Champion badge); Red reaches it, Blue falls short.
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignDemoSeeder'

# Hierarchical (subtree) project: "Q3 Regional Giving" (code q3-regional-giving).
#   team_mode = subtree — the competing teams ARE the owner's subgroups (Accra
#   Metro vs Tema district), membership DERIVED from the hierarchy, no rosters
#   (team_kind='group'). Each district has its own milestone (GHS 1,920 →
#   District Champion badge); Accra reaches it, Tema falls short by GHS 20.
php spark db:seed 'WBS\Gamification\Database\Seeds\CampaignSubtreeDemoSeeder'
```

Each seeder creates the campaign, activates it, drives real activity for the six
members through `CampaignService::recordProgress()` (populating the individual
leaderboard, awards/tier badges, and — for the team flavours — team/subgroup
standings), then closes it to snapshot recognition (top individuals, plus top
teams/subgroups when there is a team challenge). Static, no-DB visual mirrors of
the resulting scoreboards live at the repo root: `campaign_demo_base_preview.html`
(base, no teams), `campaign_demo_subtree_preview.html` (subtree), and
`campaign_demo_preview.html` (ad hoc).

## 6. Run

```bash
# App (dev). Everything routes through public/index.php.
php spark serve --host 0.0.0.0 --port 8080

# Background workers (separate shells):
php spark outbox:relay --loop          # transactional outbox -> queue
php spark queue:work                    # process jobs (reserve->dispatch->complete/fail)

# Scheduled (cron): annual gamification rollover, org tz, 1 Jan
php spark gamification:rollover --all

# Scheduled (cron): prune expired/old sessions (FR-ID session lifetime).
# Idempotent housekeeping — active() already fails closed on expiry/idle at
# request time; this bounds the table. Run hourly/daily.
php spark identity:prune-sessions                 # retention 30 days
php spark identity:prune-sessions --retention-days=7

# Scheduled (cron): expire lapsed access grants (FR-ACL-004). Idempotent -
# flips approved access_requests + active role_assignments past effective_to to
# 'expired'. Run daily (or hourly for tighter windows).
php spark acl:expire            # all orgs
php spark acl:expire --org=<uuid>

# One-off: import public geo reference data (dr5hn / GeoDB), hierarchy order.
# Source ids are preserved as our PKs; re-running is an idempotent no-op.
php spark geo:import --dir=/data/geo --version=2024.1 --source=dr5hn
#   or per file:  php spark geo:import countries /data/geo/countries.json --version=2024.1
```

Geo dataset order (parents first so FKs resolve): regions → subregions →
countries → states → cities → towns_villages. Sample fixtures showing the
expected JSON shape live in `docs/geo-samples/`.

Health/status: `GET /` (also `GET /health`) returns DB/Redis status + module list.

## 7. Quality gates

```bash
composer test                 # PHPUnit
vendor/bin/phpstan analyse    # if configured
php spark openapi:generate    # regenerate the API spec after any route change
```

These same gates run in CI on every push/PR via
`.github/workflows/ci.yml`:

- **static-analysis** job — `composer validate`, `php -l` on all sources,
  `phpstan analyse` (level 5, php 80400), then the **OpenAPI drift guard +
  schema validation** (see §7a).
- **tests** matrix — PHP 8.2 + 8.3, with MariaDB 11 and Redis 7 service
  containers; runs `spark migrate --all`, seeds RBAC + adapter catalogue
  (FQCN), then `phpunit`.

### 7a. API documentation (OpenAPI)

The OpenAPI 3.1 spec is **derived from the canonical route table** — there are
no per-controller `#[OA\...]` annotations to maintain, so it cannot drift from
the real routes. Never hand-edit `public/openapi.json`.

```bash
php spark openapi:generate                    # -> public/openapi.json
php spark openapi:generate --out=/tmp/api.json # alternate output path
```

Served publicly by `ApiDocsController`:

- `GET /openapi.json` — the spec (static file if present, else generated live).
- `GET /docs` — interactive Swagger-UI over that spec.

**CI drift guard** (in the `static-analysis` job): CI regenerates the spec to a
temp file and compares its **normalized JSON content** (decode → canonical
re-encode) against the committed `public/openapi.json`. The comparison ignores
pretty-print/whitespace, so it fails **only on real drift** — routes changed
without regenerating the spec — with a message telling you to run
`php spark openapi:generate` and commit the result. So: whenever you add/change a
route in `app/Config/Routes.php`, regenerate and commit `public/openapi.json` in
the same PR.

**CI schema validation**: after the drift guard, CI asserts the freshly
generated document is a valid **OpenAPI 3.1** spec (`openapi-spec-validator`,
which supports 3.1 natively; python3 is preinstalled on the runner). This catches
a malformed spec even if it happens to match the committed file — i.e. a bug in
`OpenApiGenerator` itself. To run the same check locally:

```bash
python3 -m pip install openapi-spec-validator
python3 -c "from openapi_spec_validator import validate; from openapi_spec_validator.readers import read_from_filename as r; validate(r('public/openapi.json')[0]); print('valid')"
```

Filter → spec mapping is automatic: `auth` ⇒ `401` + `BearerToken` security;
`authorize:<perm>` ⇒ `403` + the permission is recorded; `ratelimit:<policy>`
⇒ `429`. Toolchain-free environments can use `tools/gen_openapi.py`, which emits
the identical artifact.

---

## 8. Route map (canonical, no `/api` duplication)

Representation is negotiated in `BaseController` — every route serves JSON or HTML
from one controller. `auth` = session/API-token filter; `authorize:<perm>` = PDP;
`ratelimit:<policy>` = reusable limiter (fails closed for auth/payment/MFA).

### Identity & tokens
- **Password policy (FR-ID-003)** — enforced in `AccountService::passwordPolicy`
  for register / set-password / reset: **≥12 chars**, mixed case + digit, **and a
  breach-list check**. Hashing is **Argon2id**, calibratable via env
  (`identity.argon.memoryCost` KiB / `identity.argon.timeCost` / `identity.argon.threads`;
  bcrypt fallback `identity.bcrypt.cost`); the active params feed `needsRehash()`,
  so raising cost transparently re-hashes each password on the user's next
  successful login (no bulk migration). The breach check is a **swappable
  provider** (`identity.breachCheck`): `local` (default, offline embedded corpus +
  leet/repeat/sequence rules + `identity.breachExtraWords` org terms), `pwned`
  (HaveIBeenPwned k-anonymity range API — sends only a 5-char SHA-1 prefix, **fails
  open** to the local corpus on any network error), or `off` (explicit no-op).
  A breached password returns `WEAK_PASSWORD` / `identity.password_breached`.
- **Browser web-session flow (HTML, cookie-based)** — layered on the JSON auth
  services, no parallel auth logic: `GET /login` (form) · `POST /login`
  (`webcsrf`) · `GET /mfa` + `POST /mfa` (`webcsrf`, TOTP step-up) ·
  `GET /me` (`auth`, signed-in home) · `POST /logout` (`webcsrf`). Sets three
  HttpOnly cookies — `wbs_session` (session ref, read by `AuthFilter`),
  `wbs_csrf` (double-submit token, checked by the `webcsrf` filter), and the
  short-lived `wbs_mfa` ticket that carries the pending principal between the
  password and TOTP steps (no session exists yet at that point). Cookie `Secure`
  flag is set automatically behind a TLS-terminating proxy (`X-Forwarded-Proto`).
  The `/g/{slug}` public pages' "Member login" CTA points here.
  - Rate limits: `POST /login` runs `ratelimit:auth.login` (+`webcsrf`); `POST /mfa`
    calls `auth.mfa_verify` **in-controller** keyed on the ticket's user_id — the
    shared `ratelimit` filter keys `user` off the CI session (which this platform
    never populates), so on the pre-session `/mfa` step it would collapse to one
    global bucket. Keying on the pending principal gives each account its own budget.
  - **Optional `GET /mfa` (challenge-page) rate limit — enabled per group.** The
    `auth.mfa_challenge` policy runs on the challenge-page load ONLY when a group in
    the signing-in user's ancestry enables the group-config capability
    `security.mfa_challenge_ratelimit` (inheritance-aware via `EffectiveConfigResolver`;
    **default OFF ⇒ no-op**). The user's group is resolved with
    `GroupScopeResolver::primaryMembershipGroup` (explicit primary, else deepest active
    membership), so a parent group can switch it on for a whole subtree:
        POST /admin/groups/{groupId}/config/security.mfa_challenge_ratelimit
        body: { "value": true, "inheritance_mode": "ancestor_default_child_override" }
    Truthy values accepted: `true` / `1` / `"on"` / `"yes"` / `"enabled"` / `{ "enabled": true }`.
- **Active-session review & revoke (self-service)** — `GET /me/sessions` (`auth`)
  lists the caller's own live sessions (metadata only: id, mfa_level, risk_score,
  created/last-seen/expiry, and a `current` flag matched via `hash_equals` on the
  session cookie); JSON when `wantsJson()`, else an HTML page linked from `/me`.
  `POST /me/sessions/{id}/revoke` (`auth` + `webcsrf`) revokes **only** a session
  owned by the caller; revoking the current session also clears the cookies and
  redirects to `/login`. No token/hash material is ever returned.
- **Set-password / accept-invite** — `GET /set-password?token=…` validates a
  single-use token and renders the form; `POST /set-password` (`webcsrf` +
  `ratelimit:auth.password_reset`) consumes it, sets the password (shared policy:
  12+ chars, mixed case + digit), and for an `invite` activates the account
  (`status=active`, `email_verified=1`) and revokes any existing sessions. Backed
  by `credential_setup_tokens` (`000050`): the plaintext token (`wbsinv_`+64hex)
  travels only in the link; only its SHA-256 hash is stored; 7-day default TTL;
  issuing a new token retires prior unused ones of the same purpose. The public
  group self-join (`/g/{slug}/join`) issues an `invite` for a newly-created
  passwordless member and surfaces the set-password link on the confirmation page
  (normally emailed). Existing accounts never get an invite.
- `POST /auth/register` · `POST /auth/login` · `POST /auth/logout`
- `POST /auth/mfa/totp/enrol` · `/confirm` · `POST /auth/mfa/verify` · `GET /auth/mfa/factors`
- `POST /auth/social/{provider}/callback` · `/link` · `/unlink`
- `POST /tokens/refresh` (public, rate-limited) · `POST /tokens` · `GET /tokens` ·
  `POST /tokens/revoke-all` · `DELETE /tokens/{id}` (auth)

### Groups & referrals
- `POST /groups` · `GET /groups/{id}` · `POST /groups/{id}/move` · `/members`
- **Group lifecycle (FR-GRP-005)** — archive/merge/dissolve with evidence, a
  state machine mirroring the identity account lifecycle (`active ⇄ archived`;
  `active|archived → dissolved` terminal; `active|archived → merged` terminal).
  Every write needs a stated `reason` and accepts optional `approval_ref` +
  `evidence[]`; each writes an append-only `group_lifecycle_transitions` row and
  an immutable audit-log entry (`group.lifecycle.{state}`). All gated by
  `authorize:group.change.approve` at the route AND re-checked per-group in the
  controller (a leader may act only within their own subtree; a merge is checked
  against BOTH the merged group and the survivor):
  - `POST /groups/{id}/archive` · `POST /groups/{id}/reactivate`
  - `POST /groups/{id}/dissolve` — refused (`409`) if the group still has any
    active child group or active member (archive/merge those first).
  - `POST /groups/{id}/merge` body `{ survivor_id, reason }` — re-parents the
    merged group's children under the survivor (via `GroupService::move`, closure
    kept correct) and transfers its active members (respecting one-active-per
    `(user, group, type)`; a duplicate is ended rather than doubled). Rejects a
    self-merge, a terminal survivor, or a survivor inside the merged subtree
    (cycle). Returns `{ merged_into_id, moved:{children,members} }`.
  - `GET /groups/{id}/lifecycle` — newest-first transition history.
- `POST /referrals/links` · `/sponsorships` · `/attribute` · public cloaked landing (`GET /r/{code}`, `POST /r/{code}/prospect`)
- Tracking/analytics (auth; PII-free): `GET /referrals/links/{code}/analytics?period=30 days` · `GET /referrals/referrers/{id}/analytics` · `GET /referrals/chain/{memberId}` (IDs only) · `POST /referrals/clicks/{id}/flag` · `POST /referrals/clicks/{id}/clear`
- `GET /r/{code}` accepts optional `screen_resolution`, `timezone`, and `utm_*` params → privacy-safe `device_hash` + typed redirect (link_type: member/event/giving/course/streaming). Clicks are fraud-scored on ingest (velocity per ip_hash, bot-UA, no-UA/consent); verdict stored as `is_suspicious`+reason, never raw PII.

### Geo (reference resolution + venues)
- Public reference reads (no PII, no auth): `POST /geo/resolve` (cascade town→city→coords) · `GET /geo/reverse-geocode?latitude=&longitude=` · `GET /geo/places?latitude=&longitude=&radius_km=` · `GET /geo/stats/{region|country|state|city}`
- Venues (auth; writes need `authorize:venue.manage`): `GET /venues` · `GET /venues/nearby` · `GET /venues/stats` · `GET /venues/{id}` · `POST /venues` · `POST /venues/bulk` · `POST /venues/{id}` (optimistic `expected_version`) · `DELETE /venues/{id}` (soft; `?hard=1`) · `POST /venues/{id}/groups` · `GET /groups/{id}/venues`

### Events
- `POST /events` · `GET /events/{id}` · `GET /events/{id}/attendance` (expected-attendance report)
- `POST /events/{id}/publish` · `/register` (rate-limited) · `/complete`
- Check-in: nonce / QR / manual
- **Ticketing (FR-EVT-016)**: `GET /events/{id}/ticket-types` · `POST /events/{id}/ticket-hold` (rate-limited) · `POST /events/{id}/checkout` (rate-limited `event.checkout`, fail closed). Management (auth + `authorize:event.tickets.manage`): `POST /events/{id}/ticket-types`, `POST /events/{id}/promo-codes`. Orders: `POST /orders/{id}/pay`, `POST /orders/items/{id}/transfer`. Ticket accounting is separate from VBCS; a paid order's approved cause add-on becomes a separate contribution intent.
- **Kiosks / offline (FR-EVT-017)** (auth + `authorize:attendance.check_in`): `POST /events/{id}/kiosks` · `POST /kiosks/{id}/manifest` (encrypted, short-lived, event-scoped) · `POST /kiosks/{id}/offline-scans` · `POST /kiosks/{id}/reconcile` · `POST /kiosks/{id}/revoke`.
- **Logistics (FR-EVT-018)** (auth + `authorize:event.logistics.manage`): `/events/{id}/logistics/plan|resources|refresh-projection|seating|staff|suppliers`, `GET/POST /events/{id}/logistics/needs` (specially classified), `GET /events/{id}/logistics/catering` (aggregate, non-sensitive). Planned vs ordered quantities are kept separate; projections never overwrite approved orders.
- **Expenses (FR-EVT-019)**: submit `POST /events/{id}/expenses` (`authorize:event.expense.submit`); `POST /events/{id}/budget` and `GET /events/{id}/expenses/reconcile` (`authorize:event.expense.approve`); maker-checker `POST /expenses/{id}/approve|reject|reimburse` (`authorize:event.expense.approve`, actor must differ from submitter → 403 SOD).
- **Feedback & quizzes (FR-EVT-012)** (`authorize:event.feedback.manage`): `POST /events/{id}/feedback-forms`, `POST /feedback-forms/{id}/questions|open|close`, `GET /events/{id}/feedback-forms/{formId}/aggregate` (min-N suppressed), `POST /feedback-responses/{id}/review`. Public submit: `POST /feedback-forms/{id}/responses` (rate-limited).
- **Certificates (FR-EVT-013)**: `POST /certificates/templates`, `POST /events/{id}/certificates/request` (skips zero-attendance), `POST /certificates/{id}/issue|revoke` (`authorize:event.certificate.manage`); background job `event.certificate.render`. Public: `GET /certificates/verify/{verificationId}` (no auth; opaque QR id, non-identifying status only).
- **Media (FR-EVT-015)** (`authorize:event.media.manage`): `POST /events/{id}/media` (enqueues `event.media.scan`, EXIF stripped), `GET /events/{id}/media/review`, `POST /media/{id}/review`. Public: `GET /events/{id}/media` (clean + approved + public only).
- **Mobilization report (FR-EVT-014)** (`authorize:report.view`): `GET /events/{id}/report` (live aggregate), `POST /events/{id}/report/snapshot` (immutable), `GET /event-reports/groups/{id}/rollup`.

### Contributions (VBCS)
- `POST /causes` · `POST /causes/{id}/contributions` (idempotent intent)
- `POST /contributions/{id}/refunds` → `/approve` → `/execute` (SoD maker-checker, auth)
- `POST /webhooks/payments/{provider}` (HMAC verify-before-apply → idempotent inbox)

#### VBCS metrics / partnership / commitments / manual (adapted from GivingsLibrary)
- Member reads (auth): `GET /vbcs/subjects/{id}/metrics` (PGV/GGV) · `GET /vbcs/subjects/{id}/partnership` · `GET /vbcs/subjects/{id}/commitments` · `GET /vbcs/partnership/tiers` · `GET /vbcs/causes/{id}/progress` · `GET /vbcs/causes/{id}/donors` (anonymous respected; no email)
- Commitments (member-owned, reminder-only): `POST /vbcs/commitments` · `POST /vbcs/commitments/{id}/cancel`
- Leader report (`authorize:report.view`): `GET /vbcs/groups/{id}/report?days=30`
- Admin (`authorize:contribution.manage`): partnership tiers `POST /vbcs/partnership/tiers` · `/tiers/{code}/disable`; manual givings `POST /vbcs/manual` · `GET /vbcs/manual/pending` · `POST /vbcs/manual/{id}/approve|reject` (maker-checker, approver≠submitter); `POST /vbcs/subjects/{id}/refresh` (force metrics/partnership recompute)
- **Scheduled**: `php spark contributions:commitments-due --org=<uuid>` (or `--all`) — sends due reminders, advances next_due_at, charges nothing. Run daily.
- PGV/GGV are derived snapshots refreshed async on contribution.succeeded/refunded (RewardCoordinator → cascadeToUpline + recomputeStatus). GGV downline comes from the Referrals sponsorship graph; ×1.30 once a subject has ≥10 direct recruits.

### Courses
- `POST /courses` · `/publish` · `/lessons` · `POST /courses/{id}/enrol` (rate-limited)
- `GET /courses/{id}/syllabus` (drip lock, no leak) · complete-lesson

### Notifications
- `POST /notifications/send` (rate-limited) · campaigns create/submit/approve (SoD)

### Integrations / UPAF (auth)
- `GET /integrations/catalog` · connections create/credentials/test/submit/activate · profiles
- Streaming-provider OAuth consent (S7): `POST /integrations/oauth/{provider}/authorize` (`authorize:provider.configure`; returns authorize_url, stashes session state) · `GET /integrations/oauth/{provider}/callback` (validates state, exchanges code, stores refresh token in the vault). Providers: youtube, twitch, facebook, googlemeet, gotowebinar.
- Provider secrets live ONLY in the credential vault; app-level client id/secret from env (`YOUTUBE_/TWITCH_/GOOGLEMEET_CLIENT_ID/SECRET`). Real API calls verify in CI/staging (sandbox has no network).

### Community
- `GET /community/feed` (visibility-filtered) · posts/comments/reactions (auth) ·
  reports · `POST /community/moderate` (`authorize:community.moderate`)

### Streaming (auth)
- `POST /streams` · destinations · live · end · archive · `GET /streams/{id}/access`
- `POST /streams/{id}/provision` — create provider broadcast(s) for pending destinations (S1); stores non-secret external_ref, keys go to the vault. `live`/`end` dispatch start/end to each ready destination's adapter (YouTube/Twitch/Facebook/RTMP).
- `GET /streams/{id}/dashboard` (`authorize:stream.moderate`)
- Chat (`ratelimit:stream.chat`) · moderate · polls · metrics · vote · close
- Viewers (S3): `POST /streams/{id}/viewers` (`ratelimit:stream.viewer`; hashed IP/UA + coarse device only) · `POST /streams/viewers/{id}/leave`
- Reactions (S4): `POST /streams/{id}/reactions` (`ratelimit:stream.react`)
- Real-time metrics (S6): `GET /streams/{id}/realtime?window_minutes=5` (`authorize:stream.moderate`)
- Stream giving (S5): `POST /streams/{id}/giving` — creates a Contributions INTENT tagged to the stream; completion arrives via the contributions webhook (never a fake completed donation).
- Co-hosts + overlays (FR-STR-008): cohosts / token / remove · overlays · visibility ·
  `GET /streams/{id}/overlays/active` (relay compositor read)

### Meetings (auth)
- `POST /meetings` · `/grant` (short-lived hashed join token) · `/transition`
- Provider-backed creation (S2): when no `join_url`/`external_ref` is supplied and an adapter exists, the real meeting is created (zoom = S2S OAuth, meet = Calendar conferenceData). Passwords stored in the vault; provider failure → 502.

### Gamification (auth)
- Reads (PII-free): `GET /gamification/leaderboard?limit=&subject_type=&season_id=` (subject_id + display_name + points only, never email) · `GET /gamification/ranks` · `GET /gamification/achievements?include_secret=` · `GET /gamification/subjects/{id}/{balance|standing|achievements|streaks}` · `POST /gamification/subjects/{id}/streaks` (record)
- Admin approvals (`authorize:gamification.manage`): `GET /gamification/pending` (held awards) · `POST /gamification/awards/{ledgerId}/{approve|reject}` · `POST /gamification/subjects/{id}/achievements/unlock` · `POST /gamification/subjects/{id}/reevaluate`
- Admin config (`authorize:gamification.manage`) — every entity is admin-manageable:
  - Point rules: `GET|POST /gamification/rules` · `POST /gamification/rules/{code}` (edit = new version) · `POST /gamification/rules/{code}/disable`
  - Ranks: `POST /gamification/ranks` · `POST /gamification/ranks/{code}/disable`
  - Achievements: `POST /gamification/achievements` · `POST /gamification/achievements/{code}/disable`
  - Streak definitions: `GET|POST /gamification/streak-definitions` · `POST /gamification/streak-definitions/{code}/disable`
- Points stay on the immutable ledger; achievement bonus points post via a bonus rule (source_ref `achievement:{code}`). Rule multipliers are declarative JSON (no eval); cooldown_seconds + per_period_cap enforced. Achievements auto-evaluate after each final award.

### Reporting (auth)
- `GET /reports/funnel` (`authorize:report.view`) · `POST /reports/exports`
  (`ratelimit:report.generate`) · `GET /reports/exports/{id}/download` (re-checked)

### Admin (auth, `authorize:admin.manage`)
- Settings get/set · feature flags · group config set/resolve (inheritance-aware)

---

## 9. Security invariants (do not regress)

- Money in **integer minor units**; ledger append-only; corrections = compensating entries.
- Webhooks: **verify signature/timestamp before any state change**; idempotent via
  `UNIQUE(provider, event_id)`; unknown/unverified events quarantined.
- Refunds / campaigns / provider activation enforce **SoD** (approver ≠ requester).
- Tokens & join tokens: **hash-at-rest, plaintext returned once**; refresh rotation
  with **family replay revocation**; no wildcard scope by default.
- Sessions: **bounded lifetime, fail-closed** — `active()` rejects on absolute
  `expires_at`, idle timeout (`last_seen_at`), or revocation, whichever first
  (lazily revoking the row). MFA step-up **rotates the session id** (anti-fixation).
  Successful login **transparently re-hashes** passwords needing stronger params.
- Community/stream content **sanitized** (allowlist); moderation requires a reason
  and is recorded as evidence.
- PDP is **default-deny**: MAC → SoD → ABAC (deny-overrides) → RBAC → deny.
- Rate limiter **fails closed** for login/reset/MFA/payment/checkin/admin/provider/fan-out.
- Reporting: small-cohort **suppression**; exports **formula-injection-safe** + re-checked at download.

## 10. MySQL 9.x notes & migration/seeding troubleshooting

Target server for this deployment: **MySQL 9.6.0** (Innovation track). 9.x is strict
and removes deprecated behavior quickly, so a few things that older servers tolerated
will now hard-fail. All items below were hit and fixed during first bring-up.

### Authentication: `mysql_native_password` is REMOVED in MySQL 9.0+
- The old plugin no longer exists on the server. Create every DB user (app, queue
  workers, cron, replicas) with `caching_sha2_password`:
      ALTER USER 'wbs_app'@'%' IDENTIFIED WITH caching_sha2_password BY '...';
- PHP 8.2+ `mysqli`/`pdo_mysql` support `caching_sha2_password` natively — no client
  change needed. Only legacy provisioning scripts that say
  `IDENTIFIED WITH mysql_native_password` will fail on 9.x.

### Raw SQL: backtick reserved-word identifiers
CI4 Query Builder auto-quotes identifiers, but our migrations use raw
`$this->db->query('CREATE TABLE ...')`. Reserved words used as table/column names MUST
be backticked or MySQL 9.x throws error 1064. Already fixed: `` `groups` `` (table),
`` `condition` `` (column). If you add raw DDL/DML, backtick anything that could be a
keyword (`order`, `rank`, `system`, `usage`, `interval`, `groups`, window-function
words, etc.).

### Raw SQL: apostrophes inside single-quoted PHP strings
Migration SQL is wrapped in single-quoted PHP strings. An apostrophe in an inline SQL
comment (e.g. `-- member's group`) terminates the PHP string → ParseError. Keep SQL
comments apostrophe-free (or use a heredoc).

### Spatial columns: SPATIAL INDEX requires NOT NULL
A `SPATIAL INDEX` cannot exist on a nullable column. `addresses.geo_point` /
`venues.geo_point` are intentionally nullable (a location may be unknown; the service
sets them to NULL when cleared), so there is NO spatial index. Proximity queries use
`ST_Distance_Sphere(...)` guarded by `geo_point IS NOT NULL` (adequate at single-org
scale). The Geo migration adds the column idempotently and drops any stale spatial
index left by an older revision.

### JSON columns need valid JSON (not bare strings)
MySQL 9.x validates JSON on write. Inserting a bare word into a JSON column
(e.g. `completion_rule => 'all_required_lessons'`) fails with
"Invalid JSON text ... at position 0". Seeders/services must `json_encode(...)` the
value (services already do; the demo seeder was fixed to seed
`json_encode(['required_lessons' => 'all'])`).

### PHP 8.4: no writing to a temporary expression
`($cond ? $a : $b)[] = $x;` is a compile-time fatal on PHP 8.4
("Cannot use temporary expression in write context") — and because it is compile-time,
it takes down anything that merely autoloads the file. Use an explicit if/else. Run
`php -l` across `app/` after edits to catch these before runtime.

### Service factories: shared-instance keys are GLOBAL across modules
`getSharedInstance('key')` stores instances in one global registry keyed by the string,
shared by every module's `Config\Services`. Two modules using the same key
(`campaigns` in both Gamification and Notifications) collide and can return the wrong
type. When two modules expose a same-named service, cache it in a module-local static
property instead of a shared key.

### Base seeds directory must exist
CI4 `Seeder::__construct()` requires `Config\Database::filesPath . '/Seeds/'`
(= `app/Database/Seeds/`) to exist even when running a fully-qualified module seeder.
Keep `app/Database/Seeds/.gitkeep` and `app/Database/Migrations/.gitkeep` in the repo.

### Windows shell quoting for `db:seed`
The fully-qualified seeder argument contains backslashes. Bash uses single quotes;
Windows `cmd.exe` needs DOUBLE quotes, else the quotes become part of the class name
("Class '...' not found"):
    cmd.exe:     php spark db:seed "WBS\Admin\Database\Seeds\DemoDataSeeder"
    bash/WSL:    php spark db:seed 'WBS\Admin\Database\Seeds\DemoDataSeeder'

### Partial migrations / seeds are not transactional (MySQL DDL autocommits)
A migration that fails midway leaves earlier CREATEs in place but is NOT recorded, so a
re-run re-enters it — our migrations use `CREATE TABLE IF NOT EXISTS` and idempotent
column adds, so re-running is safe. Seeders are not transactional either; `DemoDataSeeder`
guards on a sentinel row. If a seed fails AFTER the sentinel is written, prefer a clean
reseed:  `php spark migrate:refresh` then re-run the seeders in order.
