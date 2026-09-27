<?php

declare(strict_types=1);

/**
 * VENUE CRUD wiring test — proves the venue directory is no longer read-only:
 * the list table has an Actions column (edit / delete), the venue_form view
 * renders create + edit, the form-page + write routes exist and writes are
 * webcsrf-guarded, and the controller has the form-render + PRG actions. Plus
 * i18n parity for venueForm.* / venueView action keys across all locales and a
 * headless render smoke (fr create + ar edit).
 *
 *   php app/Modules/Geo/Views/tests/geo_venue_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Geo/Language';
$viewDir    = $root . '/app/Modules/Geo/Views';
$controller = $root . '/app/Modules/Geo/Controllers/VenueController.php';
$routesFile = $root . '/app/Config/Routes.php';

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

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "language parity (venueForm.* + venueView action keys)\n";
$en = require $langDir . '/en/Geo.php';
chk('en has venueForm block', isset($en['venueForm']) && is_array($en['venueForm']));
chk('en has venueView block', isset($en['venueView']) && is_array($en['venueView']));
chk('en has venueType vocab', isset($en['venueType']['church']));
chk('en has discovery vocab', isset($en['discovery']['public']));
$formKeys = $flatten($en['venueForm']);
$actionKeys = ['colActions', 'newVenue', 'edit', 'delete', 'deleteConfirm'];
foreach ($actionKeys as $k) {
    chk("en venueView.$k present", isset($en['venueView'][$k]));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Geo.php";
    $lk = isset($l['venueForm']) ? $flatten($l['venueForm']) : [];
    chk("$loc mirrors all en venueForm keys", array_diff($formKeys, $lk) === [], implode(',', array_diff($formKeys, $lk)));
    chk("$loc has no stray venueForm keys", array_diff($lk, $formKeys) === [], implode(',', array_diff($lk, $formKeys)));
    foreach ($actionKeys as $k) {
        chk("$loc venueView.$k present", isset($l['venueView'][$k]));
    }
    chk("$loc venueType church present", isset($l['venueType']['church']));
}

// ── 2. LIST view controls ────────────────────────────────────────────────────
echo "venues.php exposes CRUD controls\n";
$listSrc = (string) file_get_contents("$viewDir/venues.php");
chk('has New venue button', str_contains($listSrc, 'venueView.newVenue'));
chk('links to create form', str_contains($listSrc, 'venues/new'));
chk('has Actions column header', str_contains($listSrc, 'venueView.colActions'));
chk('has per-row edit link', str_contains($listSrc, '/edit'));
chk('has delete POST form', str_contains($listSrc, '/delete') && str_contains($listSrc, 'method="post"'));
chk('delete asks for confirm()', str_contains($listSrc, 'confirm('));
chk('inline form carries _csrf', str_contains($listSrc, 'name="_csrf"'));
chk('renders PRG flash messages', str_contains($listSrc, "session('success')") && str_contains($listSrc, "session('error')"));
chk('url helper is test-safe', str_contains($listSrc, "function_exists('base_url')"));

// ── 3. FORM view ─────────────────────────────────────────────────────────────
echo "venue_form.php is a real create/edit form\n";
$formSrc = (string) file_get_contents("$viewDir/venue_form.php");
chk('posts a form', str_contains($formSrc, '<form method="post"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('has name + type + status fields', str_contains($formSrc, 'name="name"') && str_contains($formSrc, 'name="venue_type"') && str_contains($formSrc, 'name="status"'));
chk('has capacity + discovery + coords fields', str_contains($formSrc, 'name="capacity"') && str_contains($formSrc, 'name="discovery_status"') && str_contains($formSrc, 'name="latitude"') && str_contains($formSrc, 'name="longitude"'));
chk('carries expected_version for optimistic lock', str_contains($formSrc, 'name="expected_version"'));
chk('branches on create vs edit', str_contains($formSrc, '$isEdit'));
chk('includes _locale.php', str_contains($formSrc, "include __DIR__ . '/_locale.php'"));
chk('url helper is test-safe', str_contains($formSrc, "function_exists('base_url')"));

// ── 4. Routes + webcsrf ──────────────────────────────────────────────────────
echo "routes wired + webcsrf on writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET venues/new -> createForm', (bool) preg_match('/get\(\s*.new.\s*,.*VenueController::createForm/', $routes));
chk('GET venues/(:segment)/edit -> editForm', (bool) preg_match('#get\(\s*.\(:segment\)/edit.\s*,.*VenueController::editForm#', $routes));
foreach ([
    'create' => "VenueController::create'",
    'update' => 'VenueController::update/$1',
    'delete' => "VenueController::delete/\$1', ['filter' => ['authorize:venue.manage', 'webcsrf']]",
] as $label => $needle) {
    if (preg_match('/^.*' . preg_quote($needle, '/') . '.*$/m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label route webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}
chk('browser delete uses POST venues/{id}/delete', str_contains($routes, "VenueController::delete/\$1"));

// ── 5. Controller actions ────────────────────────────────────────────────────
echo "controller actions\n";
$ctrl = (string) file_get_contents($controller);
foreach (['createForm', 'editForm'] as $fn) {
    chk("VenueController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create redirects on browser success (PRG)', str_contains($ctrl, "redirect()->to('/venues/'"));
chk('delete redirects to /venues', str_contains($ctrl, "redirect()->to('/venues')"));
chk('index passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — venue_form (fr create, ar edit)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__vLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Geo') { return $key; }
        $v = $GLOBALS['__vLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__vLoc']  = $loc;
    $GLOBALS['__vLang'] = require $langDir . "/$loc/Geo.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$hCreate = $render("$viewDir/venue_form.php", ['csrf' => 'T1', 'mode' => 'create', 'venue' => []], 'fr');
chk('create: fr lang=fr dir=ltr', str_contains($hCreate, 'lang="fr"') && str_contains($hCreate, 'dir="ltr"'));
chk('create: heading translated', str_contains($hCreate, 'Nouveau lieu'));
chk('create: posts to /venues', str_contains($hCreate, 'action="/venues"'));
chk('create: no expected_version hidden field', ! str_contains($hCreate, 'name="expected_version"'));

$hEdit = $render("$viewDir/venue_form.php", [
    'csrf' => 'T2', 'mode' => 'edit',
    'venue' => ['id' => 'v-1', 'name' => 'Central Hall', 'venue_type' => 'fellowship_hall',
        'status' => 'maintenance', 'capacity' => 250, 'discovery_status' => 'public',
        'latitude' => 5.6037, 'longitude' => -0.187, 'version' => 3],
], 'ar');
chk('edit: ar lang=ar dir=rtl', str_contains($hEdit, 'lang="ar"') && str_contains($hEdit, 'dir="rtl"'));
chk('edit: posts to /venues/v-1', str_contains($hEdit, 'action="/venues/v-1"'));
chk('edit: name prefilled', str_contains($hEdit, 'value="Central Hall"'));
chk('edit: expected_version=3 hidden', str_contains($hEdit, 'name="expected_version" value="3"'));
chk('edit: fellowship_hall type selected', (bool) preg_match('/value="fellowship_hall" selected/', $hEdit));
chk('edit: maintenance status selected', (bool) preg_match('/value="maintenance" selected/', $hEdit));
chk('edit: public discovery selected', (bool) preg_match('/value="public" selected/', $hEdit));
chk('edit: capacity prefilled', str_contains($hEdit, 'value="250"'));
chk('edit: coords prefilled', str_contains($hEdit, 'value="5.6037"') && str_contains($hEdit, 'value="-0.187"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
