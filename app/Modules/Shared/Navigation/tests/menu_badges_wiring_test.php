<?php

declare(strict_types=1);

/**
 * MENU BADGE wiring test — proves the lazy badge path is fully connected end to
 * end (docs/DYNAMIC-MENU-DESIGN.md §6/§8.5), by static inspection (no DB/boot):
 *
 *   1. the events.mine catalog item declares a `badge` provider-id;
 *   2. Shared\Config\Services::menuBadges() registers that id, backed by the
 *      Events service, passing the resolved hierarchical scope group ids;
 *   3. RegistrationService::upcomingCount() is a resource-light, group-scoped
 *      COUNT that honours the null (org-wide) vs empty ([] => 0) distinction;
 *   4. MenuController::badges() resolves the active scope to a self+descendants
 *      subtree ONCE and returns { badges, scope } with a short private TTL;
 *   5. GET /me/menu/badges is routed behind auth;
 *   6. the SSR nav partial emits data-badge placeholder spans and the client
 *      renderer fetches /me/menu/badges and fills them.
 *
 *   php app/Modules/Shared/Navigation/tests/menu_badges_wiring_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. Catalog item carries the badge id ─────────────────────────────────────
echo "catalog: events.mine declares a badge\n";
$core = (string) file_get_contents($root . '/app/Modules/Shared/Navigation/CoreMenuProvider.php');
chk("events.mine has badge 'events.mine_upcoming'",
    (bool) preg_match("/'events\\.mine'.*?badge:\\s*'events\\.mine_upcoming'/s", $core));

// ── 2. Shared wires the resolver, scope-aware, backed by Events ──────────────
echo "services: menuBadges() registry + events resolver\n";
$svc = (string) file_get_contents($root . '/app/Modules/Shared/Config/Services.php');
chk('menuBadges() factory present', (bool) preg_match('/function menuBadges\(.*?new MenuBadgeProvider\(/s', $svc));
chk('menuBadges uses matching shared key', (bool) preg_match("/function menuBadges\\(.*?getSharedInstance\\('menuBadges'\\)/s", $svc));
chk('registers events.mine_upcoming resolver', str_contains($svc, "register('events.mine_upcoming'"));
chk('resolver calls Events upcomingCount', (bool) preg_match('/eventRegistrations\(\)\s*->\s*upcomingCount\(/s', $svc));
chk('resolver passes the resolved scope group ids', (bool) preg_match('/upcomingCount\(\$ctx->organizationId, \$ctx->userId, \$ctx->scopeGroupIds\)/s', $svc));

// ── 3. Service: resource-light, group-scoped COUNT ───────────────────────────
echo "service: RegistrationService::upcomingCount\n";
$reg = (string) file_get_contents($root . '/app/Modules/Events/Services/RegistrationService.php');
chk('upcomingCount present', str_contains($reg, 'function upcomingCount('));
chk('upcomingCount accepts optional group ids', (bool) preg_match('/function upcomingCount\(string \$organizationId, string \$userId, \?array \$groupIds = null\)/s', $reg));
chk('empty group set => 0 (never widens to org-wide)', (bool) preg_match('/\$groupIds === \[\].*?return 0/s', $reg));
chk('filters to upcoming, registered, not cancelled', (bool) preg_match("/upcomingCount\\(.*?'registered'.*?starts_at >='.*?status !=', 'cancelled'/s", $reg));
chk('is a single COUNT (no per-row fan-out)', (bool) preg_match('/function upcomingCount\(.*?countAllResults\(\)/s', $reg));
chk('scopes by e.group_id when ids given', (bool) preg_match("/function upcomingCount\\(.*?whereIn\\('e\\.group_id'/s", $reg));

// ── 4. Controller: badges() resolves subtree once, returns counts ────────────
echo "controller: MenuController::badges\n";
$ctrl = (string) file_get_contents($root . '/app/Modules/Shared/Controllers/MenuController.php');
chk('badges() action present', str_contains($ctrl, 'public function badges('));
chk('badges() resolves self+descendants subtree once', (bool) preg_match('/function badges\(.*?groupScope\(\).*?descendants\(\$scopeId\)/s', $ctrl));
chk('badges() builds a BadgeContext', (bool) preg_match('/function badges\(.*?new \\\\WBS\\\\Shared\\\\Navigation\\\\BadgeContext\(/s', $ctrl));
chk('badges() asks the registry for all counts', (bool) preg_match('/function badges\(.*?menuBadges\(\)->all\(/s', $ctrl));
chk('badges() returns { badges, scope }', (bool) preg_match("/function badges\\(.*?'badges' => \\\$counts, 'scope' => \\\$scopeId/s", $ctrl));
chk('badges() uses a short private TTL (off the cached tree)', (bool) preg_match('/function badges\(.*?private, max-age=30, must-revalidate/s', $ctrl));
chk('badges() 401s unauthenticated', (bool) preg_match('/function badges\(.*?UNAUTHENTICATED/s', $ctrl));

// ── 5. Route ────────────────────────────────────────────────────────────────
echo "routes: GET /me/menu/badges behind auth\n";
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
chk('me/menu/badges routed to MenuController::badges', (bool) preg_match("#get\\('me/menu/badges'.*?MenuController::badges#", $routes));
chk('me/menu/badges behind auth', (bool) preg_match("#get\\('me/menu/badges'.*?'auth'#", $routes));

// ── 6. SSR partial + client renderer ─────────────────────────────────────────
echo "views/js: placeholder spans + lazy fetch\n";
$nav = (string) file_get_contents($root . '/app/Modules/Shared/Views/_menu_nav.php');
chk('SSR nav emits data-badge placeholder spans (hidden)', str_contains($nav, 'data-badge=') && str_contains($nav, 'wbs-menu__badge'));
$js = (string) file_get_contents($root . '/public/assets/js/menu.js');
chk('client fetches /me/menu/badges', str_contains($js, '/me/menu/badges'));
chk('client fills the data-badge spans', (bool) preg_match('/applyBadges|\.wbs-menu__badge\[data-badge\]/', $js));
chk('client renders a badge span for CSR items', (bool) preg_match("/if \\(it\\.badge\\)/", $js));
chk('client caps overflow at 99+', str_contains($js, "'99+'"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
