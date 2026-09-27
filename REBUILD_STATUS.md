# WBS Platform — Rebuild Status

The workspace was reset (all `app/Modules/*` code, tests, vendor, and toolchain lost).
Rebuilding faithfully from the session record. User tests & gives feedback.

## Environment to restore (user side)
- PHP 8.2+ with `sodium`, `mysqli`, `redis` extensions.
- `composer install` (composer.lock survived; CI4 4.7.x).
- MariaDB/MySQL: databases `wbs_platform` (default) + `wbs_platform_test` (tests).
  Data files under `/home/user/services/mysql-data` may still hold prior schema.
- Redis (optional; rate limiter fails CLOSED for security/finance when absent).
- `.env` survived (encryption.key + DB creds present).
- Register module namespaces in `app/Config/Autoload.php` (WBS\* => app/Modules/*).
- Register `ratelimit` filter alias in `app/Config/Filters.php` =>
  `WBS\Shared\Filters\RateLimitFilter`.
- Run migrations: `php spark migrate --all` and `--all -g tests`.
- Tests use `DatabaseTestTrait` ($migrate=true) which provisions the tests DB.

## Rebuilt so far
### Shared (DONE)
- Support/Result.php, Clock.php, Uuid.php, RedisFactory.php
- Security/SecretBox.php (libsodium envelope encryption; verified design)
- Http/RequestNegotiator.php, Http/BaseController.php (unified HTML/JSON)
- RateLimiting/RatePolicy.php, PolicyRegistry.php, RateLimitDecision.php, RateLimiter.php
- Filters/RateLimitFilter.php
- Config/RateLimitPolicies.php (all policies incl. contribution.checkout, course.*, webhook.payment, provider.configure)
- Config/Services.php (clock, redis, ratePolicyRegistry, rateLimiter)

### app/Config (DONE)
- Autoload.php (WBS\* module namespaces), Filters.php (ratelimit alias)
- Controllers/Home.php + Views/landing.php (unified HTML/JSON status page)

### Identity (DONE)
- Database/Migrations: 000001 organizations, 000005 users (+user_identities, sessions), 000008 mfa (mfa_factors, recovery_codes)
- Security: PasswordHasher (Argon2id), TotpService (RFC6238), RecoveryCodeService (HMAC single-use), RiskSignals, RiskEngine (0..100), StepUpPolicy (none/low/high)
- Config/Services.php (passwordHasher, totp, recoveryCodes, riskEngine, stepUpPolicy)

### Audit (DONE)
- Database/Migrations: 000002 audit_log (append-only, hash-chained, per-org seq)
- Services/AuditLogger.php (record + verifyChain; canonical-json digest; secret redaction)
- Config/Services.php (auditLogger)

### AccessControl (DONE)
- Database/Migrations: 000006 (permissions[code globally unique], roles, role_permissions, role_assignments, security_labels, subject_clearances, object_labels, abac_policies)
- Policy: AccessRequest, Decision, AbacConditionEvaluator (declarative all/any/not + eq/ne/lt/lte/gt/gte/in/nin/exists), Combinators/SegregationOfDutiesCombinator (SOD_SELF_APPROVAL)
- Services/AuthorizationService.php (PDP: MAC -> SoD -> ABAC deny-overrides -> RBAC -> default DENY)
- Config/Services.php (abacEvaluator, sodCombinator, authorization)

### Shared/Messaging (DONE — transactional outbox + queue, Phase 0 FR-ARC-005/006)
- Database/Migrations: 000003 outbox (outbox_messages, queue_jobs, processed_messages)
- Messaging/OutboxService.php (stage() inside caller tx), OutboxRelay.php (relay exactly-once, dedupe_key = outbox id)
- Messaging/QueueService.php (enqueue idempotent, reserve FOR UPDATE SKIP LOCKED, complete/fail exponential backoff + dead-letter)
- Messaging/IdempotencyStore.php (consumer-side seen/markProcessed, UNIQUE(consumer,key))
- Config/Services.php add: outbox, queue, outboxRelay, idempotencyStore

### Groups (DONE)
- Database/Migrations: 000007 (groups, group_closure, group_members)
- Services/GroupService.php (closure hierarchy depth 1-9, acyclic move, members)
- Config/Services.php (groups)

### Referrals (DONE)
- Database/Migrations: 000009 (referral_links cloaked, referral_clicks ip_hash, sponsorships active_key single-active, prospects consent, referral_attributions idempotency UNIQUE)
- Services/SponsorshipService.php (assign single-active + acyclic upline check, upline())
- Services/ReferralService.php (createLink cloaked code, recordClick hashed ip/ua, captureProspect consent-gate, attributeConversion idempotent)
- Config/Services.php (sponsorships, referrals)

### Geo (DONE)
- Database/Migrations: 000010 (regions, subregions, countries, states, cities, towns_villages, geo_import_batches, addresses+POINT, venues+POINT, location_consents, geocode_jobs)
- Services/GeoImportService.php (versioned/source-attributed/idempotent checksum upserts, validate, deactivate via flag)
- Services/LocationService.php (POINT SRID 4326 lon,lat maintenance; consent-gated person precision; grantConsent)
- Config/Services.php (geoImport, location)

### Notifications (DONE)
- Database/Migrations: 000011 (notification_templates versioned, notification_preferences opt-out/quiet/digest, notification_channels verified, notification_campaigns SoD, notification_deliveries snapshot+dedupe, notification_suppressions)
- Services/TemplateRenderer.php (allowlisted {{ns.field}} placeholders, no code exec, escape, fingerprint)
- Services/RetentionPolicyGate.php (FR-NOT-006 pre-dispatch: suppression/verified channel/opt-out/quiet-hours defer/frequency cap; essential categories bypass prefs)
- Services/NotificationService.php (gate -> delivery snapshot -> outbox dispatch; idempotent dedupe_key; updateDeliveryStatus)
- Services/CampaignService.php (draft->pending->approved->queued; SoD approver != requester; frozen audience_count)
- Config/Services.php (templateRenderer, retentionGate, notifications, campaigns)

### Gamification (DONE)
- Database/Migrations: 000012 (gamification_seasons active_key, gamification_rules versioned, point_ledger immutable UNIQUE(rule,subject,source_ref,entry_type), badges, badge_awards, season_balance_snapshots, season_transitions UNIQUE key, user_streaks, fraud_reviews)
- Services/SeasonService.php (ensureCurrentSeason, activeSeason, idempotent lock-protected rollover at org-tz 1 Jan, snapshot+archive)
- Services/PointsEngine.php (award data-configured rules, per-period cap, held+fraud_review, reverse compensating entries, balance, clearHeld)
- Config/Services.php (seasons, pointsEngine)

### Events (DONE — core online flow; D9-B paid/kiosk/logistics/expenses deferred)
- Database/Migrations: 000013 (events, event_registrations UNIQUE(event,user), ticket_holds atomic, waitlist_entries, event_attendance UNIQUE active_key one-per-person, event_attendance_group_attribution separate, checkin_nonces single-use)
- Services/EventService.php (create/publish/cancel; complete = post-event guard FR-EVT-011 -> completed|completed_no_attendance)
- Services/RegistrationService.php (atomic capacity = confirmed+live holds FOR UPDATE; waitlist overflow; hold TTL 600s; cancel promotes next)
- Services/CheckinService.php (signed single-use QR nonce HMAC, replay guard, one-active attendance, separate group attribution, manual w/ reason)
- Config/Services.php (events, eventRegistrations, checkin)

### Contributions / VBCS (DONE)
- Database/Migrations: 000016 (causes, payment_provider_configs versioned, contribution_intents idempotency_key, contributions state machine, payment_transactions UNIQUE(provider,provider_txn_id), journal_entries append-only UNIQUE source_ref, journal_lines, manual_contribution_records maker-checker, refund_requests, webhook_inbox UNIQUE(provider,event_id), reconciliation_cases). NOTE renumbered to 000016 (000014=Integrations, 000015=Courses reserved).
- Services/LedgerService.php (balanced double-entry post, reverseEntry compensating, accountBalance; integer minor units; idempotent source_ref)
- Services/ContributionService.php (createIntent idempotent, attachCheckout, markSucceeded posts ledger + stages contribution.succeeded outbox, markFailed; credit only on verified success)
- Services/WebhookInboxService.php (verify-before-apply, UNIQUE(provider,event_id) idempotent, quarantine unverified/unknown)
- Services/RefundService.php (request->approve[SoD approver!=requester 403]->execute idempotent, compensating ledger, stages contribution.refunded)
- Services/RewardCoordinator.php (idempotent consumer: onSucceeded awards points rule contribution.verified source_ref=contribution:{id}; onRefunded reverses)
- Config/Services.php (ledger, contributions, webhookInbox, refunds, rewardCoordinator)

### Integrations / UPAF (DONE)
- Database/Migrations: 000014 (provider_adapter_catalog versioned, connector_profiles cert-flow, integration_connections lifecycle, connection_credentials write-only aad-bound, capability_grants, provider_connection_tests, provider_events UNIQUE(provider,event_id))
- Canonical/Canonical.php (8 ops, 9 families, HTTP methods, sig algos, categories) + Canonical/ProfileValidator.php (rejects code sigs/non-HTTPS/IP literal/unapproved host/arbitrary headers)
- Services/CredentialVault.php (put versioned encrypted; useSecret callback-only no plaintext return; has), CatalogService.php, ProfileService.php (create validates+versions; advance cert flow; revoke), ConnectionService.php (create->setCredential->recordTest[pass=>tested]->submit->activate[payment/notification SoD 403]; grantCapability/revokeGrant)
- Database/Seeds/AdapterCatalogSeeder.php (11 adapters honest capabilities). Seed FQCN: php spark db:seed 'WBS\Integrations\Database\Seeds\AdapterCatalogSeeder'
- Config/Services.php (adapterCatalog, profileValidator, connectorProfiles, credentialVault, connections)

### Courses (DONE)
- Database/Migrations: 000015 (courses completion_rule/points_rule_code/badge_code, course_modules, lessons drip_unlock_at/drip_offset_days, enrollments UNIQUE(course,user), learning_progress UNIQUE(enrollment,lesson), course_completions UNIQUE(enrollment) idempotent, learning_content_connections SCORM/xAPI/LTI policy-gated, learning_experience_statements UNIQUE source_ref)
- Services/CourseService.php (create/publish/addLesson; syllabusFor drip lock — locked lessons expose unlock_at but NOT content_ref)
- Services/EnrollmentService.php (enroll idempotent; completeLesson; evaluateCompletion rule-based required-lessons+min_score; recordCompletion idempotent stages course.completed outbox; overrideCompletion reviewer+reason)
- Config/Services.php (courses, enrollments)

### HTTP layer + queue glue (DONE)
- Shared/Http/BaseController.php: added input() (raw json_decode + merge get/post; avoids getJSON() 500) and field(key,default).
- Shared/Messaging/JobRouter.php: dispatch(topic,job) -> contribution.succeeded/refunded => RewardCoordinator, course.completed => PointsEngine.award, notification.dispatch => NotificationService.updateDeliveryStatus; unknown logged+acked; re-throws for retry backoff.
- Spark commands (auto-discovered under module Commands/): Shared/Commands/OutboxRelayCommand.php (outbox:relay [--loop] [--batch]), Shared/Commands/QueueWorkCommand.php (queue:work [--once] [--queue] [--sleep]), Gamification/Commands/SeasonRolloverCommand.php (gamification:rollover [--org|--all] [--tz]).
- app/Config/Routes.php: canonical no-/api tree; ratelimit filters on 11 sensitive routes (all policy names verified). auth+authorize filters on contributions/refunds and integrations admin routes.
- app/Config/Filters.php: aliases `auth` => Identity\Filters\AuthFilter, `authorize` => AccessControl\Filters\AuthorizeFilter (in addition to `ratelimit`).

### Identity domain services + controllers (DONE)
- Services/AccountService, SessionService (create/elevate/touch/revoke/active), AuthenticationService (adaptive MFA), MfaService (TOTP enrol/confirm — encrypted secret via SecretBox AAD mfa:{id}; recovery codes once; verifyTotp/verifyRecoveryCode; listFactors), SocialAuthService (signInWithClaims keyed by provider+subject; verified-email collision => link_required NO silent takeover; linkToUser/unlink guards).
- Config/Services.php binds accounts/sessions/authentication/mfa/socialAuth.
- Controllers/{AuthController,MfaController,SocialAuthController}. Filters/AuthFilter (Bearer/X-WBS-Session -> active session -> request context; 401 generic).
- NOTE: TokenService deferred (access_tokens table exists, no service yet).

### Module controllers (DONE — all Routes.php targets resolve)
- Groups/GroupController, Referrals/ReferralController.
- Events/{EventController,CheckinController}.
- Contributions/{CauseController,ContributionController,RefundController,WebhookController(HMAC verify-before-apply -> idempotent inbox -> markSucceeded/markFailed)}.
- Courses/{CourseController,EnrollmentController}. Notifications/{NotificationController,CampaignController}. Integrations/{CatalogController,ConnectionController,ProfileController}.
- NEW service: Contributions/Services/CauseService (create/activate/raisedMinor/find) + Config binding `causes`.
- AccessControl/Filters/AuthorizeFilter: invokes PDP via AccessRequest; uses Decision::isPermitted(); 403 ACCESS_DENIED / 401 if no subject.

### RBAC bootstrap seeder (DONE)
- AccessControl/Database/Seeds/RbacBootstrapSeeder.php: default org (slug 'wbs', tz Africa/Accra), permission catalogue (17 codes), roles (org_admin/finance/notification_manager/event_organizer/member) + grants; idempotent. Uses fixed env wbs.organizationId for the org id (standardized key).
  Seed FQCN: php spark db:seed 'WBS\AccessControl\Database\Seeds\RbacBootstrapSeeder'

### Later-phase modules (DONE this session)
- **Community** (FR-COM-001..004): migration 000018 (community_posts w/ visibility state, community_comments threaded, community_reactions UNIQUE, community_topics + post_topics, content_reports UNIQUE per reporter, moderation_actions append-only). Services: ContentSanitizer (allowlist tags, safe anchors http/https/mailto only, hashtag extraction), FeedService (createPost/comment/react; feed reads PDP-filtered per candidate — FR-COM-002; sanitized writes), ModerationService (report dedupe; act w/ mandatory reason + evidence; state transitions hide/delete/restore/lock). Controllers: FeedController, ModerationController. Config binds contentSanitizer/feed/moderation.
- **Streaming** (FR-STR-001..012): migration 000019 (streams access_policy independent of provider, stream_destinations honest caps + connection_id not secrets, stream_chat_messages, stream_moderation w/ reason, stream_bans, stream_polls/poll_votes UNIQUE per user, stream_metric_samples w/ exactness, stream_archives external links). Services: StreamService (create/addDestination/goLive/end/linkArchive/canView PDP), StreamEngagementService (chat w/ ban+mute+slow-mode; moderate w/ mandatory reason; launchPoll/vote/closePoll; recordMetric never fabricated — FR-STR-010). Controllers: StreamController, EngagementController. Config binds streams/streamEngagement.
- **Meetings** (FR-MTG-001..003): migration 000020 (meetings link/standalone + access_policy, meeting_participants w/ hashed short-lived join_token, meeting_attendance_evidence applied=0 — never auto-counted). Service: MeetingService (create; grantAccess returns plaintext token once, stores hash; verifyJoinToken constant-time; recordAttendanceEvidence candidate-only; transition). Controller: MeetingController. Config binds meetings.
- **Reporting** (FR-RPT-001..004): migration 000021 (report_exports async lifecycle + private artifact_ref + expiry). Services: DashboardService (wbsFunnel WIN/BUILD/SEND rollup; metadata block as_of/filters/completeness/delayed-job warning; suppression threshold 5 for person-level cells — FR-RPT-002/003), ExportService (request→markRunning→markReady/markFailed; download re-checks requester+expiry; renderCsv + neutralize() formula-injection mitigation — FR-RPT-004). Controller: DashboardController. Config binds dashboards/exports.
- **Admin** (FR-GRP-006, FR-ACL-007): migration 000022 (platform_settings versioned UNIQUE key, feature_flags org+group scope UNIQUE, group_configurations w/ inheritance_mode, config_audit append-only). Services: SettingsService (get/set versioned+audited; setFlag/isEnabled group-override-wins), EffectiveConfigResolver (resolve walks group_closure nearest-first; modes inherit_only|ancestor_default_child_override|child_owned|not_inheritable; returns value+source+version+decision). Controller: AdminController. Config binds settings/effectiveConfig.

### Routes + RBAC updates (DONE)
- app/Config/Routes.php: appended community/streams/stream-polls/meetings/reports/admin route groups (auth + authorize filters; ratelimit:stream.chat on chat, ratelimit:report.generate on exports). 25 controllers referenced — ALL resolve.
- RbacBootstrapSeeder: added permission codes stream.create/stream.moderate/meeting.manage/community.moderate/report.view/report.export/admin.manage; new roles moderator + analyst; event_organizer also gets stream/meeting caps.
- Verified: no duplicate migration timestamps (21 total, 000001..000022 with gaps 000004); every new controller factory→service method call resolves; Result::fail 5-arg (code,message,status,errors,meta) order correct; Clock::now() DateTimeImmutable modify() valid; DashboardService queue_jobs status fixed to ready|reserved.

### Identity TokenService (DONE — FR-ID-006)
- migration 000023 refresh_tokens (family_id rotation chain, token_hash UNIQUE sha256, status active|rotated|revoked, replaced_by successor link, access_token_id, scopes, expiry).
- Services/TokenService.php: issue (new family; access+refresh pair, plaintext returned ONCE, sha256 stored), refresh (rotate in same family; REPLAY DETECTION — presenting a rotated/revoked refresh revokes entire family), verifyAccessToken (constant-time, scope check, generic failure, updates last_used_at), revokeAccessToken/revokeFamily/revokeAllForUser, listAccessTokens (metadata only). No wildcard scope (empty=none; '*' rejected). Access TTL 15min, refresh 14d.
- Config/Services.php: bound `tokens` (+import). Controller TokenController (issue/refresh/revoke/revokeAll/list). Routes: POST tokens/refresh (public, ratelimit:auth.login), tokens group under auth (issue/list/revoke-all/DELETE {id}).
- AuthFilter UPGRADED: Bearer credential starting `wbsat_` → verifyAccessToken (sets wbsScopes/wbsTokenId, mfa_level='token'); else treated as session ref. extractSessionId renamed extractCredential.

### Per-module HTML views (DONE — dual HTML/JSON via BaseController negotiation)
- app/Views/layouts/app.php: shared self-contained dark layout (inline CSS only, preview-safe), topbar nav, renderSection('content').
- Community/Views/feed.php: extends layout; renders sanitized body_html (allowlist), visibility pill, reaction/comment counts. FeedController::index now returns htmlView 'WBS\Community\Views\feed'.
- Reporting/Views/funnel.php: WIN/BUILD/SEND stat grids + metadata block (as-of/completeness/delayed-job warning) honoring suppression strings. NEW member self-service dashboard "my home" (GET /me/dashboard, auth only, no admin/report permission): MemberDashboardService::forUser(org,userId) strictly self-scoped (authenticated user id only) — aggregates own profile, gamification standing (active-season points + resolved rank via pure resolveRank() + rank progress % via pure rankProgressPercent() + badge LIST + achievements [unlocked + nearest in-progress, secret hidden] + streaks), issued certificates (with self-service PDF download link), upcoming registered events, course enrollments, group memberships; view WBS\Reporting\Views\member_dashboard (JSON on negotiation); Personal MILESTONES section (distinct from configurable gamification achievements): intrinsic life-of-membership markers derived from own activity — tenure years, cumulative events attended, courses completed, certificates earned, verified gifts — each track showing highest tier reached + next threshold (remaining) via pure milestoneForTrack(). rank ladder + rankProgressPercent + milestoneForTrack unit-tested (tests/unit/MemberDashboardRankTest.php, 14 cases); nav link added. DemoDataSeeder also seeds gamification catalog (badges/achievement_definitions/streak_definitions) + awards to Ama (badge, unlocked achievement, in-progress achievement, active streak) so the enriched dashboard renders populated. Self-service cert PDF download GET /certificates/{id}/download → CertificateService::downloadOwn() (ownership enforced, non-owner 404, path-traversal guard, streams from WRITEPATH). DashboardController::funnel returns htmlView 'WBS\Reporting\Views\funnel'.
- Namespaced view refs (WBS\Module\Views\name) = CI4 FileLocator module-view convention; layout 'layouts/app' resolves to app/Views.

### More dashboards/views (DONE this session)
- EventService::expectedAttendance (FR-EVT-006): distinguishes invitations/responses/confirmed_registrations/positive_rsvp_pending/waitlisted; expected = confirmed + pending_rsvp*show_rate capped by capacity; uncertainty band (lower=confirmed, upper=confirmed+rsvp+promotable waitlist); logistics seating/materials/catering(+10%). Verified col/status names (event_registrations.status registered|waitlisted; rsvp_state yes; waitlist_entries.status waiting).
  - EventController::expectedAttendance + route GET events/(:segment)/attendance; view Events/Views/attendance.php.
- Courses syllabus HTML: EnrollmentController::syllabus now renders Courses/Views/syllabus.php (drip lock shows unlock_at, never leaks locked content_ref — FR-CRS-004). Uses existing CourseService::syllabusFor.
- Layout nav kept to id-less pages (community feed, reports funnel, status); attendance/syllabus reached via id routes.

### Streams organizer dashboard (DONE — FR-STR-010/012)
- StreamService::organizerDashboard: destinations list, engagement (chat/polls/poll_votes via join/archives), and LATEST provider metric sample per (source,metric) with exactness+retrieved_at. Never fabricates — absent metrics reported unavailable. SQL cols verified against schema.
- StreamController::dashboard + route GET streams/(:segment)/dashboard [authorize:stream.moderate]; view Streaming/Views/dashboard.php (metrics shown with source/exactness/retrieval time + honesty note).

### Co-hosts + overlays (DONE — FR-STR-008)
- migration 000024 stream_cohosts (WebRTC source-feed presenters; short-lived hashed join token; roles host|cohost|guest; UNIQUE stream+user) + stream_overlays (fixed type vocab lower_third|cause_progress|quote_card; config_json sanitized DATA not code; version bumps per edit; visible flag).
- OverlayService: inviteCohost/issueCohostToken (plaintext once, sha256 stored)/markJoined (constant-time verify)/removeCohost; upsertOverlay (per-type sanitize: strip tags+control chars+length cap; versions each edit); setVisibility; activeOverlays (relay-ready; cause_progress amounts recomputed LIVE from ledger via CauseService — presenter can't inject fake figures). Cross-module dep Streaming->Contributions::causes (no circular).
- OverlayController + routes under streams group [authorize:stream.moderate]: cohosts, cohosts/token, DELETE cohosts/{user}, overlays, overlays/{id}/visibility, GET overlays/active. Config binds `overlays`.

## Totals: 18 modules, 27 controllers, 45 services, 23 migrations, 7 views, 3 seeders.
## New migrations to apply after toolchain reinstall: 000018-000024. Then RBAC + adapter (+ optional demo) seeders.

### Polishing pass (DONE)
- **BUG FIX (env key mismatch):** code read getenv('wbs.defaultOrganizationId') (32 sites) but .env defines wbs.organizationId → single-org fallback always returned empty. Standardized ALL code on wbs.organizationId (23 files). RbacBootstrapSeeder now seeds the org with the FIXED id from getenv('wbs.organizationId') (+ tz/maxdepth from env), so seeded org == runtime-resolved org. No 'defaultOrganizationId' remnants in code.
- **DemoDataSeeder** (Admin/Database/Seeds): NOT for prod, idempotent (sentinel post). Seeds 5 users, Kumasi Central group (+closure self-row, path '/{id}/'), 3 community posts, Monthly Gathering event (3 registered + 1 rsvp-pending), New Community Hall cause + 3 succeeded contributions, Foundations of Leadership course, active season + point_ledger awards. Makes all dashboards render. NOW ALSO seeds member-facing data so GET /me/dashboard is populated: rank_definitions ladder (starter0/builder100/leader200/sender400), group_members (users 0-2; user0=leader), enrollments (user0 completed +course_completions score 92, user1 active), a PAST completed 'Foundations Retreat' event with event_attendance + ISSUED event_certificates (users 0-1, template_id null → renderer default). FIXED two latent bugs: point_ledger.state 'cleared'→'final' (canonical; matches PointsEngine/AchievementService + dashboard filter) and rule_id NULL→'demo-seed-rule' (column is NOT NULL). Sample: Ama Mensah = 250 pts → Leader rank (150 to Sender), 1 completed course, 1 issued cert, leads Kumasi Central. Certs are ALSO pre-rendered to real PDFs (writable/certificates/) via prerenderCertificates()→CertificateRenderer, storing render_ref so /me/dashboard download links work immediately after seeding; best-effort + class_exists(Dompdf/Builder) guarded so it degrades quietly (certs stay issued-but-unrendered) when Composer deps aren't installed yet. FQCN: 'WBS\Admin\Database\Seeds\DemoDataSeeder'. Verified col/enum values (point_ledger state cleared/entry_type award; seasons status active).
- **RUNBOOK.md**: full dev runbook — prereqs, .env table (+`cp .env.example .env` and keygen), DB create, migrate, seed order, run (serve + outbox:relay + queue:work + rollover cron), quality gates + CI section, complete route map (all groups), and security invariants checklist.
- **.env.example**: scrubbed template — every secret is a placeholder (CHANGE_ME / hex2bin:REPLACE…); org guardrails documented; verified real `.env` key does NOT leak into it. `.gitignore` ignores `.env` but tracks `.env.example` + `.github/`.
- **.github/workflows/ci.yml**: valid YAML (2 jobs). static-analysis (composer validate, php -l all, phpstan L5 php80400) + tests matrix (PHP 8.2/8.3, MariaDB 11 + Redis 7 services, `spark migrate --all`, seed RBAC+adapter via correct single-backslash FQCN, phpunit).
- **composer.json scripts** added: `analyse`, `lint`, `cs`, `migrate`, `seed:rbac|adapter|demo|all`, `qa` (lint+analyse+test). Seed scripts use the verified single-quoted single-backslash FQCN form; composer.json validated as JSON.
- Full-tree scan clean (163 files); all controllers resolve; 23 migrations no dup timestamps. Note: `vendor/codeigniter4/framework/system` is not populated in this snapshot — `composer install` restores it; `spark migrate`/`db:seed` are core CI4 commands.

### KeyProvider seam (DONE — non-breaking)
- **Tier 1 key-management seam now physically in place**, behavior-identical to before (no runtime change; Tier 0 hardening still deliberately deferred).
- New: `app/Modules/Shared/Security/KeyProvider.php` (interface: `activeKeyId()`, `keyFor($keyId)`) + `EnvKeyProvider.php` (default, reads `encryption.key`/`ENCRYPTION_KEY`, same `keyFromEnv` normalization, keyId `k1`).
- `SecretBox` constructor now accepts **string|KeyProvider** (legacy raw-key path unchanged); encrypt tags active keyId, decrypt resolves each blob's own keyId prefix via provider → rotation/provider coexistence with no re-encryption. `fingerprint()` uses resolved active key.
- New binding `WBS\Shared\Config\Services::keyProvider()` (returns `EnvKeyProvider::fromEnv()`); both consumers rewired: `Integrations::credentialVault()` and `Identity::mfa()` now `new SecretBox(SharedServices::keyProvider())`. No `keyFromEnv` call sites left in service factories.
- Test: `tests/unit/SecretBoxKeyProviderTest.php` (legacy round-trip, provider round-trip, env-interop bytes, cross-key rotation coexistence, unknown-keyId hard failure). **First tests in repo.**
- To switch to KMS/Vault later: implement `KeyProvider`, return it from `Services::keyProvider()` (e.g. gated by `KEY_PROVIDER` env). Doc: `docs/SECRETS_KEY_MANAGEMENT_OPTIONS.md` §1.

### Per-module views + FR-STR-008 console (DONE)
- **Events overview view** `WBS\Events\Views\show` — event header/status/capacity + link to attendance report. `EventController::show` now renders HTML (was JSON-only); JSON still served on negotiation.
- **Courses overview view** `WBS\Courses\Views\overview` — author-facing course header + lesson list with drip rule and content-attached flag (never prints content_ref). New `CourseService::overview()` + `CourseService::find()`; new `CourseController::show`; new route `GET courses/(:segment)`.
- **Streaming overlay/co-host management console (FR-STR-008)** `WBS\Streaming\Views\overlays` — moderator console: co-hosts (role/status, token *facts* only — never hash/plaintext) + ALL overlays incl. hidden, with live cause_progress figures from ledger. New `OverlayService::console()` (distinct from compositor's `activeOverlays()`); new `OverlayController::console`; new route `GET streams/(:segment)/console` behind `authorize:stream.moderate`.
- **10 views total now**: layouts/app, landing, Community/feed, Reporting/funnel, Events/{attendance,show}, Courses/{syllabus,overview}, Streaming/{dashboard,overlays}.
- All new files brace/paren-balanced; new routes single-segment (no shadowing); htmlView strings normalized to single-backslash to match exemplars. PHP not installed in sandbox (no network) → no `php -l`/live render; CI will lint on next run.
- Static visual preview of the console: `previews_overlays_console.html` (workspace root; sample data, faithful to the layout).

### Nav wiring + index pages (DONE)
- **Shared layout nav now links** Events · Courses · Streams · Community · Reports · Status (`app/Views/layouts/app.php`).
- Detail views are ID-scoped, so added **index/list pages** so nav links resolve: `WBS\{Events,Courses,Streaming}\Views\index`.
- New service list methods: `EventService::listForOrg` (newest start first; selects mode not the non-existent `visibility`), `CourseService::listForOrg` (+per-course lesson_count), `StreamService::listForOrg` (live-first via raw `orderBy("CASE WHEN status='live'…", 'ASC', false)`).
- New controller `index()` actions + routes: `GET events`, `GET courses`, `GET streams` (streams index behind `authorize:stream.moderate`, consistent with its group).
- **BUG FIXED in earlier show.php**: it referenced a non-existent `events.visibility` column → replaced with `mode` + `registration_policy` (real columns).
- **13 views total now.** Static preview: `previews_nav_events_index.html`.

### Geo module enhancement (DONE)
- Adapted the uploaded `locationservice.md` reference lib (App\Libraries/SwissArmyKnifeModel) to WBS conventions (BaseConnection/Result/Clock/Uuid, our real schema). Deliberately DROPPED mentor-matching — WBS `users` has no latitude/longitude/membership_status/max_mentees, and inventing personal-GPS columns would violate the module's consent discipline.
- **Migration 000025 EnhanceVenues**: adds venue columns (venue_type,status,address_text,contact_phone/email,website,operating_hours,accessibility_features,parking_info,version,created_by,updated_by,deleted_at) via guarded ALTERs + `venue_group_assignments` join table + indexes.
- **New `GeoResolverService`** (read engine, no writes/PII): `resolve()` cascade town→city→coords; `reverseGeocode()` two-tier 20km/50km; `findPlacesInRadius()`; `detectPreferredLanguage()`; `calculateDistance()` Haversine; `geographicStats()`. All spatial queries bounding-box-then-Haversine, fully parameterized, table whitelist, per-request static caches; `acos` clamped with LEAST/GREATEST.
- **LocationService extended** with venue CRUD: createVenue/updateVenue(optimistic version lock, 409 on conflict)/deleteVenue(soft default+hard)/getVenue/listVenues(filter+paginate)/findNearbyVenues(spatial)/venueStats/bulkCreateVenues/assignVenueToGroup(idempotent)/getGroupVenues. JSON fields hydrated; geo_point never serialized.
- **New controllers**: `GeoController` (resolve/reverse-geocode/places/stats — public reference reads) + `VenueController` (CRUD/nearby/stats/bulk/assign — auth; writes behind `authorize:venue.manage`).
- **Routes**: `geo/*` group (public) + `venues/*` group (auth) + `groups/(:segment)/venues`. Static segments declared before `(:segment)`.
- **RBAC**: added `venue.manage` permission → org_admin (all) + event_organizer.
- **Geo module now**: 3 services, 2 controllers, 2 migrations. Totals: 29 controllers, 47 services, 24 migrations. Next free migration = 000026.

### Geo importer aligned to GeoDB/dr5hn dataset (DONE)
- **Migration 000026 AlignGeoReferenceColumns**: adds full source columns — countries(tld,native,region,subregion,nationality,population,gdp,emoji,emojiU), cities(native), towns_villages(type enum,population,translations,wikiDataId); relaxes towns_villages.country_code to NULL (source towns carry only country_id). Guarded ALTERs, idempotent.
- **GeoImportService rewritten for the real dataset**: PRESERVES SOURCE IDS as PKs (city.country_id→country.id lines up, no remapping); per-table COLUMN whitelist + field-fallback (states iso2→state_code); JSON_FIELDS (translations,timezones) encoded once; BATCHED multi-row `INSERT … ON DUPLICATE KEY UPDATE` in 1000-row chunks (affectedRows); id/created_at excluded from UPDATE so re-imports keep original create time; cheap checksum (dataset|version|count|sorted-id-hash) for idempotency on 150k files. Back-compat: `import(dataset,rows,meta)` unchanged; added `importFile()` (+ object-wrapper unwrap).
- **New spark command** `geo:import` (WBS\Geo\Commands\GeoImportCommand): `geo:import <dataset> <path>` or `--dir=DIR` (imports regions→…→towns_villages in FK order), `--version`/`--source` provenance.
- **Sample fixtures** `docs/geo-samples/*.json` (Ghana/Accra chain) show the expected source shape; mapping + upsert logic validated via Python simulation (ids preserved, JSON encoded, value/column counts balanced, sparse rows null-filled).
- **Geo module now**: 3 services, 2 controllers, 1 command, 3 migrations. Totals: 29 controllers, 47 services, 25 migrations. Next free migration = 000027.

### Referrals tracking adaptation — urlmanager.md + clicktracker.md ported (DONE)
- **Adapted (not transplanted)** the external `App\Libraries\UrlManager`/`ClickTracker` specs into `WBS\Referrals`, keeping WBS conventions (BaseConnection+Clock DI, Result returns, UUIDv7, opaque codes) and privacy invariants (hash-only IP/UA/device, no raw geo). Design record: `docs/referrals-adaptation-plan.md`.
- **Migration 000027 ExtendReferralsTracking**: additive+reversible. `referral_clicks` += `is_suspicious`, `suspicious_reason`, `device_hash` (+ index `rc_susp_idx`); `referral_links` += `link_type`, `resource_id`.
- **G1 analytics**: `ReferralService::getLinkAnalytics()` / `getReferrerAnalytics()` — PII-free aggregates (total, unique visitors by ip_hash, unique devices by device_hash, suspicious count, clicks_by_hour, top_campaigns; referrer version bulk-loads clicks via WHERE IN, no N+1).
- **G2 fraud**: new `FraudService` (velocity per ip_hash, bot-UA in-request only, no-UA, no-consent → scored `FraudVerdict`) wired into `recordClick`; review helpers `markSuspicious`/`clearSuspicious`. FraudService bound in Referrals/Config/Services + injected into ReferralService.
- **G3 chain**: `ReferralController::chain()` delegates to `SponsorshipService::upline()` — member IDs only, no names/emails.
- **G4 typed redirect**: `resolveRedirectUrl()` match on link_type (member/event/giving/course/streaming) + UTM passthrough; returned in `recordClick` result.
- **G5 device hash**: privacy-safe sha256(HMAC) of normalized client signals; raw components never persisted.
- **New routes** (auth-gated): `GET referrals/links/{code}/analytics`, `GET referrals/referrers/{id}/analytics`, `GET referrals/chain/{id}`, `POST referrals/clicks/{id}/flag`, `POST referrals/clicks/{id}/clear`.
- **Deferred TODOs** (in plan §9): AB testing/variant split; per-click geo (needs DPIA).
- **Totals now**: 29 controllers, **48 services** (+FraudService), **26 migrations** (000027 latest). Next free migration = 000028. All 6 changed/new files pass brace/paren/bracket + declare scans (php -l still pending toolchain).

### Streaming provider integration adaptation — streamingservice.md ported (DONE)
- **Adapted (not transplanted)** the 1544-line `App\Libraries\StreamingService` God-class into WBS across Streaming/Meetings/Integrations, keeping conventions (BaseConnection+Clock DI, Result, UUIDv7) + privacy/secret invariants (no raw PII, all provider secrets via CredentialVault, money via Contributions). Design record: `docs/streaming-adaptation-plan.md`.
- **S1 provider integration** (the real gap): new `app/Modules/Integrations/Providers/` — `StreamProvider` interface + `YouTubeProvider`/`TwitchProvider`/`FacebookProvider`/`RtmpProvider`, `ProviderHttp` (CURLRequest wrapper, honest error surfacing), `BroadcastResult`, `ProviderException`, `ProviderRegistry`. Tokens used ONLY inside `CredentialVault::useSecret()` closures; stream keys stored in the vault, never on rows. `StreamService::provisionDestinations()` + `goLive`/`end` now dispatch to adapters per destination (failure marks that destination 'error', never aborts the batch). New route `POST streams/{id}/provision`.
- **S7 OAuth consent** (the piece the library said it omits): `StreamingOAuthController` + `StreamingOAuthService` + `OAuthProviderConfig` (non-secret authorize/token/scope metadata). Session-bound single-use `state`; code→token exchange stores refresh token via vault. Routes `POST integrations/oauth/{provider}/authorize`, `GET integrations/oauth/{provider}/callback`.
- **S2 meeting-link creation**: `MeetingProvider` interface + `ZoomProvider` (S2S OAuth) + `GoogleMeetProvider` (Calendar conferenceData), `MeetingProviderRegistry`, `MeetingResult`. Wired into `MeetingService::create()` — creates the real meeting when no link supplied and an adapter exists; provider failure → 502, never a silent empty meeting.
- **S3 viewer tracking** (privacy-safe) → migration **000028 CreateStreamViewers** (ip_hash/ua_hash/coarse device_type ONLY; no raw IP/UA/city/referrer). `trackViewer`/`endViewer`/`concurrentViewers`. Routes `POST streams/{id}/viewers`, `POST streams/viewers/{id}/leave`.
- **S4 reactions** → migration **000029 CreateStreamReactions**. `addReaction` (live-only, rate-limited); counts via aggregation, not a denormalized column. Route `POST streams/{id}/reactions`.
- **S6 real-time metrics**: `realTimeMetrics()` — concurrent viewers + chat/reactions in window + reaction breakdown + honest engagement score. Route `GET streams/{id}/realtime`.
- **S5 stream giving via Contributions**: `recordGiving()` creates a Contributions INTENT tagged with the stream (idempotency `stream:{id}:...`) — NOT a fake completed donation; completion arrives via the Contributions webhook. Route `POST streams/{id}/giving`.
- **Rate policies added**: `stream.react`, `stream.viewer`.
- **Bindings**: Integrations Config += `streamProviders`, `meetingProviders`, `streamingOAuth`; Streaming Config injects ProviderRegistry into StreamService + ContributionService into StreamEngagementService; Meetings Config injects MeetingProviderRegistry.
- **Deferred TODOs** (plan §8): AB testing/variant split; live provider analytics fan-in (scheduled worker); delegated-auth Teams/GoToWebinar; per-viewer geo (DPIA).
- **Env needed** (CI/staging; sandbox has neither network nor toolchain so adapters are structurally validated only here): `YOUTUBE_CLIENT_ID/SECRET`, `TWITCH_CLIENT_ID/SECRET`, `GOOGLEMEET_CLIENT_ID/SECRET`, `STREAM_IP_SALT`.
- **Totals now**: 30 controllers (+StreamingOAuthController), 51 services (+StreamingOAuthService, +ProviderRegistry, +MeetingProviderRegistry as bound services), **28 migrations** (000029 latest; next free = 000030). All ~25 changed/new files pass brace/paren/bracket + declare scans (php -l pending toolchain).

### Gamification award/achievements adaptation — awardlib.md ported (DONE)
- **Adapted (not transplanted)** the 2532-line `App\Libraries\AwardLib` God-class into WBS\Gamification, keeping the immutable-ledger spine + data-configured versioned rules and conventions (BaseConnection+Clock DI, Result, UUIDv7). Design record: `docs/gamification-adaptation-plan.md`.
- **HARD REJECTED** the spec's `evaluateFormula()` `@eval()` of DB strings (arbitrary code execution), mutable `user_points_summary`/`user_awards` status edits, per-award advisory locks, integer ids, SwissArmyKnifeModel, and the leaderboard leaking name+email.
- **Migration 000030 CreateAchievementsAndRanks**: adds `achievement_definitions`, `user_achievements` (UNIQUE achievement+subject), `user_achievement_progress`, `rank_definitions`; ALTER `user_streaks` += freeze_until/grace_days; ALTER `gamification_rules` += `multipliers` JSON.
- **G2 StreakService** (over existing user_streaks): record (idempotent per day, extend/reset/freeze-aware), freeze, get, getAll — season-scoped.
- **G4 RankService** (+rank_definitions): define, tiers, determineRank, nextRank — feeds rank_reached trigger.
- **G1 AchievementService**: evaluateForSubject / computeProgress / unlock / unlockManually / reevaluateAll / getUserAchievements / listAll. 8 triggers (points, count, first_time, cumulative_points, streak, combo, rank_reached, custom) ALL computed from the immutable ledger + streak/rank services. Spendable bonus points post via a configured bonus rule to the ledger (source_ref achievement:{code}); XP is a non-spendable stat. Unlock idempotent via UNIQUE.
- **G3 LeaderboardService**: top + standing from ledger (current season) / snapshots; returns subject_id + display_name + points + rank + achievement_count — NEVER email. Group leaderboards via subject_type.
- **G5 PointsEngine extensions**: cooldown_seconds enforcement (was unused column), DECLARATIVE multipliers (factor + when-condition, whitelisted ops, clamped 0.1–10×, NO eval), approveAward/rejectAward (held→final / held→reversed compensating entry), pendingAwards queue. Achievement eval hooked on final awards.
- **Recursion break**: AchievementService gets a BARE PointsEngine (no achievement hook) so bonus-point posting can't recurse; public + RewardCoordinator paths get the hooked engine.
- **G6 GamificationController** (module previously had NO controller/routes): balance, leaderboard, standing, achievements, ranks, streaks (read) + pending/approve/reject, defineRank, unlock, reevaluate, recordStreak (admin, `authorize:gamification.manage`). New `gamification.manage` permission seeded (org_admin via ALL + moderator).
- **Admin CRUD for ALL configurable entities (added on request)**: rules, ranks, achievements, streak definitions all creatable/editable by an admin behind `authorize:gamification.manage`.
  - **RuleService** (new): create/update/disable/list point rules incl. declarative multipliers. Rules are VERSIONED/IMMUTABLE — update() supersedes the active version (old→status=superseded, effective_to=now); award history never mutated.
  - **AchievementService**: +define/disable (upsert by code; validates trigger + threshold; bonus_points>0 requires a bonus_rule_code).
  - **RankService**: +disable (define already existed).
  - **StreakService**: +define/disableDefinition/definitions over a new **streak_definitions** catalog (migration 000031).
  - **GamificationController**: +listRules/createRule/updateRule/disableRule, disableRank, defineAchievement/disableAchievement, listStreakDefinitions/defineStreak/disableStreak. Routes added under /gamification (rules, ranks, achievements, streak-definitions).
- **Deferred TODOs** (plan §8): config caching layer; team/split awards (awardWithSplit); outbound webhooks (route via outbox); batch award API.
- **Totals now**: 31 controllers (+GamificationController), 53 services (+RuleService, +Streak/Rank/Achievement/Leaderboard bound; PointsEngine/SeasonService already counted), **30 migrations** (000031 latest; next free = 000032). All changed/new files pass brace/paren/bracket + declare scans; every route resolves + every controller→service call targets an existing method. php -l pending toolchain.

## GivingsLibrary → VBCS adaptation (V1–V6) — COMPLETE
Adapted `uploads/givingslibrary.md` into the existing Contributions module. Plan: `docs/givings-adaptation-plan.md` (IMPLEMENTED). Approved: all V1–V6, derived-snapshot PGV/GGV, reuse sponsorship graph.
- **Migration 000032** CreateVbcsMetricsAndPartnership: `giving_metrics` (derived PGV/GGV; multiplier_bps), `partnership_level_definitions` (admin tiers), `user_partnership_status`, `giving_commitments`.
- **New services**: `MetricsService` (PGV/GGV derive+cache; downline via SponsorshipService, ×1.30 at ≥10 recruits; cascadeToUpline), `PartnershipService` (admin tier CRUD + streak/tier recompute; level_changed via outbox), `CommitmentService` (reminder-only pledges via NotificationService::send), `ManualContributionService` (maker-checker cash/cheque/bank/in-kind; approve → verified contribution + ledger + shared contribution.succeeded event; approver≠submitter).
- **SponsorshipService** (Referrals): added `directRecruits()` + bounded `downline()` BFS — single source of truth for the sponsor tree (replaces library's raw recursive CTE over integer users.sponsor_id).
- **CauseService**: added `progress()`, `donors()` (anonymous recognition respected; email never returned), `groupGivingReport()` (totals/by-cause/monthly, minor units).
- **RewardCoordinator**: now refreshes metrics + partnership on contribution.succeeded/refunded (refund event carries user_id); still idempotent.
- **VbcsController** + `/vbcs` route group; new **`CommitmentDueCommand`** (`php spark contributions:commitments-due --org|--all`).
- **New permission `contribution.manage`** (org_admin + finance) in RbacBootstrapSeeder.
- **REJECTED from library** (not ported): SwissArmyKnifeModel, integer IDs, float/decimal money, mutable users.pgv/ggv as source of truth, raw recursive SQL over identity table, inline Events::trigger, per-call fresh-model, throw-based control flow.
- **Totals after VBCS**: 32 controllers (+VbcsController), Contributions services now 10 bound, **31 migrations** (000032 latest; next free = 000033). All new/changed files structurally validated; all `/vbcs` routes + DI resolve. php -l pending toolchain.
- **Deferred**: auto-charge of commitments (no vaulted payment methods — reminders only); synchronous PGV/GGV (kept async for 50k scale); config caching of tier definitions.

## Still to build (optional / lower priority)
3. PHP lint + migrate + seed + integration tests pending user's toolchain reinstall. All files pass structural scans (brace/paren/bracket/tag); view files legitimately open with `<?=` not `<?php`.
4. After migrate: run RBAC + adapter seeders (FQCNs in earlier sections). New migrations to apply: 000018-000023.

## Key invariants to preserve (from prior verified build)
- Money in integer minor units; ledger append-only; corrections = compensating entries.
- Webhook inbox idempotent via UNIQUE(provider, provider_event_id); quarantine unverified/unknown.
- Refund maker-checker: approver != requester (SOD_SELF_APPROVAL 403); execute idempotent.
- Gamification points ledger separate from money; award once on succeeded; reverse on refund.
- UPAF: profiles are validated data, not code; finite protocol/op/host/crypto vocab; credentials write-only.
- Rate config: always `config(RateLimitPolicies::class)` FQCN, never short-name string.
- MariaDB: no CASE in GENERATED columns; use app-maintained nullable col + UNIQUE index.
- Migration timestamps: 000001..000014 as previously assigned; Courses = 000015.

## BaseController reference adaptation (basecontroller.md) — COMPLETE
Reviewed external god-controller; adapted 3 core wins, rejected the rest. Notes: `docs/basecontroller-adaptation-notes.md`.
- **Cache-safety headers**: `BaseController::cacheSafe()` sets `Vary: Accept, X-Requested-With, Authorization` + `X-Content-Type-Options: nosniff` on respondJson()/respondHtml() (dual-representation cache-poisoning fix).
- **CorrelationIdFilter** (`WBS\Shared\Filters`, alias `correlationid`): global before+after; honours sane inbound X-Correlation-Id else mints one; sets `$request->wbsCorrelationId` + echoes X-Correlation-Id.
- **DRY base helpers**: orgId(), currentUserId(?fallbackKey), actorId(?fallbackKey), mfaLevel(), clientIp(), userAgent() added to BaseController. Migrated 26 controllers (~35 org + ~30 user inline resolutions); deleted 3 private orgId()/currentUserId() copies. SECURITY: authenticated request attr now always beats body param (no org spoof via posted organization_id).
- **Deferred**: UTF-8 cleanForJson scrubbing; in-action checkRateLimit() helper.
- **Validation**: all changed core files + 26 controllers brace/paren/bracket-balanced + declare present; no leftover getenv('wbs.organizationId') or private orgId()/currentUserId() in controllers; all migrated controllers extend Shared BaseController. php -l pending toolchain.

## API documentation (from basecontroller/ApiDocsController reference) — COMPLETE
Reference used a runtime zircote/swagger-php attribute scan; we DON'T annotate controllers, so instead the spec is DERIVED from the canonical route table (single source of truth, cannot drift, no new dependency).
- **OpenApiGenerator** (`WBS\Shared\Support`): builds OpenAPI 3.1 from the live RouteCollection — paths, path params (named from literals), per-op security (auth->401+BearerToken, authorize:perm->403, ratelimit:policy->429), uniform Success/Problem (RFC 9457) schemas, tags per module, x-permissions list.
- **OpenApiGenerateCommand** (`php spark openapi:generate`): writes public/openapi.json.
- **ApiDocsController** (`WBS\Shared\Controllers`): GET /openapi.json (static file, else live gen) + GET /docs (Swagger-UI). Both public; routes added at top of Routes.php.
- **tools/gen_openapi.py**: toolchain-free bootstrap generator producing the identical artifact (php/composer absent this session). Generated public/openapi.json = 168 ops / 153 paths / 17 tags / 12 permissions.
- Preview: static Swagger-UI served (public/docs.html + openapi.json) since PHP unavailable; in production /docs + /openapi.json serve via ApiDocsController.
- Validation: all 3 PHP files structurally sound (paren imbalance in generator = regex string literals, confirmed balanced after string-strip); spec is valid JSON; security/response mapping spot-checked (public vs secured vs authorize vs ratelimit).

## OpenAPI wired into CI — COMPLETE
- Added a **drift-guard** step to the `static-analysis` job in `.github/workflows/ci.yml` (after PHPStan): runs `php spark openapi:generate` to a temp file and compares NORMALIZED JSON (decode→canonical re-encode) against committed `public/openapi.json`; fails the build on real drift (routes changed without regenerating). Whitespace/pretty-print differences are ignored.
- RUNBOOK §7 gains `php spark openapi:generate` as a quality-gate command; new §7a documents generate/serve (/docs, /openapi.json)/drift-guard workflow + filter→spec mapping.
- Rule: never hand-edit public/openapi.json; regenerate + commit in the same PR as any Routes.php change.

## OpenAPI CI schema validation — COMPLETE
- Added a **schema-lint** step after the drift guard in `.github/workflows/ci.yml` static-analysis job: `openapi-spec-validator` asserts the freshly generated `/tmp/openapi.new.json` is valid OpenAPI 3.1 (catches a malformed generator output even when it matches the committed file). Uses runner-preinstalled python3; no Node toolchain in the PHP job.
- Verified locally: current public/openapi.json passes 3.1 validation; a spec with `openapi` key removed is correctly rejected (exit 1).
- RUNBOOK §7a documents the CI check + a local one-liner to reproduce it.

## Events D9-B — paid ticketing, kiosks/offline, logistics, expenses — COMPLETE
Implemented the deferred Events scope (SRS FR-EVT-016/017/018/019). Route-derived
OpenAPI regenerated (178 paths / 196 ops / 17 tags); spec re-validated as valid
OpenAPI 3.1. All new PHP files pass a PHP-string-aware brace/paren balance check
(no php binary in-sandbox; CI `php -l` + PHPStan run the real lint).

- **Migration 000033** (`app/Modules/Events/Database/Migrations/2026-09-04-000033_CreateEventsD9B.php`):
  event_ticket_types, event_promo_codes, event_orders (UNIQUE idempotency_key),
  event_order_items, ticket_transfers, event_checkin_kiosks, kiosk_manifests
  (UNIQUE kiosk_id+version), offline_checkin_queue (UNIQUE kiosk_id+local_ref),
  event_logistics_plans (UNIQUE event), event_resources (planned vs ordered),
  event_seating_areas, event_staff_roster, event_suppliers,
  event_catering_aggregates, event_accessibility_needs (specially classified),
  event_budgets, event_expenses, event_expense_approvals.
- **FR-EVT-016** `TicketingService` + `TicketingController`: ticket types, promo
  codes (percent bps / flat), server-side pricing, reuse of the atomic
  `ticket_holds` so payment can't oversell, per-type sold caps, controlled
  attendee transfer, order paid → confirm registrations. Cause add-on kept
  SEPARATE: on markPaid the controller creates a distinct VBCS intent
  (idempotency `order-addon:{orderId}`), never blended into the ticket ledger.
- **FR-EVT-017** `KioskService` + `KioskController`: encrypted (SecretBox aad
  `kiosk:{id}`), short-lived (4h TTL), EVENT-SCOPED manifests (never org-wide
  roster; device fp stored hash-only); offline scans queued idempotently
  (UNIQUE kiosk_id+local_ref); reconcile applies via CheckinService manual path
  so the one-active-attendance UNIQUE resolves duplicates server-side.
- **FR-EVT-018** `LogisticsService` + `LogisticsController`: resources with
  quantity_planned (projection) vs quantity_ordered (approved, protected from
  refresh); seating areas, staff roster, suppliers, catering aggregates
  (non-sensitive) vs event_accessibility_needs (specially classified; the
  `needs` read route is authorize:event.logistics.manage). refresh-projection
  derives expected attendance and reprojects only planned quantities.
- **FR-EVT-019** `ExpenseService` + `ExpenseController`: budget, submit, approve
  /reject/reimburse with SEGREGATION OF DUTIES (actor != submitter → 403
  SOD_VIOLATION), append-only event_expense_approvals trail, variance + final
  reconciliation. Separate from contribution receipt/ledger.
- **Routes**: self-service ticket routes (list types, hold, checkout) in the
  public `events` group (rate-limited: new `event.checkout` policy, fail CLOSED);
  a SECOND `events` group with `['filter'=>'auth']` holds all management routes so
  `authorize:` always runs after `auth`. New top-level groups `orders`, `kiosks`,
  `expenses` (all auth-gated).
- **RBAC**: 4 new permissions — event.tickets.manage, event.logistics.manage,
  event.expense.submit, event.expense.approve. Granted: event_organizer gets
  tickets/logistics/expense.submit; finance gets expense.approve.
- **Totals now**: 38 controllers, 32 migrations (latest 000033, next free 000034).

## SRS FR coverage audit + Events post-event cluster — COMPLETE
Ran an evidence-based audit of all 106 true FRs (see `docs/SRS_FR_COVERAGE_AUDIT.md`).
Confirmed genuine gaps and then built the #1 cluster: the Events post-event flow.

### Audit (docs/SRS_FR_COVERAGE_AUDIT.md)
Cross-referenced every `**FR-XXX-NNN —**` heading against the codebase (verified
by searching for the concrete tables/services each FR demands, not just FR tags).
Confirmed gaps: FR-EVT-012/013/014/015 (now built below), FR-ACL-004, FR-GRP-003,
FR-ID-002, FR-ID-009, FR-INT-012, FR-STR-009, FR-STR-013. Recommended build order
recorded in the audit doc.

### Events post-event cluster (FR-EVT-012/013/014/015) — COMPLETE
- **Migration 000034** (`2026-09-04-000034_CreateEventsPostEvent.php`): 9 tables —
  event_feedback_forms/questions/responses/answers, certificate_templates,
  event_certificates (UNIQUE event+user, UNIQUE verification_id),
  event_report_snapshots, event_media.
- **FR-EVT-012** `FeedbackService` + `FeedbackController`: versioned feedback/quiz
  forms, questions with answer keys (never returned), one response per respondent
  (UNIQUE), automatic vs reviewed scoring, aggregate reads honoring a min-N
  threshold (small cohorts suppressed).
- **FR-EVT-013** `CertificateService` + `CertificateController`: versioned
  templates, batch request for eligible attendees (SKIPS zero-attendance events,
  aligning with FR-EVT-011), background render job (`event.certificate.render`),
  issue/revoke state machine, public opaque `verification_id` (QR) with a
  no-auth `GET certificates/verify/{id}` returning only non-identifying status.
  Real PDF generation is now wired: `CertificateRenderer` (Dompdf, pure-PHP
  HTML/CSS→PDF — light on resources, no headless browser) renders the HTML
  template with allowlisted `{{namespace.field}}` placeholders, embeds the
  verification QR (endroid/qr-code v6 `Builder`) as a data-URI, and writes the
  artifact to `writable/certificates/{id}.pdf`; the worker calls it, stores the
  returned `render_ref`, then issues. Remote asset loading is disabled in Dompdf
  for safety; a caller-supplied `render_ref` (external renderer) is honoured as
  an override. Deps added to composer.json: `dompdf/dompdf ^3.1` (QR uses the
  already-required `endroid/qr-code`). Optional env `CERTIFICATE_VERIFY_URL`
  sets the QR's verification base URL. The template body IS the design input:
  `certificate_templates.body_template` holds admin-authored HTML (inline CSS +
  allowlisted placeholders), created via `POST /events/certificate-templates`.
  `CertificateTemplateSeeder` (composer `seed:certtemplates`, added to
  `seed:all`) seeds a default + a `gathering`-typed starter design so a cert
  renders out of the box. `CertificateRenderer::substitute()` is public+static
  and unit-tested (`tests/unit/CertificateRendererTest.php`): allowlist-only
  interpolation, unknown placeholders left literal, values HTML-escaped.
  Template design assets (logo + background): migration 000041 adds
  `logo_data_uri`/`background_data_uri` (LONGTEXT) to `certificate_templates`.
  Because Dompdf has remote loading disabled, assets are accepted ONLY as
  `data:` URIs and normalized by `WBS\Shared\Support\ImageDataUri` (allowlist
  png/jpeg/gif/webp; type decided by real magic bytes, not the declared MIME —
  anti-spoof; size cap 512 KiB default; GD-optional sniffer).
  `CertificateService::createTemplate` validates both assets before insert;
  `CertificateRenderer` re-guards the stored value (`safeDataUri`) and inlines
  the logo (top, centered) + background (cover). Unit-tested in
  `tests/unit/ImageDataUriTest.php`. `CertificateTemplateSeeder` ships a small
  "WBS" emblem PNG (`Seeds/assets/wbs_logo.datauri.txt`, ~6 KB) attached to both
  seeded templates via `logo_data_uri` — normalized through the same
  `ImageDataUri` validator real uploads use, so the demo certificate renders
  WITH a logo out of the box (end-to-end asset path proven).
- **FR-EVT-014** `ReportService` + `ReportController`: on-demand mobilization
  report aggregating mobilization funnel, attendance, qualified streaming
  attendance (≥300s watch), ticket contributions, feedback/quiz, expenses,
  media, points — AGGREGATE only. Immutable `snapshot()` + `groupRollup()` for
  authorized-ancestor roll-up.
- **FR-EVT-015** `MediaService` + `MediaController`: register opaque object ref +
  governance metadata, EXIF/GPS stripped by default, malware-scan job
  (`event.media.scan`), infected auto-quarantine, review workflow; nothing is
  public until BOTH clean AND approved.
- **JobRouter** wired for `event.certificate.render` + `event.media.scan`
  (idempotent handlers).
- **Routes**: public `GET events/{id}/media`, `GET certificates/verify/{id}`,
  `POST feedback-forms/{id}/responses` (rate-limited). Management routes under the
  auth-gated `events` group + new `feedback-forms`/`feedback-responses`/
  `certificates`/`media`/`event-reports` groups.
- **RBAC**: 3 new permissions — event.feedback.manage, event.certificate.manage,
  event.media.manage (granted to event_organizer).
- **OpenAPI** regenerated: 196 paths / 215 ops / 17 tags / 20 permissions;
  re-validated valid OpenAPI 3.1; drift-guard deterministic (NO DRIFT).
- **Totals now**: 42 controllers, 33 migrations (latest 000034, next free 000035).
  The Events module now covers FR-EVT-001…019 end-to-end.

## FR-ACL-004 — access-request / approval workflow — COMPLETE
Maker-checker access-request lifecycle in the AccessControl module. A requester
submits a scoped request (role or direct permission, scope group ±descendants,
reason, duration); a reviewer with `access.request.approve` approves/rejects,
and the PDP enforces separation of duties so a requester can never approve their
own request. Approved requests materialize as **expiring** `role_assignments`
(traced by `request_id`); direct-permission grants are resolved by the PDP from
approved unexpired `access_requests`. Renewal extends the window; revoke and a
`acl:expire` sweep close them out. Every state change is hash-chain audited and
best-effort notified.

- **Migration 000035** (`CreateAccessRequests`): `access_requests`
  (grant_type role|permission, scope_group_id/include_descendants, reason,
  duration_days, approver/decided_by, status pending|approved|rejected|revoked|
  expired|cancelled, conflict_state/detail, assignment_id, effective_from/to +
  3 indexes); `access_request_reviews` (append-only approve|reject|revoke|renew|
  cancel trail); **ALTER `role_assignments`** +status/include_descendants/source/
  issued_by/request_id/effective_from/effective_to/revoked_at + `ra_status_idx`.
  Legacy rows default status='active', source='seed', NULL expiry → PDP behaviour
  unchanged for them. `down()` is best-effort (drop index/cols/tables).
- **`Services/AccessRequestService.php`**: submit (validates role/permission
  exists, reason required, SoD conflict detection via CONFLICTING_PAIRS — flags,
  does not block); approve (row-lock FOR UPDATE, PDP decide + belt-braces self-
  approval denial, idempotent materializeRoleAssignment on subject/role/scope
  triple with effective_to from duration_days); reject; revoke (reason required);
  renew (extends effective_to); expireLapsed(org?) idempotent sweep;
  pendingForApprover; find (with review trail).
- **`Controllers/AccessRequestController.php`**: submit/show/pending/approve/
  reject/revoke/renew. Maker + reviewer from session; subject from body.
- **`Commands/AccessExpireCommand.php`**: `php spark acl:expire [--org=UUID]`.
- **AuthorizationService::rbacGrants** rewritten: role path now requires
  status='active' + effective window; added direct-permission grant path.
- **SegregationOfDutiesCombinator**: `access.request.approve` in APPROVAL_ACTIONS.
- **RBAC**: +`access.request.approve` permission (RbacBootstrapSeeder).
- **Routes**: `access-requests` group (filter `auth`); review actions gated
  `authorize:access.request.approve`.
- **Notifications**: reviewer + outcome notices use the honored `context` opt
  (not `data`), best-effort (try/catch), keyed by dedupe_key.
- **OpenAPI** regenerated: 203 paths / 222 ops / 18 tags / 21 permissions;
  re-validated valid OpenAPI 3.1.
- **Totals now**: 43 controllers, 34 migrations (latest 000035, next free 000036).

## FR-ID-002 + FR-ID-009 — identity uniqueness policy + account lifecycle — COMPLETE
The identity-security pair. FR-ID-002 makes email AND E.164-normalized phone
uniqueness configurable per jurisdiction, adds minor/age gating, and replaces
silent merges with an audited maker-checker review. FR-ID-009 turns the
free-string `users.status` into a governed state machine with reasoned, audited,
evidence-backed transitions that revoke live sessions/tokens on security states.

- **Migration 000036** (`CreateIdentityLifecycle`): ALTER `users` — widen
  `status` to VARCHAR(24); add `phone`/`phone_verified`/`phone_input`/
  `phone_region`, `date_of_birth`/`is_minor`/`country_code`, and lifecycle
  evidence cols (`status_reason`/`status_changed_at`/`status_changed_by`/
  `merged_into_id`/`anonymized_at`); UNIQUE index `users_org_phone_uq`
  (NULL-exempt). New tables: `identity_policies` (per org+country: min_age,
  phone_default_region, require_email_unique, require_phone_unique, allow_minor),
  `account_state_transitions` (append-only from/to/reason/actor/approval_ref/
  evidence), `identity_merge_requests` + `identity_merge_reviews` (maker-checker
  merge trail). down() is best-effort.
- **`Security/PhoneNormalizer.php`**: pragmatic E.164 normalizer (handles +/00
  international form and national numbers against a policy default ISO region;
  trunk-0 stripping; 8–15 digit ITU validation; GH/NG/KE/ZA/US/GB/… calling
  codes). Deterministic canonical form for stable uniqueness.
- **`Services/IdentityPolicyService.php`**: resolve (exact country → org "*"
  default → built-in defaults), upsert, listForOrg.
- **`Services/AccountService.php`** (rewired register): normalizes phone,
  resolves policy, enforces configurable email/phone uniqueness (soft error,
  never silent merge), enforces minor gating, records DOB/is_minor/country, and
  now starts accounts at `pending_verification` (or `prospect`) with seeded
  transition evidence; `markEmailVerified` promotes pending → active with
  evidence.
- **`Services/AccountLifecycleService.php`**: 8-state machine (prospect,
  pending_verification, active, suspended, locked, deactivated, anonymized,
  merged) with an allowed-transition matrix + terminal states; every transition
  requires a reason, writes append-only evidence + a hash-chain audit entry, and
  revokes all sessions AND tokens on suspend/lock/deactivate/anonymize/merge
  (accounting/audit rows untouched); anonymize scrubs PII to tombstones. Merge
  workflow: submitMerge (conflict surfacing, never immediate), approveMerge
  (PDP maker-checker via `access.request.approve` SoD + belt-braces self-approval
  denial → duplicate transitions to `merged`, merged_into_id → primary, history
  preserved), reject/cancel, find/pending.
- **Controllers**: `AccountController` (transition/suspend/lock/reactivate/
  deactivate/anonymize/history + merge submit/pending/show/approve/reject/cancel),
  `IdentityPolicyController` (index/resolve/upsert). `AuthController::register`
  now accepts phone/phone_region/date_of_birth/country_code and returns the
  same generic response on EMAIL_TAKEN **or** PHONE_TAKEN (no enumeration).
- **Routes**: `identity` group (filter `auth`); all mutations gated
  `authorize:identity.manage`.
- **RBAC**: +`identity.manage` permission; default `identity_policies` "*" row
  (min_age 13, phone region GH, email+phone unique) seeded in RbacBootstrapSeeder.
- **DI**: Identity Config/Services adds `phoneNormalizer`, `identityPolicies`,
  `accountLifecycle` factories; `accounts` rewired with the two new deps.
- **OpenAPI** regenerated: 219 paths / 238 ops / 18 tags / 22 permissions;
  valid OpenAPI 3.1, drift-guard deterministic.
- **Totals now**: 45 controllers, 35 migrations (latest 000036, next free 000037).

_NOTE for later: existing pre-migration user rows keep status='active'; the
state machine treats an unknown/legacy `from` state permissively (forward moves
only, never resurrecting terminal states)._

## FR-STR-009 — in-stream giving (Streaming ↔ VBCS bridge) — COMPLETE
A stream shows an authorized cause-progress bar and a contribution widget that
invokes the VBCS flow WITHOUT leaving the stream page. Provider checkout security
is unchanged (no PAN/CVV on our servers; the approved adapter creates the real
checkout and a signed webhook confirms it — we never fake a completion).

- **Migration 000037** (`CreateStreamGiving`): `stream_giving_configs` (per-stream
  authorization + widget config: enabled, cause_id, progress_bar/widget toggles,
  suggested_amounts, min/max, currency, allow_anonymous, ack_enabled,
  **ack_show_amount_allowed** policy gate, authorized_by/at; UNIQUE per stream);
  `stream_giving_intents` (stream↔VBCS-intent link + acknowledgement prefs:
  ack_opt_in, ack_show_amount, display_choice name|anonymous; UNIQUE on intent_id).
  No money state stored here — that stays authoritative in contributions/ledger.
- **`Services/StreamGivingService.php`**: configureGiving (organizer authorizes +
  binds to an active cause), config, widget (public descriptor: progress bar +
  suggested amounts, public cause progress only), give (validates config +
  amount bounds/currency, creates a VBCS intent via ContributionService, records
  ack prefs; idempotent on intent_id), acknowledgements (opt-in feed joined to
  contributions on intent_id state='succeeded' so it can never announce a gift
  that didn't complete; name-or-Anonymous; **amount shown only when giver opted
  in AND policy permits**).
- **`Controllers/StreamGivingController.php`**: configure/showConfig (organizer),
  widget/give/acknowledgements (public/viewer-facing). Giver identity comes from
  the session, never the body.
- **Routes**: organizer `streams/(:seg)/giving/config` (GET/POST, gated
  `authorize:stream.moderate`); public `stream-giving/(:seg)/{widget,
  acknowledgements}` (GET) + `.../give` (POST, `ratelimit:stream.giving`).
- **Rate limit**: new `stream.giving` policy (sliding window 15/60s, fail CLOSED
  — money movement).
- **DI**: Streaming Config/Services adds a `streamGiving` factory (ContributionService
  + CauseService).
- Cause-progress overlay already existed (`OverlayService` cause_progress type);
  this adds the in-page contribution widget + acknowledgement layer the SRS
  requires. The older thin `EngagementController::giving` bridge remains for
  backward compatibility.
- **OpenAPI** regenerated: 223 paths / 243 ops / 18 tags / 22 permissions; valid
  OpenAPI 3.1, drift-guard deterministic.
- **Totals now**: 46 controllers, 36 migrations (latest 000037, next free 000038).

## FR-GRP-003 — group membership model — COMPLETE
Replaced the minimal `group_members` (group/user/role/joined_at) with the full
SRS membership record: a person may belong to many groups/activity groups/
departments/teams, subject to configurable conflict rules, with complete
approval evidence and lifecycle history.

- **Migration 000038** (`EnhanceGroupMemberships`): drops the old (group_id,
  user_id) UNIQUE; ALTER `group_members` +membership_type, status, source,
  permission_scope (JSON), effective_from/to, left_at, leave_reason,
  approval_state/by/at/ref, approval_evidence (JSON), added_by, active_key,
  updated_at; backfills legacy rows (effective_from=joined_at, active_key set);
  adds UNIQUE `gm_active_uq(active_key)` (one active per user+group+type, the
  established single-active pattern) + status indexes. New tables:
  `group_membership_conflicts` (configurable type-pair conflict rules,
  global|same_group|same_branch scope) and append-only `group_membership_events`
  (requested|approved|rejected|joined|role_changed|scope_changed|left). down()
  best-effort restores the original UNIQUE.
- **`Services/GroupMembershipService.php`**: add (immediate or approval-gated,
  idempotent on active membership, conflict-checked), approve/reject (re-checks
  conflicts at approval time), leave (effective-dated; row kept as history,
  frees the active slot), changeRole (role + permission_scope), listForGroup,
  listForUser (multi-group proof), find (with event trail), pendingForGroup,
  defineConflict/listConflicts. Every mutation appends an event + hash-chain
  audit entry.
- **`GroupService::addMember`** kept as a backward-compatible shim writing a
  complete active `member` row (only internal caller).
- **`Controllers/GroupMembershipController.php`**: add/listForGroup/pending/
  listForUser/show/approve/reject/leave/changeRole + defineConflict/listConflicts.
  Actor/approver/added_by come from the session, never the body.
- **Routes**: second authenticated `groups` group (memberships list/add/pending);
  `memberships` group (show/approve/reject/leave/role + conflicts); and
  `users/(:seg)/memberships`. Mutations gated `authorize:group.change.approve`.
- **DI**: Groups Config/Services adds a `memberships` factory (+ Audit import).
- **RBAC**: reuses the existing `group.change.approve` permission (now first
  used on routes — spec permission count 22 -> 23).
- **OpenAPI** regenerated: 232 paths / 254 ops / 18 tags / 23 permissions; valid
  OpenAPI 3.1, drift-guard deterministic.
- **Totals now**: 47 controllers, 37 migrations (latest 000038, next free 000039).

## FR-STR-013 — Relay failure response (Streaming)

Streaming is now feature-complete. Mid-session relay failures are detected,
alerted, mitigated with a documented bypass, and reflected honestly in metrics.

**Migration `2026-09-04-000039_CreateStreamRelayHealth`**
- ALTER `streams`: `relay_state` (healthy|degraded|down, default healthy),
  `degraded_since`, `bypass_destination_id`.
- `stream_relay_health` — append-only samples (status, latency_ms, source =
  heartbeat|provider|manual, note), per stream and optional destination.
- `stream_relay_incidents` — severity (warning|critical), status
  (open|acknowledged|resolved), cause, `affected_destinations` (JSON),
  `alerts_sent` (JSON delivery log), ack / bypass / resolve fields,
  `metrics_degraded`.

**`StreamRelayService`**
- `heartbeat()` ingests a health sample. On a LIVE stream, a `down` beat (or
  ≥3 `degraded` beats within 2 min) auto-opens an incident; a healthy beat
  clears a transient degraded marker but never auto-closes an open incident.
- `reportFailure()` raises a manual incident (organizer/moderator).
- Opening an incident is idempotent per open incident and fires **immediate
  alerts** — in-app + configured email fallback to the stream organizer
  (`created_by`), best-effort (alert failure never crashes the handler), with
  each attempt logged in `alerts_sent`.
- `acknowledge()` → `activateBypass()` (records the documented
  direct-single-destination bypass + chosen destination; metrics stay degraded)
  → `resolve()` (restores healthy state only if no other incident is open).
- `health()` / `incidents()` read surfaces; `bypassProcedureData()` returns the
  step-by-step direct-bypass procedure plus candidate destinations.

**Honest metric degradation** — `StreamEngagementService::realTimeMetrics` now
returns `relay_state`, `metrics_degraded`, and `metrics_exactness`
(exact|estimated) so the platform never implies normal tracking during a relay
failure.

**Wiring** — `StreamRelayController` (heartbeat/report/health/incidents +
acknowledge/bypass/resolve); routes under `streams/{id}/relay/*` and
`stream-incidents/{id}/*`, all gated `authorize:stream.moderate`; DI factory
`StreamingServices::streamRelay()` (db, Clock, NotificationService).

**OpenAPI** — regenerated: **239 paths / 261 operations / 18 tags / 23
permissions**, valid 3.1, drift-guard deterministic.

## FR-INT-012 — Adapter fallback matrix + provider circuit-breaking/quota (Integrations)

This closes the LAST confirmed SRS functional-requirement gap. Every named
broadcast/meeting provider now has a documented per-feature fallback, and
provider calls are protected by a circuit breaker + quota accounting.

**Documented fallback matrix (code-owned)**
- `Providers/FallbackMatrix.php` — per category (`stream`, `meeting`) and feature
  (broadcast, metrics, chat, poll, VOD / create, attendance, recording, chat,
  poll): the provider-API **primary** path, the **documented fallback**
  (`hosted_link` | `manual_import` | `external_vod` | `platform_native`), and a
  note. `resolve()`/`plan()` pick primary vs fallback from an adapter's DECLARED
  capabilities — never claiming a path the adapter didn't declare.
- `Services/FallbackPlanService.php` — turns catalogue capabilities + the matrix
  into a per-adapter coverage plan and a full cross-adapter matrix (honest UI +
  auditor view). No provider secrets cross this boundary.

**Circuit breaker + quota**
- Migration `2026-09-04-000040_CreateProviderReliability` — `provider_circuit_state`
  (per org+scope: state closed|open|half_open, consecutive/lifetime counters,
  cooldown `next_probe_at`, last error) + `provider_quota_usage` (rolling
  minute/hour/day buckets, unique per bucket).
- `Services/ProviderReliabilityService.php` — `call()` wraps a provider closure;
  `admit()` gates on circuit state + quota; a run of `FAILURE_THRESHOLD` (5)
  consecutive failures OPENS the breaker for `COOLDOWN_SECONDS` (60), then a
  single HALF_OPEN probe closes or re-opens it; `reset()` operator override;
  `health()` snapshot. Concurrency-safe seed + atomic `used + 1` increments.

**Wiring** — `StreamService` provision/dispatch and `MeetingService` create now
fail fast when a provider circuit is open, recording success/failure and
returning a `fallback: hosted_link` hint per destination/meeting instead of
hammering a broken provider. DI factories `IntegrationServices::fallbackMatrix()`,
`fallbackPlans()`, `providerReliability()`; StreamService/MeetingService DI updated.

**Reliability breaker — full provider coverage (follow-up).** The breaker is now
wired into every genuine provider-outcome boundary, not just streaming/meetings:
- **Connection tests** (`ConnectionService::recordTest`) — a pass/fail probe for
  ANY connection-based provider (incl. payment + notification) feeds
  success/failure to the per-`(org, adapter_code)` breaker.
- **Payments** (`ContributionService::markSucceeded`/`markFailed`, scope
  `payment:<provider>`) — a provider-verified success is a healthy signal; only a
  genuine PROVIDER/transport fault trips the breaker. Ordinary giver-side
  declines do NOT (webhook passes `provider_fault` explicitly; default false).
- **Notifications** (`NotificationService::updateDeliveryStatus`, scope
  `notification:<channel>`) — `sent`/`delivered` = healthy, `failed` = provider
  fault; recipient-side `bounced`/`complained` never affect the breaker. The
  dispatch worker (`JobRouter::notificationDispatch`) now fails fast (re-queues
  with backoff) when the channel circuit is open instead of marking delivered
  against a dead transport.
All new dependencies are optional/nullable and injected via each module's Config;
`ProviderReliabilityService` stays a leaf (Database + Clock), so there is no DI
cycle.

**Catalogue** — `AdapterCatalogSeeder` now seeds YouTube/Twitch/Facebook/RTMP
(stream) and Zoom/Google Meet/MS Teams (meeting) with honest capability sets so
the matrix shows a real mix of primary vs fallback (e.g. RTMP → metrics/VOD
fallback; Teams → attendance/recording fallback).

**Routes** (under `integrations`, `auth`): `GET fallback-matrix`,
`GET adapters/{code}/fallback`, `GET circuits`, `POST circuits/{scope}/reset`
(reset gated `authorize:provider.configure`).

**OpenAPI** — regenerated: **243 paths / 265 operations / 18 tags / 23
permissions**, valid 3.1, drift-guard deterministic.

**Status:** all Section-A SRS gaps in `docs/SRS_FR_COVERAGE_AUDIT.md` are now
closed.


---

## Group-scoped configurable awards/ranks + group campaigns ("projects")

**FR-GAM-009/010/011** — makes awards/ranks/achievements/streaks configurable
**per hierarchical group** and adds time-boxed group **campaigns** with arbitrary
durations, delivering "any season/duration, per group, per activity" alongside
the annual org-wide season (which is untouched).

**Migration 000042** (`GroupScopedAwardsAndCampaigns`):
- Adds `group_id` (NULL = org-wide) + `include_descendants` to `rank_definitions`,
  `achievement_definitions`, `streak_definitions`; widens their UNIQUE to
  `(org, group, code)` so a group can override an org-wide code.
- Widens `badge_awards` idempotency key to include `source_ref` → **repeatable
  badge wins** (normal awards still one-per-season via NULL source_ref).
- New tables: `group_campaigns`, `campaign_progress`, `campaign_awards`,
  `campaign_recognitions`.

**Services:**
- `CampaignService` — create/activate/cancel, `recordProgress` (idempotent,
  grants every target multiple crossed → badge and/or points, optional roll-up
  to season), `close` (snapshots configurable top‑N recognition), `leaderboard`,
  `listForGroup`. Pure static `awardsDeserved()` encodes the win math.
- `RankService` — now group-aware: `tiers/determineRank/nextRank` accept an
  optional `$groupId` and resolve most-specific-wins along the `group_closure`
  ancestor chain, falling back to org-wide. Org-wide calls unchanged.
- Bound as `campaigns()` in `Gamification/Config/Services.php`.

**HTTP** (under `gamification`): `GET groups/{id}/campaigns`,
`GET campaigns/{id}/leaderboard`, `POST campaigns`, `POST campaigns/{id}/activate`,
`/cancel`, `/progress`, `/close` (writes gated `authorize:gamification.manage`).
`defineRank`/`disableRank` accept optional `group_id`.

**Tests:** `tests/unit/CampaignAwardsDeservedTest.php` (7 cases).

**Docs:** `docs/group-scoped-awards-and-campaigns.md`; audit updated (§B2).

**Backward compatible:** all `group_id` columns default NULL/org-wide; existing
rows and code paths unchanged. Every mutation idempotent via a UNIQUE key.

**Deferred:** automatic progress feed (wiring PointsEngine/VBCS events to
`recordProgress` by `activity_scope`) — manual endpoint exists now.

### Update — tiered awards + automatic activity feed (hook)

**Tiered awards.** `group_campaigns.award_mode` ∈ `single|repeatable|tiered`.
New `campaign_tiers` table (code/name/tier_position/threshold_value/badge_code/
award_points/icon/color) models a ladder (e.g. silver→gold→diamond); each tier
is granted once via `campaign_awards.award_index = tier_position`. `CampaignService`
gains `defineTier`/`tiers`, tiered branch in `recordProgress`, and pure static
`tiersReached()` (unit-tested). `create()` accepts an inline `tiers[]` and legacy
`repeatable` still maps to `award_mode`.

**Automatic feed hook.** `CampaignService::feedFromActivity()` + `matchingCampaigns()`
resolve a subject's groups (self + ancestors via `group_closure`) → active,
in-window campaigns whose `activity_scope` includes the rule code → increment each
by its own metric (count/points/amount/volume). Wired into `PointsEngine::award()`
after FINAL awards only (held awards, roll-up, and the achievements' bare engine
are excluded → no feedback loop). `RewardCoordinator.onSucceeded` now passes
`amount_minor` so giving projects (metric=amount) are fed the contribution value.

**Routes added:** `GET campaigns/{id}/tiers`, `POST campaigns/{id}/tiers`
(manage). **Tests:** `CampaignAwardsDeservedTest` now 13 cases (adds tiered).

### Update — group-vs-group ("team") campaigns

`subject_type='group'` campaigns now fully supported end-to-end. Member activity
rolls up to a competing TEAM: teams = the owner group's immediate children
(`resolveTeams()` / pure `attributedTeams()`); a user accrues to whichever child
is an ancestor-or-self of their group. Owner with no children → the owner
competes as one aggregate subject. Feed `source_ref` namespaced per
(campaign,user) so distinct members each add to the team total while replays
dedupe; a user under multiple teams feeds each. Tier/target awards go to the team
group id; `leaderboard()` attaches team `display_name` (group name, no PII).
`matchingCampaigns()` now takes the subject's direct group ids and also matches
group-subject campaigns owned up the ancestor chain. Tests: +5 `attributedTeams`
cases (16 total in CampaignAwardsDeservedTest).

### Update — ad hoc teams (rosters aggregated across the hierarchy)

Group campaigns now support two team-formation modes via `group_campaigns.team_mode`:
- **subtree** (default) — teams are the owner group's immediate children (prior behavior).
- **adhoc** — teams are explicit rosters drawn from members ANYWHERE in the
  hierarchy. New tables `campaign_teams` (roster def: code/name/captain/color/icon)
  + `campaign_team_members` (UNIQUE(campaign_id,user_id) → one team per user per
  campaign). `subject_type` on progress/awards/recognition rows is stored as
  `team` (vs `group` for subtree teams) so team ids are resolved from
  `campaign_teams` for leaderboard display_name.

`CampaignService`: +`defineTeam`/`addTeamMember`/`removeTeamMember`/`listTeams`,
+`adhocTeamsFor()`. `create()` validates `team_mode` (adhoc requires
subject_type=group). `activate()` requires ≥1 team for adhoc. `matchingCampaigns()`
signature now `(org, subjectId, directIds[], activityCode)` — ORs in campaigns the
user is rostered on (matched independent of group tree) and filters out adhoc
campaigns the user is NOT rostered on. Feed routes group→ subtree teams
(resolveTeams) or adhoc roster team, subject_type team.

Routes: `GET/POST campaigns/{id}/teams`, `POST campaigns/{id}/teams/{teamId}/members`,
`DELETE campaigns/{id}/members/{userId}` (writes gated gamification.manage).
Migration 000042 extended (still needs spark migrate). Balance-checked.

### Correction — campaign is individual-first; team challenge is an OPTIONAL overlay

Reworked the model per user clarification: a campaign ALWAYS drives the
INDIVIDUAL member to do more (individual progress + individual target/tiered
awards are core, always on; leaderboard ranks individuals; subject_type always
'user'). A TEAM CHALLENGE (Red vs Blue etc.) is OPTIONAL at creation
(`group_campaigns.team_challenge`): the same individual contribution ALSO tallies
into a team total. Standings live in new `campaign_team_standings`
(UNIQUE(campaign_id, team_ref); team_ref = group_id for subtree, campaign_teams.id
for adhoc; tracks total_value + distinct contributors). Added
`campaign_progress.team_ref` to record each member's team + make contributor
counting exact/idempotent.

`CampaignService`: `create()` takes optional `team_challenge`/`team_mode`/
`recognize_top_teams`; feed always credits the individual then (if team_challenge)
tallies via `memberTeam()`→`tallyTeam()`; `matchingCampaigns()` = normal group
scope OR explicit roster (roster no longer gates eligibility, only team
attribution); `leaderboard()` back to individuals-only (no PII);
+`teamStandings()`; `close()` recognizes top individuals AND (optional) top teams.
Controller +`campaignTeamStandings`; manual progress endpoint accepts team_ref/
team_kind. Route `GET campaigns/{id}/team-standings`. Migration 000042 extended
(campaign_team_standings + team_challenge/team_mode/recognize_top_teams +
campaign_progress.team_ref). All balance-checked; docs updated.

## Refinement — group/team milestones + contribution-directed attribution (this session)

Per user clarification ("the contribution goes where the member directed it; each group/team has its own milestone as do individuals"):

- **Attribution is contribution-directed.** `feedFromActivity` now honors an explicit designated target (`opts['target_ref']` + `target_kind`) — the group/team the contribution went to is credited. Falls back to the member's own team (`memberTeam()`) only when no target is designated. Credit goes to that group only (target_only — no roll-up to parents). The member's personal milestone remains the cumulative of all their contributions.
- **Group/team milestones (parity with individuals).** New config on `group_campaigns`: `team_target_value` (single milestone target), `team_badge_code`, `team_award_points` — configurable at project creation. `campaign_team_standings` gains `awards_count` (0/1). `tallyTeam()` now grants a group/team its single milestone award via new `grantTeamAward()` (subject_type group|team, idempotent source_ref `campaign:{id}:team:{ref}`) the first time its directed total reaches `team_target_value`. NULL target = scoreboard-only (prior behavior).
- All changes folded into migration 000042 (not yet applied) + CampaignService + docs. Balance-checked OK.

### Demo + tests for group/team milestones (this session)
- CampaignDemoSeeder: added team_target_value=190000 + team_badge_code='campaign_team_champion' (+ badge seeded). Red (195000) reaches the team milestone → Team Champion badge; Blue (185000) falls 5000 short. Same 6 gifts, directed at the team via team_ref.
- Extracted pure CampaignService::teamMilestoneReached(newTotal,target,prevAwards) → used by tallyTeam; 5 new unit tests in CampaignAwardsDeservedTest (21 test methods total). All balance-checked.
- campaign_demo_preview.html updated: per-team milestone rows (Red reached / Blue short), banner + footer note the team milestone.

### Integration test for group/team milestone (this session)
- NEW tests/integration/CampaignTeamMilestoneTest.php (DB-backed, DatabaseTestTrait, $migrate=true/$refresh=true, $namespace=null so all module migrations run). Self-SKIPS when no test DB reachable (Database::connect()+SELECT 1 in setUp), so the suite still passes in a bare sandbox.
- 3 cases: (1) team reaches its own team_target_value -> awards_count 0->1, real campaign_awards row subject_type='team' + badge_awards subject=team; Blue short -> no award; contributors counts distinct members; (2) overshoot/3 crossings -> milestone granted exactly once; (3) team_target_value NULL -> scoreboard only, no award.
- Fixtures seed org/group/group_closure/active season(season_year,no name/updated_at)/2 badges/3 users+group_members. Attribution is contribution-directed via team_ref. Balance-checked OK. Cannot execute in-sandbox (vendor/ empty; php/composer unavailable) — runs in real CI.

### Demo now shows each member's SUBGROUP (this session)
- CampaignDemoSeeder: replaced flat single-region setup with a real 2-level hierarchy — Greater Accra Region (owner) → Accra Metro District + Tema District — via new ensureGroups()/generalized ensureGroup(parentId,...) that wires group_closure (self + inherited ancestors). Each of the 6 members now joins a DISTRICT (not the region): Accra = Ama/Esi/Abena; Tema = Kofi/Yaw/Kwame. Districts deliberately cut across the ad hoc Red/Blue teams to show subgroup ≠ team.
- campaign_demo_preview.html: added a "Group hierarchy" card (tree diagram) + a subgroup pill on every leaderboard row (e.g. "Red Team · Accra Metro District"); banner kv gained a "2 subgroups" stat. Div tags balanced 120/120.

### Leaderboard now returns each member's hierarchical group PATH (O(1) reads) (this session)
- Design (cost-optimized for the hot, concurrency-bound read): denormalize member's most-specific group at WRITE time; stitch full path at READ from materialized groups.path + ONE bounded groups query. Added read cost = O(rows)+O(1) queries, independent of concurrent users.
- Migration 000042: campaign_progress += subject_group_id CHAR(36) NULL.
- CampaignService: recordProgress stamps subject_group_id on first contribution (opts['subject_group_id'] preferred → free from feed; else mostSpecificGroup() one indexed lookup). leaderboard() now returns group_id/group_name(leaf)/group_path(root→leaf names) via attachGroupPaths(). New pure helpers parsePathIds() + groupPathNames() (unit-testable). New private mostSpecificGroup() (deepest active direct group, deterministic tie-break by g.id).
- Tests: +5 unit (parsePathIds x2, groupPathNames x3) → 26 unit methods; +1 integration (testLeaderboardCarriesEachMembersHierarchicalGroupPath: members in a district child group; asserts group_id=district, group_name='District', group_path=['Region','District']) → 4 integration methods. Integration fixtures gained a district child + closure rows; members now join the district; drive()/createGivingCampaign() gained nullable teamRef / teamChallenge flag.
- Docs updated (leaderboard HTTP row + new "Member's hierarchical group on the leaderboard (O(1) reads)" section). All files balance-checked.

### Member dashboard now shows each membership's hierarchical group PATH (this session)
- MemberDashboardService::groups() now selects g.path and attaches group_path (root→leaf names) to each membership, using the same materialized-path + ONE bounded groups-name query strategy (O(memberships)+O(1) queries; Reporting stays decoupled from Gamification — helpers inlined).
- New public static pure helpers on MemberDashboardService: parsePathIds(), groupPathNames() (mirrors CampaignService; unit-testable).
- View app/Modules/Reporting/Views/member_dashboard.php: "My groups" cards render the path as a "A › B › C" breadcrumb line when present.
- Tests: +3 unit in MemberDashboardRankTest (parsePathIds, groupPathNames x2) → 17 dashboard test methods. All balance-checked.
- GET /me/dashboard payload: groups[].group_path added (names only, no PII).

### Subtree (hierarchical group) project — seeder + integration test + preview (this session)
- NEW preview campaign_demo_subtree_preview.html: "Q3 Regional Giving" — teams ARE the region's subgroups (Accra Metro vs Tema District), membership derived from hierarchy (no rosters). Accra 1950 reaches district milestone GHS1920 (District Champion badge); Tema 1900 short by GHS20. Both previews now cross-link via a .switch header. campaign_demo_preview.html unchanged except switch header.
- NEW seeder app/Modules/Gamification/Database/Seeds/CampaignSubtreeDemoSeeder.php (FQCN WBS\Gamification\Database\Seeds\CampaignSubtreeDemoSeeder). code 'q3-regional-giving'. team_mode='subtree', team_target_value=192000, team_badge_code='campaign_district_champion'. NO defineTeam/addTeamMember (subtree needs none). Drives 6 gifts with team_ref=district group id, team_kind='group' (Accra: ama120000/abena55000/esi20000=195000 REACH; Tema: yaw90000/kofi60000/kwame40000=190000 short). Reuses same groups/users as CampaignDemoSeeder (idempotent by slug/email), so both seeders coexist. Idempotent by campaign code.
- Integration test: +1 method testSubtreeSubgroupEarnsItsOwnMilestoneAsGroupSubject (team_kind='group'; asserts standing total 160000, team_kind='group', contributors 2, awards_count 1, campaign_awards subject_type='group' + badge_awards subject=district group id). → 5 integration methods. drive() gained $teamKind param (default 'team'); createGivingCampaign() gained $subtree flag (team_mode subtree|adhoc). Balance-checked.

### RUNBOOK: Campaign project demos documented (this session)
- RUNBOOK.md §5 seed list: added CampaignDemoSeeder + CampaignSubtreeDemoSeeder as step 4 (OPTIONAL). New §5a "Campaign project demos (FR-GAM-009/010/011)" explains both flavours (adhoc Red-vs-Blue rosters vs subtree Accra-vs-Tema subgroups), their codes, milestones/outcomes, shared hierarchy+members, idempotency, and points to the two static preview HTML mirrors at repo root.

### Reframed workflow: base project first, team challenge as opt-in overlay (this session)
- User correction: a project is fundamentally "bundle Win-Build-Send activities + target + start/close dates"; "Red vs Blue" team challenge is OPTIONAL, not central. Code already matched (team_challenge defaults 0; team guards only fire when enabled) — this was a docs/demo emphasis fix.
- docs/group-scoped-awards-and-campaigns.md §3: added "What a project IS (the base case — no teams)" subsection up front (min fields table + 5-step no-team lifecycle) before the group_campaigns field table; added an "optional team overlay below" divider row in the field table; team fields now clearly gated on team_challenge=1.
- NEW campaign_demo_base_preview.html: "Q3 Outreach Drive" — metric=count, single target 40, activity_scope bundle (event.attended/contribution.verified/course.completed), NO team_challenge. Shows config (bundle/target/window), individual leaderboard with progress-to-target, top-3 recognition. 6 members (same demo people/groups), 2 reach target (Ama 52, Yaw 44). group shown for context only.
- All three previews now cross-link via a 3-way .switch header (base / subtree / adhoc). Balance-checked.

### Base (no-team) demo seeder + integration test (this session)
- NEW app/Modules/Gamification/Database/Seeds/CampaignBaseDemoSeeder.php (FQCN WBS\Gamification\Database\Seeds\CampaignBaseDemoSeeder). code 'q3-outreach-drive'. The core project: activity_scope=[event.attended,contribution.verified,course.completed], metric=count, award_mode=single, target_value=40, badge campaign_outreach_champion(+300pts,rollup). NO team_challenge. Drives count increments per member (Ama 52/Yaw 44/Kofi 38/Abena 31/Kwame 22/Esi 12 → 2 reach 40), closes top-3. Reuses shared groups/users (idempotent by slug/email); idempotent by code. Seeds Outreach Champion badge.
- Integration test +1: testBaseProjectNoTeamsGrantsIndividualAwardAndHasNoTeamStandings (teamChallenge=false; asserts current_value 1,100,000, awards_count 1, campaign_awards subject_type='user', ZERO campaign_team_standings rows). → 6 integration methods.
- RUNBOOK §5/§5a: base seeder listed FIRST as step 4; §5a reworded "Three ... seeders" with base described as the core case; closing paragraph lists all three preview files incl. campaign_demo_base_preview.html. All balance-checked.

## Campaign CRUD gap-fills — COMPLETE
- Routes (Routes.php ~525-532): GET campaigns/{id} (read, no filter); PATCH campaigns/{id}; PATCH campaigns/{id}/teams/{teamId}; DELETE campaigns/{id}/teams/{teamId} — all mutating behind authorize:gamification.manage. `(:segment)` single-segment match confirmed not to shadow /tiers /teams sub-routes.
- Service: show(), update() (DRAFT-only), updateTeam() (draft|active), deleteTeam() (DRAFT-only, txn). Controller: getCampaign/updateCampaign/updateCampaignTeam/deleteCampaignTeam.
- Tests: CampaignTeamMilestoneTest.php now 11 methods; added show-inlines-tiers-teams, update-draft-then-frozen, update-rejects-nonpositive-target, updateTeam-rename + deleteTeam-draft-removes-roster, deleteTeam-rejected-when-active. Bracket-balanced. (env-gated: phpunit still unavailable in sandbox — not executed.)
- Docs: group-scoped-awards-and-campaigns.md HTTP table updated with 4 new endpoints.
