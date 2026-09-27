<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Canonical route table (SRS FR-ARC-001/002).
 *
 * There is NO duplicate /api tree — every resource has ONE canonical URL that
 * serves HTML or JSON by representation negotiation in the BaseController.
 * Sensitive state-changing endpoints declare a named rate-limit policy via the
 * `ratelimit:` filter (FR-RL-001/005), which fails closed for auth/payment/MFA.
 *
 * @var RouteCollection $routes
 */

$routes->get('/', 'Home::index');
$routes->get('health', 'Home::index');

// Full-path console aliases (also in Modules/*/Config/Routes.php). Declared
// here so GET /announcements is not only a group+'' match that 404s on some hosts.
$routes->get('announcements', '\WBS\Announcements\Controllers\AnnouncementController::index', ['filter' => ['auth', 'authorize:notification.send,any']]);
$routes->get('announcements/', '\WBS\Announcements\Controllers\AnnouncementController::index', ['filter' => ['auth', 'authorize:notification.send,any']]);
$routes->get('announcements/inbox', '\WBS\Announcements\Controllers\AnnouncementController::inbox', ['filter' => 'auth']);
$routes->get('announcements/inbox/', '\WBS\Announcements\Controllers\AnnouncementController::inbox', ['filter' => 'auth']);
$routes->get('notifications/templates', '\WBS\Notifications\Controllers\TemplateController::index', ['filter' => ['auth', 'authorize:provider.configure,any']]);
$routes->get('event-committees', '\WBS\Events\Controllers\CommitteeController::hub', ['filter' => 'auth']);
$routes->get('my/integration', '\WBS\Referrals\Controllers\IntegrationController::mine', ['filter' => 'auth']);
$routes->get('events/calendar/settings', '\WBS\Events\Controllers\EventController::calendarSettings', ['filter' => ['auth', 'authorize:event.create,any']]);

// Self-documenting API: machine-readable spec + interactive reference. Public;
// the spec is generated from THIS route table (php spark openapi:generate).
$routes->get('openapi.json', '\WBS\Shared\Controllers\ApiDocsController::spec');
$routes->get('docs', '\WBS\Shared\Controllers\ApiDocsController::ui');

// ---------------------------------------------------------------------------
// Identity: registration, login, MFA, social sign-in (Phase 1 "Win")
// ---------------------------------------------------------------------------
// Browser web-session flow (HTML): cookie-based sign-in layered on the JSON auth
// services. Distinct from the /auth/* JSON API below. CSRF-guarded POSTs; /me is
// session-cookie protected via the auth filter.
$routes->get('login', '\WBS\Identity\Controllers\WebSessionController::showLogin');
$routes->post('login', '\WBS\Identity\Controllers\WebSessionController::login', ['filter' => ['webcsrf', 'ratelimit:auth.login']]);
$routes->get('mfa', '\WBS\Identity\Controllers\WebSessionController::showMfa');
$routes->post('mfa', '\WBS\Identity\Controllers\WebSessionController::verifyMfa', ['filter' => 'webcsrf']);
$routes->get('me', '\WBS\Identity\Controllers\WebSessionController::me', ['filter' => 'auth']);

// Member self-service profile editor (SRS FR-ID-008): edit display name +
// language + time zone (wired to the previously-orphaned updateProfile) plus the
// photo controls. auth + webcsrf; API callers are header-exempt from webcsrf.
$routes->get('me/profile', '\WBS\Identity\Controllers\WebSessionController::profile', ['filter' => 'auth']);
$routes->post('me/profile', '\WBS\Identity\Controllers\WebSessionController::updateProfile', ['filter' => ['auth', 'webcsrf']]);

// Birthday hub (own + peers in current groups + ancestor-group leaders).
$routes->get('me/birthdays', '\WBS\Groups\Controllers\BirthdayController::index', ['filter' => 'auth']);

// Member profile photo (self-service). The AVATAR endpoint always returns an
// image — the member's photo when set, else a deterministic inline-SVG initials
// avatar (no external calls, works in a network-less preview). Set/remove are
// CSRF-guarded; there is no upload pipeline (a hosted URL or data: URI).
$routes->get('me/avatar', '\WBS\Identity\Controllers\WebSessionController::avatar', ['filter' => 'auth']);
$routes->post('me/photo', '\WBS\Identity\Controllers\WebSessionController::setPhoto', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/photo/remove', '\WBS\Identity\Controllers\WebSessionController::removePhoto', ['filter' => ['auth', 'webcsrf']]);

// Member/staff ADDRESS BOOK & outreach (dashboard). Birds-eye of the downline,
// consent-gated GPS tagging, dated decisions, staff bulk (authorize:identity.manage).
$routes->get('me/contacts', '\WBS\Referrals\Controllers\ContactBookController::index', ['filter' => 'auth']);
$routes->post('me/contacts', '\WBS\Referrals\Controllers\ContactBookController::create', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/contacts/(:segment)/follow-up', '\WBS\Referrals\Controllers\ContactBookController::followUp/$1', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/contacts/(:segment)/decision', '\WBS\Referrals\Controllers\ContactBookController::decide/$1', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/contacts/(:segment)/attend', '\WBS\Referrals\Controllers\ContactBookController::attend/$1', ['filter' => ['auth', 'webcsrf']]);
// Staff bulk: auth + CSRF only. The SERVICE enforces leadership-scope containment
// (a leader may only bulk-add on behalf of groups within their own scope), so no
// separate org-admin capability is required.
$routes->post('me/contacts/bulk', '\WBS\Referrals\Controllers\ContactBookController::bulk', ['filter' => ['auth', 'webcsrf']]);
// Integration decisions (FR-REF-3b) — the standalone dated decisions of the
// integration lifecycle (salvation, water baptism, Holy Spirit baptism,
// foundation course). `my/integration` is the member self-service page;
// `me/integration-decisions` is the owning mentor's pending-confirmation queue.
// Service-gated (auth + webcsrf only): the authoritative decision is the
// IntegrationService's config gate + ownership/sponsor check, so no new
// permission bit is declared here.
$routes->get('my/integration', '\WBS\Referrals\Controllers\IntegrationController::mine', ['filter' => 'auth']);
$routes->post('my/integration', '\WBS\Referrals\Controllers\IntegrationController::declareSelf', ['filter' => ['auth', 'webcsrf']]);
$routes->get('me/integration-decisions', '\WBS\Referrals\Controllers\IntegrationController::queue', ['filter' => 'auth']);
$routes->post('me/integration-decisions/confirm/(:segment)', '\WBS\Referrals\Controllers\IntegrationController::confirm/$1', ['filter' => ['auth', 'webcsrf']]);
$routes->post('me/integration-decisions/reject/(:segment)', '\WBS\Referrals\Controllers\IntegrationController::reject/$1', ['filter' => ['auth', 'webcsrf']]);
// Universal dynamic menu (docs/DYNAMIC-MENU-*.md). Derive-don't-store: the word is
// computed from the subject's roles, never stored per user. GET honours
// If-None-Match -> 304 (Tier 0, zero render). ?bundle=1 = client render bundle.
// Manual language switch (CSP-safe form POST). No auth: anonymous visitors may
// switch too (persisted via cookie); signed-in users also get it saved to prefs.
$routes->post('prefs/locale', '\WBS\Shared\Controllers\LocaleController::set', ['filter' => 'webcsrf']);

// Frontend translation bundle: merged, English-backed catalog (file strings +
// DB sources) for one locale. Public + edge-cacheable + ETag/304 (no per-user
// data), so a CDN serves one copy per (locale, ns, version). ?ns= narrows to
// dotted key prefixes. No auth: UI strings are non-secret.
$routes->get('i18n/(:segment)', '\WBS\Shared\Controllers\TranslationController::bundle/$1');

$routes->get('me/menu', '\WBS\Shared\Controllers\MenuController::index', ['filter' => 'auth']);
$routes->get('me/menu/badges', '\WBS\Shared\Controllers\MenuController::badges', ['filter' => 'auth']);
$routes->get('me/menu/authority', '\WBS\Shared\Controllers\MenuController::authority', ['filter' => 'auth']);
$routes->get('me/sessions', '\WBS\Identity\Controllers\WebSessionController::sessions', ['filter' => 'auth']);
$routes->post('me/sessions/(:segment)/revoke', '\WBS\Identity\Controllers\WebSessionController::revokeSession/$1', ['filter' => ['auth', 'webcsrf']]);
$routes->post('logout', '\WBS\Identity\Controllers\WebSessionController::logout', ['filter' => 'webcsrf']);
// Set-password / accept-invite (public; token is the bearer secret). CSRF on the
// POST; rate-limited to blunt token brute-forcing.
$routes->get('set-password', '\WBS\Identity\Controllers\SetPasswordController::show');
$routes->post('set-password', '\WBS\Identity\Controllers\SetPasswordController::submit', ['filter' => ['webcsrf', 'ratelimit:auth.password_reset']]);

$routes->group('auth', static function ($routes): void {
    $routes->post('register', '\WBS\Identity\Controllers\AuthController::register', ['filter' => 'ratelimit:auth.register']);
    $routes->post('login', '\WBS\Identity\Controllers\AuthController::login', ['filter' => 'ratelimit:auth.login']);
    $routes->post('logout', '\WBS\Identity\Controllers\AuthController::logout');

    // MFA (adaptive/dynamic)
    $routes->post('mfa/totp/enrol', '\WBS\Identity\Controllers\MfaController::enrolTotp', ['filter' => 'ratelimit:auth.mfa_verify']);
    $routes->post('mfa/totp/confirm', '\WBS\Identity\Controllers\MfaController::confirmTotp', ['filter' => 'ratelimit:auth.mfa_verify']);
    $routes->post('mfa/verify', '\WBS\Identity\Controllers\MfaController::verify', ['filter' => 'ratelimit:auth.mfa_verify']);
    $routes->get('mfa/factors', '\WBS\Identity\Controllers\MfaController::factors');

    // Social OIDC (claims validated by adapter before callback)
    $routes->post('social/(:segment)/callback', '\WBS\Identity\Controllers\SocialAuthController::callback/$1', ['filter' => 'ratelimit:auth.login']);
    $routes->post('social/(:segment)/link', '\WBS\Identity\Controllers\SocialAuthController::link/$1', ['filter' => 'ratelimit:auth.mfa_verify']);
    $routes->post('social/(:segment)/unlink', '\WBS\Identity\Controllers\SocialAuthController::unlink/$1');
});

// ---------------------------------------------------------------------------
// API tokens (SRS FR-ID-006) — hashed-at-rest, scoped, rotating refresh family
// ---------------------------------------------------------------------------
// Refresh is public (bearer of the refresh token proves possession) but rate
// limited; issuance/listing/revocation require an authenticated session.
$routes->post('tokens/refresh', '\WBS\Identity\Controllers\TokenController::refresh', ['filter' => 'ratelimit:auth.login']);
$routes->group('tokens', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('', '\WBS\Identity\Controllers\TokenController::issue', ['filter' => 'ratelimit:auth.login']);
    $routes->get('', '\WBS\Identity\Controllers\TokenController::list');
    $routes->post('revoke-all', '\WBS\Identity\Controllers\TokenController::revokeAll');
    $routes->delete('(:segment)', '\WBS\Identity\Controllers\TokenController::revoke/$1');
});

// ---------------------------------------------------------------------------
// Account lifecycle + identity uniqueness policy + audited merge review
// (SRS FR-ID-009, FR-ID-002). All authenticated; mutations gated by the
// `identity.manage` permission. Merge approval additionally enforces the PDP's
// maker-checker (a requester can't approve their own merge).
// ---------------------------------------------------------------------------
// Member roster (read-only landing page for the People menu). Authenticated +
// identity.manage in ANY scope (matches the menu item's scopeCheck: 'any').
$routes->get('members', '\WBS\Identity\Controllers\AccountController::index', ['filter' => ['auth', 'authorize:identity.manage,any']]);

$routes->group('identity', ['filter' => 'auth'], static function ($routes): void {
    // Per-jurisdiction identity policy (uniqueness switches, min age, region).
    $routes->get('policies', '\WBS\Identity\Controllers\IdentityPolicyController::index', ['filter' => 'authorize:identity.manage']);
    $routes->get('policies/(:segment)/effective', '\WBS\Identity\Controllers\IdentityPolicyController::resolve/$1', ['filter' => 'authorize:identity.manage']);
    // Create: the "add a policy" form posts here with country_code in the body
    // (no path segment) so the surface is no-JS / CSP-safe. Update keeps the
    // jurisdiction in the path segment (read-only in the edit form).
    $routes->post('policies', '\WBS\Identity\Controllers\IdentityPolicyController::upsert', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('policies/(:segment)', '\WBS\Identity\Controllers\IdentityPolicyController::upsert/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);

    // Identity-merge review workflow (maker-checker).
    $routes->post('merges', '\WBS\Identity\Controllers\AccountController::submitMerge', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->get('merges/pending', '\WBS\Identity\Controllers\AccountController::pendingMerges', ['filter' => 'authorize:identity.manage']);
    $routes->get('merges/(:segment)', '\WBS\Identity\Controllers\AccountController::showMerge/$1', ['filter' => 'authorize:identity.manage']);
    $routes->post('merges/(:segment)/approve', '\WBS\Identity\Controllers\AccountController::approveMerge/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('merges/(:segment)/reject', '\WBS\Identity\Controllers\AccountController::rejectMerge/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('merges/(:segment)/cancel', '\WBS\Identity\Controllers\AccountController::cancelMerge/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);

    // Account lifecycle state machine.
    $routes->get('accounts/(:segment)/transitions', '\WBS\Identity\Controllers\AccountController::history/$1', ['filter' => 'authorize:identity.manage']);
    $routes->post('accounts/(:segment)/transition', '\WBS\Identity\Controllers\AccountController::transition/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('accounts/(:segment)/suspend', '\WBS\Identity\Controllers\AccountController::suspend/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('accounts/(:segment)/lock', '\WBS\Identity\Controllers\AccountController::lock/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('accounts/(:segment)/reactivate', '\WBS\Identity\Controllers\AccountController::reactivate/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('accounts/(:segment)/deactivate', '\WBS\Identity\Controllers\AccountController::deactivate/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
    $routes->post('accounts/(:segment)/anonymize', '\WBS\Identity\Controllers\AccountController::anonymize/$1', ['filter' => ['authorize:identity.manage', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Public group landing pages (unauthenticated) — directory + per-group page.
// Kept on the short /g path, separate from the management /groups API.
// ---------------------------------------------------------------------------
$routes->get('g', '\WBS\Groups\Controllers\GroupPublicController::directory');
// Global->local drill-down map (Country > State > City > Venue > groups).
// Declared BEFORE g/(:segment) so 'map' is not captured as a group slug.
$routes->get('g/map', '\WBS\Groups\Controllers\GroupPublicController::geoDirectory');
$routes->get('g/(:segment)', '\WBS\Groups\Controllers\GroupPublicController::page/$1');
$routes->get('g/(:segment)/join', '\WBS\Groups\Controllers\GroupPublicController::joinForm/$1');
$routes->post('g/(:segment)/join', '\WBS\Groups\Controllers\GroupPublicController::join/$1', ['filter' => ['ratelimit:auth.register', 'webcsrf']]);

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------
$routes->group('groups', static function ($routes): void {
    // Hierarchy browser (list/tree). Authenticated read of the org's own tree.
    $routes->get('', '\WBS\Groups\Controllers\GroupController::index', ['filter' => 'auth']);
    $routes->get('create', '\WBS\Groups\Controllers\GroupController::createForm', ['filter' => ['auth', 'authorize:group.create,any']]);
    $routes->post('', '\WBS\Groups\Controllers\GroupController::create', ['filter' => ['auth', 'webcsrf']]);
    $routes->get('move', '\WBS\Groups\Controllers\GroupController::moveForm', ['filter' => ['auth', 'authorize:group.move,any']]);
    $routes->post('move', '\WBS\Groups\Controllers\GroupController::moveDispatch', ['filter' => ['auth', 'authorize:group.move,any', 'webcsrf']]);
    // Edit core fields (name/slug/type/kind) — governance-gated. The literal
    // '(:segment)/edit' is declared before '(:segment)' so it is not shadowed.
    $routes->get('(:segment)/edit', '\WBS\Groups\Controllers\GroupController::editForm/$1', ['filter' => ['auth', 'authorize:group.change.approve']]);
    $routes->post('(:segment)/edit', '\WBS\Groups\Controllers\GroupController::update/$1', ['filter' => ['auth', 'authorize:group.change.approve', 'webcsrf']]);
    // Hard-delete an empty leaf (guarded in the service). Governance-gated.
    $routes->post('(:segment)/delete', '\WBS\Groups\Controllers\GroupController::delete/$1', ['filter' => ['auth', 'authorize:group.change.approve', 'webcsrf']]);
    $routes->get('(:segment)', '\WBS\Groups\Controllers\GroupController::show/$1');
    $routes->post('(:segment)/move', '\WBS\Groups\Controllers\GroupController::move/$1', ['filter' => ['auth', 'webcsrf']]);
    // GR1: this add-member mutation had NO filter at all (the public `groups`
    // group applies filters per-route). Gate it: auth + group.change.approve +
    // webcsrf; the controller/service still does the per-group scope check.
    $routes->post('(:segment)/members', '\WBS\Groups\Controllers\GroupController::addMember/$1', ['filter' => ['auth', 'authorize:group.change.approve', 'webcsrf']]);
});

// Group memberships (SRS FR-GRP-003) — full lifecycle with type/role/scope/
// approval evidence + configurable conflict rules. A second, authenticated
// `groups` group so `authorize:` always runs after `auth` (membership changes
// are governance actions gated by group.change.approve). Read listings only need
// an authenticated session.
$routes->group('groups', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('(:segment)/memberships', '\WBS\Groups\Controllers\GroupMembershipController::listForGroup/$1');
    $routes->get('(:segment)/memberships/pending', '\WBS\Groups\Controllers\GroupMembershipController::pending/$1', ['filter' => 'authorize:group.change.approve']);
    $routes->post('(:segment)/memberships', '\WBS\Groups\Controllers\GroupMembershipController::add/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    // Public landing-page profile (theme + presentational/contact/join settings).
    $routes->post('(:segment)/profile', '\WBS\Groups\Controllers\GroupController::updateProfile/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);

    // Group lifecycle — archive / reactivate / dissolve / merge with evidence
    // (FR-GRP-005). Governance actions gated by group.change.approve at the route
    // AND re-checked per-group in the controller (a leader may only act within
    // their own subtree). Each requires a stated reason.
    $routes->get('(:segment)/lifecycle', '\WBS\Groups\Controllers\GroupLifecycleController::history/$1');
    $routes->post('(:segment)/archive', '\WBS\Groups\Controllers\GroupLifecycleController::archive/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/reactivate', '\WBS\Groups\Controllers\GroupLifecycleController::reactivate/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/dissolve', '\WBS\Groups\Controllers\GroupLifecycleController::dissolve/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/merge', '\WBS\Groups\Controllers\GroupLifecycleController::merge/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);

    // Cross-cutting group links (leadership-responsibility model). A cross-cut
    // group (worship team, youth network, choir) is linked to the hierarchy
    // node(s) it spans; the access layer extends leader scope to these groups
    // opt-in + down-only. Mutations are governance actions (group.change.approve).
    $routes->get('(:segment)/crosscuts', '\WBS\Groups\Controllers\GroupCrosscutController::forNode/$1');
    $routes->get('(:segment)/crosscut-nodes', '\WBS\Groups\Controllers\GroupCrosscutController::forCrosscut/$1');
    $routes->post('(:segment)/crosscuts', '\WBS\Groups\Controllers\GroupCrosscutController::link/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/crosscuts/unlink', '\WBS\Groups\Controllers\GroupCrosscutController::unlink/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);

    // Set/clear a group's classification (kind). Governance action.
    $routes->post('(:segment)/kind', '\WBS\Groups\Controllers\GroupController::setKind/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
});

// Configurable group-kind taxonomy (department / activity team / ministry /
// committee). Reads need an authenticated session; mutations are governance
// actions (group.change.approve). Classification only — does not affect scope.
$routes->group('group-kinds', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Groups\Controllers\GroupKindController::index');
    $routes->get('(:segment)', '\WBS\Groups\Controllers\GroupKindController::show/$1');
    $routes->post('', '\WBS\Groups\Controllers\GroupKindController::create', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)', '\WBS\Groups\Controllers\GroupKindController::update/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
});

// Membership records + conflict rules addressed by membership id (not nested
// under a group). Authenticated; mutations gated by group.change.approve.
$routes->group('memberships', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('conflicts', '\WBS\Groups\Controllers\GroupMembershipController::listConflicts');
    $routes->post('conflicts', '\WBS\Groups\Controllers\GroupMembershipController::defineConflict', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->get('(:segment)', '\WBS\Groups\Controllers\GroupMembershipController::show/$1');
    $routes->post('(:segment)/approve', '\WBS\Groups\Controllers\GroupMembershipController::approve/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/reject', '\WBS\Groups\Controllers\GroupMembershipController::reject/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/leave', '\WBS\Groups\Controllers\GroupMembershipController::leave/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
    $routes->post('(:segment)/role', '\WBS\Groups\Controllers\GroupMembershipController::changeRole/$1', ['filter' => ['authorize:group.change.approve', 'webcsrf']]);
});

// A person's memberships across groups (FR-GRP-003 multi-group). Authenticated.
$routes->get('users/(:segment)/memberships', '\WBS\Groups\Controllers\GroupMembershipController::listForUser/$1', ['filter' => 'auth']);

// ---------------------------------------------------------------------------
// Membership Journey — the configurable discipleship spine (assessment Option B).
// Stage-ladder config is gated by gamification.manage (the engagement-model
// permission); per-member moves are additionally group-scope-checked inside the
// controller so a leader can only move members within their own scope.
$routes->group('journey', ['filter' => 'auth'], static function ($routes): void {
    // Configurable stage ladder.
    $routes->get('stages', '\WBS\Journey\Controllers\JourneyController::listStages');
    $routes->post('stages', '\WBS\Journey\Controllers\JourneyController::defineStage', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);

    // Pipeline / reporting reads.
    $routes->get('pipeline', '\WBS\Journey\Controllers\JourneyController::pipeline');
    $routes->get('funnel', '\WBS\Journey\Controllers\JourneyController::funnel');
    $routes->get('stages/(:segment)/members', '\WBS\Journey\Controllers\JourneyController::membersAtStage/$1');
    // Rebuild the involvement snapshots for a context (admin/batch action);
    // PRGs back to the pipeline. Gated + webcsrf (browser cookie-auth write).
    $routes->post('involvement/recompute', '\WBS\Journey\Controllers\JourneyController::recomputeInvolvement', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    // Involvement-triage config console: enable/disable + tune window, activity
    // target, band thresholds, quantum weights for a context (org-wide or group).
    $routes->get('involvement/config', '\WBS\Journey\Controllers\JourneyController::involvementConfig');
    $routes->post('involvement/config', '\WBS\Journey\Controllers\JourneyController::saveInvolvementConfig', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    // Disciple-making leaderboard (Option D): who has moved the most people forward.
    $routes->get('leaderboard/disciplers', '\WBS\Journey\Controllers\JourneyController::disciplerLeaderboard');

    // Per-member journey operations (scope-checked in the controller).
    $routes->post('members/(:segment)/open', '\WBS\Journey\Controllers\JourneyController::openJourney/$1', ['filter' => 'webcsrf']);
    $routes->post('members/(:segment)/transition', '\WBS\Journey\Controllers\JourneyController::transition/$1', ['filter' => 'webcsrf']);
    $routes->post('members/(:segment)/status', '\WBS\Journey\Controllers\JourneyController::setStatus/$1', ['filter' => 'webcsrf']);
    $routes->get('members/(:segment)/recommendations', '\WBS\Journey\Controllers\JourneyController::recommendations/$1');
    $routes->get('members/(:segment)', '\WBS\Journey\Controllers\JourneyController::showForUser/$1');
    $routes->get('members/(:segment)/all', '\WBS\Journey\Controllers\JourneyController::listForUser/$1');

    // Rule-driven transitions (Option C): signal ingestion + proposal review.
    $routes->post('signals', '\WBS\Journey\Controllers\JourneyController::signal', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('proposals', '\WBS\Journey\Controllers\JourneyController::listProposals');
    $routes->post('proposals/(:segment)/approve', '\WBS\Journey\Controllers\JourneyController::approveProposal/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('proposals/(:segment)/reject', '\WBS\Journey\Controllers\JourneyController::rejectProposal/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Geo — resolution/reverse-geocode (public reference reads) + venues (managed)
// ---------------------------------------------------------------------------
$routes->group('geo', static function ($routes): void {
    // Reference-data reads: no personal data, no auth required.
    $routes->post('resolve', '\WBS\Geo\Controllers\GeoController::resolve');
    $routes->get('reverse-geocode', '\WBS\Geo\Controllers\GeoController::reverseGeocode');
    $routes->get('places', '\WBS\Geo\Controllers\GeoController::placesInRadius');
    $routes->get('stats/(:segment)', '\WBS\Geo\Controllers\GeoController::stats/$1');
});
$routes->group('venues', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Geo\Controllers\VenueController::index');
    $routes->get('nearby', '\WBS\Geo\Controllers\VenueController::nearby');
    $routes->get('stats', '\WBS\Geo\Controllers\VenueController::stats');
    // Admin global->local venue directory (Country > State > City > Venue).
    // Declared BEFORE the (:segment) catch-all so 'directory' isn't a venue id.
    $routes->get('directory', '\WBS\Geo\Controllers\VenueController::geoDirectory', ['filter' => 'authorize:venue.manage']);
    // Browser CRUD form pages (GET) - the "New venue" / "Edit venue" landing pages
    // the directory links to. `new`/`edit` declared BEFORE the `(:segment)`
    // catch-all so they match as literal pages, not venue ids.
    $routes->get('new', '\WBS\Geo\Controllers\VenueController::createForm', ['filter' => 'authorize:venue.manage']);
    $routes->get('(:segment)/edit', '\WBS\Geo\Controllers\VenueController::editForm/$1', ['filter' => 'authorize:venue.manage']);
    $routes->get('(:segment)', '\WBS\Geo\Controllers\VenueController::show/$1');
    $routes->post('', '\WBS\Geo\Controllers\VenueController::create', ['filter' => ['authorize:venue.manage', 'webcsrf']]);
    $routes->post('bulk', '\WBS\Geo\Controllers\VenueController::bulkCreate', ['filter' => 'authorize:venue.manage']);
    $routes->post('(:segment)', '\WBS\Geo\Controllers\VenueController::update/$1', ['filter' => ['authorize:venue.manage', 'webcsrf']]);
    // Browser delete: a native <form> can't issue DELETE, so accept a webcsrf POST
    // alongside the REST DELETE the JSON API uses.
    $routes->post('(:segment)/delete', '\WBS\Geo\Controllers\VenueController::delete/$1', ['filter' => ['authorize:venue.manage', 'webcsrf']]);
    $routes->delete('(:segment)', '\WBS\Geo\Controllers\VenueController::delete/$1', ['filter' => 'authorize:venue.manage']);
    $routes->post('(:segment)/groups', '\WBS\Geo\Controllers\VenueController::assignToGroup/$1', ['filter' => 'authorize:venue.manage']);
});
$routes->get('groups/(:segment)/venues', '\WBS\Geo\Controllers\VenueController::groupVenues/$1', ['filter' => 'auth']);

// ---------------------------------------------------------------------------
// Referrals (cloaked links, clicks, attribution) — "Win"
// ---------------------------------------------------------------------------
$routes->group('referrals', static function ($routes): void {
    // R1: these three mutations were UNAUTHENTICATED (no filter at all) — an open
    // door to create cloaked links for any referrer, rewrite the sponsorship
    // graph, and mint conversion credit. Gate all three: auth + capability +
    // webcsrf. `attribute` is a conversion-crediting hook (a system/admin action,
    // not a member action) so it takes admin.manage; direct sponsor assignment is
    // gated by the same maker-checker capability as the reassignment workflow it
    // must not bypass.
    $routes->post('links', '\WBS\Referrals\Controllers\ReferralController::createLink', ['filter' => ['auth', 'authorize:referral.link.create', 'webcsrf']]);
    $routes->post('sponsorships', '\WBS\Referrals\Controllers\ReferralController::assignSponsor', ['filter' => ['auth', 'authorize:sponsor.reassign.approve', 'webcsrf']]);
    $routes->post('attribute', '\WBS\Referrals\Controllers\ReferralController::attribute', ['filter' => ['auth', 'authorize:admin.manage', 'webcsrf']]);

    // Analytics + chain + manual review (auth-gated; PII-free aggregates).
    $routes->get('links/(:segment)/analytics', '\WBS\Referrals\Controllers\ReferralController::linkAnalytics/$1', ['filter' => 'auth']);
    $routes->get('referrers/(:segment)/analytics', '\WBS\Referrals\Controllers\ReferralController::referrerAnalytics/$1', ['filter' => 'auth']);
    $routes->get('chain/(:segment)', '\WBS\Referrals\Controllers\ReferralController::chain/$1', ['filter' => 'auth']);
    $routes->post('clicks/(:segment)/flag', '\WBS\Referrals\Controllers\ReferralController::flagClick/$1', ['filter' => 'auth']);
    $routes->post('clicks/(:segment)/clear', '\WBS\Referrals\Controllers\ReferralController::clearClick/$1', ['filter' => 'auth']);

    // Sponsor reassignment — maker-checker workflow (FR-MEM-002). Submit is the
    // maker step (authenticated); approve/reject are the checker step, gated by
    // sponsor.reassign.approve and blocked from self-approval by the PDP's SoD
    // combinator. History is never rewritten; only affected non-finalized
    // metrics are recalculated on approval.
    $routes->post('sponsor-reassignments', '\WBS\Referrals\Controllers\SponsorReassignmentController::submit', ['filter' => ['auth', 'webcsrf']]);
    $routes->get('sponsor-reassignments/pending', '\WBS\Referrals\Controllers\SponsorReassignmentController::pending', ['filter' => ['auth', 'authorize:sponsor.reassign.approve']]);
    $routes->get('sponsor-reassignments/(:segment)', '\WBS\Referrals\Controllers\SponsorReassignmentController::show/$1', ['filter' => 'auth']);
    $routes->post('sponsor-reassignments/(:segment)/approve', '\WBS\Referrals\Controllers\SponsorReassignmentController::approve/$1', ['filter' => ['auth', 'authorize:sponsor.reassign.approve', 'webcsrf']]);
    $routes->post('sponsor-reassignments/(:segment)/reject', '\WBS\Referrals\Controllers\SponsorReassignmentController::reject/$1', ['filter' => ['auth', 'authorize:sponsor.reassign.approve', 'webcsrf']]);
    $routes->post('sponsor-reassignments/(:segment)/cancel', '\WBS\Referrals\Controllers\SponsorReassignmentController::cancel/$1', ['filter' => ['auth', 'webcsrf']]);

    // Prospect inactivity transfer — maker-checker QUEUE (FR-REF-7 review path).
    // Fills only where a subtree sets `referrals.prospect_transfer.requires_review`;
    // elsewhere a due transfer applies on the spot and never comes here. It is the
    // same duty as a sponsor reassignment (a second leader confirming a
    // re-parenting), so it reuses the FROZEN sponsor.reassign.approve capability
    // instead of spending a new permission bit. Approval delegates to
    // ProspectTransferService::apply(), which re-checks the policy at decision
    // time — the queue changes WHO signs off, never WHAT a transfer does.
    $routes->post('prospect-transfers', '\WBS\Referrals\Controllers\ProspectTransferController::submit', ['filter' => ['auth', 'webcsrf']]);
    $routes->get('prospect-transfers/pending', '\WBS\Referrals\Controllers\ProspectTransferController::pending', ['filter' => ['auth', 'authorize:sponsor.reassign.approve']]);
    $routes->get('prospect-transfers/(:segment)', '\WBS\Referrals\Controllers\ProspectTransferController::show/$1', ['filter' => 'auth']);
    $routes->post('prospect-transfers/(:segment)/approve', '\WBS\Referrals\Controllers\ProspectTransferController::approve/$1', ['filter' => ['auth', 'authorize:sponsor.reassign.approve', 'webcsrf']]);
    $routes->post('prospect-transfers/(:segment)/reject', '\WBS\Referrals\Controllers\ProspectTransferController::reject/$1', ['filter' => ['auth', 'authorize:sponsor.reassign.approve', 'webcsrf']]);
    $routes->post('prospect-transfers/(:segment)/cancel', '\WBS\Referrals\Controllers\ProspectTransferController::cancel/$1', ['filter' => ['auth', 'webcsrf']]);
});
// Public cloaked link landing + click capture (rate-limited, no auth).
$routes->get('r/(:segment)', '\WBS\Referrals\Controllers\ReferralController::land/$1', ['filter' => 'ratelimit:referral.click']);
$routes->post('r/(:segment)/prospect', '\WBS\Referrals\Controllers\ReferralController::captureProspect/$1', ['filter' => ['ratelimit:referral.click', 'webcsrf']]);

// ---------------------------------------------------------------------------
// Events (core online flow) — "Build"
// ---------------------------------------------------------------------------
$routes->group('events', static function ($routes): void {
    $routes->get('', '\WBS\Events\Controllers\EventController::index');
    $routes->get('create', '\WBS\Events\Controllers\EventController::createForm', ['filter' => ['auth', 'authorize:event.create,any']]);
    $routes->post('', '\WBS\Events\Controllers\EventController::create', ['filter' => ['auth', 'webcsrf']]);
    // Collection-level action LANDING pages (menu launchers). Declared before
    // the '(:segment)' show route so the literal path wins over the wildcard.
    $routes->get('checkin', '\WBS\Events\Controllers\CheckinController::checkinForm', ['filter' => ['auth', 'authorize:attendance.check_in,any']]);
    $routes->post('checkin', '\WBS\Events\Controllers\CheckinController::checkinDispatch', ['filter' => ['auth', 'authorize:attendance.check_in,any', 'webcsrf']]);
    $routes->get('expenses', '\WBS\Events\Controllers\ExpenseController::approvalsForm', ['filter' => ['auth', 'authorize:event.expense.approve,any']]);
    $routes->post('expenses', '\WBS\Events\Controllers\ExpenseController::approvalsDispatch', ['filter' => ['auth', 'authorize:event.expense.approve,any', 'webcsrf']]);
    // Cross-event reporting + calendar + member hub (literal paths before the
    // '(:segment)' show route so they are not shadowed by the wildcard).
    $routes->get('analytics', '\WBS\Events\Controllers\EventController::analytics', ['filter' => ['auth', 'authorize:report.view,any']]);
    $routes->get('calendar', '\WBS\Events\Controllers\EventController::calendar');
    $routes->get('calendar.ics', '\WBS\Events\Controllers\EventController::calendarFeed');
    $routes->get('calendar/settings', '\WBS\Events\Controllers\EventController::calendarSettings', ['filter' => ['auth', 'authorize:event.create,any']]);
    $routes->post('calendar/settings', '\WBS\Events\Controllers\EventController::saveCalendarSettings', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->get('mine', '\WBS\Events\Controllers\EventController::mine', ['filter' => 'auth']);
    $routes->get('mine.ics', '\WBS\Events\Controllers\EventController::mineFeed');
    $routes->get('(:segment)', '\WBS\Events\Controllers\EventController::show/$1');
    // Pre-event curation: edit an existing event (form + submit). Organizer-gated
    // like create/publish; the segment makes it structurally non-page-like (it is
    // reached from the event page's "Edit" control, not primary navigation).
    $routes->get('(:segment)/edit', '\WBS\Events\Controllers\EventController::editForm/$1', ['filter' => ['auth', 'authorize:event.create,any']]);
    $routes->post('(:segment)/edit', '\WBS\Events\Controllers\EventController::update/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->get('(:segment)/attendance', '\WBS\Events\Controllers\EventController::expectedAttendance/$1');
    $routes->get('(:segment)/event.ics', '\WBS\Events\Controllers\EventController::eventFeed/$1');
    $routes->post('(:segment)/publish', '\WBS\Events\Controllers\EventController::publish/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->post('(:segment)/register', '\WBS\Events\Controllers\EventController::register/$1', ['filter' => ['auth', 'webcsrf', 'ratelimit:event.rsvp']]);
    // Attendee self-cancel RSVP (no-JS form on the event page). auth + webcsrf.
    $routes->post('(:segment)/register/cancel', '\WBS\Events\Controllers\EventController::cancelRegistration/$1', ['filter' => ['auth', 'webcsrf', 'ratelimit:event.rsvp']]);
    $routes->post('(:segment)/checkin/qr', '\WBS\Events\Controllers\CheckinController::qr/$1', ['filter' => 'ratelimit:event.checkin']);
    $routes->post('(:segment)/checkin/manual', '\WBS\Events\Controllers\CheckinController::manual/$1', ['filter' => 'ratelimit:event.checkin']);
    $routes->post('(:segment)/checkin/nonce', '\WBS\Events\Controllers\CheckinController::issueNonce/$1');
    $routes->post('(:segment)/complete', '\WBS\Events\Controllers\EventController::complete/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    // Guarded, auditable event cancel (gap G7): reason required, actor recorded.
    $routes->post('(:segment)/cancel', '\WBS\Events\Controllers\EventController::cancel/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    // Soft-archive / restore a SETTLED event (gap L4): drops out of active
    // index/calendar/feeds/analytics without deletion; fully reversible.
    $routes->post('(:segment)/archive', '\WBS\Events\Controllers\EventController::archive/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->post('(:segment)/unarchive', '\WBS\Events\Controllers\EventController::unarchive/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    // Invite-only allow-list console (gap G5): organizers issue/revoke direct
    // invitations AND manage the ONE shareable, cloaked per-event link (broadcast
    // on social media + enclosed in direct email/SMS invites).
    $routes->get('(:segment)/invitations', '\WBS\Events\Controllers\EventController::invitations/$1', ['filter' => ['auth', 'authorize:event.create,any']]);
    $routes->post('(:segment)/invitations', '\WBS\Events\Controllers\EventController::invite/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->post('(:segment)/invitations/link', '\WBS\Events\Controllers\EventController::generateInviteLink/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->post('(:segment)/invitations/link/toggle', '\WBS\Events\Controllers\EventController::toggleInviteLink/$1', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    $routes->post('(:segment)/invitations/(:segment)/revoke', '\WBS\Events\Controllers\EventController::revokeInvite/$1/$2', ['filter' => ['auth', 'authorize:event.create,any', 'webcsrf']]);
    // Guest sign-up via the shareable invite link (gap G5): a NOT-signed-in
    // visitor submits contact details and is captured as the sponsor's prospect.
    // The link token in the body is the bearer credential; rate-limited like RSVP.
    $routes->post('(:segment)/register-guest', '\WBS\Events\Controllers\EventController::registerGuest/$1', ['filter' => ['webcsrf', 'ratelimit:event.rsvp']]);

    // ---- D9-B: paid ticketing — public/self-service (FR-EVT-016) ----------
    // Consistent with `register` above: buyer identity comes from the body/session
    // and the endpoints are rate-limited; capacity is protected by the atomic hold.
    $routes->get('(:segment)/ticket-types', '\WBS\Events\Controllers\TicketingController::listTicketTypes/$1');
    // Attendee-facing browser purchase page + one-step (hold+checkout) buy form.
    // Consistent with `register`: auth + webcsrf for the browser; API callers are
    // exempted by the webcsrf filter's Bearer/session check. Rate-limited; capacity
    // stays protected by the atomic hold. The raw hold/checkout JSON endpoints below
    // stay unchanged for API two-step flows.
    $routes->get('(:segment)/tickets', '\WBS\Events\Controllers\TicketingController::tickets/$1');
    $routes->post('(:segment)/tickets', '\WBS\Events\Controllers\TicketingController::purchase/$1', ['filter' => ['auth', 'webcsrf', 'ratelimit:event.checkout']]);
    $routes->post('(:segment)/ticket-hold', '\WBS\Events\Controllers\TicketingController::hold/$1', ['filter' => 'ratelimit:event.rsvp']);
    $routes->post('(:segment)/checkout', '\WBS\Events\Controllers\TicketingController::checkout/$1', ['filter' => 'ratelimit:event.checkout']);

    // ---- Post-event: attendee-facing reads (FR-EVT-012/015) ---------------
    $routes->get('(:segment)/media', '\WBS\Events\Controllers\MediaController::publicMedia/$1');
});

// Post-event public verification (FR-EVT-013): no auth — the opaque verification
// id in the QR is the credential; returns only non-identifying status.
$routes->get('certificates/verify/(:segment)', '\WBS\Events\Controllers\CertificateController::verify/$1');

// Feedback/quiz submission (FR-EVT-012): respondent may be a member (session)
// or a guest; rate-limited like RSVP. Reads/writes go through the form id.
$routes->get('feedback-forms/(:segment)/respond', '\WBS\Events\Controllers\FeedbackController::respond/$1');
$routes->post('feedback-forms/(:segment)/responses', '\WBS\Events\Controllers\FeedbackController::submit/$1', ['filter' => ['webcsrf', 'ratelimit:event.rsvp']]);

// D9-B event-scoped MANAGEMENT routes — authenticated + permission-gated.
// A second `events` group so `authorize:` always runs after `auth`.
$routes->group('events', ['filter' => 'auth'], static function ($routes): void {
    // Ticketing config (FR-EVT-016)
    $routes->get('(:segment)/ticketing', '\WBS\Events\Controllers\TicketingController::console/$1', ['filter' => 'authorize:event.tickets.manage']);
    $routes->post('(:segment)/ticket-types', '\WBS\Events\Controllers\TicketingController::createTicketType/$1', ['filter' => ['authorize:event.tickets.manage', 'webcsrf']]);
    $routes->post('(:segment)/promo-codes', '\WBS\Events\Controllers\TicketingController::createPromoCode/$1', ['filter' => ['authorize:event.tickets.manage', 'webcsrf']]);

    // Check-in kiosks (FR-EVT-017)
    $routes->get('(:segment)/kiosks', '\WBS\Events\Controllers\KioskController::console/$1', ['filter' => 'authorize:attendance.check_in']);
    $routes->post('(:segment)/kiosks', '\WBS\Events\Controllers\KioskController::register/$1', ['filter' => ['authorize:attendance.check_in', 'webcsrf']]);

    // Logistics (FR-EVT-018)
    $routes->get('(:segment)/logistics', '\WBS\Events\Controllers\LogisticsController::planConsole/$1', ['filter' => 'authorize:event.logistics.manage']);
    $routes->post('(:segment)/logistics/plan', '\WBS\Events\Controllers\LogisticsController::ensurePlan/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/resources', '\WBS\Events\Controllers\LogisticsController::addResource/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/refresh-projection', '\WBS\Events\Controllers\LogisticsController::refreshProjection/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/seating', '\WBS\Events\Controllers\LogisticsController::addSeatingArea/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/staff', '\WBS\Events\Controllers\LogisticsController::assignStaff/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/suppliers', '\WBS\Events\Controllers\LogisticsController::addSupplier/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->post('(:segment)/logistics/needs', '\WBS\Events\Controllers\LogisticsController::recordNeed/$1', ['filter' => ['authorize:event.logistics.manage', 'webcsrf']]);
    $routes->get('(:segment)/logistics/needs', '\WBS\Events\Controllers\LogisticsController::needs/$1', ['filter' => 'authorize:event.logistics.manage']);
    $routes->get('(:segment)/logistics/catering', '\WBS\Events\Controllers\LogisticsController::cateringAggregates/$1', ['filter' => 'authorize:event.logistics.manage']);

    // Expenses (FR-EVT-019)
    $routes->post('(:segment)/budget', '\WBS\Events\Controllers\ExpenseController::setBudget/$1', ['filter' => 'authorize:event.expense.approve']);
    $routes->post('(:segment)/expenses', '\WBS\Events\Controllers\ExpenseController::submit/$1', ['filter' => 'authorize:event.expense.submit']);
    $routes->get('(:segment)/expenses', '\WBS\Events\Controllers\ExpenseController::list/$1', ['filter' => 'authorize:event.expense.submit']);
    $routes->get('(:segment)/expenses/reconcile', '\WBS\Events\Controllers\ExpenseController::reconcile/$1', ['filter' => 'authorize:event.expense.approve']);

    // Feedback & quizzes (FR-EVT-012)
    $routes->get('(:segment)/feedback-forms', '\WBS\Events\Controllers\FeedbackController::console/$1', ['filter' => 'authorize:event.feedback.manage']);
    $routes->post('(:segment)/feedback-forms', '\WBS\Events\Controllers\FeedbackController::createForm/$1', ['filter' => ['authorize:event.feedback.manage', 'webcsrf']]);
    $routes->get('(:segment)/feedback-forms/(:segment)/aggregate', '\WBS\Events\Controllers\FeedbackController::aggregate/$2', ['filter' => 'authorize:event.feedback.manage']);

    // Certificates (FR-EVT-013)
    $routes->get('(:segment)/certificates', '\WBS\Events\Controllers\CertificateController::eventConsole/$1', ['filter' => 'authorize:event.certificate.manage,any']);
    $routes->post('(:segment)/certificates/request', '\WBS\Events\Controllers\CertificateController::requestForEvent/$1', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);

    // Media review listing (FR-EVT-015)
    $routes->post('(:segment)/media', '\WBS\Events\Controllers\MediaController::register/$1', ['filter' => ['authorize:event.media.manage', 'webcsrf']]);
    $routes->get('(:segment)/media/review', '\WBS\Events\Controllers\MediaController::listForReview/$1', ['filter' => 'authorize:event.media.manage']);

    // Mobilization report (FR-EVT-014)
    $routes->get('(:segment)/report', '\WBS\Events\Controllers\ReportController::build/$1', ['filter' => 'authorize:report.view']);
    $routes->post('(:segment)/report/snapshot', '\WBS\Events\Controllers\ReportController::snapshot/$1', ['filter' => 'authorize:report.view']);
    // Post-event attendance reconciliation (gap L6): matched vs walk-in
    // attendance, no-shows, paid-but-absent. Aggregate-only, read-only.
    $routes->get('(:segment)/attendance/reconcile', '\WBS\Events\Controllers\CheckinController::reconcile/$1', ['filter' => ['auth', 'authorize:attendance.check_in,any']]);

    // ── Event committees: OPTIONAL pre-event project management ──────────────
    // Gated by the hierarchical group capability `event_committee` (DEFAULT OFF),
    // so a body that has not opted in never sees any of this and its events behave
    // exactly as before.
    //
    // The route gate here is `auth` (+ `webcsrf` on writes) and the AUTHORITATIVE
    // check is per-event inside the services, because no single frozen capability
    // bit can express "this event's committee": forming one requires `event.create`
    // over the event's group, working its plan requires `event.logistics.manage`
    // there OR an active committee membership, and deciding its requests requires
    // the very capability the decision names (`event.expense.approve` for money,
    // `event.schedule.approve` for dates). Each question is asked of the platform's
    // PDP — so delegations count, MAC/SoD/RuBAC apply, and being merely scoped to a
    // group is never mistaken for authority.
    $routes->get('(:segment)/committee', '\WBS\Events\Controllers\CommitteeController::console/$1');
    $routes->post('(:segment)/committee', '\WBS\Events\Controllers\CommitteeController::form/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/committee/dissolve', '\WBS\Events\Controllers\CommitteeController::dissolve/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/committee/members', '\WBS\Events\Controllers\CommitteeController::addMember/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/committee/chair', '\WBS\Events\Controllers\CommitteeController::appointChair/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/committee/decisions', '\WBS\Events\Controllers\CommitteeController::requestDecision/$1', ['filter' => 'webcsrf']);

    // The plan (workstreams → tasks → dependencies, milestones, progress).
    $routes->get('(:segment)/plan', '\WBS\Events\Controllers\EventPlanController::console/$1');
    $routes->post('(:segment)/plan/workstreams', '\WBS\Events\Controllers\EventPlanController::addWorkstream/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/plan/tasks', '\WBS\Events\Controllers\EventPlanController::addTask/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/plan/milestones', '\WBS\Events\Controllers\EventPlanController::addMilestone/$1', ['filter' => 'webcsrf']);
});

// Committee child resources: memberships, the oversight queue, and plan rows. Same
// reasoning as above — `auth` + `webcsrf` at the route, the authoritative decision
// in the service (which knows the event's group and the actor's membership).
$routes->group('event-committees', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Events\Controllers\CommitteeController::hub');
    $routes->get('decisions', '\WBS\Events\Controllers\CommitteeController::queue');
    $routes->post('member/(:segment)/remove', '\WBS\Events\Controllers\CommitteeController::removeMember/$1', ['filter' => 'webcsrf']);
    $routes->post('member/(:segment)/responsibility', '\WBS\Events\Controllers\CommitteeController::updateMember/$1', ['filter' => 'webcsrf']);
    $routes->post('decisions/(:segment)/approve', '\WBS\Events\Controllers\CommitteeController::approveDecision/$1', ['filter' => 'webcsrf']);
    $routes->post('decisions/(:segment)/reject', '\WBS\Events\Controllers\CommitteeController::rejectDecision/$1', ['filter' => 'webcsrf']);
    $routes->post('decisions/(:segment)/cancel', '\WBS\Events\Controllers\CommitteeController::cancelDecision/$1', ['filter' => 'webcsrf']);
});
$routes->group('event-plan', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('workstreams/(:segment)', '\WBS\Events\Controllers\EventPlanController::updateWorkstream/$1', ['filter' => 'webcsrf']);
    $routes->post('workstreams/(:segment)/delete', '\WBS\Events\Controllers\EventPlanController::deleteWorkstream/$1', ['filter' => 'webcsrf']);
    $routes->post('tasks/(:segment)', '\WBS\Events\Controllers\EventPlanController::updateTask/$1', ['filter' => 'webcsrf']);
    $routes->post('tasks/(:segment)/action', '\WBS\Events\Controllers\EventPlanController::taskAction/$1', ['filter' => 'webcsrf']);
    $routes->post('tasks/(:segment)/delete', '\WBS\Events\Controllers\EventPlanController::deleteTask/$1', ['filter' => 'webcsrf']);
    $routes->post('tasks/(:segment)/dependencies', '\WBS\Events\Controllers\EventPlanController::addDependency/$1', ['filter' => 'webcsrf']);
    $routes->post('tasks/(:segment)/dependencies/remove', '\WBS\Events\Controllers\EventPlanController::removeDependency/$1', ['filter' => 'webcsrf']);
    $routes->post('milestones/(:segment)/action', '\WBS\Events\Controllers\EventPlanController::milestoneAction/$1', ['filter' => 'webcsrf']);
    $routes->post('milestones/(:segment)/delete', '\WBS\Events\Controllers\EventPlanController::deleteMilestone/$1', ['filter' => 'webcsrf']);
});

// Post-event admin: feedback forms, certificates, media, group report roll-up.
$routes->group('feedback-forms', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('(:segment)/questions', '\WBS\Events\Controllers\FeedbackController::questionsConsole/$1', ['filter' => 'authorize:event.feedback.manage']);
    $routes->post('(:segment)/questions', '\WBS\Events\Controllers\FeedbackController::addQuestion/$1', ['filter' => ['authorize:event.feedback.manage', 'webcsrf']]);
    $routes->post('(:segment)/open', '\WBS\Events\Controllers\FeedbackController::openForm/$1', ['filter' => ['authorize:event.feedback.manage', 'webcsrf']]);
    $routes->post('(:segment)/close', '\WBS\Events\Controllers\FeedbackController::closeForm/$1', ['filter' => ['authorize:event.feedback.manage', 'webcsrf']]);
});
$routes->group('feedback-responses', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/review', '\WBS\Events\Controllers\FeedbackController::reviewScore/$1', ['filter' => 'authorize:event.feedback.manage']);
});
$routes->group('certificates', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('templates', '\WBS\Events\Controllers\CertificateController::templatesConsole', ['filter' => 'authorize:event.certificate.manage,any']);
    $routes->post('templates', '\WBS\Events\Controllers\CertificateController::createTemplate', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);
    $routes->get('templates/(:segment)/edit', '\WBS\Events\Controllers\CertificateController::editTemplate/$1', ['filter' => 'authorize:event.certificate.manage,any']);
    $routes->get('templates/(:segment)/preview', '\WBS\Events\Controllers\CertificateController::previewTemplate/$1', ['filter' => 'authorize:event.certificate.manage,any']);
    $routes->get('templates/(:segment)', '\WBS\Events\Controllers\CertificateController::editTemplate/$1', ['filter' => 'authorize:event.certificate.manage,any']);
    $routes->post('templates/(:segment)/retire', '\WBS\Events\Controllers\CertificateController::retireTemplate/$1', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);
    $routes->post('templates/(:segment)', '\WBS\Events\Controllers\CertificateController::reviseTemplate/$1', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);
    $routes->post('(:segment)/issue', '\WBS\Events\Controllers\CertificateController::issue/$1', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);
    $routes->post('(:segment)/revoke', '\WBS\Events\Controllers\CertificateController::revoke/$1', ['filter' => ['authorize:event.certificate.manage,any', 'webcsrf']]);
    // Self-service: a member downloads their OWN issued certificate PDF
    // (ownership enforced in the service; non-owners get 404).
    $routes->get('(:segment)/download', '\WBS\Events\Controllers\CertificateController::download/$1');
});
$routes->group('media', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/review', '\WBS\Events\Controllers\MediaController::review/$1', ['filter' => ['authorize:event.media.manage', 'webcsrf']]);
});
$routes->group('event-reports', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('groups/(:segment)/rollup', '\WBS\Events\Controllers\ReportController::groupRollup/$1', ['filter' => 'authorize:report.view']);
});

// D9-B ticket order lifecycle (FR-EVT-016), event-independent segments.
$routes->group('orders', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/pay', '\WBS\Events\Controllers\TicketingController::markPaid/$1', ['filter' => 'authorize:event.tickets.manage']);
    $routes->post('items/(:segment)/transfer', '\WBS\Events\Controllers\TicketingController::transfer/$1');
    // Refund REQUEST (maker) — organizer scope (gap L2).
    $routes->post('(:segment)/refunds', '\WBS\Events\Controllers\TicketingController::requestRefund/$1', ['filter' => ['authorize:event.tickets.manage', 'webcsrf']]);
});

// D9-B event ticket-order refunds — maker-checker money reversal (gap L2).
// Mirrors the VBCS refund routes: request is organizer-scoped (above); approval
// and execution reuse the platform's money-reversal approver (finance).
$routes->group('event-refunds', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('pending', '\WBS\Events\Controllers\TicketingController::pendingRefunds', ['filter' => 'authorize:contribution.refund.approve']);
    $routes->post('(:segment)/approve', '\WBS\Events\Controllers\TicketingController::approveRefund/$1', ['filter' => ['authorize:contribution.refund.approve', 'webcsrf']]);
    $routes->post('(:segment)/reject', '\WBS\Events\Controllers\TicketingController::rejectRefund/$1', ['filter' => ['authorize:contribution.refund.approve', 'webcsrf']]);
    $routes->post('(:segment)/execute', '\WBS\Events\Controllers\TicketingController::executeRefund/$1', ['filter' => ['authorize:contribution.refund.approve', 'webcsrf']]);
});

// D9-B kiosk operations (FR-EVT-017).
$routes->group('kiosks', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/revoke', '\WBS\Events\Controllers\KioskController::revoke/$1', ['filter' => ['authorize:attendance.check_in', 'webcsrf']]);
    $routes->post('(:segment)/manifest', '\WBS\Events\Controllers\KioskController::manifest/$1', ['filter' => ['authorize:attendance.check_in', 'webcsrf']]);
    // Device API: the kiosk app posts queued offline scans (JSON, no browser CSRF).
    $routes->post('(:segment)/offline-scans', '\WBS\Events\Controllers\KioskController::enqueueScans/$1', ['filter' => 'authorize:attendance.check_in']);
    $routes->post('(:segment)/reconcile', '\WBS\Events\Controllers\KioskController::reconcile/$1', ['filter' => ['authorize:attendance.check_in', 'webcsrf']]);
});

// D9-B expense maker-checker actions (FR-EVT-019).
$routes->group('expenses', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/approve', '\WBS\Events\Controllers\ExpenseController::approve/$1', ['filter' => 'authorize:event.expense.approve']);
    $routes->post('(:segment)/reject', '\WBS\Events\Controllers\ExpenseController::reject/$1', ['filter' => 'authorize:event.expense.approve']);
    $routes->post('(:segment)/reimburse', '\WBS\Events\Controllers\ExpenseController::reimburse/$1', ['filter' => 'authorize:event.expense.approve']);
});

// ---------------------------------------------------------------------------
// Contributions / VBCS — "Build"
// ---------------------------------------------------------------------------
$routes->group('causes', static function ($routes): void {
    // Public giving path — a donor posts a contribution intent (rate-limited,
    // no auth): kept exactly as before so the checkout flow is unaffected.
    $routes->post('(:segment)/contribute', '\WBS\Contributions\Controllers\ContributionController::createIntent/$1', ['filter' => 'ratelimit:contribution.checkout']);

    // Admin cause CRUD — browser directory + PRG forms and JSON share the same
    // actions. Reads require auth; writes are gated `contribution.manage` and,
    // for browsers, webcsrf. Form pages (`new`/`edit`) are declared BEFORE the
    // `(:segment)` catch-all so they match as literal pages, not cause ids.
    $routes->get('', '\WBS\Contributions\Controllers\CauseController::index', ['filter' => 'auth']);
    $routes->get('new', '\WBS\Contributions\Controllers\CauseController::createForm', ['filter' => ['auth', 'authorize:contribution.manage']]);
    $routes->get('(:segment)/edit', '\WBS\Contributions\Controllers\CauseController::editForm/$1', ['filter' => ['auth', 'authorize:contribution.manage']]);
    $routes->get('(:segment)', '\WBS\Contributions\Controllers\CauseController::show/$1', ['filter' => 'auth']);
    $routes->post('', '\WBS\Contributions\Controllers\CauseController::create', ['filter' => ['auth', 'authorize:contribution.manage', 'webcsrf']]);
    $routes->post('(:segment)/status', '\WBS\Contributions\Controllers\CauseController::setStatus/$1', ['filter' => ['auth', 'authorize:contribution.manage', 'webcsrf']]);
    $routes->post('(:segment)/delete', '\WBS\Contributions\Controllers\CauseController::delete/$1', ['filter' => ['auth', 'authorize:contribution.manage', 'webcsrf']]);
    $routes->post('(:segment)', '\WBS\Contributions\Controllers\CauseController::update/$1', ['filter' => ['auth', 'authorize:contribution.manage', 'webcsrf']]);
});
$routes->group('contributions', ['filter' => 'auth'], static function ($routes): void {
    // Refund maker-checker console (queue of open requests).
    $routes->get('refunds/pending', '\WBS\Contributions\Controllers\RefundController::pending', ['filter' => 'authorize:contribution.refund.approve']);
    $routes->post('(:segment)/refunds', '\WBS\Contributions\Controllers\RefundController::request/$1', ['filter' => 'authorize:contribution.refund.request']);
    $routes->post('refunds/(:segment)/approve', '\WBS\Contributions\Controllers\RefundController::approve/$1', ['filter' => ['authorize:contribution.refund.approve', 'webcsrf']]);
    $routes->post('refunds/(:segment)/execute', '\WBS\Contributions\Controllers\RefundController::execute/$1', ['filter' => ['authorize:contribution.refund.approve', 'webcsrf']]);
});
// Signed provider webhooks — dedicated quota + inbox path (FR-VBCS-006).
$routes->post('webhooks/payments/(:segment)', '\WBS\Contributions\Controllers\WebhookController::receive/$1', ['filter' => 'ratelimit:webhook.payment']);

// VBCS metrics / partnership / commitments / manual givings / reporting.
$routes->group('vbcs', ['filter' => 'auth'], static function ($routes): void {
    // Member-facing reads.
    $routes->get('subjects/(:segment)/metrics', '\WBS\Contributions\Controllers\VbcsController::metrics/$1');
    $routes->get('subjects/(:segment)/partnership', '\WBS\Contributions\Controllers\VbcsController::partnershipStatus/$1');
    $routes->get('subjects/(:segment)/commitments', '\WBS\Contributions\Controllers\VbcsController::listCommitments/$1');
    $routes->get('partnership/tiers', '\WBS\Contributions\Controllers\VbcsController::partnershipTiers');
    $routes->get('causes/(:segment)/progress', '\WBS\Contributions\Controllers\VbcsController::causeProgress/$1');
    $routes->get('causes/(:segment)/donors', '\WBS\Contributions\Controllers\VbcsController::causeDonors/$1');

    // Member-owned commitments (pledges). Browser callers get a bespoke capture
    // form + PRG; API callers (Accept: application/json) get JSON. Writes are
    // webcsrf-guarded for the browser (Bearer/X-WBS-Session clients are exempt).
    $routes->get('commitments/new', '\WBS\Contributions\Controllers\VbcsController::createCommitmentForm');
    $routes->post('commitments', '\WBS\Contributions\Controllers\VbcsController::createCommitment', ['filter' => 'webcsrf']);
    $routes->post('commitments/(:segment)/cancel', '\WBS\Contributions\Controllers\VbcsController::cancelCommitment/$1', ['filter' => 'webcsrf']);

    // Leader reporting.
    $routes->get('groups/(:segment)/report', '\WBS\Contributions\Controllers\VbcsController::groupReport/$1', ['filter' => 'authorize:report.view']);

    // Admin: partnership tier configuration. The manage list's "New" and per-row
    // "Edit" forms both POST to /partnership/tiers (upsert); disable = status flip.
    $routes->get('partnership/tiers/manage', '\WBS\Contributions\Controllers\VbcsController::managePartnershipTiers', ['filter' => 'authorize:contribution.manage']);
    $routes->post('partnership/tiers', '\WBS\Contributions\Controllers\VbcsController::definePartnershipTier', ['filter' => ['authorize:contribution.manage', 'webcsrf']]);
    $routes->post('partnership/tiers/(:segment)/disable', '\WBS\Contributions\Controllers\VbcsController::disablePartnershipTier/$1', ['filter' => ['authorize:contribution.manage', 'webcsrf']]);

    // Admin: manual / in-kind givings (maker-checker; approver != submitter).
    $routes->get('manual/new', '\WBS\Contributions\Controllers\VbcsController::submitManualForm', ['filter' => 'authorize:contribution.manage']);
    $routes->post('manual', '\WBS\Contributions\Controllers\VbcsController::submitManual', ['filter' => ['authorize:contribution.manage', 'webcsrf']]);
    $routes->get('manual/pending', '\WBS\Contributions\Controllers\VbcsController::pendingManual', ['filter' => 'authorize:contribution.manage']);
    $routes->post('manual/(:segment)/approve', '\WBS\Contributions\Controllers\VbcsController::approveManual/$1', ['filter' => ['authorize:contribution.manage', 'webcsrf']]);
    $routes->post('manual/(:segment)/reject', '\WBS\Contributions\Controllers\VbcsController::rejectManual/$1', ['filter' => ['authorize:contribution.manage', 'webcsrf']]);

    // Admin: force a metrics/partnership recompute.
    $routes->post('subjects/(:segment)/refresh', '\WBS\Contributions\Controllers\VbcsController::refreshMetrics/$1', ['filter' => 'authorize:contribution.manage']);
});

// ---------------------------------------------------------------------------
// Courses / Learning — "Build"
// ---------------------------------------------------------------------------
$routes->group('courses', static function ($routes): void {
    $routes->get('', '\WBS\Courses\Controllers\CourseController::index');
    $routes->get('create', '\WBS\Courses\Controllers\CourseController::createForm', ['filter' => ['auth', 'authorize:course.create,any']]);
    $routes->post('', '\WBS\Courses\Controllers\CourseController::create', ['filter' => ['auth', 'webcsrf']]);
    $routes->get('(:segment)', '\WBS\Courses\Controllers\CourseController::show/$1');
    // Authoring writes (publish course / append lesson) — previously UNGATED and
    // would fall through to the generic data page on a browser POST. Now auth +
    // authorize:course.create (,any) + webcsrf; controls live on the course page.
    $routes->post('(:segment)/publish', '\WBS\Courses\Controllers\CourseController::publish/$1', ['filter' => ['auth', 'authorize:course.create,any', 'webcsrf']]);
    $routes->post('(:segment)/lessons', '\WBS\Courses\Controllers\CourseController::addLesson/$1', ['filter' => ['auth', 'authorize:course.create,any', 'webcsrf']]);
    $routes->post('(:segment)/enrol', '\WBS\Courses\Controllers\EnrollmentController::enrol/$1', ['filter' => ['auth', 'webcsrf', 'ratelimit:course.enroll']]);
    $routes->get('(:segment)/syllabus', '\WBS\Courses\Controllers\EnrollmentController::syllabus/$1');
});
$routes->post('enrollments/(:segment)/lessons/(:segment)/complete', '\WBS\Courses\Controllers\EnrollmentController::completeLesson/$1/$2', ['filter' => ['auth', 'webcsrf']]);

// ---------------------------------------------------------------------------
// Notifications — preference centre + campaigns
// ---------------------------------------------------------------------------
$routes->group('notifications', static function ($routes): void {
    // N5: `send` was ONLY rate-limited — unauthenticated, trusting the caller's
    // user_id + arbitrary body (a phishing / notification-trigger primitive).
    // Gate it: auth + notification.send capability + webcsrf, keeping the
    // broadcast rate-limit.
    $routes->post('send', '\WBS\Notifications\Controllers\NotificationController::send', ['filter' => ['auth', 'authorize:notification.send', 'webcsrf', 'ratelimit:notification.broadcast']]);
    $routes->get('campaigns', '\WBS\Notifications\Controllers\CampaignController::index', ['filter' => 'auth']);
    $routes->post('campaigns', '\WBS\Notifications\Controllers\CampaignController::create', ['filter' => ['auth', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/submit', '\WBS\Notifications\Controllers\CampaignController::submit/$1', ['filter' => ['auth', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/approve', '\WBS\Notifications\Controllers\CampaignController::approve/$1', ['filter' => ['auth', 'webcsrf']]);

    // Per-group notification credentials (FR-INT-007): each hierarchical body
    // supplies its OWN provider account and decides how far down its subtree that
    // account is shared. Reads and writes are bounded to the actor's leadership
    // scope in the controller (PDP `provider.configure`), a grant is additionally
    // bounded by ConnectionService to the account OWNER's subtree, and secrets are
    // write-only in the vault — never echoed back.
    $routes->get('templates', '\WBS\Notifications\Controllers\TemplateController::index', ['filter' => ['auth', 'authorize:provider.configure,any']]);
    $routes->post('templates', '\WBS\Notifications\Controllers\TemplateController::create', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);
    $routes->get('templates/(:segment)/edit', '\WBS\Notifications\Controllers\TemplateController::edit/$1', ['filter' => ['auth', 'authorize:provider.configure,any']]);
    $routes->post('templates/(:segment)/retire', '\WBS\Notifications\Controllers\TemplateController::retire/$1', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);
    $routes->post('templates/(:segment)', '\WBS\Notifications\Controllers\TemplateController::revise/$1', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);

    $routes->get('credentials', '\WBS\Notifications\Controllers\GroupCredentialController::index', ['filter' => ['auth', 'authorize:provider.configure,any']]);
    $routes->post('credentials/connections', '\WBS\Notifications\Controllers\GroupCredentialController::create', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);
    $routes->post('credentials/connections/(:segment)/secrets', '\WBS\Notifications\Controllers\GroupCredentialController::setSecret/$1', ['filter' => ['auth', 'ratelimit:provider.configure', 'webcsrf']]);
    $routes->post('credentials/connections/(:segment)/grants', '\WBS\Notifications\Controllers\GroupCredentialController::grant/$1', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);
    $routes->post('credentials/grants/(:segment)/revoke', '\WBS\Notifications\Controllers\GroupCredentialController::revokeGrant/$1', ['filter' => ['auth', 'authorize:provider.configure,any', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Announcements — first-class targeted bulletins (extended campaigns: same
// send/approve SoD, optional NotificationService fan-out, must-ack inbox).
// Inbox is auth-only; compose/submit/cancel require notification.send; publish
// requires notification.broadcast.approve. Leadership scope is re-checked in
// the controller (canManageGroupScope).
// ---------------------------------------------------------------------------
$routes->group('announcements', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Announcements\Controllers\AnnouncementController::index', ['filter' => 'authorize:notification.send,any']);
    $routes->post('', '\WBS\Announcements\Controllers\AnnouncementController::create', ['filter' => ['authorize:notification.send,any', 'webcsrf']]);
    $routes->get('inbox', '\WBS\Announcements\Controllers\AnnouncementController::inbox');
    $routes->post('(:segment)/ack', '\WBS\Announcements\Controllers\AnnouncementController::ack/$1', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/submit', '\WBS\Announcements\Controllers\AnnouncementController::submit/$1', ['filter' => ['authorize:notification.send,any', 'webcsrf']]);
    $routes->post('(:segment)/approve', '\WBS\Announcements\Controllers\AnnouncementController::approve/$1', ['filter' => ['authorize:notification.broadcast.approve,any', 'webcsrf']]);
    $routes->post('(:segment)/cancel', '\WBS\Announcements\Controllers\AnnouncementController::cancel/$1', ['filter' => ['authorize:notification.send,any', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Integrations / UPAF — admin
// ---------------------------------------------------------------------------
$routes->group('integrations', ['filter' => 'auth'], static function ($routes): void {
    // Provider catalogue is a configuration/admin surface: gate the read so the
    // "Integrations" menu item (Administration) is a projection of a real right
    // rather than leaking the category to every member.
    $routes->get('catalog', '\WBS\Integrations\Controllers\CatalogController::index', ['filter' => 'authorize:provider.configure']);
    $routes->get('connections', '\WBS\Integrations\Controllers\ConnectionController::index', ['filter' => 'authorize:provider.configure']);
    $routes->post('connections', '\WBS\Integrations\Controllers\ConnectionController::create', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('connections/(:segment)/credentials', '\WBS\Integrations\Controllers\ConnectionController::setCredential/$1', ['filter' => ['ratelimit:provider.configure', 'webcsrf']]);
    $routes->post('connections/(:segment)/test', '\WBS\Integrations\Controllers\ConnectionController::test/$1', ['filter' => 'webcsrf']);
    $routes->post('connections/(:segment)/submit', '\WBS\Integrations\Controllers\ConnectionController::submit/$1', ['filter' => 'webcsrf']);
    $routes->post('connections/(:segment)/activate', '\WBS\Integrations\Controllers\ConnectionController::activate/$1', ['filter' => ['ratelimit:provider.configure', 'webcsrf']]);
    // No-code connector-profile authoring (FR-INT-002/004). GET renders the browser
    // console; create/advance/revoke are webcsrf-guarded browser writes (API callers
    // are header-exempt). All gated by provider.configure.
    $routes->get('profiles', '\WBS\Integrations\Controllers\ProfileController::index', ['filter' => 'authorize:provider.configure']);
    $routes->post('profiles', '\WBS\Integrations\Controllers\ProfileController::create', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('profiles/(:segment)/advance', '\WBS\Integrations\Controllers\ProfileController::advance/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('profiles/(:segment)/revoke', '\WBS\Integrations\Controllers\ProfileController::revoke/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);

    // Custom-adapter SDK (SRS FR-INT-013): onboard a NON-conforming provider as a
    // versioned, signed, reviewed module. register -> contract-test (signs on
    // pass) -> security_review -> approve (checker != submitter) -> activate
    // (publishes to the shared catalogue). All writes gated by provider.configure.
    $routes->get('custom-adapters', '\WBS\Integrations\Controllers\CustomAdapterController::index');
    $routes->get('custom-adapters/allowlist', '\WBS\Integrations\Controllers\CustomAdapterController::allowlist');
    $routes->get('custom-adapters/(:segment)', '\WBS\Integrations\Controllers\CustomAdapterController::show/$1');
    $routes->post('custom-adapters', '\WBS\Integrations\Controllers\CustomAdapterController::register', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('custom-adapters/(:segment)/contract-test', '\WBS\Integrations\Controllers\CustomAdapterController::contractTest/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('custom-adapters/(:segment)/advance', '\WBS\Integrations\Controllers\CustomAdapterController::advance/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('custom-adapters/(:segment)/approve', '\WBS\Integrations\Controllers\CustomAdapterController::approve/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('custom-adapters/(:segment)/activate', '\WBS\Integrations\Controllers\CustomAdapterController::activate/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->post('custom-adapters/(:segment)/revoke', '\WBS\Integrations\Controllers\CustomAdapterController::revoke/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);

    // Provider reliability (SRS FR-INT-012): documented per-feature fallback
    // matrix (read-only, generated from honest declared capabilities) + circuit-
    // breaker health. A manual circuit reset is a configuration action.
    $routes->get('fallback-matrix', '\WBS\Integrations\Controllers\ReliabilityController::matrix');
    $routes->get('adapters/(:segment)/fallback', '\WBS\Integrations\Controllers\ReliabilityController::adapter/$1');
    $routes->get('circuits', '\WBS\Integrations\Controllers\ReliabilityController::circuits');
    $routes->post('circuits/(:segment)/reset', '\WBS\Integrations\Controllers\ReliabilityController::resetCircuit/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);

    // Streaming-provider OAuth consent flow (S7). The consent dashboard (GET) lists
    // oauth-capable connections; authorize starts the provider redirect (webcsrf-
    // guarded, browsers are redirected to the consent screen); callback exchanges
    // the code and stores the refresh token write-only in the vault.
    $routes->get('oauth', '\WBS\Integrations\Controllers\StreamingOAuthController::index', ['filter' => 'authorize:provider.configure']);
    $routes->post('oauth/(:segment)/authorize', '\WBS\Integrations\Controllers\StreamingOAuthController::authorize/$1', ['filter' => ['authorize:provider.configure', 'webcsrf']]);
    $routes->get('oauth/(:segment)/callback', '\WBS\Integrations\Controllers\StreamingOAuthController::callback/$1');
});

// ---------------------------------------------------------------------------
// Community — feeds, posts, moderation (SRS FR-COM-*)
// ---------------------------------------------------------------------------
$routes->group('community', static function ($routes): void {
    // Public feed read is visibility-filtered in the service (unauth => public only).
    $routes->get('feed', '\WBS\Community\Controllers\FeedController::index');

    $routes->group('', ['filter' => 'auth'], static function ($routes): void {
        $routes->post('posts', '\WBS\Community\Controllers\FeedController::create', ['filter' => 'webcsrf']);
        $routes->post('posts/(:segment)/comments', '\WBS\Community\Controllers\FeedController::comment/$1', ['filter' => 'webcsrf']);
        $routes->post('posts/(:segment)/reactions', '\WBS\Community\Controllers\FeedController::react/$1', ['filter' => 'webcsrf']);
        $routes->post('reports', '\WBS\Community\Controllers\ModerationController::report', ['filter' => 'webcsrf']);
        $routes->post('moderate', '\WBS\Community\Controllers\ModerationController::act', ['filter' => ['authorize:community.moderate', 'webcsrf']]);
    });
});

// ---------------------------------------------------------------------------
// Streaming — orchestration + engagement (SRS FR-STR-*)
// ---------------------------------------------------------------------------
$routes->group('streams', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Streaming\Controllers\StreamController::index', ['filter' => 'authorize:stream.moderate']);
    $routes->post('', '\WBS\Streaming\Controllers\StreamController::create', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->post('(:segment)/destinations', '\WBS\Streaming\Controllers\StreamController::addDestination/$1', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->post('(:segment)/provision', '\WBS\Streaming\Controllers\StreamController::provision/$1', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->post('(:segment)/live', '\WBS\Streaming\Controllers\StreamController::goLive/$1', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->post('(:segment)/end', '\WBS\Streaming\Controllers\StreamController::end/$1', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->post('(:segment)/archive', '\WBS\Streaming\Controllers\StreamController::archive/$1', ['filter' => ['authorize:stream.create', 'webcsrf']]);
    $routes->get('(:segment)/access', '\WBS\Streaming\Controllers\StreamController::access/$1');
    $routes->get('(:segment)/dashboard', '\WBS\Streaming\Controllers\StreamController::dashboard/$1', ['filter' => 'authorize:stream.moderate']);
    // Co-hosts + overlays (FR-STR-008). Writes require moderate; active read feeds the relay.
    $routes->get('(:segment)/console', '\WBS\Streaming\Controllers\OverlayController::console/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/cohosts', '\WBS\Streaming\Controllers\OverlayController::inviteCohost/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->post('(:segment)/cohosts/token', '\WBS\Streaming\Controllers\OverlayController::issueCohostToken/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->post('(:segment)/cohosts/(:segment)/remove', '\WBS\Streaming\Controllers\OverlayController::removeCohost/$1/$2', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->delete('(:segment)/cohosts/(:segment)', '\WBS\Streaming\Controllers\OverlayController::removeCohost/$1/$2', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/overlays', '\WBS\Streaming\Controllers\OverlayController::upsertOverlay/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->post('overlays/(:segment)/visibility', '\WBS\Streaming\Controllers\OverlayController::setVisibility/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->get('(:segment)/overlays/active', '\WBS\Streaming\Controllers\OverlayController::active/$1');
    // Live engagement.
    $routes->post('(:segment)/chat', '\WBS\Streaming\Controllers\EngagementController::chat/$1', ['filter' => 'ratelimit:stream.chat']);
    $routes->post('(:segment)/moderate', '\WBS\Streaming\Controllers\EngagementController::moderate/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/polls', '\WBS\Streaming\Controllers\EngagementController::launchPoll/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/metrics', '\WBS\Streaming\Controllers\EngagementController::metric/$1');
    $routes->post('(:segment)/viewers', '\WBS\Streaming\Controllers\EngagementController::trackViewer/$1', ['filter' => 'ratelimit:stream.viewer']);
    $routes->post('viewers/(:segment)/leave', '\WBS\Streaming\Controllers\EngagementController::endViewer/$1');
    $routes->post('(:segment)/reactions', '\WBS\Streaming\Controllers\EngagementController::react/$1', ['filter' => 'ratelimit:stream.react']);
    $routes->get('(:segment)/realtime', '\WBS\Streaming\Controllers\EngagementController::realtime/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/giving', '\WBS\Streaming\Controllers\EngagementController::giving/$1');

    // In-stream giving configuration (SRS FR-STR-009) — organizer authorizes the
    // widget/progress-bar and binds it to a cause. Gated by stream.moderate.
    $routes->post('(:segment)/giving/config', '\WBS\Streaming\Controllers\StreamGivingController::configure/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->get('(:segment)/giving/config', '\WBS\Streaming\Controllers\StreamGivingController::showConfig/$1', ['filter' => 'authorize:stream.moderate']);
    // Relay health + mid-session failure response (SRS FR-STR-013). Heartbeat is
    // the trusted relay/monitor signal; report raises a manual incident. Both
    // gated by stream.moderate. Read surfaces (health/incidents) let organizers
    // see honest degradation and the documented direct-single-destination bypass.
    $routes->post('(:segment)/relay/heartbeat', '\WBS\Streaming\Controllers\StreamRelayController::heartbeat/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->post('(:segment)/relay/report', '\WBS\Streaming\Controllers\StreamRelayController::report/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->get('(:segment)/relay/health', '\WBS\Streaming\Controllers\StreamRelayController::health/$1', ['filter' => 'authorize:stream.moderate']);
    $routes->get('(:segment)/relay/incidents', '\WBS\Streaming\Controllers\StreamRelayController::incidents/$1', ['filter' => 'authorize:stream.moderate']);
});

// Relay incident lifecycle (SRS FR-STR-013): acknowledge, activate the
// documented direct-single-destination bypass, and resolve. Keyed by incident
// id; all organizer/operator actions gated by stream.moderate.
$routes->group('stream-incidents', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/acknowledge', '\WBS\Streaming\Controllers\StreamRelayController::acknowledge/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->post('(:segment)/bypass', '\WBS\Streaming\Controllers\StreamRelayController::bypass/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
    $routes->post('(:segment)/resolve', '\WBS\Streaming\Controllers\StreamRelayController::resolve/$1', ['filter' => ['authorize:stream.moderate', 'webcsrf']]);
});
// Public in-stream giving (SRS FR-STR-009): the contribution widget invokes the
// VBCS flow WITHOUT leaving the stream page. Viewer-facing, so no session is
// required (public streams); rate-limited instead. The giver identity, when
// present, is taken from the session by the controller, never from the body.
// Money completion still arrives via the signed provider webhook.
$routes->group('stream-giving', static function ($routes): void {
    $routes->get('(:segment)/widget', '\WBS\Streaming\Controllers\StreamGivingController::widget/$1');
    $routes->get('(:segment)/acknowledgements', '\WBS\Streaming\Controllers\StreamGivingController::acknowledgements/$1');
    $routes->post('(:segment)/give', '\WBS\Streaming\Controllers\StreamGivingController::give/$1', ['filter' => 'ratelimit:stream.giving']);
});

$routes->group('stream-polls', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('(:segment)/vote', '\WBS\Streaming\Controllers\EngagementController::vote/$1');
    $routes->post('(:segment)/close', '\WBS\Streaming\Controllers\EngagementController::closePoll/$1', ['filter' => 'authorize:stream.moderate']);
});

// ---------------------------------------------------------------------------
// Meetings — provider meeting/webinar integrations (SRS FR-MTG-*)
// ---------------------------------------------------------------------------
$routes->group('meetings', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Meetings\Controllers\MeetingController::index', ['filter' => 'authorize:meeting.manage,any']);
    $routes->post('', '\WBS\Meetings\Controllers\MeetingController::create', ['filter' => ['authorize:meeting.manage', 'webcsrf']]);
    $routes->post('(:segment)/grant', '\WBS\Meetings\Controllers\MeetingController::grant/$1', ['filter' => ['authorize:meeting.manage', 'webcsrf']]);
    $routes->post('(:segment)/transition', '\WBS\Meetings\Controllers\MeetingController::transition/$1', ['filter' => ['authorize:meeting.manage', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Gamification — points, leaderboards, achievements, streaks, ranks (SRS FR-GAM-*)
// ---------------------------------------------------------------------------
$routes->group('gamification', ['filter' => 'auth'], static function ($routes): void {
    // Reads (member-facing; PII-free).
    $routes->get('leaderboard', '\WBS\Gamification\Controllers\GamificationController::leaderboard');
    // Dimension-aware ranking boards (design doc Part B.5): individuals (optionally
    // within a group/subtree), groups (ranked at any ancestor level), and
    // cross-cutting activity/project/phase boards. All member-visible, PII-free.
    $routes->get('leaderboards/individuals', '\WBS\Gamification\Controllers\GamificationController::individualsBoard');
    $routes->get('leaderboards/groups', '\WBS\Gamification\Controllers\GamificationController::groupsBoard');
    $routes->get('leaderboards/groups/(:segment)/standing', '\WBS\Gamification\Controllers\GamificationController::groupStanding/$1');
    $routes->get('leaderboards/by/(:segment)/(:segment)', '\WBS\Gamification\Controllers\GamificationController::dimensionBoard/$1/$2');
    $routes->get('leaderboards/membership/(:segment)', '\WBS\Gamification\Controllers\GamificationController::membershipBoard/$1');
    $routes->get('ranks', '\WBS\Gamification\Controllers\GamificationController::ranks');
    $routes->get('achievements', '\WBS\Gamification\Controllers\GamificationController::allAchievements');
    $routes->get('subjects/(:segment)/balance', '\WBS\Gamification\Controllers\GamificationController::balance/$1');
    $routes->get('subjects/(:segment)/standing', '\WBS\Gamification\Controllers\GamificationController::standing/$1');
    $routes->get('subjects/(:segment)/achievements', '\WBS\Gamification\Controllers\GamificationController::achievements/$1');
    $routes->get('subjects/(:segment)/streaks', '\WBS\Gamification\Controllers\GamificationController::streaks/$1');
    $routes->post('subjects/(:segment)/streaks', '\WBS\Gamification\Controllers\AwardsController::recordStreak/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);

    // Admin / write — approval queue.
    $routes->get('pending', '\WBS\Gamification\Controllers\AwardsController::pending', ['filter' => 'authorize:gamification.manage,any']);
    $routes->post('awards/(:segment)/approve', '\WBS\Gamification\Controllers\AwardsController::approve/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('awards/(:segment)/reject', '\WBS\Gamification\Controllers\AwardsController::reject/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('subjects/(:segment)/achievements/unlock', '\WBS\Gamification\Controllers\AwardsController::unlockAchievement/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('subjects/(:segment)/reevaluate', '\WBS\Gamification\Controllers\AwardsController::reevaluate/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    // Browser action aliases (no-JS forms on the achievement detail page): the
    // achievement CODE is the fixed path segment and the subject is chosen in the
    // POST body. API clients keep using the subject-in-path routes above.
    $routes->post('achievements/(:segment)/unlock', '\WBS\Gamification\Controllers\AwardsController::unlockAchievementForCode/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('achievements/(:segment)/reevaluate', '\WBS\Gamification\Controllers\AwardsController::reevaluateForCode/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);

    // Admin config — point RULES (versioned/immutable edits).
    $routes->get('rules', '\WBS\Gamification\Controllers\ConfigController::listRules', ['filter' => 'authorize:gamification.manage']);
    // Browser CRUD form pages (GET) — the "New rule" / "Edit rule" landing pages
    // the rules list links to. `new`/`edit` declared BEFORE the `(:segment)`
    // catch-all so they match as literal pages, not rule codes.
    $routes->get('rules/new', '\WBS\Gamification\Controllers\ConfigController::createRuleForm', ['filter' => 'authorize:gamification.manage,any']);
    $routes->get('rules/(:segment)/edit', '\WBS\Gamification\Controllers\ConfigController::editRuleForm/$1', ['filter' => 'authorize:gamification.manage,any']);
    $routes->post('rules', '\WBS\Gamification\Controllers\ConfigController::createRule', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('rules/(:segment)', '\WBS\Gamification\Controllers\ConfigController::showRule/$1', ['filter' => 'authorize:gamification.manage']);
    $routes->post('rules/(:segment)', '\WBS\Gamification\Controllers\ConfigController::updateRule/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('rules/(:segment)/disable', '\WBS\Gamification\Controllers\ConfigController::disableRule/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);

    // Configurable Win-Build-Send activities: activity catalog, follow-ups, config.
    $routes->get('activity-catalog', '\WBS\Gamification\Controllers\ConfigController::activityCatalog', ['filter' => 'authorize:gamification.manage']);
    $routes->get('activity-categories', '\WBS\Gamification\Controllers\ConfigController::listActivityCategories', ['filter' => 'authorize:gamification.manage']);
    $routes->post('activity-categories', '\WBS\Gamification\Controllers\ConfigController::defineActivityCategory', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('activity-categories/(:segment)', '\WBS\Gamification\Controllers\ConfigController::updateActivityCategory/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('activity-categories/(:segment)/disable', '\WBS\Gamification\Controllers\ConfigController::disableActivityCategory/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('activity-categories/(:segment)', '\WBS\Gamification\Controllers\ConfigController::showActivityCategory/$1', ['filter' => 'authorize:gamification.manage']);

    $routes->get('follow-up-types', '\WBS\Gamification\Controllers\FollowUpsController::listFollowUpTypes', ['filter' => 'authorize:gamification.manage']);
    $routes->post('follow-up-types', '\WBS\Gamification\Controllers\FollowUpsController::defineFollowUpType', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('follow-up-types/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::updateFollowUpType/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('follow-up-types/(:segment)/disable', '\WBS\Gamification\Controllers\FollowUpsController::disableFollowUpType/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('follow-up-types/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::showFollowUpType/$1', ['filter' => 'authorize:gamification.manage']);
    $routes->get('follow-up-methods', '\WBS\Gamification\Controllers\FollowUpsController::listFollowUpMethods', ['filter' => 'authorize:gamification.manage']);
    $routes->post('follow-up-methods', '\WBS\Gamification\Controllers\FollowUpsController::defineFollowUpMethod', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);
    $routes->patch('follow-up-methods/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::updateFollowUpMethod/$1', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);
    $routes->post('follow-up-methods/(:segment)/disable', '\WBS\Gamification\Controllers\FollowUpsController::disableFollowUpMethod/$1', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);
    $routes->get('follow-up-methods/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::showFollowUpMethod/$1', ['filter' => 'authorize:gamification.manage']);
    $routes->get('follow-ups/new', '\WBS\Gamification\Controllers\FollowUpsController::recordFollowUpForm');
    $routes->post('follow-ups', '\WBS\Gamification\Controllers\FollowUpsController::recordFollowUp', ['filter' => 'webcsrf']);
    $routes->get('follow-ups/due', '\WBS\Gamification\Controllers\FollowUpsController::dueFollowUps');
    $routes->get('follow-ups/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::showFollowUp/$1');
    $routes->patch('follow-ups/(:segment)', '\WBS\Gamification\Controllers\FollowUpsController::updateFollowUp/$1', ['filter' => 'webcsrf']);
    // Browser edit alias: an HTML form cannot emit PATCH without JS, so a plain
    // POST to /update maps to the same handler (guarded identically). API clients
    // continue to use the RESTful PATCH verb.
    $routes->post('follow-ups/(:segment)/update', '\WBS\Gamification\Controllers\FollowUpsController::updateFollowUp/$1', ['filter' => 'webcsrf']);
    $routes->post('follow-ups/(:segment)/cancel', '\WBS\Gamification\Controllers\FollowUpsController::cancelFollowUp/$1', ['filter' => 'webcsrf']);
    $routes->get('subjects/(:segment)/follow-ups', '\WBS\Gamification\Controllers\FollowUpsController::subjectFollowUps/$1');

    $routes->get('config', '\WBS\Gamification\Controllers\ConfigController::listConfig', ['filter' => 'authorize:gamification.manage']);
    $routes->get('config/(:segment)', '\WBS\Gamification\Controllers\ConfigController::showConfig/$1', ['filter' => 'authorize:gamification.manage']);
    $routes->post('config/(:segment)', '\WBS\Gamification\Controllers\ConfigController::setConfig/$1', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);
    $routes->delete('config/(:segment)', '\WBS\Gamification\Controllers\ConfigController::deleteConfig/$1', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);
    // POST alias for the no-JS browser form (HTML forms cannot emit DELETE).
    $routes->post('config/(:segment)/delete', '\WBS\Gamification\Controllers\ConfigController::deleteConfig/$1', ['filter' => ['authorize:gamification.manage', 'webcsrf']]);

    // Admin config — RANKS.
    $routes->get('rank-definitions', '\WBS\Gamification\Controllers\AwardsController::listRankDefinitions', ['filter' => 'authorize:gamification.manage']);
    $routes->post('ranks', '\WBS\Gamification\Controllers\AwardsController::defineRank', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('ranks/(:segment)', '\WBS\Gamification\Controllers\AwardsController::updateRank/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('ranks/(:segment)/disable', '\WBS\Gamification\Controllers\AwardsController::disableRank/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('ranks/(:segment)', '\WBS\Gamification\Controllers\AwardsController::showRank/$1', ['filter' => 'authorize:gamification.manage']);

    // Admin config — ACHIEVEMENTS (catalog CRUD). define() upserts by code, so the
    // admin list's "New" and per-row "Edit" forms both POST to /achievements.
    $routes->get('achievement-definitions', '\WBS\Gamification\Controllers\AwardsController::listAchievementDefinitions', ['filter' => 'authorize:gamification.manage']);
    $routes->post('achievements', '\WBS\Gamification\Controllers\AwardsController::defineAchievement', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('achievements/(:segment)', '\WBS\Gamification\Controllers\AwardsController::updateAchievement/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('achievements/(:segment)/disable', '\WBS\Gamification\Controllers\AwardsController::disableAchievement/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('achievements/(:segment)', '\WBS\Gamification\Controllers\AwardsController::showAchievement/$1', ['filter' => 'authorize:gamification.manage']);

    // Admin config — STREAK definitions.
    $routes->get('streak-definitions', '\WBS\Gamification\Controllers\AwardsController::listStreakDefinitions', ['filter' => 'authorize:gamification.manage']);
    $routes->post('streak-definitions', '\WBS\Gamification\Controllers\AwardsController::defineStreak', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('streak-definitions/(:segment)', '\WBS\Gamification\Controllers\AwardsController::updateStreak/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('streak-definitions/(:segment)/disable', '\WBS\Gamification\Controllers\AwardsController::disableStreak/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('streak-definitions/(:segment)', '\WBS\Gamification\Controllers\AwardsController::showStreak/$1', ['filter' => 'authorize:gamification.manage']);
    // Browser action alias (no-JS form on the streak detail page): streak CODE in
    // path, subject in the POST body. API keeps subjects/(:segment)/streaks.
    $routes->post('streak-definitions/(:segment)/record', '\WBS\Gamification\Controllers\AwardsController::recordStreakForCode/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);

    // Admin config - BADGES (catalog CRUD) + manual grant/revoke.
    $routes->get('badges', '\WBS\Gamification\Controllers\AwardsController::listBadges', ['filter' => 'authorize:gamification.manage']);
    $routes->post('badges', '\WBS\Gamification\Controllers\AwardsController::defineBadge', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('badges/(:segment)', '\WBS\Gamification\Controllers\AwardsController::updateBadge/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('badges/(:segment)/disable', '\WBS\Gamification\Controllers\AwardsController::disableBadge/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('badges/(:segment)/grant', '\WBS\Gamification\Controllers\AwardsController::grantBadge/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('badges/(:segment)/revoke', '\WBS\Gamification\Controllers\AwardsController::revokeBadge/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('badges/(:segment)', '\WBS\Gamification\Controllers\AwardsController::showBadge/$1', ['filter' => 'authorize:gamification.manage']);
    $routes->get('subjects/(:segment)/badges', '\WBS\Gamification\Controllers\AwardsController::subjectBadges/$1');

    // Group campaigns / "projects" — time-boxed, group-scoped, target-based.
    // Reads are auth-only; leaderboard is member-visible. Writes require manage.
    $routes->get('groups/(:segment)/campaigns', '\WBS\Gamification\Controllers\CampaignsController::campaigns/$1');
    // Campaign config FORM (browser create/edit) — declared before the generic
    // campaigns/(:segment) read so 'new' is not captured as an id. The edit form
    // needs a campaign id so it is already non-page-like.
    $routes->get('campaigns/new', '\WBS\Gamification\Controllers\CampaignsController::createCampaignForm', ['filter' => 'authorize:gamification.manage,any']);
    $routes->get('campaigns/(:segment)/edit', '\WBS\Gamification\Controllers\CampaignsController::editCampaignForm/$1', ['filter' => 'authorize:gamification.manage,any']);
    $routes->get('campaigns/(:segment)/leaderboard', '\WBS\Gamification\Controllers\CampaignsController::campaignLeaderboard/$1');
    $routes->get('campaigns/(:segment)/team-standings', '\WBS\Gamification\Controllers\CampaignsController::campaignTeamStandings/$1');
    // Create posts from the browser form (webcsrf) or JSON API (Bearer exempt).
    $routes->post('campaigns', '\WBS\Gamification\Controllers\CampaignsController::createCampaign', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('campaigns/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::getCampaign/$1');
    $routes->patch('campaigns/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::updateCampaign/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    // No-JS PRG update alias for the browser edit form (webcsrf); PATCH stays for API.
    $routes->post('campaigns/(:segment)/update', '\WBS\Gamification\Controllers\CampaignsController::updateCampaign/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    // Reward-ladder MANAGEMENT console (browser CRUD) + no-JS PRG write aliases.
    // Declared before the generic tiers read so 'manage' is not captured as data.
    $routes->get('campaigns/(:segment)/tiers/manage', '\WBS\Gamification\Controllers\CampaignsController::manageCampaignTiers/$1', ['filter' => 'authorize:gamification.manage,any']);
    $routes->get('campaigns/(:segment)/tiers', '\WBS\Gamification\Controllers\CampaignsController::campaignTiers/$1');
    // Add-tier posts from the browser form (webcsrf) or JSON API (Bearer exempt).
    $routes->post('campaigns/(:segment)/tiers', '\WBS\Gamification\Controllers\CampaignsController::defineCampaignTier/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->delete('campaigns/(:segment)/tiers/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::deleteCampaignTier/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/tiers/(:segment)/delete', '\WBS\Gamification\Controllers\CampaignsController::deleteCampaignTier/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->get('campaigns/(:segment)/teams', '\WBS\Gamification\Controllers\CampaignsController::campaignTeams/$1');
    // Ad hoc team MANAGEMENT console (browser CRUD) + the no-JS PRG write aliases
    // it posts to. The management list's create/rename/delete team + add/remove
    // member forms all target these webcsrf-guarded POST routes (the PATCH/DELETE
    // routes above remain for JSON/API clients).
    $routes->get('campaigns/(:segment)/teams/manage', '\WBS\Gamification\Controllers\CampaignsController::manageCampaignTeams/$1', ['filter' => 'authorize:gamification.manage,any']);
    $routes->post('campaigns/(:segment)/teams', '\WBS\Gamification\Controllers\CampaignsController::defineCampaignTeam/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->patch('campaigns/(:segment)/teams/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::updateCampaignTeam/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/teams/(:segment)/update', '\WBS\Gamification\Controllers\CampaignsController::updateCampaignTeam/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->delete('campaigns/(:segment)/teams/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::deleteCampaignTeam/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/teams/(:segment)/delete', '\WBS\Gamification\Controllers\CampaignsController::deleteCampaignTeam/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/teams/(:segment)/members', '\WBS\Gamification\Controllers\CampaignsController::addCampaignTeamMember/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->delete('campaigns/(:segment)/members/(:segment)', '\WBS\Gamification\Controllers\CampaignsController::removeCampaignTeamMember/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/members/(:segment)/remove', '\WBS\Gamification\Controllers\CampaignsController::removeCampaignTeamMember/$1/$2', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/activate', '\WBS\Gamification\Controllers\CampaignsController::activateCampaign/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/cancel', '\WBS\Gamification\Controllers\CampaignsController::cancelCampaign/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/progress', '\WBS\Gamification\Controllers\CampaignsController::campaignProgress/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
    $routes->post('campaigns/(:segment)/close', '\WBS\Gamification\Controllers\CampaignsController::closeCampaign/$1', ['filter' => ['authorize:gamification.manage,any', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// Reporting — dashboards + exports (SRS FR-RPT-*)
// ---------------------------------------------------------------------------
$routes->group('reports', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('funnel', '\WBS\Reporting\Controllers\DashboardController::funnel', ['filter' => 'authorize:report.view']);
    $routes->get('export', '\WBS\Reporting\Controllers\DashboardController::exports', ['filter' => 'authorize:report.export,any']);
    $routes->post('exports', '\WBS\Reporting\Controllers\DashboardController::requestExport', ['filter' => ['ratelimit:report.generate', 'webcsrf']]);
    $routes->get('exports/(:segment)/download', '\WBS\Reporting\Controllers\DashboardController::download/$1');
});

// Member self-service dashboard ("my home"). Self-scoped to the authenticated
// user — no admin/report permission, only auth. Serves HTML or JSON.
$routes->get('me/dashboard', '\WBS\Reporting\Controllers\DashboardController::me', ['filter' => 'auth']);

// Hierarchical group dashboard — dynamic widgets from all modules, scoped to
// the user's groups. Auth-only; widgets are fail-closed on permissions/config.
$routes->get('me/groups', '\WBS\Groups\Controllers\GroupDashboardController::index', ['filter' => 'auth']);

// ---------------------------------------------------------------------------
// Admin — platform settings, feature flags, group config (SRS FR-GRP-006/FR-ACL-007)
// ---------------------------------------------------------------------------
$routes->group('admin', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\Admin\Controllers\AdminController::index', ['filter' => 'authorize:admin.manage']);
    $routes->get('providers', '\WBS\Admin\Controllers\AdminController::providers', ['filter' => 'authorize:provider.configure']);
    $routes->get('settings/(:segment)', '\WBS\Admin\Controllers\AdminController::getSetting/$1', ['filter' => 'authorize:admin.manage']);
    // Create alias: the browser create form POSTs here with the admin-typed key as
    // a body field (CSP forbids inline JS to rewrite the action into the URL). The
    // per-key route handles edits of an existing row. All are webcsrf-guarded.
    $routes->post('settings', '\WBS\Admin\Controllers\AdminController::setSetting', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->post('settings/(:segment)', '\WBS\Admin\Controllers\AdminController::setSetting/$1', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->post('flags', '\WBS\Admin\Controllers\AdminController::setFlag', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->post('flags/(:segment)', '\WBS\Admin\Controllers\AdminController::setFlag/$1', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->post('group-config', '\WBS\Admin\Controllers\AdminController::setGroupConfig', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->post('groups/(:segment)/config/(:segment)', '\WBS\Admin\Controllers\AdminController::setGroupConfig/$1/$2', ['filter' => ['authorize:admin.manage', 'webcsrf']]);
    $routes->get('groups/(:segment)/config/(:segment)', '\WBS\Admin\Controllers\AdminController::resolveGroupConfig/$1/$2', ['filter' => 'authorize:admin.manage']);
});

// Access-request / approval workflow (SRS FR-ACL-004). All routes require an
// authenticated session; review actions additionally require the reviewer
// permission and the PDP's maker-checker (a requester can't approve their own).
$routes->group('access-requests', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('', '\WBS\AccessControl\Controllers\AccessRequestController::submit', ['filter' => 'webcsrf']);
    $routes->get('pending', '\WBS\AccessControl\Controllers\AccessRequestController::pending');
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\AccessRequestController::show/$1');
    $routes->post('(:segment)/approve', '\WBS\AccessControl\Controllers\AccessRequestController::approve/$1', ['filter' => ['authorize:access.request.approve', 'webcsrf']]);
    $routes->post('(:segment)/reject', '\WBS\AccessControl\Controllers\AccessRequestController::reject/$1', ['filter' => ['authorize:access.request.approve', 'webcsrf']]);
    $routes->post('(:segment)/revoke', '\WBS\AccessControl\Controllers\AccessRequestController::revoke/$1', ['filter' => ['authorize:access.request.approve', 'webcsrf']]);
    $routes->post('(:segment)/renew', '\WBS\AccessControl\Controllers\AccessRequestController::renew/$1', ['filter' => ['authorize:access.request.approve', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// AccessControl - direct role-assignment management (SRS FR-ACL-003).
// Coarse ,any gate admits any holder of access.assignment.manage; the
// authoritative per-group (delegated-administration) check is in the service.
// ---------------------------------------------------------------------------
$routes->group('access-assignments', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('', '\WBS\AccessControl\Controllers\RoleAssignmentController::assign', ['filter' => ['authorize:access.assignment.manage,any', 'webcsrf']]);
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\RoleAssignmentController::show/$1', ['filter' => 'authorize:access.assignment.manage,any']);
    $routes->post('(:segment)/revoke', '\WBS\AccessControl\Controllers\RoleAssignmentController::revoke/$1', ['filter' => ['authorize:access.assignment.manage,any', 'webcsrf']]);
    $routes->get('subjects/(:segment)', '\WBS\AccessControl\Controllers\RoleAssignmentController::forSubject/$1', ['filter' => 'authorize:access.assignment.manage,any']);
});

// ---------------------------------------------------------------------------
// AccessControl - delegation of authority (SRS FR-ACL-005).
// No separate permission gate: the right to delegate IS holding the underlying
// permission at a covering scope, verified in DelegationService. Auth only.
// ---------------------------------------------------------------------------
$routes->group('delegations', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('', '\WBS\AccessControl\Controllers\DelegationController::create', ['filter' => 'webcsrf']);
    $routes->post('(:segment)/revoke', '\WBS\AccessControl\Controllers\DelegationController::revoke/$1', ['filter' => 'webcsrf']);
    $routes->get('(:segment)/chain', '\WBS\AccessControl\Controllers\DelegationController::chain/$1');
    $routes->get('received/(:segment)', '\WBS\AccessControl\Controllers\DelegationController::received/$1');
});

// ---------------------------------------------------------------------------
// AccessControl - break-glass emergency access (SRS FR-ACL-006).
// Gated behind access.break_glass (not for routine use). Strong MFA, reason,
// narrow TTL, no finance/audit bypass, auto-expiry, and mandatory post-use
// review are all enforced in BreakGlassService.
// ---------------------------------------------------------------------------
$routes->group('break-glass', ['filter' => 'auth'], static function ($routes): void {
    $routes->post('', '\WBS\AccessControl\Controllers\BreakGlassController::open', ['filter' => ['authorize:access.break_glass,any', 'webcsrf']]);
    $routes->get('pending-reviews', '\WBS\AccessControl\Controllers\BreakGlassController::pendingReviews', ['filter' => 'authorize:access.break_glass.review,any']);
    $routes->post('(:segment)/close', '\WBS\AccessControl\Controllers\BreakGlassController::close/$1', ['filter' => ['authorize:access.break_glass,any', 'webcsrf']]);
    $routes->post('(:segment)/review', '\WBS\AccessControl\Controllers\BreakGlassController::review/$1', ['filter' => ['authorize:access.break_glass.review,any', 'webcsrf']]);
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\BreakGlassController::show/$1', ['filter' => 'authorize:access.break_glass.review,any']);
});

// ---------------------------------------------------------------------------
// AccessControl - RBAC role catalogue CRUD (SRS FR-ACL-002/003).
// Coarse gate access.role.manage; RoleService enforces the org-wide coverage
// rule plus system-role and privilege-escalation guards.
// ---------------------------------------------------------------------------
$routes->group('roles', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\AccessControl\Controllers\RoleController::index', ['filter' => 'authorize:access.role.manage']);
    // Browser CRUD form pages (GET) — the "New role" / "Edit role" landing pages
    // the catalogue links to. `new`/`edit` declared BEFORE the `(:segment)`
    // catch-all so they match as literal pages, not role ids.
    $routes->get('new', '\WBS\AccessControl\Controllers\RoleController::createForm', ['filter' => 'authorize:access.role.manage']);
    $routes->post('', '\WBS\AccessControl\Controllers\RoleController::create', ['filter' => ['authorize:access.role.manage', 'webcsrf']]);
    $routes->get('(:segment)/edit', '\WBS\AccessControl\Controllers\RoleController::editForm/$1', ['filter' => 'authorize:access.role.manage']);
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\RoleController::show/$1', ['filter' => 'authorize:access.role.manage']);
    $routes->post('(:segment)', '\WBS\AccessControl\Controllers\RoleController::update/$1', ['filter' => ['authorize:access.role.manage', 'webcsrf']]);
    $routes->post('(:segment)/permissions', '\WBS\AccessControl\Controllers\RoleController::setPermissions/$1', ['filter' => ['authorize:access.role.manage', 'webcsrf']]);
    $routes->post('(:segment)/delete', '\WBS\AccessControl\Controllers\RoleController::delete/$1', ['filter' => ['authorize:access.role.manage', 'webcsrf']]);
});

// ---------------------------------------------------------------------------
// AccessControl - ABAC policy CRUD (SRS: ABAC in the MAC+RBAC+ABAC PDP).
// Coarse gate access.policy.manage; AbacPolicyService enforces org-wide coverage
// and validates every condition tree through the PDP's own evaluator on write.
// ---------------------------------------------------------------------------
$routes->group('abac-policies', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\AccessControl\Controllers\AbacPolicyController::index', ['filter' => 'authorize:access.policy.manage']);
    // Browser CRUD form pages (GET) — "New policy" / "Edit policy" landing pages.
    // `new`/`edit` declared BEFORE the `(:segment)` catch-all.
    $routes->get('new', '\WBS\AccessControl\Controllers\AbacPolicyController::createForm', ['filter' => 'authorize:access.policy.manage']);
    $routes->post('', '\WBS\AccessControl\Controllers\AbacPolicyController::create', ['filter' => ['authorize:access.policy.manage', 'webcsrf']]);
    $routes->get('(:segment)/edit', '\WBS\AccessControl\Controllers\AbacPolicyController::editForm/$1', ['filter' => 'authorize:access.policy.manage']);
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\AbacPolicyController::show/$1', ['filter' => 'authorize:access.policy.manage']);
    $routes->post('(:segment)', '\WBS\AccessControl\Controllers\AbacPolicyController::update/$1', ['filter' => ['authorize:access.policy.manage', 'webcsrf']]);
    $routes->post('(:segment)/enabled', '\WBS\AccessControl\Controllers\AbacPolicyController::setEnabled/$1', ['filter' => ['authorize:access.policy.manage', 'webcsrf']]);
    $routes->post('(:segment)/delete', '\WBS\AccessControl\Controllers\AbacPolicyController::delete/$1', ['filter' => ['authorize:access.policy.manage', 'webcsrf']]);
});

// RuBAC rules — the general, leader-authored rule engine (access + other facets).
// Gated `,any` (coarse capability gate): unlike org-wide ABAC policies a rule is
// authored WITHIN A LEADER'S OWN SCOPE, so RuleService enforces the authoritative
// containment check (a leader can never author a rule broader than they hold).
$routes->group('rules', ['filter' => 'auth'], static function ($routes): void {
    $routes->get('', '\WBS\AccessControl\Controllers\RuleController::index', ['filter' => 'authorize:access.rule.manage,any']);
    // Browser CRUD form pages (GET) — the "New rule" / "Edit rule" landing pages
    // that the rule catalogue links to. `new`/`edit` are declared BEFORE the
    // catch-all `(:segment)` so they are matched as literal pages, not rule ids.
    $routes->get('new', '\WBS\AccessControl\Controllers\RuleController::createForm', ['filter' => 'authorize:access.rule.manage,any']);
    $routes->post('', '\WBS\AccessControl\Controllers\RuleController::create', ['filter' => ['authorize:access.rule.manage,any', 'webcsrf']]);
    $routes->get('(:segment)/edit', '\WBS\AccessControl\Controllers\RuleController::editForm/$1', ['filter' => 'authorize:access.rule.manage,any']);
    $routes->get('(:segment)', '\WBS\AccessControl\Controllers\RuleController::show/$1', ['filter' => 'authorize:access.rule.manage,any']);
    $routes->post('(:segment)', '\WBS\AccessControl\Controllers\RuleController::update/$1', ['filter' => ['authorize:access.rule.manage,any', 'webcsrf']]);
    $routes->post('(:segment)/enabled', '\WBS\AccessControl\Controllers\RuleController::setEnabled/$1', ['filter' => ['authorize:access.rule.manage,any', 'webcsrf']]);
    $routes->post('(:segment)/delete', '\WBS\AccessControl\Controllers\RuleController::delete/$1', ['filter' => ['authorize:access.rule.manage,any', 'webcsrf']]);
});
