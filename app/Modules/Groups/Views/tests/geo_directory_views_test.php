<?php

declare(strict_types=1);

/**
 * Global→local drill-down VIEWS — i18n parity + CSP-safe render smoke for BOTH
 * new directory pages (Groups+Venues+Geo unification):
 *   • public  /g/map           -> Groups/Views/public_geo_directory.php
 *   • admin   /venues/directory -> Geo/Views/venue_geo_directory.php
 *
 * Pins:
 *   - Groups.geoDirectory.* parity across the 6 locales; Geo.venueDirectory.*
 *     parity across the 6 locales;
 *   - both routes are registered, declared BEFORE their catch-alls, with the
 *     right access (public map is auth-free; venue directory is venue.manage);
 *   - CSP-safe render (no <script>, no inline on* handlers) for each view;
 *   - the drill-down actually nests Country ▸ State ▸ City ▸ Venue ▸ groups and
 *     the public cards carry a per-group Join link;
 *   - ar renders RTL (dir="rtl"); no leaked lang keys.
 *
 *   php app/Modules/Groups/Views/tests/geo_directory_views_test.php
 */

$root       = dirname(__DIR__, 5);
$gLangDir   = $root . '/app/Modules/Groups/Language';
$geoLangDir = $root . '/app/Modules/Geo/Language';
$pubView    = $root . '/app/Modules/Groups/Views/public_geo_directory.php';
$admView    = $root . '/app/Modules/Geo/Views/venue_geo_directory.php';
$routes     = $root . '/app/Config/Routes.php';

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
    sort($o);
    return $o;
};

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "i18n: Groups.geoDirectory.* parity across 6 locales\n";
$en     = require $gLangDir . '/en/Groups.php';
$enKeys = $flatten($en['geoDirectory'] ?? []);
chk('en geoDirectory block present (>= 14 keys)', count($enKeys) >= 14, (string) count($enKeys));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $gLangDir . "/$loc/Groups.php";
    $keys = $flatten($m['geoDirectory'] ?? []);
    chk("$loc mirrors en geoDirectory keys", $keys === $enKeys, 'diff: ' . implode(',', array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys))));
}

echo "i18n: Geo.venueDirectory.* parity across 6 locales\n";
$geoEn     = require $geoLangDir . '/en/Geo.php';
$geoEnKeys = $flatten($geoEn['venueDirectory'] ?? []);
chk('en venueDirectory block present (>= 18 keys)', count($geoEnKeys) >= 18, (string) count($geoEnKeys));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $geoLangDir . "/$loc/Geo.php";
    $keys = $flatten($m['venueDirectory'] ?? []);
    chk("$loc mirrors en venueDirectory keys", $keys === $geoEnKeys, 'diff: ' . implode(',', array_merge(array_diff($geoEnKeys, $keys), array_diff($keys, $geoEnKeys))));
}

// ── 2. Routes ────────────────────────────────────────────────────────────────
echo "routes: /g/map and /venues/directory\n";
$rt = (string) file_get_contents($routes);
$posMap  = strpos($rt, "GroupPublicController::geoDirectory");
$posSlug = strpos($rt, "GroupPublicController::page/\$1");
chk('/g/map route present', $posMap !== false);
chk('/g/map declared before g/(:segment)', $posMap !== false && $posSlug !== false && $posMap < $posSlug);
chk('/g/map is auth-free (public discovery)', (bool) preg_match("#get\\('g/map'[^\\n]*#", $rt, $mm) && ! str_contains($mm[0] ?? '', "'filter'"));

$posDir  = strpos($rt, "VenueController::geoDirectory");
$posVSeg = strpos($rt, "VenueController::show/\$1");
chk('/venues/directory route present', $posDir !== false);
chk('/venues/directory before venues/(:segment)', $posDir !== false && $posVSeg !== false && $posDir < $posVSeg);
chk('/venues/directory gated venue.manage', (bool) preg_match("#get\\('directory'[^\\n]*#", $rt, $md) && str_contains($md[0] ?? '', 'authorize:venue.manage'));

// ── 3. CSP-safety ────────────────────────────────────────────────────────────
echo "CSP-safety: no script / inline handlers\n";
foreach (['public_geo_directory' => $pubView, 'venue_geo_directory' => $admView] as $name => $file) {
    $src = (string) file_get_contents($file);
    $noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    chk("$name: no <script>", ! str_contains($noC, '<script'));
    chk("$name: no inline on* handlers", ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));
    chk("$name: uses native <details> disclosure (no-JS drill-down)", str_contains($noC, '<details'));
}

// ── 4. Render smoke ──────────────────────────────────────────────────────────
echo "render smoke\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { public function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return 'https://public.test/' . ltrim((string) $p, '/'); }
}
if (! class_exists('Config\\Locale')) {
    eval('namespace Config; class Locale { public array $rtl = ["ar","he","fa","ur"]; }');
}
if (! function_exists('view')) {
    function view(string $name, array $data = []) { return '<nav data-partial="' . htmlspecialchars($name, ENT_QUOTES) . '"></nav>'; }
}

$mkLang = static function (string $langFile, string $topBase) {
    // Returns a lang() reading the right module map by top-namespace.
    return $langFile;
};

// A generic lang() dispatching on the first segment (Groups.* / Geo.*).
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p    = explode('.', $key);
        $top  = array_shift($p);
        $v    = $GLOBALS['__lang'][$top] ?? null;
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}

$loadLang = static function (string $loc) use ($gLangDir, $geoLangDir): void {
    $GLOBALS['__gLoc'] = $loc;
    $GLOBALS['__lang'] = [
        'Groups' => require $gLangDir . "/$loc/Groups.php",
        'Geo'    => require $geoLangDir . "/$loc/Geo.php",
    ];
};

// Public tree fixture: Country ▸ State ▸ City ▸ Venue ▸ groups + a No-venue node.
$pubTree = [[
    'key' => 'co-1', 'label' => 'Ghana', 'level' => 'country', 'count' => 2,
    'children' => [[
        'key' => 'st-1-9', 'label' => 'Greater Accra', 'level' => 'state', 'count' => 2, '_nospec' => false,
        'children' => [[
            'key' => 'ci-1-9-3', 'label' => 'Accra', 'level' => 'city', 'count' => 2, '_nospec' => false,
            'children' => [
                ['key' => 've-1', 'label' => 'Grace Chapel', 'level' => 'venue', 'count' => 1, '_nospec' => false,
                    'venue_type' => 'church', 'venue_capacity' => 800, 'venue_address' => '12 High St',
                    'groups' => [['slug' => 'accra-central', 'name' => 'Accra Central', 'tagline' => 'Welcome', 'type' => 'assembly', 'contact_email' => 'a@b.co', 'contact_phone' => null, 'location_text' => null]]],
                ['key' => 've-nov', 'label' => '', 'level' => 'venue', 'count' => 1, '_nospec' => true,
                    'venue_type' => null, 'venue_capacity' => null, 'venue_address' => null,
                    'groups' => [['slug' => 'roam', 'name' => 'Accra Roaming', 'tagline' => null, 'type' => null, 'contact_email' => null, 'contact_phone' => null, 'location_text' => null]]],
            ],
        ]],
    ]],
]];

$loadLang('fr');
ob_start();
$tree = $pubTree;
include $pubView;
$pubOut = (string) ob_get_clean();
chk('public: renders Country/State/City/Venue chain', str_contains($pubOut, 'Ghana') && str_contains($pubOut, 'Greater Accra') && str_contains($pubOut, 'Accra') && str_contains($pubOut, 'Grace Chapel'));
chk('public: group card + per-group Join link', str_contains($pubOut, 'Accra Central') && str_contains($pubOut, '/g/accra-central/join'));
chk('public: No-venue node uses localized label', str_contains($pubOut, 'Aucun lieu de réunion défini'));
chk('public (fr): no leaked Groups.geoDirectory keys', ! str_contains($pubOut, 'Groups.geoDirectory.'));

$loadLang('ar');
ob_start();
$tree = $pubTree;
include $pubView;
$pubAr = (string) ob_get_clean();
chk('public (ar): renders RTL', str_contains($pubAr, 'dir="rtl"'));
chk('public (ar): no leaked keys', ! str_contains($pubAr, 'Groups.geoDirectory.'));

// Admin tree fixture.
$admTree = [[
    'key' => 'co-1', 'label' => 'Ghana', 'level' => 'country', 'count' => 1,
    'children' => [[
        'key' => 'st-1-9', 'label' => 'Greater Accra', 'level' => 'state', 'count' => 1, '_nospec' => false,
        'children' => [[
            'key' => 'ci-1-9-3', 'label' => 'Accra', 'level' => 'city', 'count' => 1, '_nospec' => false,
            'venues' => [[
                'id' => 'V1', 'name' => 'Grace Chapel', 'venue_type' => 'church', 'status' => 'active',
                'discovery_status' => 'private', 'capacity' => 800,
                'groups' => [
                    ['slug' => 'accra-central', 'name' => 'Accra Central', 'assignment_type' => 'primary'],
                    ['slug' => 'legon', 'name' => 'Legon Fellowship', 'assignment_type' => 'secondary'],
                ],
            ]],
        ]],
    ]],
]];

$loadLang('es');
ob_start();
$tree = $admTree;
include $admView;
$admOut = (string) ob_get_clean();
chk('admin: renders Country/State/City/Venue chain', str_contains($admOut, 'Ghana') && str_contains($admOut, 'Grace Chapel'));
chk('admin: shows assigned groups + assignment chips', str_contains($admOut, 'Accra Central') && str_contains($admOut, 'Legon Fellowship') && str_contains($admOut, 'Principal') && str_contains($admOut, 'Secundario'));
chk('admin: private venue flagged (localized)', str_contains($admOut, 'Privado'));
chk('admin: edit link to venue', str_contains($admOut, 'venues/V1/edit'));
chk('admin (es): no leaked Geo.venueDirectory keys', ! str_contains($admOut, 'Geo.venueDirectory.'));

$loadLang('ar');
ob_start();
$tree = $admTree;
include $admView;
$admAr = (string) ob_get_clean();
chk('admin (ar): renders RTL', str_contains($admAr, 'dir="rtl"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
