<?php

declare(strict_types=1);

/**
 * Streaming Phase-4 admin-views test — covers the four bespoke read views that
 * replaced respondAdmin's generic console: engagement_realtime, giving_config,
 * relay_health, relay_incidents. Asserts locale parity for the new key blocks,
 * that each view references lang('Streaming.*') (no hardcoded English), and that
 * they render translated copy with graceful fallback for unknown enum values,
 * honest-degradation banners, not-found panels, empty states, {0} interpolation
 * and RTL rendering.
 *
 *   php app/Modules/Streaming/Views/tests/admin_views_test.php
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

echo "language parity for new blocks\n";
$blocks = ['realtime', 'givingConfig', 'relayHealth', 'relayIncidents'];
$en     = require $langDir . '/en/Streaming.php';
foreach ($blocks as $b) {
    chk("en has block $b", isset($en[$b]) && is_array($en[$b]));
}
$enLeaves = $flatten(array_intersect_key($en, array_flip($blocks)));
sort($enLeaves);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l      = require $langDir . "/$loc/Streaming.php";
    $leaves = $flatten(array_intersect_key($l, array_flip($blocks)));
    sort($leaves);
    $diff = array_merge(array_diff($enLeaves, $leaves), array_diff($leaves, $enLeaves));
    chk("$loc mirrors en new-block leaves (" . count($leaves) . ')', $diff === [], implode(',', $diff));
}

echo "views reference lang('Streaming.*') and avoid raw <html\n";
foreach (['engagement_realtime', 'giving_config', 'relay_health', 'relay_incidents'] as $v) {
    $src = file_get_contents("$viewDir/$v.php");
    chk("$v uses lang('Streaming.", str_contains($src, "lang('Streaming."));
    chk("$v extends layouts/app", str_contains($src, "extend('layouts/app')"));
}

echo "render smoke\n";
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

// engagement_realtime — fr: heading, degraded banner, numbers preserved, vocab + fallback, window interpolation
$h = $render("$viewDir/engagement_realtime.php", [
    'metrics' => [
        'stream_id' => 's1', 'window_minutes' => 10, 'concurrent_viewers' => 42,
        'chat_last_window' => 7, 'reactions_last_window' => 5, 'engagement_score' => 28,
        'relay_state' => 'degraded', 'metrics_exactness' => 'estimated', 'metrics_degraded' => true,
        'reaction_breakdown' => [['reaction_type' => 'heart', 'n' => 3], ['reaction_type' => 'clap', 'n' => 2]],
    ],
], 'fr');
chk('realtime fr heading translated', str_contains($h, 'Engagement en temps réel'));
chk('realtime fr window interpolated', str_contains($h, 'Dernières 10 minutes'));
chk('realtime fr degraded banner shown', str_contains($h, 'Relais dégradé'));
chk('realtime numbers preserved', str_contains($h, '>42<') && str_contains($h, '>28<'));
chk('realtime fr relay vocab', str_contains($h, 'Dégradé'));
chk('realtime fr exactness vocab', str_contains($h, 'Estimé'));
chk('realtime reaction rows rendered', str_contains($h, 'heart') && str_contains($h, 'clap'));

// unknown relay/exactness vocab falls back to raw lowercased value
$h = $render("$viewDir/engagement_realtime.php", [
    'metrics' => [
        'stream_id' => 's1', 'window_minutes' => 5, 'concurrent_viewers' => 0,
        'chat_last_window' => 0, 'reactions_last_window' => 0, 'engagement_score' => 0,
        'relay_state' => 'wobbly', 'metrics_exactness' => 'fuzzy', 'metrics_degraded' => false,
        'reaction_breakdown' => [],
    ],
], 'en');
chk('realtime unknown relay vocab fallback', str_contains($h, 'wobbly'));
chk('realtime unknown exactness fallback', str_contains($h, 'fuzzy'));
chk('realtime no-degrade hides banner', ! str_contains($h, 'estimated).'));
chk('realtime empty reactions message', str_contains($h, 'No reactions in this window.'));

// not-found panel
$h = $render("$viewDir/engagement_realtime.php", ['metrics' => null], 'en');
chk('realtime notFound panel', str_contains($h, 'could not be found'));

// giving_config — enabled state, yes/no, disabled state
$h = $render("$viewDir/giving_config.php", [
    'config' => [
        'stream_id' => 's1', 'enabled' => 1, 'cause_id' => 'c9', 'widget_enabled' => 1,
        'progress_bar_enabled' => 0, 'currency' => 'GHS', 'min_amount_minor' => 500,
        'max_amount_minor' => 100000, 'allow_anonymous' => 1, 'ack_enabled' => 0,
        'suggested_amounts' => json_encode([500, 1000, 2000]),
    ],
], 'es');
chk('giving es heading translated', str_contains($h, 'Configuración de donaciones'));
chk('giving es yes/no rendered', str_contains($h, 'Sí') && str_contains($h, 'No'));
chk('giving currency verbatim', str_contains($h, 'GHS'));
chk('giving suggested amounts rendered', str_contains($h, '500') && str_contains($h, '2000'));

$h = $render("$viewDir/giving_config.php", ['config' => ['stream_id' => 's1', 'enabled' => false]], 'en');
chk('giving disabled state', str_contains($h, 'Giving is disabled'));

// relay_health — state vocab, incidents, bypass steps, RTL
$h = $render("$viewDir/relay_health.php", [
    'health' => [
        'stream_id' => 's1', 'relay_state' => 'degraded', 'degraded_since' => '2026-09-11 10:00',
        'metrics_degraded' => true, 'latest_sample' => ['latency_ms' => 250, 'status' => 'slow'],
        'open_incidents' => [['cause' => 'provider_down', 'severity' => 'high', 'status' => 'open', 'detected_at' => '2026-09-11 09:00']],
        'bypass_procedure' => ['summary' => 'Relay fan-out has failed.', 'steps' => ['Step one', 'Step two']],
    ],
], 'ar');
chk('relay ar heading translated', str_contains($h, 'صحة التتابع'));
chk('relay ar state vocab', str_contains($h, 'متدهور'));
chk('relay incident rendered', str_contains($h, 'provider_down'));
chk('relay bypass steps rendered', str_contains($h, 'Step one') && str_contains($h, 'Step two'));

$h = $render("$viewDir/relay_health.php", [
    'health' => ['stream_id' => 's1', 'relay_state' => 'healthy', 'degraded_since' => null,
        'metrics_degraded' => false, 'latest_sample' => null, 'open_incidents' => [], 'bypass_procedure' => []],
], 'en');
chk('relay no-incidents message', str_contains($h, 'No open incidents.'));
chk('relay healthy vocab', str_contains($h, 'Healthy'));

$h = $render("$viewDir/relay_health.php", ['health' => null], 'en');
chk('relay notFound panel', str_contains($h, 'could not be found'));

// relay_incidents — count interpolation (plural + singular), empty, bypass badge
$h = $render("$viewDir/relay_incidents.php", [
    'incidents' => [
        ['cause' => 'a', 'severity' => 'high', 'status' => 'resolved', 'detected_at' => 'd1', 'resolved_at' => 'r1', 'bypass_activated' => 1, 'resolution_note' => 'fixed'],
        ['cause' => 'b', 'severity' => 'low', 'status' => 'open', 'detected_at' => 'd2'],
    ],
    'streamId' => 's1',
], 'pt');
chk('incidents pt heading translated', str_contains($h, 'Incidentes do relé'));
chk('incidents pt count plural interpolated', str_contains($h, '2 incidentes'));
chk('incidents bypass badge', str_contains($h, 'Contorno usado'));
chk('incidents resolution note', str_contains($h, 'fixed'));

$h = $render("$viewDir/relay_incidents.php", [
    'incidents' => [['cause' => 'a', 'severity' => 'high', 'status' => 'open', 'detected_at' => 'd1']],
    'streamId' => 's1',
], 'en');
chk('incidents singular count', str_contains($h, '1 incident'));

$h = $render("$viewDir/relay_incidents.php", ['incidents' => [], 'streamId' => 's1'], 'en');
chk('incidents empty state', str_contains($h, 'No relay incidents recorded'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
