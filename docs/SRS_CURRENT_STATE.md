# Software Requirements Specification (SRS)
## Win–Build–Send (WBS) Platform — Current-State Specification

**Document status:** As-built specification, reverse-derived from the running codebase
**Version:** 1.6 (current-state snapshot — Groups+Venues+Geo unified & corrected; onboarding placement/transfers + the transfer maker–checker queue; SMS provider chain: mNotify → Nalo; per-group notification credentials with subtree sharing; negotiated, localized error pages below the controller layer; **integration decisions — the standalone dated decisions of the integration lifecycle, FR-REF-3b, baptisms separated 2026-09-23**)
**Date:** 2026-09-20
**Prepared from:** Live inspection of `wbs-platform/` — 19 module namespaces, 93 migrations, ~211 tables, 557 routed endpoints, 41 permission bits (frozen budget — the transfer queue reuses `sponsor.reassign.approve` and the group-credentials screen reuses `provider.configure`), 244 standalone test files (15,293 assertions, green).

> **Nature of this document.** This is a *descriptive* SRS: it specifies the system **as it currently exists**, not a forward-looking wishlist. Every requirement below is traceable to concrete tables, services, controllers, routes, or tests in the workspace. Where a capability is deliberately deferred, it is called out explicitly in §13. This document is intended to become the shared baseline before the next design work (Groups + Venues + Geo unification).

---

## 1. Introduction

### 1.1 Purpose
The WBS Platform is a secure, multilingual, modular community & capacity-building system organized around the **Win–Build–Send** discipleship/growth funnel:

- **Win** — acquisition and conversion (outreach, referrals, prospects, first contact).
- **Build** — formation and participation (groups, courses, events, contributions, community).
- **Send** — leadership, recognition, and multiplication (gamification, ranks, campaigns, sending people back out to Win).

The platform serves a **single organization** per deployment (multi-tenant-ready at the column level via `organization_id`, but scoped to one org boundary — §2.3), structured as a deep hierarchical group tree (National → Region → Area → Local Assembly → Fellowship → Senior Cell → Cell).

### 1.2 Scope
The system provides, as built:
- Identity, authentication (password + social + WebAuthn + MFA), session and token management.
- A general-purpose authorization stack: RBAC + RuBAC/ABAC rule engine, delegations, access requests, break-glass, hierarchical scope resolution.
- A hierarchical Groups module with lifecycle, kinds/taxonomy, cross-cut links, memberships, and a public location directory.
- A configurable member **Journey** (Win–Build–Send stages) driven by a signal/rule engine, plus involvement-based triage.
- Full **Events** lifecycle (registration, ticketing, check-in, certificates, feedback, logistics, expenses, media, ICS feeds, reporting).
- **Contributions** (causes, intents, ledger, manual capture, commitments, partnership levels, refunds, reconciliation, webhooks).
- **Gamification** (points engine, badges, achievements, ranks, streaks, seasons, campaigns, leaderboards, follow-ups) gated by hierarchical group config.
- **Referrals / Outreach** (referral links + click tracking, prospects/contacts address book, sponsorships, fraud review, follow-ups).
- **Streaming** (streams, overlays, relays, chat/moderation, live giving, engagement).
- **Integrations** (connector catalog, credential vault, connections, custom-adapter SDK, provider reliability/fallback).
- **Community** (posts/topics/comments/reactions, moderation), **Meetings**, **Courses**, **Notifications**, **Geo/Venues**, **Reporting**, **Admin/Settings**, **Audit**.

### 1.3 Definitions, Acronyms, Abbreviations
| Term | Meaning |
|------|---------|
| **WBS** | Win–Build–Send (the funnel and the platform) |
| **HMVC** | Hierarchical MVC — each module is a self-contained namespace with its own Config/Controllers/Services/Views/Migrations/Language |
| **RBAC / RuBAC / ABAC** | Role- / Rule- / Attribute-Based Access Control |
| **PDP** | Policy Decision Point (`AuthorizationService`) |
| **Outbox** | Transactional outbox pattern — domain writes stage messages in-transaction; a relay dispatches them |
| **Effective config** | A capability value resolved by walking the group ancestry (`EffectiveConfigResolver`) |
| **Scope** | The set of groups a grant/role applies to: `self`, `self_and_descendants`, `descendants_only`, or a hand-picked multi-group set |
| **Prospect / Contact** | An outreach lead (`prospects` table) — distinct from an account-lifecycle `prospect` status and a Journey `prospect` stage (see §5.7.4) |

### 1.4 References
- `Pre_Development_Decision_Guide.md` — original decision guide / FR source.
- `docs/SRS_FR_COVERAGE_AUDIT.md` — FR-by-FR coverage audit (106 FRs).
- Per-module lifecycle reviews under `docs/*_LIFECYCLE_REVIEW.md`.
- Generated API contract: `public/openapi.json` (OpenAPI 3.1, CI drift-guarded).

---

## 2. Overall Description

### 2.1 Product Perspective
A server-rendered + JSON-API monolith built on **CodeIgniter 4.7 / PHP 8.4**, backed by **MySQL 8.4 / MariaDB** (utf8mb4, UTC) and **Redis** (rate-limiting, caching, queues). It follows a **modular HMVC** layout: 19 module namespaces under `app/Modules/`, each owning its Config, Controllers, Services, Views, Database (Migrations/Seeds), Language, and standalone tests.

### 2.2 Architecture Principles (as built)
1. **Unified web/API endpoints (FR-ARC-001..004).** One canonical route per operation; `BaseController` negotiates representation (HTML view vs JSON) from the request. A `Result` envelope standardizes success/error across both.
2. **Transactional outbox + idempotency (FR-ARC-005/006).** Domain services stage messages via `OutboxService` inside the same DB transaction; `OutboxRelay` dispatches every pending row to `JobRouter`; `IdempotencyStore` + `processed_messages` guarantee exactly-once handling.
3. **Append-only hash-chained audit (NFR-SEC-007).** `AuditLogger` writes tamper-evident entries: `entry_hash = sha256(prev_hash || canonical_json(core))`; `verifyChain()` re-walks and reports the first break.
4. **Ports & Adapters between modules.** Cross-module coupling goes through explicit `*Port`/`*Adapter` pairs (e.g. `JourneySignalPort`, `EventRosterPort`, `InvolvementSourcePort`), so modules integrate without hard dependencies and are unit-testable in isolation.
5. **Hierarchical configuration.** Feature gating and tunables resolve through `EffectiveConfigResolver` over the group ancestry (inherit_only / ancestor_default_child_override / child_owned / not_inheritable). Global security/financial/abuse limits live in `platform_settings` and cannot be weakened by a child group.
6. **Resource-light reads.** Version-stamped caches, O(1) INCR invalidation, batch permission checks, ETag/304, and denormalized bucket keys avoid per-row queries on hot paths (menu, directory, funnel).

### 2.3 Organizational Boundary
Single-organization deployment. Every domain table carries `organization_id`; all reads/writes are org-scoped. The `organizations` table anchors the boundary; a starter RBAC catalogue is seeded per org.

### 2.4 User Classes & Roles
Roles are data-driven (`roles`, `permissions`, `role_permissions`, `role_assignments`) rather than hardcoded. Internal roles reuse `group_members.role` + `membership_type` (no separate table). Representative classes:
- **Member / Prospect / First-timer** — a person progressing through the Journey.
- **Group leader / staff** — scoped authority over their group subtree (bounded by `GroupScopeResolver`).
- **Ministry/team leaders** — via cross-cut groups (orthogonal to the hierarchy).
- **Administrators** — `admin.manage`, `identity.manage`, config and settings.
- **Reviewers/approvers** — maker-checker on merges, refunds, expenses, break-glass, sponsor reassignment, custom adapters.
- **Anonymous visitors** — public group directory, public group pages, self-join, invite redemption.

### 2.5 Operating Environment
- PHP 8.4, CodeIgniter 4.7.
- MySQL 8.4 LTS / MariaDB (utf8mb4, `caching_sha2_password`); SPATIAL data via nullable `POINT SRID 4326` with `ST_Distance_Sphere` proximity (no spatial index, adequate at single-org scale).
- Redis for rate limiting, caching, and queue coordination; queue workers for async jobs.
- Real dev host `https://public.test/`, `forceGlobalSecureRequests=true`, `CSPEnabled=true`.

### 2.6 Design & Implementation Constraints
- **CSP-safe, no-JS-dependent views.** Server-rendered views must work without inline JS (CSP blocks it); disclosure via native `<details>`, forms via standard POST + CSRF token.
- **6-locale parity (i18n).** All user-facing copy localized across **en, es, fr, pt, zh, ar** (with RTL for Arabic); English fallback; key parity enforced by tests. Interpolation supports both `{0}` and `:name` forms.
- **Reuse over fork.** New behavior extends existing services/tables; no forked writers for the same domain data.
- **Permission bit budget frozen at 41.** New capabilities must reuse existing bits where possible.
- **Standalone test discipline.** Every feature ships a dedicated standalone test; full suite must stay green.

---

## 3. System Features — Module Map (as built)

| # | Module | Core responsibility | Services | Controllers | Migrations | Views |
|---|--------|---------------------|:-------:|:-----------:|:----------:|:-----:|
| 1 | **Identity** | Accounts, auth, MFA, sessions, tokens, account lifecycle, identity policy, merges | 17 | 8 | 10 | 15 |
| 2 | **AccessControl** | RBAC + RuBAC/ABAC, delegations, access requests, break-glass, grant/scope cascade | 11 | 7 | 7 | 18 |
| 3 | **Groups** | Hierarchy, lifecycle, kinds, cross-cut links, memberships, public directory | 8 | 6 | 8 | 20 |
| 4 | **Journey** | WBS stages, signals/rules, transitions, involvement triage, proposals, attribution | 17 | 1 | 7 | 8 |
| 5 | **Events** | Full event lifecycle: reg, tickets, check-in, certs, feedback, logistics, expenses, media, ICS | 28 | 10 | 11 | 31 |
| 6 | **Contributions** | Causes, intents, ledger, manual capture, commitments, partnerships, refunds, reconciliation | 12 | 5 | 3 | 16 |
| 7 | **Gamification** | Points engine, badges, achievements, ranks, streaks, seasons, campaigns, leaderboards, follow-ups | 18 | 5 | 11 | 35 |
| 8 | **Referrals** | Referral links + click tracking, prospects/contacts, sponsorships, fraud, follow-ups, inactivity transfers + their review queue | 20 | 4 | 7 | 9 |
| 9 | **Streaming** | Streams, overlays, relays, chat/moderation, live giving, engagement | 7 | 5 | 6 | 7 |
| 10 | **Integrations** | Connector catalog, credential vault, connections, custom-adapter SDK, provider reliability | 8 | 6 | 4 | 7 |
| 11 | **Community** | Posts/topics/comments/reactions, content moderation | 3 | 2 | 2 | 1 |
| 12 | **Notifications** | Templates, channels, campaigns, deliveries, preferences, suppressions, retention | 6 | 2 | 2 | 2 |
| 13 | **Geo** | Reference hierarchy, addresses, venues, geocoding, location consent | 3 | 2 | 3 | 3 |
| 14 | **Meetings** | Meetings, participants, attendance evidence (bridges to Events attendance) | 3 | 1 | 1 | 2 |
| 15 | **Courses** | Courses, modules, lessons, enrollments, completions, learning content | 2 | 2 | 2 | 5 |
| 16 | **Reporting** | WBS funnel dashboards, member dashboards, exports | 3 | 1 | 1 | 4 |
| 17 | **Admin** | Platform settings, effective-config resolution | 2 | 1 | 1 | 5 |
| 18 | **Audit** | Append-only hash-chained audit log | 1 | 0 | 1 | 0 |
| 19 | **Shared** | Cross-cutting: messaging/outbox, rate limiting, security, i18n, navigation/menu, HTTP base, sweep | — | 4 | 2 | 4 |

---

## 4. Cross-Cutting (Shared) Requirements

### 4.1 Unified Endpoints & Result Envelope
- **SR-ARC-1.** Each operation has ONE canonical route; `BaseController` returns an HTML view to browsers and JSON to API clients from the same controller action.
- **SR-ARC-2.** All service methods return a `Result` (ok/created/fail/notFound/denied) carrying data, an API error code, an HTTP status, and optional metadata.
- **SR-ARC-3.** The same negotiation applies **below** the controller layer, where `BaseController` never runs. `RequestNegotiator` decides the representation from `?format=`, `X-Requested-With`, `Accept` and — when a client names neither JSON nor HTML — Fetch metadata (`Sec-Fetch-Dest`/`Sec-Fetch-Mode`), so a script call is never handed a page and a human navigation is never handed raw JSON. `ProblemResponder` renders every filter refusal (`auth` 401, `authorize` 403/401, `webcsrf` 403, `ratelimit` 429) through it: API clients get the unchanged `application/problem+json` envelope (`type`/`title`/`status`/`detail`, `detail` a stable message key), browsers get `Shared/Views/error_page` — localized in six locales, RTL-correct, house tokens, no JS, `no-store`/`nosniff`/`Vary`, and an action that fits the failure (sign in again with a return to the page they were on, back-and-retry for a stale form, wait N seconds for a rate limit). `ErrorPages` gives the framework's own views (404, 400, production 500) the same page instead of the stock English templates; the development stack-trace template stays stock and is never served in production.

### 4.2 Messaging, Outbox & Idempotency
- **SR-MSG-1.** Domain writes stage outbox messages in-transaction (`OutboxService.stage(topic, subject, payload)`); `OutboxRelay.relayBatch()` dispatches every pending row to `JobRouter::dispatch(topic, data)`.
- **SR-MSG-2.** `JobRouter` fan-out is **fault-isolated** (each consumer wrapped in `isolate()`) and **idempotent**; unknown topics are acked to `unknown()`.
- **SR-MSG-3.** Inbound/processed messages deduplicate via `IdempotencyStore` + `processed_messages`; async work runs via `queue_jobs` + `QueueService`.

### 4.3 Security
- **SR-SEC-1 (Passwords).** Argon2id hashing, ≥12 chars, needs-rehash upgrade path (FR-ID-003).
- **SR-SEC-2 (Sessions/Tokens).** DB-backed sessions; access/refresh tokens; recovery codes; credential-setup tokens (SHA-256 stored, plaintext shown once).
- **SR-SEC-3 (Rate limiting, FR-RL-001..006).** Reusable token-bucket + sliding-window limiter over Redis Lua (atomic), fail-closed fallback, hierarchical stricter-only overrides, hashed keys; a CI4 filter returns 429 + `Retry-After` for BOTH representations — problem+json for API clients, a localized page telling a member how long to wait for browsers (SR-ARC-3).
- **SR-SEC-4 (Headers/CSRF).** Secure response headers, CSP enabled, per-route CSRF strategy (`webcsrf`/`csrf`), `invalidchars`, `honeypot`, `forcehttps` filters.
- **SR-SEC-7 (Error pages disclose nothing, and redirect nowhere).** An error page's action targets are built server-side and held to one same-site rule (a single leading `/`, no `//host` or `/\host`, no control characters, no overlong target, never the sign-in page itself), from either the raw request target or a same-host `Referer` — so a refusal cannot be turned into an open redirect. A 500 names no exception class, file, line or message; a 403 shows only the PDP's stable reason code as a reference; a 401 never distinguishes "no such account" from "expired session" beyond whether the caller presented a credential.
- **SR-SEC-5 (Audit).** Every consequential write records a hash-chained audit entry with actor, action, object, outcome, and redacted metadata (secret-bearing keys stripped defensively).
- **SR-SEC-6 (MFA).** TOTP factors (`spomky-labs/otphp`), recovery codes, WebAuthn.

### 4.4 Internationalization
- **SR-I18N-1.** Six locales (en/es/fr/pt/zh/ar) with full key parity, English fallback, RTL for Arabic, and resource-light merged translation loading (file `lang()` + DB `notification_templates` + locale tables; version-stamped, DB-free hot path).

### 4.5 Dynamic Navigation Menu
- **SR-NAV-1.** A universal, resource-light dynamic menu: declarative `MenuItem` catalog + `MenuService`, visibility derived from existing systems (PDP / route `authorize:` / `GroupScopeResolver` / `EffectiveConfigResolver`), version-stamped cache with O(1) INCR invalidation, batch `permittedActions`, ETag/304, and volatile badges computed off the cached tree.

### 4.6 Observability & Ops
- **SR-OPS-1.** Health/readiness endpoints check DB + Redis (§14.2 of original SRS).
- **SR-OPS-2.** Background **sweeps** (registration/dormancy/verify-expiry/relay-health/proposal-aging/fraud-review-aging/follow-up-decay — 22 registered) run as bounded, config-gated, idempotent jobs.

---

## 5. Functional Requirements by Module

### 5.1 Identity
- **FR-ID-1 Accounts.** Register, profile, preferences, user identities (social), consents. `AccountService.register` emits a `journey.signal.member.registered` signal (best-effort).
- **FR-ID-2 Authentication.** Password (Argon2id), social auth, WebAuthn, session + token issuance; login-attempt tracking + risk scoring.
- **FR-ID-3 MFA.** Enrol/verify TOTP factors, recovery codes.
- **FR-ID-4 Account lifecycle (state machine).** States: `prospect`, `pending_verification`, `active`, `suspended`, `locked`, `deactivated`, `anonymized`, `merged`. Transitions are guarded by an explicit allowed-edge map; every change writes an immutable `account_state_transitions` row with actor + reason.
  - **FR-ID-4a Teardown cascade.** Entering `suspended`/`deactivated`/`anonymized` stages an outbox teardown topic that fans out to revoke access, pause journeys, and end memberships (marker-stamped).
  - **FR-ID-4b Reactivation (true inverse).** Returning to `active` from `suspended`/`deactivated` stages `account.reactivated`, which restores ONLY teardown-marked journeys/memberships (never a member-initiated leave).
- **FR-ID-5 Identity policy.** Per-jurisdiction uniqueness switches, min age, region rules; effective-policy resolution.
- **FR-ID-6 Merges.** Maker-checker identity-merge request/review with belonging + journey reassignment on approval.
- **FR-ID-7 Admin roster + lifecycle UI.** `/members` roster with per-row CSP-safe lifecycle actions (suspend/lock/reactivate/deactivate/anonymize) limited to legal transitions, each requiring a reason + CSRF; deep-link to lifecycle history.

### 5.2 AccessControl
- **FR-ACL-1 RBAC.** Roles, permissions (41 bits), role-permission mapping, role assignments; `AuthorizationService` is the PDP.
- **FR-ACL-2 RuBAC/ABAC.** General-purpose rule engine (`RuleEngine` over `AbacConditionEvaluator`) with revisioned rules; policies in `abac_policies`.
- **FR-ACL-3 Scope model.** Grants apply over `self`, `self_and_descendants`, `descendants_only` (excludes own group), or a hand-picked multi-group set (`grant_scope_groups`); any leader may sub-assign within their own scope, bounded by containment.
- **FR-ACL-4 Cross-cut coverage.** Opt-in per grant (`include_crosscut`, default OFF); coverage is down-only via `group_crosscut_links`.
- **FR-ACL-5 Delegation / access request / break-glass.** Full writers with maker-checker reviews (`delegations`, `access_requests` + reviews, `break_glass_sessions` + reviews); security events logged.
- **FR-ACL-6 Grant cascade.** `GrantCascadeService` propagates/revokes grants consistently across the scope model.
- **FR-ACL-7 Clearances/labels.** `subject_clearances`, `security_labels`, `object_labels` support label-based checks.

### 5.3 Groups
- **FR-GRP-1 Hierarchy.** Deep tree (National→Region→Area→Local Assembly→Fellowship→Senior Cell→Cell) with `parent_id`, materialized `path`, `depth`, and a `group_closure` ancestor/descendant projection (cycle-safe).
- **FR-GRP-2 Lifecycle.** `active`/archived/dissolved/merged with reason + actor; `group_lifecycle_transitions` history.
- **FR-GRP-3 Kinds/taxonomy (Option B).** `group_kinds` taxonomy + nullable `groups.kind_code`; kind is advisory (`default_placement`) and MUST NOT change scope.
- **FR-GRP-4 Cross-cut groups.** Orthogonal teams/ministries/networks via `group_crosscut_links` spanning hierarchy nodes (down-only).
- **FR-GRP-5 Memberships.** Typed memberships (`membership_type` + `role`), one-active-per-slot guard, join/leave events, pending-approval workflow, conflict detection, teardown/restore hooks.
- **FR-GRP-6 Effective config.** Per-group capability values resolved over ancestry via `EffectiveConfigResolver` (`group_configurations`).
- **FR-GRP-7 Public directory.** `/g` renders a location directory nested Country→State→City (`GroupPublicService.directoryByLocation`); public group pages; self-join + invite flows honoring join policy and discovery status.
- **FR-GRP-8 Location fields.** `groups.address_id` → Geo `addresses`, plus denormalized `region_label`/`city_label`/`location_group_key`/lat-long for cheap directory bucketing.

### 5.4 Journey (Win–Build–Send)
- **FR-JRN-1 Configurable stages.** `journey_stages` model both first-class **phase** (win|build|send) and stage codes; per-group overrides supported. Point computation is safe (no `eval()`).
- **FR-JRN-2 Signals & rules.** `JourneySignalService.ingest` matches `gamification_rules`-style journey rules to advance/open/set stages; entry rules gate on conditions (e.g. `member.registered`→prospect, `member.converted`→first_timer).
- **FR-JRN-3 Transitions & history.** Stage moves and lifecycle `status` moves both write `member_journey_transitions`; `status` moves are guarded (state machine) and excluded from progression analytics.
- **FR-JRN-4 Involvement triage.** Involvement-based birds-eye triage (`InvolvementService`) with snapshots; sourced via `InvolvementSourcePort` from Events/Courses/Contributions.
- **FR-JRN-5 Proposals.** Stage-change proposals with aging + supersede listeners; opt-in config.
- **FR-JRN-6 Attribution & recommendations.** `JourneyAttributionService` + `JourneyRecommendationService` for next-step guidance.

### 5.5 Events
- **FR-EVT-1 Event lifecycle.** Draft→published→(cancelled|completed); soft-**archive** (`archived_at`, orthogonal to status) drops events from active lists/feeds without deletion.
- **FR-EVT-2 Registration & ticketing.** Registrations, ticket types, holds, waitlists, orders, order items, promo codes, transfers, refunds; capacity/waitlist accounting.
- **FR-EVT-3 Check-in.** QR/manual/streaming check-in; kiosks + offline queue + nonces; **walk-in** flag distinguishes unregistered attendance without forking a fake registration.
- **FR-EVT-4 Attendance & attribution.** `event_attendance` with group attribution; multi-group check-in configurable (`group_credit_mode`, default per_group).
- **FR-EVT-5 Certificates.** Templates, background render job, issue/revoke, QR verification, zero-attendance skip.
- **FR-EVT-6 Feedback & quizzes.** Versioned feedback forms/questions/answers; auto/reviewed scoring; min-N aggregation.
- **FR-EVT-7 Logistics.** Plans, resources, suppliers, catering aggregates, seating areas, staff roster, accessibility needs.
- **FR-EVT-8 Budgets & expenses.** Budgets, expenses, expense approvals (maker-checker).
- **FR-EVT-9 Media.** Upload, scan job, EXIF strip default, review workflow, clean+approved gating.
- **FR-EVT-10 Reporting & feeds.** On-demand mobilization report + immutable snapshot + group roll-up; ICS calendar feeds with feed tokens.

### 5.6 Contributions
- **FR-CON-1 Causes & intents.** Causes, contribution intents, and confirmed `contributions` with state machine.
- **FR-CON-2 Attribution.** Prefers receiving group; membership fallback → member's own group (rolled to ancestors); no group → no org-level. Credits the exact group and accumulates to every ancestor. First-class `project_code` column.
- **FR-CON-3 Ledger.** Double-entry `journal_entries`/`journal_lines`; `LedgerService`.
- **FR-CON-4 Manual capture.** `manual_contribution_records` + `ManualContributionService` for offline/manual giving.
- **FR-CON-5 Commitments & partnerships.** Giving commitments/pledges; partnership level definitions + user partnership status (with level-change outbox events).
- **FR-CON-6 Refunds.** Refund request/approve (maker-checker), payment transactions.
- **FR-CON-7 Reconciliation & webhooks.** Reconciliation cases; signed webhook inbox with signature verification and idempotent processing.
- **FR-CON-8 Metrics.** `giving_metrics` rollups feeding dashboards.

### 5.7 Gamification
- **FR-GAM-1 Points engine.** Safe point computation (no eval), configurable measure (default points), rejection adapter; `point_ledger`.
- **FR-GAM-2 Awards.** Badges, achievements (+progress), ranks, streaks — group/team-scoped and individual, first-class.
- **FR-GAM-3 Seasons.** Season definitions, transitions, balance snapshots.
- **FR-GAM-4 Campaigns.** Group campaigns with tiers, teams, team standings/members, progress + progress events, recognitions, awards.
- **FR-GAM-5 Leaderboards & rollups.** Org-wide + group-wide ranking; every ancestor level; opt-in ancestor award minting (`rollup_awards` off by default); `group_point_rollup`.
- **FR-GAM-6 Configurable WBS activities.** First-class phase (win|build|send) AND categories; follow-ups earn points as Build/Send; activity catalog; rule revisions.
- **FR-GAM-7 Feature gating.** All gamification features gated via hierarchical group config (default OFF), not env/global.
- **FR-GAM-8 Fraud review.** Fraud reviews with aging.

### 5.8 Referrals / Outreach
- **FR-REF-1 Referral links + tracking.** Single-use/generated links (SHA-256 hashed token, plaintext shown once), click tracking, attribution.
- **FR-REF-2 Prospects / Contact address book.** `prospects` extended to a full contact book (owner, assigned group, linked user, source, contact details, journey_stage mirror, temperature hot/warm/cold, follow-up triage, invite context, consent-gated coordinates). Extends existing tables (no fork). Location private (GPS only with two-flag consent).
- **FR-REF-3 Dated decisions.** ≥3 dated decisions per prospect, at least one `join_group`; a `join_group` decision creates the membership + emits a journey signal.
- **FR-REF-3b Integration decisions (the standalone dated decisions; baptisms separated 2026-09-23).** The integration lifecycle requires **four major user decisions, each with a date, each standing alone**: `salvation`, `water_baptism`, `holy_spirit_baptism`, and `foundation_course`. (Earlier shape bundled both baptisms as one `baptism` group with a `baptism_mode` both/any knob — per direct instruction each baptism now stands alone; `IntegrationConfig::normalize()` expands a legacy `baptism` code in `required_groups` to **both** standalone baptisms, and `baptism_mode` is gone.) They may all happen in one day or on different dates, at an invitation, an event, or staff/member-assisted registration. `prospect_decisions` is extended (idempotent migration `000091`) with a nullable `user_id` (prospect XOR user subject), `status` (`pending|confirmed|rejected`), `source` (`assisted|self|landing|event_guest|derived_course|derived_completion|system`), `source_ref` (idempotency anchor for derived rows, backed by two UNIQUE keys), and `decided_by`/`decided_at`. `IntegrationService` owns the non-assisted half: self-declarations are stored **pending** and only count once the owning mentor (`prospects.owner_user_id`) or the member's sponsor (`SponsorshipService::activeSponsor`) confirms them (maker-checker, **no TTL**); a foundation-category course enrolment (and optionally completion) auto-appends the decision via the `IntegrationDecisionsPort` seam on `EnrollmentService` (best-effort, idempotent, no course group ⇒ fail closed). The assisted path (`ContactBookService::recordDecision`) now validates the shared `IntegrationDecision` catalog and refuses future dates. **Integration gates the Journey:** `JourneyService::transition()` consults an optional `IntegrationGatePort` before any write and refuses a move into a configured gated stage (default `in_foundation`, `established`) for a member who is not integrated (`INTEGRATION_REQUIRED`) — config-gated via capability `referrals.integration_decisions`, **default OFF**. Surfaces: `my/integration` (member self-service, menu item `overview.integration`, unmasked personal workspace) and `me/integration-decisions` (the mentor's confirmation queue; `MenuCoverage::EXCLUSIONS`). No new permission bits (41 frozen). Full design: **`docs/INTEGRATION_DECISIONS.md`**.
- **FR-REF-4 Downline.** Birds-eye by stage/condition (hot/warm/cold); owner and group-subtree views; summaries.
- **FR-REF-5 Follow-ups.** Full follow-up module (also earns gamification points as Build/Send); also manages the contact's event/course registrations (reuse Events/Courses tables).
- **FR-REF-6 Onboarding placement (a prospect is NOT offered a choice of group).** Every system user belongs to a particular group, and a prospect is placed in **the group of the mentor/sponsor who owns them** — never a group the prospect picks. `ProspectGroupResolver::homeGroupOf()` derives a mentor's home group from their active memberships (a group they **lead** beats one they attend; then the **deepest/most local** group; then earliest joined; an archived group or no membership ⇒ no home). `ContactBookService::createContact()` / `bulkCreate()` persist that derived placement, a `join_group` decision targets the contact's **placement** (a submitted `target_group_id` cannot relocate them), and `assigned_group_id` is deliberately **not** editable via `updateContact()`. Linking a contact's platform account asserts the belonging invariant through `GroupMembershipPort::ensureBelonging()` → `GroupMembershipService::ensureBelonging()` (no-op when the user already belongs anywhere; belonging is never *moved* by it).
- **FR-REF-7 Inactivity transfer.** When a prospect has been quiet for **X weeks** and a **different** mentor/sponsor follows them up (typically inviting them to that mentor's event), the belonging **and** the sponsor move to that mentor's group. X is **hierarchical group config** — capability `referrals.prospect_transfer`, value `{"enabled":true,"inactive_weeks":8}` (a bare int is also accepted), resolved by `EffectiveConfigResolver` off the group that currently holds the contact; absent/disabled/0 ⇒ **OFF** (default), clamped to 1..104 weeks. Inactivity is measured from `last_contacted_at`, falling back to `created_at`; no baseline ⇒ never transferred on a guess. `ProspectTransferService::evaluate()` is a read-only decision (verdicts: `inactive_threshold_met`, `still_active`, `same_mentor`, `same_group`, `transfer_disabled`, `new_mentor_has_no_group`, `no_activity_baseline`, `no_new_mentor`); `apply()` ENDS the old `group_members` row (history kept), opens the new one as an assisted `system` join (no approval queue — the mentor vouches), re-parents the sponsorship, re-points `prospects.owner_user_id`/`assigned_group_id`, records the touch, and appends append-only provenance to **`prospect_group_transfers`** (from/to group + owner, trigger type/id, the threshold in force, observed days inactive, and both membership ids + the sponsorship id). Issued attributions, points and certificates are untouched — the past stays where it happened. A same-group mentor change is a sponsor re-parent, **not** a transfer.
- **FR-REF-7b Transfer review queue (maker–checker).** The same policy carries a third key — `{"enabled":true,"inactive_weeks":8,"requires_review":true}` — resolved by `ProspectTransferService::requiresReview()` off the group that currently holds the contact. **Default OFF**, so a body that only set a week count keeps auto-applying exactly as before. Where it is ON, a due transfer is **queued instead of applied**: `ProspectTransferReviewService` (mirroring `SponsorReassignmentService`) writes `prospect_transfer_requests` (from/to group + owner, the maker, an optional nominated approver, the reason, the trigger, and the **evaluation snapshot** — threshold in force and observed days inactive — so the checker sees the consequences before deciding) plus an append-only `prospect_transfer_reviews` trail, and `captureGuestFromInvite()` returns `transferred:false, transfer_queued:true` with the request id. Approval is the checker step: **segregation of duties** (maker ≠ checker, re-asserted in the service and by the PDP), **eligibility re-checked at decision time** (a contact followed up while the request sat in the queue, a receiving mentor who lost their group, or a subtree whose policy changed ⇒ refused 409 and marked `blocked`, never silently applied) — there is deliberately **no TTL**: a request stays pending until a human decides it, and staleness is caught by that re-check rather than by a clock — and delegation to `ProspectTransferService::apply()` — so the queue changes **who signs off**, never **what a transfer does**. The applied provenance row carries `request_id` back to the request that authorized it (NULL for an automatic transfer). Reject/cancel close it without moving anyone; a closed request cannot be decided twice. A **manual** proposal is judged by the same policy — a request that is not `due` is stored (so the trail and the reason exist) but cannot be approved: no side door around the inactivity rule. Surfaces: `POST /referrals/prospect-transfers` (maker), `GET …/pending` (checker dashboard), `GET …/{id}` (detail + trail), `POST …/{id}/approve|reject|cancel`, plus the PEOPLE menu item `people.transfers`. Gated by the **frozen** `sponsor.reassign.approve` capability (the same duty: a second leader confirming a re-parenting) — no new permission bit. **Subtree-scoped, not org-wide:** the checker's queue filters every row through the same PDP scope check the rest of the platform uses (`canManageGroupScope('sponsor.reassign.approve', group)`, memoised per group) — **either** side of the move counts — out-of-scope requests read as 404 and are refused on write with `ACCESS_OUT_OF_SCOPE`, and `SponsorReassignmentController` applies the identical rule keyed on the member's own primary group (`GroupScopeResolver::primaryMembershipGroup`), so both review queues behave alike without a second scope implementation.
- **FR-REF-8 One person is one contact.** `captureGuestFromInvite()` **reuses** an existing contact instead of creating a duplicate — matched on `email_hash`, else on phone via `ContactBookService::phoneKey()` (separators dropped; `+233`/`233`/`00233` folded to the local `0…` form), so "024 123 4567", "+233241234567" and "233241234567" are one person. The transfer is evaluated **before** anything is written, so the new mentor's own touch can never reset the clock it is measured against. The host may register a guest they do not (yet) own for **their** event (`recordAttendance(..., bypassOwnership: true)`) — ownership moves only through FR-REF-7, never as a side effect of an RSVP.

> **Design record:** `docs/ONBOARDING_GROUP_PLACEMENT.md`.
- **FR-REF-6 Sponsorships.** One active sponsor per member (`sponsorships` with active_key uniqueness); sponsor reassignment (maker-checker); auto-sponsor on member creation.
- **FR-REF-7 Fraud.** Fraud verdicts + reviews on referral abuse.
- **FR-REF-8 Guest capture / bulk.** Guest capture from invite; staff bulk sign-up bounded to leader's scope (`GroupScopeResolver`).

### 5.9 Streaming
- **FR-STR-1 Streams.** Stream lifecycle, destinations, co-hosts, archives.
- **FR-STR-2 Overlays.** Configurable stream overlays.
- **FR-STR-3 Relays.** Relay health monitoring + incidents + health sweep.
- **FR-STR-4 Chat/moderation.** Chat messages, reactions, polls + votes, bans, moderation, viewers.
- **FR-STR-5 Live giving.** Stream giving configs + intents (live-gated).
- **FR-STR-6 Engagement.** Engagement metrics + metric samples.

### 5.10 Integrations
- **FR-INT-1 Connector catalog.** Provider adapter catalog + connector profiles.
- **FR-INT-2 Credential vault.** Encrypted `connection_credentials`; credential-setup tokens.
- **FR-INT-3 Connections.** Integration connections + connection tests; provider events.
- **FR-INT-4 Custom adapters.** Custom-adapter SDK with maker-checker review (`custom_adapters` + reviews).
- **FR-INT-5 Reliability.** Provider circuit state, quota usage, reliability service, fallback plans.
- **FR-INT-6 Streaming OAuth.** OAuth flows for streaming providers.
- **FR-INT-7 Per-group notification credentials.** An `integration_connections` row may belong to a hierarchical body (`group_id`) rather than the whole organization, so each body supplies **its own** provider account: secrets go into the write-only, versioned `CredentialVault` (mNotify `api_key`; Nalo `username`/`password`/`auth_key`), the approved sender id lives on the connection, and only non-secret wire config is kept in `settings`. A body shares its account downward with an explicit `capability_grants` row (`sms.send`) whose `scope_mode` is `self`, `self_and_descendants`, `descendants_only` or a hand-picked `groups` set, with `include_crosscut` opt-in and an optional `starts_at`/`expires_at` window. `ConnectionService::grantCapability` refuses a grantee outside the account **owner's** subtree (`GRANT_OUT_OF_SCOPE`, 403); org-wide accounts may be shared anywhere. Approval, testing and activation are unchanged (maker-checker on `/integrations/connections`).

### 5.11 Community
- **FR-COM-1 Content.** Posts, topics, post-topic links, comments, reactions.
- **FR-COM-2 Moderation.** Content sanitizer, content reports, moderation actions, retention purge.

### 5.12 Notifications
- **FR-NOT-1 Templates & channels.** Localized templates, channels, deliveries.
- **FR-NOT-2 Campaigns.** Notification campaigns with audience resolution (`CampaignAudienceResolver`).
- **FR-NOT-3 Preferences & suppressions.** Per-user preferences, suppressions, retention policy gate.
- **FR-NOT-4 Real channel transports.** `NotificationDispatcher` performs actual outbound I/O through a lazy `TransportRegistry` keyed by channel; a channel with no transport is a **hard error** (the queue retries and operators see the misconfiguration) rather than a silent "sent". Outcomes map onto the delivery lifecycle + circuit breaker: `accepted → sent`, `rejected → terminal failure (no retry, breaker untouched)`, `failed → nack/retry + breaker fault`.
  - `email` — `EmailTransport` (transactional-provider JSON send over `ProviderHttp`).
  - `sms` — **`SmsProviderChain`**: **`MNotifySmsTransport`** (mNotify/BMS *Quick Bulk SMS*) primary, **`NaloSmsTransport`** (Nalo Solutions reseller SMS) fallback, order from `SMS_PROVIDER_ORDER`. The chain is itself a `ChannelTransport`, so the dispatcher/queue/breaker are unchanged. It fails over on a provider **fault** only (`failed`) — **never** on a permanent per-message `rejected` (another provider would refuse the same undeliverable message), stops at the first `accepted` (no double send/charge), **skips** providers this environment never configured, and fails loudly when none is configured. See §7.
  - `inapp` — `InAppTransport` (no external provider).
- **FR-NOT-5 Credential resolution per body (fail closed).** When a `GroupCredentialSource` is wired, `SmsProviderChain` sends on credentials the **sending body provided or was granted** — never on another body's account and never on the platform's env key. `NotificationCredentialResolver` unions the group's own active connections with active connections named by an active, in-window grant whose scope covers the group (`GroupScopeResolver::grantCoversScoped`; an ancestor merely owning an account is *not* enough), keeps **one credential per provider** with the nearest winning (own → … → org-wide → off-chain), matches capability `{channel}.send` exactly or via `.*`, and hands secrets to a transport **only** through a `withSecrets()` callback. A delivery with no group, or a group with no usable credential, is **rejected permanently** with a reason naming the group; a hop whose credential has no stored secret is skipped for the next one. `planFor()` returns the same answer without secrets — the "effective chain" a leader sees. The resolver is channel-agnostic, so email/push adopt it without redesign.
- **FR-NOT-6 Group credentials screen.** `/notifications/credentials` (`GroupCredentialController`, menu `comms.credentials`, gated by `provider.configure`, six locales) shows the selected group's effective sending chain (provider, own vs. shared-with-us, the share that reaches it, sender id, ready/no-secret), the accounts inside the actor's leadership scope with a write-only secret-slot form (storing rotates the slot), their shares with reach and window plus revoke, a share form (grantee, capability, `scope_mode`, hand-picked groups, cross-cut opt-in — offered only for an `active` account), and an add-account form (group, catalogue-validated SMS adapter with its version pinned from the catalogue, display name, sender id, optional per-body `settings.provider_order`). Every read and write is bounded by the PDP to the actor's own subtree, all writes delegate to `ConnectionService` (one writer, no fork), secrets are never echoed, and the secret slot is validated against the adapter's own slot list.

### 5.13 Geo / Venues
- **FR-GEO-1 Reference hierarchy.** regions→subregions→countries→states→cities→towns_villages, versioned + source-attributed + idempotently imported (`GeoImportService`, `geo_import_batches`).
- **FR-GEO-2 Addresses.** Org address records referencing the geo hierarchy, with lat/long/`geo_point` (POINT SRID 4326, nullable) + precision + consent metadata + geocode jobs.
- **FR-GEO-3 Venues.** Facility records (`venues`) linked to `address_id`, with type/status/capacity/contact/hours/accessibility; discovery status (public/private/unlisted); optimistic-locked CRUD.
- **FR-GEO-4 Venue↔Group assignment.** M:N `venue_group_assignments` (primary/secondary/overflow).
- **FR-GEO-5 Location consent.** `location_consents` + person-precision resolution; venue data is non-personal (not consent-gated).
- **FR-GEO-6 Proximity.** `ST_Distance_Sphere` nearby-venue search guarded by `geo_point IS NOT NULL`.

- **FR-GEO-7 Unified global→local directory (SHIPPED 2026-09-20).** Two surfaces over one model: the public drill-down **Country ▸ State ▸ City ▸ Venue ▸ groups** (`GET /g/map`, auth-free, `GroupPublicService::geoDirectory()` + pure `nestByGeoVenue()`) and the admin venue-centric map (`GET /venues/directory`, `venue.manage`, `LocationService::venueDirectory()` + pure `nestVenuesByGeo()`). CSP-safe no-JS views, 6 locales, RTL-aware. Unresolved location sinks to a pinned **Unlocated** bucket; a placed group with no venue row hangs on a synthetic "(No venue)" node.
- **FR-GEO-8 A group's geo comes FROM ITS VENUE — never replicated.** The location chain is `groups.primary_venue_id → venues → Geo reference`; **venues are the only entity carrying resolved geo and GPS** (`venues.{country,state,city,town_village}_id`, `*_label`, `location_group_key`, `latitude`/`longitude`), written solely by `LocationSyncService::stampVenue()`. `groups` stores **no** place ids or coordinates (migration `000085_GroupGeoLivesOnVenue` dropped the replicated columns added by `000064`/`000078`), and `groups.address_id` is a postal/contact address, **not** a geo fallback — a group with no venue is Unlocated. Consequence: moving a venue is a single-row write that re-places every group meeting there, with no fan-out and no drift window. `geo:backfill-location` stamps venues and reports group placement coverage (placed / venue-without-geo / no-venue) plus `primary_venue_id` ↔ `venue_group_assignments` drift.

> **Design record:** `docs/GROUPS_VENUES_GEO_UNIFICATION_DESIGN.md` (includes the correction banner explaining why the first cut's group-side denormalization was removed).

### 5.14 Meetings
- **FR-MEE-1 Meetings.** Meetings, participants, attendance evidence; bridges to Events attendance via `EventAttendancePort` (welfare/integration checks factored via `integration_check`).

### 5.15 Courses
- **FR-CRS-1 Courses.** Courses, modules, lessons, enrollments, completions (with override permission), learning content connections + experience statements (xAPI-style) + progress; enrollment gating and teardown.

### 5.16 Reporting
- **FR-RPT-1 WBS funnel.** `DashboardService.wbsFunnel` — Win/Build/Send rollup with as-of timestamp, filters, source state, completeness/delayed-job warning, and small-cohort suppression. Prospect counting reconciled to open leads only (no double count with members).
- **FR-RPT-2 Member dashboards.** `MemberDashboardService`.
- **FR-RPT-3 Exports.** `report_exports` + `ExportService`.

### 5.17 Admin / Settings
- **FR-ADM-1 Platform settings.** `platform_settings` (global security/financial/abuse limits — not weakenable by children); `feature_flags`; config audit.
- **FR-ADM-2 Effective config.** `EffectiveConfigResolver` over group ancestry with four inheritance modes.

### 5.18 Audit
- **FR-AUD-1 Hash-chained log.** Append-only `audit_log`; per-org sequence; `entry_hash`/`prev_hash` chain; defensive secret redaction; `metadata` persisted as JSON; `verifyChain()`.

---

## 6. Data Model (high level)

~209 tables across the 19 modules (full list in migrations). Anchor entities:

- **Identity/Access:** `organizations`, `users`, `user_identities`, `sessions`, `access_tokens`, `refresh_tokens`, `mfa_factors`, `account_state_transitions`, `identity_policies`, `identity_merge_requests`, `roles`, `permissions`, `role_permissions`, `role_assignments`, `abac_policies`, `rules`, `delegations`, `access_requests`, `break_glass_sessions`, `capability_grants`, `grant_scope_groups`, `subject_clearances`.
- **Groups/Geo:** `groups`, `group_closure`, `group_members`, `group_kinds`, `group_crosscut_links`, `group_configurations`, `group_lifecycle_transitions`, `regions`/`subregions`/`countries`/`states`/`cities`/`towns_villages`, `addresses`, `venues` (**the only carrier of resolved geo + GPS**), `venue_group_assignments`, `location_consents`. `groups` holds `primary_venue_id` as the link that carries its location — no place ids or coordinates of its own (FR-GEO-8).
- **Journey:** `journey_stages`, `member_journeys`, `member_journey_transitions`, `journey_stage_proposals`, `member_involvement_snapshots`.
- **Events:** `events`, `event_registrations`, `event_ticket_types`, `event_orders`, `event_attendance`, `event_certificates`, `event_feedback_*`, `event_logistics_plans`, `event_budgets`/`event_expenses`, `event_media`, `event_invitations`/`event_invite_links`.
- **Contributions:** `causes`, `contribution_intents`, `contributions`, `journal_entries`/`journal_lines`, `giving_commitments`, `partnership_level_definitions`, `refund_requests`, `reconciliation_cases`, `webhook_inbox`, `giving_metrics`.
- **Gamification:** `point_ledger`, `badges`/`badge_awards`, `achievement_definitions`/`user_achievements`, `rank_definitions`, `streak_definitions`/`user_streaks`, `gamification_seasons`, `group_campaigns`/`campaign_*`, `group_point_rollup`.
- **Referrals:** `referral_links`, `referral_clicks`, `referral_attributions`, `prospects`, `prospect_decisions`, `prospect_group_transfers` (+`request_id`), `prospect_transfer_requests`, `prospect_transfer_reviews`, `sponsorships`, `sponsor_reassignments`, `sponsor_reassignment_reviews`, `follow_ups`.
- **Platform:** `outbox_messages`, `processed_messages`, `queue_jobs`, `audit_log`, `platform_settings`, `feature_flags`, `notification_*`.

**Conventions:** UUIDv7 external IDs; UTC everywhere; `organization_id` on every domain table; soft-state flags (`archived_at`, `deleted_at`, lifecycle status) rather than hard deletes where history matters.

---

## 7. External Interfaces

- **API.** 557 routed endpoints; canonical operations documented in OpenAPI 3.1 (`public/openapi.json`), CI drift-guarded. JSON via `Result` envelope.
- **Web.** Server-rendered CSP-safe views in all six locales.
- **Feeds.** ICS calendar feeds (Events) with feed tokens.
- **Webhooks.** Signed inbound payment/provider webhooks (Contributions, Integrations) with signature verification + idempotency.
- **Integrations.** Outbound connectors via the adapter catalog + custom-adapter SDK.
- **SMS provider — mNotify / BMS (Ghana).** `MNotifySmsTransport` posts to `https://api.mnotify.com/api/sms/quick` with the API key in the **query string** (`?key=`) and a JSON body `{recipient:[…], sender, message, is_schedule, schedule_date}`; delivery state is **polled** from `GET /api/status/{campaignId}` (mNotify exposes no inbound SMS webhook, so the catalog entry declares no `verifyWebhook` capability). Provider specifics the adapter handles: a **200 response can still be an error** (only `status:"success"` / `code:"2000"` is accepted, with `summary._id` stored as the campaign id); `recipient` is an array so a blast is one campaign; and `sms_type:"otp"` costs extra per campaign, so it is **opt-in** (explicit `meta.sms_type` or an unmistakable otp/mfa/verification category — never inferred from message text). Configured by env: `MNOTIFY_API_KEY` (or `SMS_API_KEY`), `SMS_API_BASE_URL`, `SMS_SENDER_ID` (must be pre-registered/approved with mNotify), `SMS_DEFAULT_COUNTRY_CODE` (default `233`, used to fold `+233…` into the local `0…` form). Unset key ⇒ transient failure + retry, never a silent "sent". Catalog entry: `mnotify_sms_v1`.
- **SMS provider — Nalo Solutions (Ghana), the FALLBACK.** `NaloSmsTransport` posts **form-encoded** to the reseller endpoint (`https://api.nalosolutions.com/smsbackend/clientapi/ResellerAPI/send_sms/`, both base and path env-overridable because reseller endpoints differ between account types) with `username`/`password` (or a single `auth_key`), `message`, `sender_id` (truncated to the 11 characters Nalo approves) and `recipients` as a **comma-separated** string in **international** form (`233XXXXXXXXX` — no `+`, no trunk `0`; the opposite of mNotify's local form, so each adapter normalizes independently). Like mNotify it can answer **HTTP 200 with an error body**, and its envelope shape varies by account, so acceptance is read **permissively**: a success-ish `status` (`success`/`ok`/`sent`/`1`/`200`), or a message/campaign id with no error text. An unrecognisable body is a transient failure — never a claimed "sent". Nalo exposes no documented inbound webhook or per-message status endpoint, so the catalog entry declares only `sendNotification` + `healthCheck` (FR-INT-011: never advertise an op the provider cannot fulfil) and it is the row the FR-INT-012 fallback matrix points at when mNotify faults. Configured by env: `NALO_SMS_USERNAME`/`NALO_SMS_PASSWORD` (or `NALO_SMS_AUTH_KEY`), `NALO_SMS_SENDER_ID` (falls back to `SMS_SENDER_ID`; approved separately in the Nalo portal), `NALO_SMS_API_BASE_URL`, `NALO_SMS_API_PATH`. Catalog entry: `nalo_sms_v1`.
- **SMS failover.** `SmsProviderChain` walks `SMS_PROVIDER_ORDER` (default `mnotify,nalo`): **accepted ⇒ stop**, **rejected ⇒ stop and report** (permanent, per-message — no double send), **failed ⇒ next provider**; only when every configured provider faults does the chain report `failed`, with both hops named in the reason, so one delivery row explains the whole chain and the queue retries. An unconfigured provider is skipped, not attempted; a typo'd order name is ignored and an omitted provider is demoted rather than dropped.

- **Per-group SMS credentials.** Each body's account is an `integration_connections` row (`group_id` = owner, `sender_identity` = approved sender id) with its secret in the encrypted, write-only `connection_credentials` vault; subtree access is an explicit `capability_grants` (`sms.send`) with a `ScopeMode` reach. The env block above is the platform bootstrap and the org-wide path — a body's messages are **refused, not borrowed**, when it has neither its own credential nor a grant covering it. Leaders manage this at `/notifications/credentials`; provider testing/approval/activation stay at `/integrations/connections`.

> **Design record:** `docs/NOTIFICATIONS_SMS_MNOTIFY.md` (both providers, the chain, and §10 per-group credentials + subtree sharing).

---

## 8. Non-Functional Requirements

- **NFR-SEC.** Argon2id, MFA, hash-chained audit, per-route CSRF, CSP, secure headers, rate limiting (fail-closed), encrypted credential vault, secret redaction, hashed tokens/keys.
- **NFR-PERF.** Resource-light hot paths (version-stamped caches, O(1) invalidation, batch permission checks, ETag/304, denormalized bucket keys, single bounded indexed reads).
- **NFR-I18N.** Six-locale parity + RTL + English fallback, test-enforced.
- **NFR-A11Y.** No-JS-dependent, CSP-safe views; accessible rate-limit/error messaging.
- **NFR-REL.** Transactional outbox, idempotent consumers, fault-isolated fan-out, optimistic locking on concurrent-edit surfaces (e.g. venues).
- **NFR-MAINT.** Modular HMVC, ports/adapters, no forked writers, frozen permission budget (41), standalone-test discipline.
- **NFR-OBS.** Health/readiness endpoints; correlation-id filter; audit + security events.

---

## 9. Configuration & Feature Gating
- Feature gating resolves through `EffectiveConfigResolver` over the group ancestry, default OFF, with four inheritance modes (inherit_only / ancestor_default_child_override / child_owned / not_inheritable).
- **Group-config capabilities in force** include `referrals.prospect_transfer` — the prospect inactivity-transfer policy `{"enabled":bool,"inactive_weeks":int,"requires_review":bool}` (a bare int or numeric/JSON string is also accepted; absent/disabled/0 ⇒ OFF; weeks clamped 1..104; `requires_review` defaults **false**). Resolved off the group that currently holds the contact, so each body sets its own grace period — and its own review requirement — or inherits its parent's (FR-REF-7/7b).
- **Notification transports** are env-configured, not group-configured: `EMAIL_API_BASE_URL`/`EMAIL_API_KEY`/`EMAIL_FROM_*`, `SMS_PROVIDER_ORDER`, `MNOTIFY_API_KEY`/`SMS_API_BASE_URL`/`SMS_SENDER_ID`/`SMS_DEFAULT_COUNTRY_CODE`, `NALO_SMS_USERNAME`/`NALO_SMS_PASSWORD`/`NALO_SMS_AUTH_KEY`/`NALO_SMS_SENDER_ID`/`NALO_SMS_API_BASE_URL`/`NALO_SMS_API_PATH` (see `.env.example`). A provider endpoint and sender identity are app-level secrets, not per-group OAuth, so they do not live in the CredentialVault.
- Global security/financial/abuse limits live in `platform_settings` and cannot be weakened by descendant groups.
- `feature_flags` for coarse toggles; `config_audit` records changes.

---

## 10. Security & Authorization Model (summary)
1. **Authentication** establishes the subject (password/social/WebAuthn + optional MFA).
2. **PDP (`AuthorizationService`)** evaluates RBAC bits + RuBAC/ABAC rules + scope (self/descendants/multi-group/cross-cut) + labels/clearances.
3. **Scope resolution (`GroupScopeResolver`)** bounds every leader/staff action to their subtree; sub-assignment is containment-bounded.
4. **Escalation paths:** delegation, access request (maker-checker), break-glass (time-boxed, reviewed) — all audited.
5. **Everything consequential is audited** via the hash-chained log.

---

## 11. Quality Assurance
- **Standalone suite:** 227 test files, 14,049 assertions, green (`tests/run-standalone.php`).
- **i18n parity test**, OpenAPI drift guard, PHPStan, PHPUnit.
- **Per-module lifecycle reviews** under `docs/` track findings to resolution.
- **Test-first discipline** for each feature; fault-isolation and idempotency explicitly tested for outbox consumers.

---

## 12. Traceability
- FR-by-FR coverage: `docs/SRS_FR_COVERAGE_AUDIT.md` (106 FRs — all confirmed gaps closed).
- Write-endpoint gap sweep: `docs/SRS_WRITE_ENDPOINT_GAP_SWEEP.md`.
- Module reviews: `docs/*_LIFECYCLE_REVIEW.md` (Access Control, Community, Contributions, Courses, Events, Gamification, Groups, Identity, Integrations, Journey, Meetings, Membership, Notifications, Referrals, Streaming).

---

## 13. Deferred / Out of Scope (explicit decisions)
- **Tier-0 secret-fallback hardening** and **Tier-1 KMS/Vault providers** — deferred (`docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md`); switchable later.
- ~~**Groups + Venues + Geo unification**~~ — **done** (migration `000085`): the venue is the single holder of geo (GPS + locality), and a group's location resolves **by reference** through `groups.primary_venue_id` rather than being denormalized onto `groups` (`docs/GROUPS_VENUES_GEO_UNIFICATION_DESIGN.md`).
- **Go-live gate items** historically deferred (now partially addressed as real-MySQL testing surfaced bugs): full `composer install` in this environment, HTTP/feature tests against a live server. Recent live-MySQL runs revealed and fixed schema-drift (`events.archived_at` migration) and an `audit_log` JSON-encoding defect — reinforcing the need for DB-dialect integration tests beyond the standalone suite.
- **Remaining backlog** (`docs/TODO_REMAINING_BACKLOG.md`): IN4 (blocked on Groups GR2/GR3), IN5, ID3 anonymization fan-out.

---

## 14. Known Risks & Limitations
- **Standalone tests use fake query builders** — they do not exercise real SQL dialect, so DB-specific bugs (row-constructor rendering, missing columns, SRID rules) can pass tests yet fail on MySQL. *Mitigation:* add live-DB integration tests (deferred).
- **Denormalized location labels on groups are not auto-synced** to their address; drift is possible until the Geo unification lands.
- **Proximity search has no spatial index** (nullable POINT constraint); adequate at single-org scale, may need revisiting at larger scale.
- **Single-organization boundary** — multi-tenant fan-out is column-ready but not operated.
- **Nalo Solutions' reseller contract — confirmed, and still overridable.** The endpoint (`https://api.nalosolutions.com/smsbackend/clientapi/ResellerAPI/send_sms/`) and its field names (`username`/`password`/`auth_key`, `message`, `sender_id`, comma-separated `recipients`) were **confirmed against the account's own API sheet on 2026-09-20**, so the transport ships as-is. They remain **configurable rather than hardcoded** as a feature, not as a hedge: base URL and path from env (`NALO_SMS_API_BASE_URL`, `NALO_SMS_API_PATH`) and every outbound field name in one private `NaloSmsTransport::payload()`, because reseller endpoints differ between account types and a future account must not need a code change. Response parsing stays deliberately **permissive** (a success-ish status, or an id with no error text) and an unrecognisable envelope is a *transient* failure, so an unexpected reply retries loudly instead of marking SMS "sent".
- **The transfer review queue reuses `sponsor.reassign.approve`** rather than spending a new permission bit (the budget is frozen at 41). A body that needs to separate "who may approve a sponsor re-parent" from "who may approve a group transfer" would need a new bit — a deliberate budget decision, not an oversight.

---

*End of current-state SRS. This document reflects the platform as built at the stated date and is intended as the baseline for the upcoming Groups + Venues + Geo design.*
