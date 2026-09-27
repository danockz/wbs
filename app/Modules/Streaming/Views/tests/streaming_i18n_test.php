<?php

declare(strict_types=1);

/**
 * Streaming i18n test — asserts every locale's Streaming.php mirrors the English
 * keys (incl. nested status/access/exactness/role/overlayType enum groups), that
 * the three views reference lang('Streaming.*') rather than hardcoded English,
 * and that views render translated strings with graceful fallback for unknown
 * enum values, preserve numeric output, and interpolate {0} placeholders.
 *
 * These views extend the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation + interpolation.
 *
 *   php app/Modules/Streaming/Views/tests/streaming_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Streaming/Language';
$viewDir = $root . '/app/Modules/Streaming/Views';

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
$en     = require $langDir . '/en/Streaming.php';
$enKeys = $flatten($en);
chk('en has nested status.live', in_array('status.live', $enKeys, true));
chk('en has nested access.restricted', in_array('access.restricted', $enKeys, true));
chk('en has nested exactness.estimated', in_array('exactness.estimated', $enKeys, true));
chk('en has nested role.cohost', in_array('role.cohost', $enKeys, true));
chk('en has nested overlayType.lower_third', in_array('overlayType.lower_third', $enKeys, true));
chk('en started keeps {0}', str_contains((string) $en['started'], '{0}'));
chk('en noCohosts keeps {0}', str_contains((string) $en['noCohosts'], '{0}'));
chk('en consoleNote keeps {0}', str_contains((string) $en['consoleNote'], '{0}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Streaming.php";
    if (! is_file($f)) {
        chk("$loc/Streaming.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc started keeps {0}", str_contains((string) ($arr['started'] ?? ''), '{0}'));
    chk("$loc consoleNote keeps {0}", str_contains((string) ($arr['consoleNote'] ?? ''), '{0}'));
}

echo "views use lang(), not hardcoded English\n";
foreach (['index', 'dashboard', 'overlays'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Streaming.", str_contains($src, "lang('Streaming."));
}
$bare = [
    ['index', '<h1>Streams</h1>'],
    ['dashboard', '<h2>Engagement</h2>'],
    ['dashboard', '<h2>Destinations</h2>'],
    ['dashboard', '<h2>Provider metrics</h2>'],
    ['overlays', 'Overlays &amp; co-hosts ·'],
];
foreach ($bare as [$v, $needle]) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Streaming') {
            return $key;
        }
        $v = $GLOBALS['__sLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return '/' . ltrim((string) $p, '/'); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__sFlash'][$k] ?? null; }
}
$GLOBALS['__sFlash'] = [];
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__sLang'] = require $langDir . "/$loc/Streaming.php";
    $renderer           = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data) {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

// fr index — heading, plural, status label + unknown status fallback + started interpolation
$h = $render("$viewDir/index.php", [
    'result' => ['streams' => [
        ['id' => 's1', 'title' => 'Sunday', 'status' => 'live', 'access_policy' => 'public', 'started_at' => '2026-10-01'],
        ['id' => 's2', 'title' => 'Odd', 'status' => 'weird_status', 'access_policy' => 'mystery_policy'],
    ]],
], 'fr');
chk('fr index heading translated', str_contains($h, '<h1>Diffusions</h1>'));
chk('fr index liveFirst translated', str_contains($h, 'les diffusions en direct d’abord'));
chk('fr index status live -> en direct', str_contains($h, 'en direct'));
chk('fr index unknown status falls back', str_contains($h, 'weird_status'));
chk('fr index access public translated', str_contains($h, 'publique'));
chk('fr index unknown access falls back', str_contains($h, 'mystery_policy'));
chk('fr index started interpolated', str_contains($h, 'démarrée 2026-10-01'));
chk('fr index stream plural', str_contains($h, '2 diffusions'));

// singular check
$h1 = $render("$viewDir/index.php", [
    'result' => ['streams' => [['id' => 's1', 'title' => 'One', 'status' => 'live', 'access_policy' => 'public']]],
], 'fr');
chk('fr index stream singular', (bool) preg_match('/1 diffusion\b/u', $h1) && ! str_contains($h1, '1 diffusions'));

// ar dashboard — RTL locale strings + exactness fallback + numbers preserved
$h = $render("$viewDir/dashboard.php", [
    'result' => [
        'stream' => ['title' => 'الأحد', 'status' => 'live', 'access_policy' => 'restricted', 'started_at' => '2026-10-01'],
        'engagement' => ['chat_messages' => 12, 'polls' => 3, 'poll_votes' => 40, 'archived_recordings' => 2],
        'destinations' => [['provider' => 'YouTube', 'label' => 'Main', 'status' => 'live']],
        'provider_metrics' => [
            ['metric' => 'concurrent_viewers', 'value' => 87, 'source' => 'youtube', 'exactness' => 'estimated', 'retrieved_at' => '12:00'],
            ['metric' => 'unknown', 'value' => 0, 'source' => 'x', 'exactness' => 'weird_exact', 'retrieved_at' => '12:01'],
        ],
    ],
], 'ar');
chk('ar dashboard status live translated', str_contains($h, 'مباشر'));
chk('ar dashboard engagement heading translated', str_contains($h, 'التفاعل'));
chk('ar dashboard chat label translated', str_contains($h, 'رسائل الدردشة'));
chk('ar dashboard exactness estimated translated', str_contains($h, 'تقديري'));
chk('ar dashboard unknown exactness falls back', str_contains($h, 'weird_exact'));
chk('ar dashboard source interpolated', str_contains($h, 'المصدر youtube'));
chk('ar dashboard numbers preserved', str_contains($h, '>12<') && str_contains($h, '>40<'));
chk('ar dashboard provider metric name verbatim', str_contains($h, 'concurrent_viewers'));

// fr overlays — role/type vocabularies localized + join token + version + cause fallback
$h = $render("$viewDir/overlays.php", [
    'result' => [
        'stream' => ['id' => 'X1', 'title' => 'Culte', 'status' => 'live', 'access_policy' => 'public'],
        'cohost_roles' => ['guest', 'moderator'],
        'overlay_types' => ['lower_third', 'quote_card'],
        'cohosts' => [
            ['user_id' => 'u1', 'role' => 'presenter', 'status' => 'joined', 'token_active' => true, 'token_expires_at' => '13:00', 'joined_at' => '12:30'],
        ],
        'overlays' => [
            ['overlay_type' => 'cause_progress', 'version' => 2, 'visible' => true, 'updated_at' => '12:45',
                'config' => ['raised_minor' => 5000, 'currency' => 'USD', 'target_minor' => 10000, 'percent' => 50]],
        ],
    ],
], 'fr');
chk('fr overlays heading translated', str_contains($h, 'Incrustations et co-animateurs'));
// NB: roles/types available-vocab lists only render in the EMPTY states; with
// cohosts/overlays populated here we assert the per-item role/type labels below
// and the empty-state vocab localization in the ar render further down.
chk('fr overlays cause_progress type via config', str_contains($h, 'progression de la cause'));
chk('fr overlays role presenter translated', str_contains($h, 'présentateur'));
chk('fr overlays status joined translated', str_contains($h, 'connecté'));
chk('fr overlays token active translated', str_contains($h, 'jeton de connexion actif'));
chk('fr overlays token expires interpolated', str_contains($h, 'expire 13:00'));
chk('fr overlays joined interpolated', str_contains($h, 'connecté 12:30'));
chk('fr overlays version interpolated', str_contains($h, 'v2'));
chk('fr overlays live label translated', str_contains($h, 'en direct'));
chk('fr overlays live-from-ledger translated', str_contains($h, 'en direct depuis le registre'));
chk('fr overlays money preserved', str_contains($h, 'USD 50.00') && str_contains($h, 'USD 100.00'));
chk('fr overlays percent preserved', str_contains($h, '50%'));
chk('fr overlays updated interpolated', str_contains($h, 'mis à jour 12:45'));
chk('fr overlays consoleNote interpolated', str_contains($h, 'Diffusion X1'));

// ar overlays — empty states interpolate localized vocab + cause fallback label
$h = $render("$viewDir/overlays.php", [
    'result' => [
        'stream' => ['id' => 'X2', 'title' => 'بث', 'status' => 'scheduled', 'access_policy' => 'restricted'],
        'cohost_roles' => ['guest'],
        'overlay_types' => ['quote_card'],
        'cohosts' => [],
        'overlays' => [],
    ],
], 'ar');
chk('ar overlays no-cohosts interpolates localized role', str_contains($h, 'ضيف'));
chk('ar overlays no-overlays interpolates localized type', str_contains($h, 'بطاقة اقتباس'));
chk('ar overlays status scheduled translated', str_contains($h, 'مجدول'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
