# Win–Build–Send (WBS) Platform

A highly secure, multilingual, modular community & capacity-building platform
built around the **Win–Build–Send** funnel.

Dated apply-pack history (full original text, newest first) is in
[`CHANGELOG.md`](CHANGELOG.md). How to run locally is in [`RUNBOOK.md`](RUNBOOK.md).

> **Status: Feature-complete across all 18 modules.** Every confirmed SRS
> functional-requirement gap tracked in `docs/SRS_FR_COVERAGE_AUDIT.md` is now
> implemented and verified. Current footprint: **18 modules · 39 migrations · 48
> controllers · 265 canonical API operations (243 paths) · 23 permissions**, with
> a generated OpenAPI 3.1 spec (`public/openapi.json`) that CI drift-guards.
> (Deferred by explicit decision: Tier-0 secret-fallback hardening and Tier-1
> KMS/Vault providers — see `docs/`.)

**Stack:** PHP 8.4 · CodeIgniter 4.7 · MySQL/MariaDB (utf8mb4, UTC) · Redis · queue workers

---

## What is implemented

The platform is **runnable and tested end-to-end** against live MySQL/MariaDB
and Redis, not a paper skeleton. The secure foundation below underpins the full
feature set delivered across all 18 modules (see the module map and
`docs/SRS_FR_COVERAGE_AUDIT.md` for per-requirement coverage).

| Area | SRS refs | Status |
|---|---|---|
| Secure HMVC modular structure (18 modules, own namespaces/Config/Services/migrations) | §6.3 | ✅ |
| Unified web/API endpoints — one canonical route, representation negotiated in `BaseController` | FR-ARC-001..004 | ✅ |
| Reusable rate-limiting service (token-bucket + sliding-window, atomic Redis Lua, fail-closed fallback, hierarchical stricter-only overrides, hashed keys) | FR-RL-001..006, NFR-SEC-004 | ✅ |
| Reusable CI4 rate-limit **filter** returning 429 + `Retry-After` (JSON) / accessible message (HTML) | FR-RL-004 | ✅ |
| Transactional **outbox** + **idempotency keys** schema | FR-ARC-005/006 | ✅ |
| Append-only, **hash-chained tamper-evident audit log** with chain verification | NFR-SEC-007 | ✅ |
| Argon2id **password hasher** (≥12 chars, needs-rehash upgrade path) | FR-ID-003 | ✅ |
| Identity/session, RBAC (roles/permissions/assignments/decisions), Groups hierarchy (1–9, cycle-safe, closure projection) schema | §7.1/7.3/7.4 | ✅ |
| Single-organization boundary + starter RBAC catalogue seeded | §2.3, §7.4 | ✅ |
| UUIDv7 external IDs, injectable UTC clock, Result envelope | §9.1 | ✅ |
| Secure response headers, per-route CSRF strategy, invalidchars filter | NFR-SEC-002, FR-ARC-003 | ✅ |
| Health/readiness endpoints (DB + Redis checks) | §14.2 | ✅ |
| CI-grade quality gates: **PHPStan (clean)** + **PHPUnit (30 tests)** | §12, §15 | ✅ |

### Deliberately deferred (require approved decisions before build)
Payments/VBCS adapters, ticketing, streaming relay, meeting adapters, SCORM/xAPI/LTI,
notifications provider sends — their **schemas/contracts** are staged but no provider
code is written yet (SRS §16 “No phase may bypass core authorization, consent, audit,
outbox, monitoring, or data ownership requirements.”).

---

## Quick start

```bash
# One command brings up MariaDB + Redis, migrates, seeds, and serves on :8080
./bin/dev-up.sh
```

Then open:

- `http://localhost:8080/` — Phase-0 status dashboard (HTML)
- `http://localhost:8080/?_format=json` — **same route**, JSON representation
- `http://localhost:8080/health/ready` — readiness; try `-H "Accept: application/json"`

### Tests & static analysis

```bash
vendor/bin/phpunit            # 30 tests (unit + DB-backed integration)
vendor/bin/phpstan analyse    # level 5, clean
```

---

## Architecture

```
Browser / JSON client            (same canonical resource URLs)
        │
        ▼
CI4 Front Controller → Filters (invalidchars, ratelimit:<policy>, secureheaders)
        │
        ▼
Extended BaseController  ── representation negotiation (HTML | RFC 9457 JSON)
        │
        ▼
Feature Module Controller → Application Service → Result (representation-agnostic)
        │                          │
        │                          ├─ MySQL (transactional data + outbox row, one tx)
        │                          └─ Redis (cache / locks / rate limiting)
        ▼
HTML view / JSON envelope
```

### Modules (`app/Modules/`)

`Shared` `Identity` `AccessControl` `Groups` `Referrals` `Geo` `Notifications`
`Contributions` `Events` `Courses` `Streaming` `Meetings` `Community`
`Gamification` `Reporting` `Integrations` `Admin` `Audit`

Each is its own PSR-4 namespace (`WBS\<Module>`) so CI4 auto-discovers its
`Config/Routes.php`, `Config/Services.php`, migrations, views and language files.
Controllers stay thin and never invoke another module's controller (SRS §6.3).

### Key design decisions

- **One canonical route** per resource; no duplicate `/api` tree. `BaseController`
  chooses HTML vs JSON from `?_format`, bearer token, XHR, or `Accept` q-values —
  **never** infers CSRF exemption from `Accept` (FR-ARC-003).
- **Rate limiting fails closed** for security/financial policies: if Redis is down,
  a conservative per-node fallback still enforces the limit (FR-RL-005). Group
  overrides may only be **stricter**; `auth.*`, `payment.*`, `webhook.*`, `admin.*`,
  `provider.*` are protected and cannot be weakened (FR-RL-003).
- **Audit log is hash-chained**: `row_hash = SHA-256(prev_hash ‖ canonical row)`,
  head read inside the insert transaction; any tampering breaks verification (NFR-SEC-007).

See `docs/adr/` for the full rationale.

---

## Configuration

All secrets/config come from `.env` (never committed). Key entries:

```
database.default.*        MySQL/MariaDB connection (utf8mb4, UTC)
database.tests.*          Test database
redis.host/port           Redis
encryption.key            Envelope master key (use KMS/secret store in prod)
wbs.organizationId        Fixed single-organization boundary
wbs.organizationTimezone  Season rollover / display tz (default Africa/Accra)
wbs.maxGroupDepth         Effective group hierarchy depth (default 9)
```

---

## Project layout

```
app/
  Config/            App, Autoload (module namespaces), Filters (ratelimit alias)
  Controllers/       Home (unified HTML/JSON landing)
  Modules/
    Shared/          Support (Uuid, Result, Clock, RedisFactory), Http
                     (BaseController, RequestNegotiator, RateLimitFilter),
                     RateLimiting (RateLimiter, policies, stores), Config, migrations
    Identity/        users/sessions/credentials, Argon2id hasher, phone-uniqueness policy, account lifecycle
    AccessControl/   MAC+RBAC+ABAC PDP, authorization_decisions, access-request workflow (maker-checker)
    Groups/          groups 1–9 + closure projection + full membership model & conflict rules
    Audit/           audit_log, hash-chained AuditLogger
    Referrals/       referral links, sponsorship, UPAF-scoped reuse
    Contributions/   VBCS (Stripe + Ghana/Nigeria MoMo), causes, refunds, outbox+webhooks
    Events/          events, tickets, logistics, expenses, feedback/quizzes, certificates, reports, media
    Streaming/       streams, destinations, overlays, engagement, in-stream giving, relay-failure response
    Meetings/        Zoom/Meet/Teams meeting linking, per-user join tokens, candidate presence
    Notifications/   channel gate, template renderer, campaigns, outbox delivery
    Courses/         courses, enrolment, completion overrides
    Community/       feed, moderation, gamification hooks
    Gamification/    points/badges, annual rollover
    Geo/             geocoding source adapter
    Reporting/       aggregate reports + immutable snapshots
    Integrations/    UPAF adapter catalogue, credential vault, fallback matrix + circuit-breaker/quota
    Admin/           Health controller/routes, RBAC bootstrap + foundation seeders
bin/dev-up.sh        One-command local bring-up
tests/               unit/ + integration/ (PHPUnit)
phpstan.neon.dist    Static analysis config
```
