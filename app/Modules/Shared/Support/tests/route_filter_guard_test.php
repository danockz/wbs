<?php

declare(strict_types=1);

/**
 * ROUTE-FILTER GUARD test (Theme A — the mechanical guard).
 *
 * The recurring platform-wide defect class (R1, N5, GR1, M3, J5, AC11, G6) is a
 * STATE-CHANGING route that does not prove the caller is authenticated,
 * authorized, and (for browser writes) CSRF-protected — it relies on the service
 * being reached only from a trusted place. Spotting these in review does not
 * scale; this test converts the whole class to "CI fails on regression".
 *
 * It parses the canonical route table (app/Config/Routes.php) exactly the way the
 * OpenAPI generator does (tools/gen_openapi.py) — resolving group-prefix + group
 * filter inheritance — then, for every non-GET (state-changing) route, asserts:
 *
 *   1. AUTH  — the route carries `auth` OR `authorize:<cap>` (authorize implies an
 *      authenticated subject), UNLESS it is on the PUBLIC allowlist (a recorded,
 *      deliberate decision: pre-login auth flows, donor checkout, signed webhooks,
 *      public landings, kiosk check-in).
 *   2. CSRF  — the route carries `webcsrf`, UNLESS it is on the PUBLIC allowlist
 *      (public routes have their own protection: rate-limit / signature / token)
 *      OR the CSRF-EXEMPT allowlist (JSON/API-first endpoints where webcsrf is
 *      header-exempt by design; the browser-write gaps M3/J5/AC11/G6 are frozen
 *      here and scheduled for Phase 3 — the allowlist makes each a recorded TODO,
 *      not a silent hole).
 *
 * The allowlists are EXHAUSTIVE and CLOSED: a new state-changing route that is
 * neither gated nor listed FAILS the test, and a listed route that no longer
 * exists FAILS too (so the lists cannot rot). To add a public/exempt route you
 * must consciously add it here.
 *
 *   php app/Modules/Shared/Support/tests/route_filter_guard_test.php
 */

$root = dirname(__DIR__, 4); // -> /app
$routesFile = $root . '/Config/Routes.php';

$pass = 0;
$fail = 0;
$chk = static function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
};

// --------------------------------------------------------------------------
// Route-table parser (mirrors tools/gen_openapi.py parse()).
// --------------------------------------------------------------------------
$VERB_RE  = '/\$routes->(get|post|put|patch|delete)\(\s*\'([^\']*)\'\s*,\s*\'([^\']*)\'\s*(?:,\s*\[(.*)\])?\s*\)\s*;/';
$GROUP_RE = '/\$routes->group\(\s*\'([^\']*)\'\s*(?:,\s*\[(.*?)\])?\s*,\s*static function/';

$parseFilters = static function (string $opts): array {
    if (preg_match('/\'filter\'\s*=>\s*\[([^\]]*)\]/', $opts, $m) === 1) {
        preg_match_all('/\'([^\']*)\'/', $m[1], $mm);

        return $mm[1];
    }
    if (preg_match('/\'filter\'\s*=>\s*\'([^\']*)\'/', $opts, $m) === 1) {
        return [$m[1]];
    }

    return [];
};

if (! is_file($routesFile)) {
    echo "  FAIL routes file present — {$routesFile}\n";
    echo "\nFAIL 0 passed, 1 failed\n";
    exit(1);
}

$lines = file($routesFile);
$stack = []; // list of [prefix, filters[]]
$routes = []; // list of ['verb'=>, 'path'=>, 'filters'=>[]]
foreach ($lines as $line) {
    if (preg_match($GROUP_RE, $line, $g) === 1) {
        $stack[] = [$g[1], $parseFilters($g[2] ?? '')];
        continue;
    }
    if (trim($line) === '});' && $stack !== []) {
        array_pop($stack);
        continue;
    }
    if (preg_match($VERB_RE, $line, $v) === 1) {
        $verb = $v[1];
        $path = $v[2];
        $opts = $v[4] ?? '';
        $prefixes = [];
        $filters  = [];
        foreach ($stack as $s) {
            if ($s[0] !== '') {
                $prefixes[] = $s[0];
            }
            $filters = array_merge($filters, $s[1]);
        }
        $filters = array_merge($filters, $parseFilters($opts));
        $full    = implode('/', array_filter(array_merge($prefixes, [$path])));
        $routes[] = ['verb' => $verb, 'path' => $full, 'filters' => $filters];
    }
}

$chk('route table parsed (>= 200 routes)', count($routes) >= 200, 'parsed ' . count($routes));

// --------------------------------------------------------------------------
// Allowlists. Keys are "VERB path" (path in CI route syntax).
// --------------------------------------------------------------------------

// PUBLIC: deliberately reachable WITHOUT an authenticated session. Each has its
// own protection (rate-limit / CSRF / signed token / signature) recorded here so
// "unauthenticated" is an explicit decision, not an oversight.
$PUBLIC = [
    // Pre-login / session auth flows (the caller has no session yet by definition).
    'POST login', 'POST mfa', 'POST logout', 'POST set-password', 'POST prefs/locale',
    'POST auth/register', 'POST auth/login', 'POST auth/logout',
    'POST auth/mfa/totp/enrol', 'POST auth/mfa/totp/confirm', 'POST auth/mfa/verify',
    'POST auth/social/(:segment)/callback', 'POST auth/social/(:segment)/link',
    'POST auth/social/(:segment)/unlink', 'POST tokens/refresh',
    // Public group join (self-service; rate-limited + webcsrf).
    'POST g/(:segment)/join',
    // Anonymous geo resolve (reference-data lookup; no state change of note).
    'POST geo/resolve',
    // Public cloaked-link prospect capture (rate-limited + webcsrf).
    'POST r/(:segment)/prospect',
    // Event door check-in + guest RSVP + ticket purchase (attendee-facing; nonce/
    // rate-limit/webcsrf as appropriate — attendees are not org members).
    'POST events/(:segment)/checkin/qr', 'POST events/(:segment)/checkin/manual',
    'POST events/(:segment)/checkin/nonce', 'POST events/(:segment)/register-guest',
    'POST events/(:segment)/ticket-hold', 'POST events/(:segment)/checkout',
    // Public feedback form submission (attendee-facing; webcsrf + rate-limit).
    'POST feedback-forms/(:segment)/responses',
    // Donor giving (donors are not authenticated members; rate-limited checkout).
    'POST causes/(:segment)/contribute', 'POST stream-giving/(:segment)/give',
    // Payment provider webhooks (authenticated by signature, not a session).
    'POST webhooks/payments/(:segment)',
];

// CSRF-EXEMPT: authenticated, but no `webcsrf`. These are JSON/API-first
// endpoints where the webcsrf filter is header-exempt by design (token/API
// callers). The former browser-write gaps (M3 identity lifecycle, J5 journey/
// signals, AC11 ACL mutations, G6 gamification PATCH/DELETE) have now been
// CLOSED — each carries webcsrf (header-exempt for Bearer/API callers, so
// API-first clients are unaffected) and is asserted below; none may return to
// this allowlist (the "closed §C not on any allowlist" checks enforce that).
$CSRF_EXEMPT = [
    'POST tokens', 'POST tokens/revoke-all', 'DELETE tokens/(:segment)',
    'POST venues/bulk', 'DELETE venues/(:segment)', 'POST venues/(:segment)/groups',
    'POST referrals/clicks/(:segment)/flag', 'POST referrals/clicks/(:segment)/clear',
    'POST events/(:segment)/budget', 'POST events/(:segment)/expenses',
    'POST events/(:segment)/report/snapshot', 'POST feedback-responses/(:segment)/review',
    'POST orders/(:segment)/pay', 'POST orders/items/(:segment)/transfer',
    'POST kiosks/(:segment)/offline-scans',
    'POST expenses/(:segment)/approve', 'POST expenses/(:segment)/reject',
    'POST expenses/(:segment)/reimburse',
    'POST contributions/(:segment)/refunds', 'POST vbcs/subjects/(:segment)/refresh',
    'DELETE streams/(:segment)/cohosts/(:segment)', 'POST streams/(:segment)/chat',
    'POST streams/(:segment)/moderate', 'POST streams/(:segment)/polls',
    'POST streams/(:segment)/metrics', 'POST streams/(:segment)/viewers',
    'POST streams/viewers/(:segment)/leave', 'POST streams/(:segment)/reactions',
    'POST streams/(:segment)/giving', 'POST streams/(:segment)/relay/heartbeat',
    'POST stream-polls/(:segment)/vote', 'POST stream-polls/(:segment)/close',
];

$publicSet = array_fill_keys($PUBLIC, false);      // value flips true when matched
$exemptSet = array_fill_keys($CSRF_EXEMPT, false);

$authFailures = [];
$csrfFailures = [];

foreach ($routes as $r) {
    if ($r['verb'] === 'get') {
        continue; // reads are not state-changing
    }
    $key  = strtoupper($r['verb']) . ' ' . $r['path'];
    $auth = false;
    $csrf = false;
    foreach ($r['filters'] as $f) {
        if ($f === 'auth' || str_starts_with($f, 'authorize:')) {
            $auth = true;
        }
        if ($f === 'webcsrf') {
            $csrf = true;
        }
    }

    $isPublic = array_key_exists($key, $publicSet);
    $isExempt = array_key_exists($key, $exemptSet);
    if ($isPublic) {
        $publicSet[$key] = true;
    }
    if ($isExempt) {
        $exemptSet[$key] = true;
    }

    // Invariant 1 — AUTH: every state-changing route must be authenticated
    // unless it's a recorded public endpoint.
    if (! $auth && ! $isPublic) {
        $authFailures[] = $key . '  [' . implode(',', $r['filters']) . ']';
    }

    // Invariant 2 — CSRF: an authenticated state-changing route must carry
    // webcsrf unless recorded as CSRF-exempt (API-first) or public.
    if ($auth && ! $csrf && ! $isExempt && ! $isPublic) {
        $csrfFailures[] = $key . '  [' . implode(',', $r['filters']) . ']';
    }
}

$chk('every state-changing route is authenticated or on the PUBLIC allowlist',
    $authFailures === [], "\n    - " . implode("\n    - ", $authFailures));

$chk('every authenticated write carries webcsrf or is on an allowlist',
    $csrfFailures === [], "\n    - " . implode("\n    - ", $csrfFailures));

// The allowlists must not rot: every listed route must still exist.
$stalePublic = array_keys(array_filter($publicSet, static fn ($seen) => $seen === false));
$staleExempt = array_keys(array_filter($exemptSet, static fn ($seen) => $seen === false));
$chk('no stale PUBLIC allowlist entries', $stalePublic === [], "\n    - " . implode("\n    - ", $stalePublic));
$chk('no stale CSRF-EXEMPT allowlist entries', $staleExempt === [], "\n    - " . implode("\n    - ", $staleExempt));

// --------------------------------------------------------------------------
// Targeted regression assertions for the HIGHs closed this turn (R1, N5, GR1).
// --------------------------------------------------------------------------
$byKey = [];
foreach ($routes as $r) {
    if ($r['verb'] === 'get') {
        continue;
    }
    $byKey[strtoupper($r['verb']) . ' ' . $r['path']] = $r['filters'];
}
$hasAuthz = static fn (array $fl, string $cap): bool => in_array('auth', $fl, true)
    && in_array('authorize:' . $cap, $fl, true) && in_array('webcsrf', $fl, true);

$chk('R1: referrals/links gated (auth+cap+csrf)', isset($byKey['POST referrals/links']) && $hasAuthz($byKey['POST referrals/links'], 'referral.link.create'));
$chk('R1: referrals/sponsorships gated', isset($byKey['POST referrals/sponsorships']) && $hasAuthz($byKey['POST referrals/sponsorships'], 'sponsor.reassign.approve'));
$chk('R1: referrals/attribute gated (admin capability)', isset($byKey['POST referrals/attribute']) && $hasAuthz($byKey['POST referrals/attribute'], 'admin.manage'));
$chk('N5: notifications/send gated (auth+notification.send+csrf)', isset($byKey['POST notifications/send']) && $hasAuthz($byKey['POST notifications/send'], 'notification.send'));
$chk('N5: notifications/send keeps the broadcast rate-limit', isset($byKey['POST notifications/send']) && in_array('ratelimit:notification.broadcast', $byKey['POST notifications/send'], true));
$chk('GR1: groups/(:segment)/members gated', isset($byKey['POST groups/(:segment)/members']) && $hasAuthz($byKey['POST groups/(:segment)/members'], 'group.change.approve'));

// None of the three R1 routes nor N5 may appear on either allowlist any more.
foreach (['POST referrals/links', 'POST referrals/sponsorships', 'POST referrals/attribute', 'POST notifications/send', 'POST groups/(:segment)/members'] as $k) {
    $chk("closed HIGH not on any allowlist: {$k}", ! in_array($k, $PUBLIC, true) && ! in_array($k, $CSRF_EXEMPT, true));
}

// --------------------------------------------------------------------------
// Targeted regression assertions for §C (M3, J5, AC11, G6) closed this turn.
// A CSRF check that tolerates BOTH filter orderings + the `,any`/`,self`
// capability suffixes the routes use.
// --------------------------------------------------------------------------
$hasCsrf   = static fn (array $fl): bool => in_array('webcsrf', $fl, true);
$hasCapAny = static fn (array $fl, string $cap): bool => in_array('auth', $fl, true)
    || (bool) array_filter($fl, static fn ($f) => str_starts_with((string) $f, 'authorize:'));
$hasExactCap = static fn (array $fl, string $cap): bool => (bool) array_filter(
    $fl,
    static fn ($f) => $f === 'authorize:' . $cap || str_starts_with((string) $f, 'authorize:' . $cap . ','),
);

// M3 — identity lifecycle mutations + merge submit now carry identity.manage + webcsrf.
foreach ([
    'POST identity/merges',
    'POST identity/accounts/(:segment)/transition',
    'POST identity/accounts/(:segment)/suspend',
    'POST identity/accounts/(:segment)/lock',
    'POST identity/accounts/(:segment)/reactivate',
    'POST identity/accounts/(:segment)/deactivate',
    'POST identity/accounts/(:segment)/anonymize',
] as $k) {
    $fl = $byKey[$k] ?? null;
    $chk("M3: {$k} carries identity.manage + webcsrf",
        $fl !== null && $hasExactCap($fl, 'identity.manage') && $hasCsrf($fl),
        $fl === null ? 'route missing' : '[' . implode(',', $fl) . ']');
}

// J5 — journey/signals now authenticated (capability) + webcsrf.
$fl = $byKey['POST journey/signals'] ?? null;
$chk('J5: journey/signals gated (authorize + webcsrf)',
    $fl !== null && $hasExactCap($fl, 'gamification.manage') && $hasCsrf($fl),
    $fl === null ? 'route missing' : '[' . implode(',', ($fl ?? [])) . ']');

// AC11 — the four previously-CSRF-exempt ACL writes now carry webcsrf.
foreach ([
    'POST access-requests',
    'POST access-assignments',
    'POST access-assignments/(:segment)/revoke',
    'POST break-glass',
] as $k) {
    $fl = $byKey[$k] ?? null;
    $chk("AC11: {$k} carries webcsrf", $fl !== null && $hasCsrf($fl),
        $fl === null ? 'route missing' : '[' . implode(',', $fl) . ']');
}

// G6 — gamification config PATCH/DELETE + campaign lifecycle POSTs now carry webcsrf.
foreach ([
    'PATCH gamification/activity-categories/(:segment)',
    'PATCH gamification/follow-up-types/(:segment)',
    'PATCH gamification/follow-up-methods/(:segment)',
    'PATCH gamification/ranks/(:segment)',
    'PATCH gamification/achievements/(:segment)',
    'PATCH gamification/streak-definitions/(:segment)',
    'PATCH gamification/badges/(:segment)',
    'PATCH gamification/campaigns/(:segment)',
    'PATCH gamification/campaigns/(:segment)/teams/(:segment)',
    'DELETE gamification/config/(:segment)',
    'DELETE gamification/campaigns/(:segment)/tiers/(:segment)',
    'DELETE gamification/campaigns/(:segment)/teams/(:segment)',
    'DELETE gamification/campaigns/(:segment)/members/(:segment)',
    'POST gamification/campaigns/(:segment)/activate',
    'POST gamification/campaigns/(:segment)/cancel',
    'POST gamification/campaigns/(:segment)/progress',
    'POST gamification/campaigns/(:segment)/close',
] as $k) {
    $fl = $byKey[$k] ?? null;
    $chk("G6: {$k} carries webcsrf", $fl !== null && $hasCsrf($fl),
        $fl === null ? 'route missing' : '[' . implode(',', $fl) . ']');
}

// None of the §C routes may reappear on either allowlist.
foreach ([
    'POST identity/merges', 'POST identity/accounts/(:segment)/transition',
    'POST identity/accounts/(:segment)/suspend', 'POST identity/accounts/(:segment)/lock',
    'POST identity/accounts/(:segment)/reactivate', 'POST identity/accounts/(:segment)/deactivate',
    'POST identity/accounts/(:segment)/anonymize', 'POST journey/signals',
    'POST access-requests', 'POST access-assignments',
    'POST access-assignments/(:segment)/revoke', 'POST break-glass',
    'PATCH gamification/activity-categories/(:segment)', 'PATCH gamification/campaigns/(:segment)',
    'DELETE gamification/config/(:segment)',
] as $k) {
    $chk("closed §C not on any allowlist: {$k}", ! in_array($k, $PUBLIC, true) && ! in_array($k, $CSRF_EXEMPT, true));
}

echo "\n";
if ($fail === 0) {
    echo "OK  {$pass} passed, 0 failed\n";
    exit(0);
}
echo "FAIL  {$pass} passed, {$fail} failed\n";
exit(1);
