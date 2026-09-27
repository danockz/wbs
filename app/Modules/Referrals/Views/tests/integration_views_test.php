<?php

declare(strict_types=1);

/**
 * Integration decisions — views + wiring test (FR-REF-3b).
 *
 * Renders the two self-contained pages (member self-service + the mentor's
 * confirmation queue) with the real six-locale Referrals language files, then
 * checks the seams in source: routes are auth+webcsrf with real controller
 * targets and no invented permission bit, the controller stays thin and PRG,
 * ContactBookService now validates the catalog + future dates, DI hands the
 * integration service to the Courses and Journey seams, the migration extends
 * `prospect_decisions` idempotently, and the seeder ships the capability
 * DISABLED.
 *
 *   php app/Modules/Referrals/Views/tests/integration_views_test.php
 */

if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { public function getLocale() { return $GLOBALS['__intLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Referrals') { return $key; }
        $v = $GLOBALS['__intLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}

$root    = dirname(__DIR__, 5);
$pass    = 0;
$fail    = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$render = static function (string $view, array $data, string $loc = 'en'): string {
    $GLOBALS['__intLoc']  = $loc;
    $GLOBALS['__intLang'] = require dirname(__DIR__, 5) . "/app/Modules/Referrals/Language/$loc/Referrals.php";
    extract($data, EXTR_SKIP);
    ob_start();
    include dirname(__DIR__, 5) . '/app/Modules/Referrals/Views/' . $view;
    return (string) ob_get_clean();
};

// ---- 1. Render: member self-service --------------------------------------------
echo "render: my integration (en / ar RTL / disabled / integrated)\n";
$groups = ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course'];
$types  = ['salvation', 'water_baptism', 'holy_spirit_baptism', 'foundation_course'];

$on = ['satisfied' => ['salvation'], 'outstanding' => ['water_baptism', 'holy_spirit_baptism', 'foundation_course'], 'integrated' => false, 'enabled' => true, 'byType' => ['salvation' => '2026-08-01']];
$html = $render('integration.php', ['state' => $on, 'declarations' => [], 'groups' => $groups, 'types' => $types, 'csrf' => 'TKN']);
chk('renders locale-aware <html lang dir>', (bool) preg_match('/<html lang="en" dir="ltr">/', $html));
chk('has exactly one POST form to /my/integration', substr_count($html, 'action="/my/integration"') === 1);
chk('_csrf is bound', substr_count($html, 'name="_csrf" value="TKN"') === 1);
chk('shows the not-integrated verdict', str_contains($html, 'Not yet integrated'));
chk('shows the outstanding decisions (baptisms SEPARATE)', str_contains($html, 'Water baptism') && str_contains($html, 'Holy Spirit baptism') && str_contains($html, 'Foundation course'));
chk('no bundled "Baptism" tile remains', ! str_contains($html, '>Baptism</div>') && ! str_contains($html, '>Baptism</'));
chk('shows the recorded date', str_contains($html, '2026-08-01'));
chk('no raw key leaks', ! str_contains($html, 'Referrals.integration.'));
chk('no script tags', ! str_contains($html, '<script'));

$ar = $render('integration.php', ['state' => $on, 'declarations' => [], 'groups' => $groups, 'types' => $types, 'csrf' => 'TKN'], 'ar');
chk('Arabic renders RTL', (bool) preg_match('/<html lang="ar" dir="rtl">/', $ar));

$off = ['satisfied' => [], 'outstanding' => $groups, 'integrated' => false, 'enabled' => false, 'byType' => []];
$offHtml = $render('integration.php', ['state' => $off, 'declarations' => [], 'groups' => $groups, 'types' => $types, 'csrf' => 'TKN']);
chk('disabled state shows the off banner', str_contains($offHtml, 'disabledTitle') === false && str_contains($offHtml, 'not enabled yet'));
chk('disabled state offers NO form', ! str_contains($offHtml, 'action="/my/integration"'));

$all = ['satisfied' => $groups, 'outstanding' => [], 'integrated' => true, 'enabled' => true, 'byType' => ['salvation' => '2026-08-01', 'water_baptism' => '2026-08-10', 'holy_spirit_baptism' => '2026-08-15', 'foundation_course' => '2026-08-20']];
$allHtml = $render('integration.php', ['state' => $all, 'declarations' => [], 'groups' => $groups, 'types' => $types, 'csrf' => 'TKN']);
chk('integrated state shows the ✓ verdict', str_contains($allHtml, 'Integrated'));

// ---- 2. Render: the mentor queue ----------------------------------------------
echo "queue: pending confirmations\n";
$pending = [
    ['id' => 'd1', 'contact_name' => 'Ama Mensah', 'decision_type' => 'salvation', 'decision_date' => '2026-09-01', 'note' => 'at the outreach'],
    ['id' => 'd2', 'contact_name' => 'Kojo', 'decision_type' => 'water_baptism', 'decision_date' => '2026-09-02', 'note' => ''],
];
$q = $render('decisions_queue.php', ['pending' => $pending, 'csrf' => 'TKN']);
chk('queue lists each contact + decision + date', str_contains($q, 'Ama Mensah') && str_contains($q, 'Salvation') && str_contains($q, '2026-09-01') && str_contains($q, 'Water baptism'));
chk('confirm + reject forms hit the right endpoints', substr_count($q, 'action="/me/integration-decisions/confirm/d1"') === 1 && substr_count($q, 'action="/me/integration-decisions/reject/d2"') === 1);
chk('every form carries _csrf (2 rows × confirm+reject)', substr_count($q, 'name="_csrf" value="TKN"') === 4);
chk('queue has no raw key leaks', ! str_contains($q, 'Referrals.integration.'));

$qEmpty = $render('decisions_queue.php', ['pending' => [], 'csrf' => 'TKN']);
chk('empty queue shows the empty state', str_contains($qEmpty, 'Nothing waiting for you.'));

// ---- 3. i18n parity ------------------------------------------------------------
echo "i18n: six locales mirror en, no empty values\n";
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        if (is_array($v)) { $o = array_merge($o, $flatten($v, $key)); } else { $o[$key] = $v; }
    }
    return $o;
};
$en = $flatten(require $root . '/app/Modules/Referrals/Language/en/Referrals.php');
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = $flatten(require $root . "/app/Modules/Referrals/Language/$loc/Referrals.php");
    chk("$loc mirrors en keys", array_diff(array_keys($en), array_keys($l)) === [] && array_diff(array_keys($l), array_keys($en)) === []);
}
$emptyEn = array_filter($en, static fn ($v) => ! is_string($v) || trim($v) === '');
chk('en has no empty values', $emptyEn === [], implode(',', array_keys($emptyEn)));

// ---- 4. Source-level wiring ----------------------------------------------------
echo "wiring: routes, controller, DI, migration, seeder\n";
$routes  = (string) file_get_contents($root . '/app/Config/Routes.php');
$ctl     = (string) file_get_contents($root . '/app/Modules/Referrals/Controllers/IntegrationController.php');
$svcSrc  = (string) file_get_contents($root . '/app/Modules/Referrals/Services/IntegrationService.php');
$cbSrc   = (string) file_get_contents($root . '/app/Modules/Referrals/Services/ContactBookService.php');
$jrnSrc  = (string) file_get_contents($root . '/app/Modules/Journey/Services/JourneyService.php');
$refDi   = (string) file_get_contents($root . '/app/Modules/Referrals/Config/Services.php');
$crsDi   = (string) file_get_contents($root . '/app/Modules/Courses/Config/Services.php');
$jrnDi   = (string) file_get_contents($root . '/app/Modules/Journey/Config/Services.php');
$mig     = (string) file_get_contents($root . '/app/Modules/Referrals/Database/Migrations/2026-09-22-000091_ExtendProspectDecisions.php');
$seeder  = (string) file_get_contents($root . '/app/Modules/Admin/Database/Seeds/AdminConfigSeeder.php');

$routeLines = array_filter(array_map('trim', explode("\n", $routes)), static fn ($l) => str_starts_with($l, '$routes->') && (str_contains($l, 'my/integration') || str_contains($l, 'me/integration-decisions')));
$webcsrfOk = true;
foreach ($routeLines as $l) {
    if (str_starts_with($l, '$routes->post(') && ! str_contains($l, 'webcsrf')) {
        $webcsrfOk = false;
    }
}
chk('every new POST route is auth+webcsrf', $webcsrfOk && count($routeLines) === 5);
chk('no new authorize: bit on the new routes', ! preg_match('/my\/integration.*authorize:|integration-decisions.*authorize:/', $routes));
chk('route targets exist in the controller', (bool) preg_match('/function (mine|declareSelf|queue|confirm|reject)\(/', $ctl));
chk('controller is thin — no DB, no scopeCovers', ! str_contains($ctl, 'Database::connect') && ! str_contains($ctl, '$this->db') && ! str_contains($ctl, 'scopeCovers'));
chk('controller PRGs writes', str_contains($ctl, 'redirect()->to('));
chk('controller answers JSON', str_contains($ctl, 'wantsJson()') && str_contains($ctl, 'respondWith('));
chk('controller reaches the service via the facade', str_contains($ctl, 'ReferralServices::integration'));
chk('service validates the catalog', str_contains($svcSrc, 'isValidType') && str_contains($cbSrc, 'isValidType'));
chk('assisted path refuses future dates', str_contains($cbSrc, 'DECISION_DATE_FUTURE'));
chk('JourneyService consults the gate port', str_contains($jrnSrc, '->gate(') && str_contains($jrnSrc, 'IntegrationGatePort'));
chk('Referrals DI exposes integration()', str_contains($refDi, 'public static function integration('));
chk('Courses DI injects the derivation adapter', str_contains($crsDi, 'IntegrationDecisionsAdapter'));
chk('Journey DI injects the gate adapter', str_contains($jrnDi, 'IntegrationGateAdapter'));
chk('migration adds status/source/user_id/decided_by', str_contains($mig, "'status'") && str_contains($mig, "'source'") && str_contains($mig, "'user_id'") && str_contains($mig, "'decided_by'") && str_contains($mig, "'source_ref'"));
chk('migration declares the two idempotency unique keys', str_contains($mig, 'pd_prospect_uq') && str_contains($mig, 'pd_user_uq'));
chk('migration is idempotent (fieldExists/IF guards)', str_contains($mig, 'resetDataCache()') && str_contains($mig, 'fieldExists'));
chk('seeder ships the capability DISABLED', (bool) preg_match("/'referrals\.integration_decisions'.*'enabled'\\s*=>\\s*false/s", $seeder));

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
