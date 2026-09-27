<?php

declare(strict_types=1);

/**
 * Geo venues-view i18n + render smoke. Asserts Geo.* key parity across locales,
 * that the SELF-CONTAINED venues view references lang('Geo.*'), includes
 * _locale.php, emits a dynamic <html lang dir>, and renders localized strings
 * with correct direction (RTL for Arabic), localized-with-fallback status,
 * humanized venue type, "{0} of {1}" interpolation, verbatim data, and the
 * not-geolocated / capacity fallbacks.
 *
 *   php app/Modules/Geo/Views/tests/geo_venues_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Geo/Language';
$viewDir = $root . '/app/Modules/Geo/Views';

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

echo "language file completeness\n";
$en     = require $langDir . '/en/Geo.php';
$enKeys = $flatten($en);
chk('en has nested status.active', in_array('status.active', $enKeys, true));
chk('en has heading + count', in_array('heading', $enKeys, true) && in_array('count', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/Geo.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/venues.php");
chk("venues.php calls lang('Geo.", str_contains($src, "lang('Geo."));
chk('venues.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('venues.php includes _locale.php', str_contains($src, '_locale.php'));
chk('venues.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Venue directory', 'Not geolocated', 'No venues match'] as $needle) {
    chk("venues.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Geo') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Geo.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — count interpolation, status label, humanized type, verbatim + fallbacks
$h = $render("$viewDir/venues.php", [
    'venues' => [
        ['name' => 'Central Hall', 'venue_type' => 'fellowship_hall', 'status' => 'active', 'capacity' => 250, 'discovery_status' => 'public', 'latitude' => 6.6885, 'longitude' => -1.6244],
        ['name' => 'Old Annex', 'venue_type' => 'classroom', 'status' => 'weird_status', 'capacity' => null, 'discovery_status' => 'private', 'latitude' => null, 'longitude' => null],
    ],
    'total'  => 12,
    'limit'  => 20,
    'offset' => 0,
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Répertoire des lieux'));
chk('fr count interpolated ({0} of {1})', str_contains($h, '2 sur 12 lieux'));
chk('fr status active -> Actif', str_contains($h, 'Actif'));
chk('fr unknown status falls back (Weird_status)', str_contains($h, 'Weird_status'));
chk('fr venue type humanized verbatim', str_contains($h, 'Fellowship Hall'));
chk('fr venue name verbatim', str_contains($h, 'Central Hall'));
chk('fr coords formatted verbatim', str_contains($h, '6.6885'));
chk('fr not-geolocated fallback translated', str_contains($h, 'Non géolocalisé'));
chk('fr discoverable label translated', str_contains($h, 'Découvrable'));
chk('fr private label translated', str_contains($h, 'Privé'));

// ar — RTL + empty
$ha = $render("$viewDir/venues.php", ['venues' => [], 'total' => 0, 'limit' => 20, 'offset' => 0], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'دليل الأماكن'));
chk('ar empty venues translated', str_contains($ha, 'لا توجد أماكن تطابق عوامل التصفية الحالية.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
