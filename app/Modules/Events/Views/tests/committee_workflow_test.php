<?php

declare(strict_types=1);

/**
 * EVENT COMMITTEE workflow + wiring test — the seams that make the feature safe,
 * checked across the source of the routes, controllers, services, DI, sweep,
 * migration, menu, seeder and language files (no framework boot, no database):
 *
 *   1. ROUTES: every committee/plan write is `auth` + `webcsrf`; every route target
 *      is a real controller method; the coarse cross-event lists declare NO new
 *      permission bit (gating is the service's PDP decision); the member paths stay
 *      singular so no other module's route scanner can mistake them.
 *   2. CONTROLLERS: thin and PRG — they read input, call a service, and redirect or
 *      answer JSON. No database access, no authorization of their own, and above all
 *      no `scopeCovers()`: reach is not authority.
 *   3. AUTHORITY SEAM: the adapter asks the platform's decision point WITH the group
 *      (the authoritative per-group question) and refuses when no PDP is wired; the
 *      services ask it for every governance write and for every decision approval.
 *   4. LIFECYCLE: DI passes the same seam to all three services, the close-out hook
 *      runs on both event-closing paths, and the expiry sweep is registered.
 *   5. SCHEMA: one committee per event, one seat per person, delegations cascade to
 *      SET NULL, and the oversight queue has NO expiry column (a request stays
 *      pending until a human decides).
 *   6. SURFACE: the menu item is a personal workspace (no permission mask, localized
 *      in all six locales), the capability ships DISABLED in the demo seeder, and
 *      every literal language key the pages and controllers use actually resolves.
 *
 *   php app/Modules/Events/Views/tests/committee_workflow_test.php
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
$read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);

$routes       = $read('app/Config/Routes.php');
$committeeCtl = $read('app/Modules/Events/Controllers/CommitteeController.php');
$planCtl      = $read('app/Modules/Events/Controllers/EventPlanController.php');
$committeeSvc = $read('app/Modules/Events/Services/CommitteeService.php');
$workSvc      = $read('app/Modules/Events/Services/EventWorkService.php');
$decisionSvc  = $read('app/Modules/Events/Services/CommitteeDecisionService.php');
$adapter      = $read('app/Modules/Events/Services/DelegationAuthorityAdapter.php');
$eventSvc     = $read('app/Modules/Events/Services/EventService.php');
$eventDi      = $read('app/Modules/Events/Config/Services.php');
$sharedDi     = $read('app/Modules/Shared/Config/Services.php');
$sweep        = $read('app/Modules/Events/Sweep/CommitteeAuthorityExpirySweep.php');
$migration    = $read('app/Modules/Events/Database/Migrations/2026-09-21-000090_CreateEventCommittees.php');
$menu         = $read('app/Modules/Shared/Navigation/CoreMenuProvider.php');
$seeder       = $read('app/Modules/Admin/Database/Seeds/AdminConfigSeeder.php');

// ── 1. Routes ─────────────────────────────────────────────────────────────────
echo "routes: gated, webcsrf, real targets, no new permission bits\n";

// Every line that routes to one of the two new controllers.
preg_match_all('/^\s*\$routes->(get|post)\(\'([^\']*)\',\s*\'\\\\WBS\\\\Events\\\\Controllers\\\\(Committee|EventPlan)Controller::([A-Za-z]+)([^\']*)\'(.*)$/m', $routes, $m, PREG_SET_ORDER);
chk('the committee/plan routes are all found', count($m) >= 24, (string) count($m));

$writeNoCsrf = [];
$newBits     = [];
$targets     = [];
foreach ($m as $route) {
    [, $verb, $path, , $method, , $tail] = $route;
    $targets[$method === '' ? '' : $method] = true;
    if ($verb === 'post' && ! str_contains($tail, 'webcsrf')) {
        $writeNoCsrf[] = "$path";
    }
    if (preg_match('/authorize:([a-z_.]+)/', $tail, $perm) === 1) {
        $newBits[] = $perm[1];
    }
}
chk('every committee/plan POST route is webcsrf-guarded', $writeNoCsrf === [], implode(',', $writeNoCsrf));
chk('no committee/plan route invents a permission bit', $newBits === [], implode(',', $newBits));

// Both coarse groups are `auth` at the group level (the service bounds the rest).
chk('the event-committees group is auth-gated', (bool) preg_match("/group\\('event-committees', \\['filter' => 'auth'\\]/", $routes));
chk('the event-plan group is auth-gated', (bool) preg_match("/group\\('event-plan', \\['filter' => 'auth'\\]/", $routes));
chk('the per-event consoles sit inside the events auth group', (bool) preg_match("/get\\('\\(:segment\\)\\/committee', '\\\\WBS\\\\Events\\\\Controllers\\\\CommitteeController::console/", $routes));
chk('the member paths are singular (no cross-module route collision)', str_contains($routes, "member/(:segment)/remove") && ! str_contains($routes, "event-committees/members/(:segment)"));

// Every referenced controller method really exists.
$missing = [];
foreach (array_keys($targets) as $method) {
    if ($method === '') {
        continue;
    }
    $inCommittee = (bool) preg_match('/function ' . preg_quote((string) $method, '/') . '\\(/', $committeeCtl);
    $inPlan      = (bool) preg_match('/function ' . preg_quote((string) $method, '/') . '\\(/', $planCtl);
    if (! $inCommittee && ! $inPlan) {
        $missing[] = (string) $method;
    }
}
chk('every route target is a real controller method', $missing === [], implode(',', $missing));
chk('both consoles are routed', isset($targets['console']));

// ── 2. Controllers stay thin ──────────────────────────────────────────────────
echo "\ncontrollers: thin, PRG, no authorization of their own\n";
foreach (['CommitteeController' => $committeeCtl, 'EventPlanController' => $planCtl] as $name => $src) {
    chk("$name extends BaseController", str_contains($src, 'extends BaseController'));
    chk("$name is final", (bool) preg_match('/^final class ' . $name . '/m', $src));
    chk("$name never touches the database", ! str_contains($src, 'Database::connect') && ! str_contains($src, '$this->db'));
    chk("$name never authorizes by scope coverage", ! str_contains($src, 'scopeCovers'));
    chk("$name takes the actor from the session", str_contains($src, '$this->actorId()'));
    chk("$name answers JSON when asked", str_contains($src, 'wantsJson()') && str_contains($src, 'respondWith('));
    chk("$name PRGs every write", str_contains($src, 'redirect()->to(') && str_contains($src, "with('success'") && str_contains($src, "with('error'"));
    chk("$name reaches services through the DI facade", str_contains($src, 'EventServices::') && ! str_contains($src, 'new CommitteeService') && ! str_contains($src, 'new EventWorkService'));
    chk("$name flashes localized copy", (bool) preg_match("/lang\\('Events\\.(committee|plan|decision)\\./", $src));
}
chk('the committee controller uses the service vocabularies, not literals', str_contains($committeeCtl, 'CommitteeDecisionService::STATUSES') && str_contains($committeeCtl, 'CommitteeDecisionService::EFFECTS'));
chk('the plan controller uses the work-plan vocabulary', str_contains($planCtl, 'WorkPlan::') && str_contains($planCtl, 'CommitteeResponsibility::ALL'));
chk('the plan controller can find the event behind any plan row', str_contains($planCtl, 'eventIdFor('));
chk('the committee controller maps a seat back to its event', str_contains($committeeCtl, 'eventIdForMember('));

// ── 3. The authority seam ─────────────────────────────────────────────────────
echo "\nauthority seam: the PDP decides, with the group\n";
chk('the adapter asks the platform decision point', str_contains($adapter, 'isAllowed(') && str_contains($adapter, 'AccessRequest('));
chk('…and passes the group, making it the authoritative per-group question', str_contains($adapter, "['group_id' => \$groupId]"));
chk('…and fails closed on any PDP error', (bool) preg_match('/catch \\(Throwable\\) \\{\\s*\\n\\s*return false;/s', $adapter));
chk('the adapter refuses when no decision point is wired', (bool) preg_match('/authorization === null.*\n.*return false;/s', $adapter) || str_contains($adapter, '$this->authorization === null'));
chk('the adapter never falls back to scope coverage', ! str_contains($adapter, 'scopeCovers'));
chk('the port declares holds() as part of the contract', str_contains($read('app/Modules/Events/Services/CommitteeAuthorityPort.php'), 'public function holds('));

chk('CommitteeService asks the PDP for governance', str_contains($committeeSvc, "'event.create', \$groupId") || (bool) preg_match("/holdsGovernance\\(/", $committeeSvc));
chk('CommitteeService asks the PDP for the chair\'s own capability', str_contains($committeeSvc, "'event.logistics.manage', \$oversight"));
chk('the work engine gate asks the PDP', str_contains($workSvc, "'event.logistics.manage', \$anchor"));
chk('the work engine gate does NOT accept scope coverage', ! str_contains($workSvc, 'scopeCovers'));
chk('approving a decision asks for the decision\'s own capability', str_contains($decisionSvc, '$required, $oversight') || (bool) preg_match('/holds\\(\\$organizationId, \\$actorId, \\$required/', $decisionSvc));
chk('rejecting demands the same authority as approving', (bool) preg_match("/\\\$status === 'rejected'.*holds\\(/s", $decisionSvc));
chk('the committee service keeps scopeCovers for READ bounds only', str_contains($committeeSvc, 'READ bound'));
chk('delegations are bounded to the member (scope_mode SELF)', str_contains($committeeSvc, 'ScopeMode::SELF'));
chk('delegations are bounded in days', str_contains($committeeSvc, 'MAX_DELEGATION_DAYS') && str_contains($committeeSvc, "'duration_days'"));
chk('a delegation names its purpose (the mandate)', str_contains($committeeSvc, "'purpose'"));

// ── 4. Lifecycle wiring ───────────────────────────────────────────────────────
echo "\nwiring: DI, close-out hook, expiry sweep\n";
chk('DI hands the same seam to the committee service', (bool) preg_match('/eventCommittees.*committeeAuthority\\(\\)/s', $eventDi));
chk('DI hands the seam to the work engine', (bool) preg_match('/eventWork.*committeeAuthority\\(\\)/s', $eventDi));
chk('DI hands the seam to the decision queue', (bool) preg_match('/committeeDecisions.*committeeAuthority\\(\\)/s', $eventDi));
chk('the work engine is given the committee service (seat = authority to plan)', (bool) preg_match('/new EventWorkService\\([^;]*eventCommittees\\(\\)/s', $eventDi));
chk('the decision queue is given the work engine (effects)', (bool) preg_match('/new CommitteeDecisionService\\([^;]*eventWork\\(\\)/s', $eventDi));
chk('EventService is given the committee service', (bool) preg_match('/static::eventCommittees\\(\\)/', $eventDi));

chk('close-out runs on the completing path', substr_count($eventSvc, 'onEventClosed(') >= 2, (string) substr_count($eventSvc, 'onEventClosed('));
chk('close-out reports that the committee was dissolved', str_contains($eventSvc, "'committee_dissolved'"));
chk('the expiry sweep is registered', str_contains($sharedDi, 'CommitteeAuthorityExpirySweep'));
chk('the sweep has a stable key', str_contains($sweep, "'events.committee-expiry'"));
chk('the sweep expires windows through the service, never by hand', str_contains($sweep, 'expireDue(') && ! str_contains($sweep, "table('event_committee_members')"));

// ── 5. Schema ─────────────────────────────────────────────────────────────────
echo "\nschema: one committee per event, one seat per person, no expiry\n";
foreach ([
    'event_committees', 'event_committee_members', 'event_workstreams', 'event_milestones',
    'event_tasks', 'event_task_dependencies', 'event_committee_decisions',
] as $table) {
    chk("migration creates $table", str_contains($migration, "CREATE TABLE IF NOT EXISTS $table"));
}
chk('one committee per event (UNIQUE)', (bool) preg_match('/UNIQUE KEY [a-z_]+ \\(event_id\\)/', $migration) || str_contains($migration, 'UNIQUE KEY ecc_event_uq (event_id)'));
chk('one seat per person per committee (UNIQUE)', str_contains($migration, 'UNIQUE KEY ecm_member_uq (committee_id, user_id)'));
chk('a seat\'s delegation is SET NULL when the grant goes', str_contains($migration, 'REFERENCES delegations(id) ON DELETE SET NULL'));
chk('the oversight queue has NO expiry column', ! (bool) preg_match('/event_committee_decisions.*expires_at/s', $migration) && ! str_contains($migration, 'expires_at'));
chk('decisions record the capability they need', str_contains($migration, 'required_permission'));
chk('decisions record the effect they promise', str_contains($migration, 'effect_json') && str_contains($migration, 'effect_applied'));
chk('seats carry the delegated capability + window', str_contains($migration, 'delegated_permission') && str_contains($migration, 'effective_to'));
chk('tasks carry owner, due date, status, percent and blockers', str_contains($migration, 'assignee_user_id') && str_contains($migration, 'due_at')
    && str_contains($migration, 'progress_pct') && str_contains($migration, 'blocked_reason'));
chk('the dependency graph is its own table', str_contains($migration, 'depends_on_task_id'));
chk('the migration is idempotent', ! str_contains($migration, 'CREATE TABLE event_') || substr_count($migration, 'IF NOT EXISTS') >= 7);
chk('the migration resets the schema cache (CI4 dataCache caveat)', str_contains($migration, 'resetDataCache()'));

// ── 6. Surface: menu, seeder, language ────────────────────────────────────────
echo "\nsurface: menu, seeder, language keys\n";
chk('the menu points at the hub', (bool) preg_match("/MenuItem\\('events\\.committees', \\\$C::EVENTS, '([^']+)', 'event-committees'/", $menu, $mm));
$label = $mm[1] ?? '';
chk('the hub item is a personal workspace (no permission mask)', (bool) preg_match("/MenuItem\\('events\\.committees'[^\\)]*\\)/", $menu, $mi)
    && ! str_contains($mi[0], 'permissions:'), $mi[0] ?? '');
chk('the menu item documents why it is unmasked', str_contains($menu, 'PERSONAL workspace'));
chk('the menu icon is one the nav knows', (bool) preg_match("/MenuItem\\('events\\.committees'[^\\)]*icon: '([a-z-]+)'/", $menu, $ic)
    && str_contains($read('app/Modules/Shared/Views/_menu_nav.php'), "'" . ($ic[1] ?? '') . "'"), $ic[1] ?? '');

foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require $root . "/app/Language/$loc/App.php";
    $has = isset($app['menuItems']['events_committees']) && is_string($app['menuItems']['events_committees']) && $app['menuItems']['events_committees'] !== '';
    chk("$loc has App.menuItems.events_committees", $has);
    if ($loc === 'en') {
        chk('the English label matches the catalog item', ($app['menuItems']['events_committees'] ?? '') === $label, ($app['menuItems']['events_committees'] ?? '') . ' vs ' . $label);
    }
}

chk('the seeder ships the capability DISABLED (default OFF)', (bool) preg_match("/'event_committee'.*'enabled'\\s*=>\\s*false/s", $seeder));
chk('the seeder uses the documented inheritance mode', (bool) preg_match("/'event_committee', 'ancestor_default_child_override'/", $seeder));

// Every literal language key the pages and controllers use must resolve.
$en = require $root . '/app/Modules/Events/Language/en/Events.php';
$resolves = static function (string $dotted) use ($en): bool {
    $v = $en;
    foreach (explode('.', $dotted) as $seg) {
        if (! is_array($v) || ! array_key_exists($seg, $v)) {
            return false;
        }
        $v = $v[$seg];
    }

    return is_string($v) && $v !== '';
};
$surfaces = [
    'committee_console.php' => $read('app/Modules/Events/Views/committee_console.php'),
    'event_plan.php'        => $read('app/Modules/Events/Views/event_plan.php'),
    'committee_hub.php'     => $read('app/Modules/Events/Views/committee_hub.php'),
    'committee_queue.php'   => $read('app/Modules/Events/Views/committee_queue.php'),
    'CommitteeController'   => $committeeCtl,
    'EventPlanController'   => $planCtl,
];
foreach ($surfaces as $name => $src) {
    // Literal keys only: `lang('Events.plan.status' . ucfirst(...))` is built at
    // runtime and is covered by the render tests' raw-key-leak assertions.
    preg_match_all("/lang\\('(Events\\.(?:committee|plan|decision|status)\\.[A-Za-z0-9_.]+)'\\)/", $src, $km);
    $keys    = array_values(array_unique($km[1]));
    $missing = [];
    foreach ($keys as $k) {
        if (! $resolves(substr($k, strlen('Events.')))) {
            $missing[] = $k;
        }
    }
    chk("$name: all " . count($keys) . ' literal language keys resolve', $missing === [], implode(',', $missing));
}

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
