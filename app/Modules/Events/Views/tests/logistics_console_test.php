<?php

declare(strict_types=1);

/**
 * LOGISTICS plan console — the write face of LogisticsController::planConsole +
 * the seven logistics write actions (create plan / add resource / add seating /
 * assign staff / add supplier / refresh projection).
 *
 * The event logistics page (GET events/{id}/logistics) is now a console: a plan
 * header, read tables for resources/seating/staff/suppliers, and a no-JS PRG form
 * per write action posting to a webcsrf-guarded route with the `_csrf` field. This
 * test covers:
 *   - Events.logistics.* key parity across all 6 locales
 *   - no-plan state shows only "create plan"; with-plan state shows all forms +
 *     projection refresh + read tables
 *   - forms use _csrf, post to the correct routes, required fields present
 *   - RTL for Arabic; flash banners
 *   - controller: planConsole renders the view / JSON for API; each write PRG via
 *     respondLogistics; service planOverview() read helper exists
 *   - routes: GET console + all seven write POSTs webcsrf-guarded
 *
 *   php app/Modules/Events/Views/tests/logistics_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';
require $viewDir . '/tests/view_test_helpers.php';

$ctrl  = file_get_contents($root . '/app/Modules/Events/Controllers/LogisticsController.php');
$svc   = file_get_contents($root . '/app/Modules/Events/Services/LogisticsService.php');
$routes = file_get_contents($root . '/app/Config/Routes.php');

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

echo "language parity (Events.logistics.* across 6 locales)\n";
$en     = require $langDir . '/en/Events.php';
$enKeys = $flatten($en['logistics'] ?? []);
chk('en defines Events.logistics.*', $enKeys !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Events.php";
    $miss = array_diff($enKeys, $flatten($m['logistics'] ?? []));
    chk("$loc logistics.* parity", $miss === [], implode(',', $miss));
}

$render = wbs_events_renderer($langDir);
$file   = $viewDir . '/logistics_plan.php';

echo "\nview: no-plan state\n";
$h = $render($file, [
    'plan' => null, 'resources' => [], 'seating' => [], 'staff' => [], 'suppliers' => [],
    'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('shows create-plan form', str_contains($h, 'action="/events/ev-1/logistics/plan"'));
chk('create-plan form uses _csrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('no-plan hides resource form', ! str_contains($h, '/logistics/resources'));
chk('no-plan hides staff form', ! str_contains($h, '/logistics/staff'));

echo "\nview: with-plan state (all sections)\n";
$h = $render($file, [
    'plan'      => ['status' => 'draft', 'projection_expected' => 120],
    'resources' => [['name' => 'Chairs', 'kind' => 'equipment', 'quantity_planned' => 100, 'quantity_ordered' => 50, 'status' => 'ordered']],
    'seating'   => [['name' => 'Main Hall', 'capacity' => 300]],
    'staff'     => [['user_id' => 'u-7', 'role' => 'usher', 'status' => 'assigned']],
    'suppliers' => [['name' => 'ABC Catering', 'category' => 'food', 'contact' => 'abc@x.co']],
    'eventId'   => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('shows plan status + projection', str_contains($h, 'draft') && str_contains($h, '120'));
chk('refresh-projection form', str_contains($h, 'action="/events/ev-1/logistics/refresh-projection"') && str_contains($h, 'name="show_rate"'));
chk('add-resource form + kind select', str_contains($h, 'action="/events/ev-1/logistics/resources"') && str_contains($h, '>equipment<'));
chk('resource name required', (bool) preg_match('/name="name" required/', $h));
chk('add-seating form', str_contains($h, 'action="/events/ev-1/logistics/seating"'));
chk('assign-staff form (user+role required)', str_contains($h, 'action="/events/ev-1/logistics/staff"') && str_contains($h, 'name="user_id" required') && str_contains($h, 'name="role" required'));
chk('add-supplier form', str_contains($h, 'action="/events/ev-1/logistics/suppliers"'));
chk('lists resource row', str_contains($h, 'Chairs'));
chk('lists seating row', str_contains($h, 'Main Hall'));
chk('lists staff row', str_contains($h, 'usher'));
chk('lists supplier row', str_contains($h, 'ABC Catering'));
chk('every form uses _csrf', substr_count($h, 'name="_csrf" value="TKN"') >= 5 && ! str_contains($h, 'name="webcsrf"'));

echo "\nassign-staff user_id entity-reference picker\n";
$hp = $render($file, [
    'plan'      => ['status' => 'draft', 'projection_expected' => 120],
    'resources' => [], 'seating' => [],
    'staff'     => [['user_id' => 'u-7', 'role' => 'usher', 'status' => 'assigned']], // excluded (already assigned)
    'suppliers' => [],
    'eventId'   => 'ev-1', 'csrf' => 'TKN',
    'roster'    => [
        ['id' => 'u-7', 'display_name' => 'Existing Usher'],  // excluded
        ['id' => 'u-9', 'display_name' => 'Yaa Asante'],      // offered
    ],
], 'en');
chk('staff picker: renders <select id="st-user"> required', str_contains($hp, '<select id="st-user" name="user_id" required>'));
chk('staff picker: offers an unassigned person', str_contains($hp, 'value="u-9"') && str_contains($hp, 'Yaa Asante'));
chk('staff picker: excludes already-assigned staff', ! preg_match('/<option value="u-7"/', $hp));
chk('staff picker: none option present', str_contains($hp, lang('Events.logistics.userNone')));
// no roster → bounded text fallback (unchanged behaviour)
$hpNo = $render($file, [
    'plan' => ['status' => 'draft', 'projection_expected' => 0], 'resources' => [], 'seating' => [],
    'staff' => [], 'suppliers' => [], 'eventId' => 'ev-1', 'csrf' => 'TKN',
], 'en');
chk('staff picker: bounded text fallback when no roster', str_contains($hpNo, 'id="st-user" name="user_id" required maxlength="64"'));

echo "\nview: RTL + confidentiality\n";
$h = $render($file, ['plan' => ['status' => 'draft', 'projection_expected' => 0], 'resources' => [], 'seating' => [], 'staff' => [], 'suppliers' => [], 'eventId' => 'e', 'csrf' => 'T'], 'ar');
chk('ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('no accessibility-needs detail leaked', stripos($h, 'accessibility') === false && ! str_contains($h, '/logistics/needs'));

echo "\ncontroller (source)\n";
chk('planConsole renders logistics_plan view', str_contains($ctrl, 'WBS\Events\Views\logistics_plan'));
chk('planConsole JSON for API', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, 'planOverview'));
chk('planConsole uses renderForm (mints csrf)', str_contains($ctrl, "renderForm('WBS\Events\Views\logistics_plan'"));
chk('respondLogistics PRG helper exists', str_contains($ctrl, 'private function respondLogistics'));
chk('ensurePlan PRG', str_contains($ctrl, "respondLogistics(\n            EventServices::logistics()->ensurePlan") || str_contains($ctrl, "'planReadyFlash'"));
chk('addResource PRG', str_contains($ctrl, "'resourceAddedFlash'"));
chk('addSeatingArea PRG', str_contains($ctrl, "'seatingAddedFlash'"));
chk('assignStaff PRG', str_contains($ctrl, "'staffAssignedFlash'"));
chk('addSupplier PRG', str_contains($ctrl, "'supplierAddedFlash'"));
chk('refreshProjection PRG', str_contains($ctrl, "'projectionRefreshedFlash'"));
chk('flash keys localized under Events.logistics', str_contains($ctrl, "lang('Events.logistics."));

echo "\nservice (source)\n";
chk('planOverview read helper exists', str_contains($svc, 'public function planOverview'));
chk('planOverview returns all sections', str_contains($svc, "'resources'") && str_contains($svc, "'seating'") && str_contains($svc, "'staff'") && str_contains($svc, "'suppliers'"));

echo "\nroutes: console GET + write POSTs webcsrf\n";
chk('GET console route', (bool) preg_match('#/logistics\'.*::planConsole#', $routes));
foreach (['plan', 'resources', 'refresh-projection', 'seating', 'staff', 'suppliers', 'needs'] as $seg) {
    chk("POST logistics/$seg webcsrf", (bool) preg_match('#/logistics/' . preg_quote($seg, '#') . "'.*webcsrf#", $routes));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
