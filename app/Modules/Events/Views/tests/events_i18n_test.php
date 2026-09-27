<?php

declare(strict_types=1);

/**
 * Events i18n test — asserts every locale's Events.php mirrors the English keys
 * (incl. nested enum groups), that the three views reference lang('Events.*')
 * rather than hardcoded English, and that a view renders translated strings with
 * graceful fallback for unknown enum values.
 *
 *   php app/Modules/Events/Views/tests/events_i18n_test.php
 */

$root = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

$flatten = static function (array $a, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        is_array($v) ? $out = array_merge($out, $flatten($v, $key)) : $out[] = $key;
    }
    return $out;
};

echo "language file completeness\n";
$en = require $langDir . '/en/Events.php';
chk('en/Events.php is array', is_array($en));
$enKeys = $flatten($en);
chk('en has title', in_array('title', $enKeys, true));
chk('en has nested status.published', in_array('status.published', $enKeys, true));
chk('en has regPolicy.approval', in_array('regPolicy.approval', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Events.php";
    if (! is_file($f)) {
        chk("$loc/Events.php exists", false);
        continue;
    }
    $keys    = $flatten(require $f);
    $missing = array_diff($enKeys, $keys);
    $extra   = array_diff($keys, $enKeys);
    chk("$loc mirrors all en keys", $missing === [], 'missing: ' . implode(',', $missing));
    chk("$loc has no stray keys", $extra === [], 'extra: ' . implode(',', $extra));
}

echo "views use lang(), not hardcoded English\n";
foreach (['index', 'show', 'attendance'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Events.", str_contains($src, "lang('Events."));
    // Heading must no longer be a bare hardcoded string.
    chk("$v.php no bare <h1>Events</h1>", ! str_contains($src, '<h1>Events</h1>'));
}
$idx = (string) file_get_contents("$viewDir/index.php");
chk('index.php no hardcoded "No events yet."', ! str_contains($idx, 'No events yet.'));
$show = (string) file_get_contents("$viewDir/show.php");
chk('show.php no hardcoded "At a glance"', ! str_contains($show, '>At a glance<'));

echo "render smoke (fr) with fallback\n";
// Minimal lang() over the fr file; unknown keys return the key unchanged (CI4 behaviour).
$fr = require $langDir . '/fr/Events.php';
$GLOBALS['__fr'] = $fr;
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') {
            return $key;
        }
        $v = $GLOBALS['__fr'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key; // missing -> key (so views can detect + fall back)
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

$renderer = new class {
    // Stubs that let section content echo inline (no real layout in the harness).
    function extend($x) { return ''; }
    function section($x) { return ''; }
    function endSection() { return ''; }
    function render(string $file, array $data): string
    {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }
};

$html = $renderer->render("$viewDir/index.php", [
    'result' => ['events' => [
        ['id' => 'e1', 'title' => 'Conférence', 'status' => 'published', 'starts_at' => '2026-10-01 09:00', 'capacity' => 200, 'mode' => 'hybrid'],
        ['id' => 'e2', 'title' => 'Atelier', 'status' => 'weird_unknown_status', 'capacity' => null, 'mode' => 'physical'],
    ]],
]);
chk('fr index shows translated heading Événements', str_contains($html, 'Événements'), substr($html, 0, 120));
chk('fr index translates status published -> publié', str_contains($html, 'publié'));
chk('fr index translates mode hybrid -> hybride', str_contains($html, 'hybride'));
chk('fr index falls back on unknown status', str_contains($html, 'weird_unknown_status'));
chk('fr index shows unlimited symbol for null capacity', str_contains($html, '∞'));
chk('fr index interpolates starts_at value', str_contains($html, '2026-10-01 09:00'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
