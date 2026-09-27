<?php

declare(strict_types=1);

/**
 * Contributions Phase-4 admin-views test — covers the two bespoke read views that
 * replaced respondAdmin's generic console: group_report and manual_pending.
 * Asserts locale parity for the new key blocks, that each view references
 * lang('Contributions.*') (no hardcoded English), and that they render translated
 * copy with graceful fallback for unknown enum values, empty states, {0}
 * interpolation, minor-unit money formatting and RTL rendering.
 *
 *   php app/Modules/Contributions/Views/tests/admin_views_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Contributions/Language';
$viewDir = $root . '/app/Modules/Contributions/Views';

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
$blocks = ['groupReport', 'manualPending'];
$en     = require $langDir . '/en/Contributions.php';
foreach ($blocks as $b) {
    chk("en has block $b", isset($en[$b]) && is_array($en[$b]));
}
$enLeaves = $flatten(array_intersect_key($en, array_flip($blocks)));
sort($enLeaves);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l      = require $langDir . "/$loc/Contributions.php";
    $leaves = $flatten(array_intersect_key($l, array_flip($blocks)));
    sort($leaves);
    $diff = array_merge(array_diff($enLeaves, $leaves), array_diff($leaves, $enLeaves));
    chk("$loc mirrors en new-block leaves (" . count($leaves) . ')', $diff === [], implode(',', $diff));
}

echo "views reference lang('Contributions.*') and extend layout\n";
foreach (['group_report', 'manual_pending'] as $v) {
    $src = file_get_contents("$viewDir/$v.php");
    chk("$v uses lang('Contributions.", str_contains($src, "lang('Contributions."));
    chk("$v extends layouts/app", str_contains($src, "extend('layouts/app')"));
}

echo "render smoke\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Contributions') {
            return $key;
        }
        $v = $GLOBALS['__cLang'];
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
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
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

// group_report — fr: heading, window interpolation, money minor->major, top causes, monthly
$h = $render("$viewDir/group_report.php", [
    'result' => [
        'group_id' => 'g1', 'days' => 30,
        'totals' => ['count' => 12, 'verified_minor' => 250000, 'pending_minor' => 5000, 'manual_minor' => 10000],
        'by_cause' => [
            ['cause_id' => 'c1', 'cause_name' => 'Building Fund', 'total_minor' => 200000, 'count' => 8],
            ['cause_id' => 'c2', 'cause_name' => 'Missions', 'total_minor' => 50000, 'count' => 4],
        ],
        'monthly' => [['month' => '2026-08', 'total_minor' => 100000], ['month' => '2026-09', 'total_minor' => 150000]],
    ],
    'title' => 'Group giving report',
], 'fr');
chk('groupReport fr heading translated', str_contains($h, 'Rapport de dons du groupe'));
chk('groupReport fr window interpolated', str_contains($h, '30 derniers jours'));
chk('groupReport money formatted (minor->major)', str_contains($h, '2,500.00'));
chk('groupReport cause name verbatim', str_contains($h, 'Building Fund') && str_contains($h, 'Missions'));
chk('groupReport gift count preserved', str_contains($h, '>12<'));
chk('groupReport monthly rows', str_contains($h, '2026-08') && str_contains($h, '2026-09'));

// group_report — empty causes + monthly
$h = $render("$viewDir/group_report.php", [
    'result' => ['group_id' => 'g1', 'days' => 7, 'totals' => [], 'by_cause' => [], 'monthly' => []],
    'title' => 'x',
], 'en');
chk('groupReport empty causes msg', str_contains($h, 'No causes with giving'));
chk('groupReport empty monthly msg', str_contains($h, 'No monthly activity'));

// manual_pending — es: heading, count plural, type vocab, money+currency
$h = $render("$viewDir/manual_pending.php", [
    'result' => [
        ['type' => 'cash', 'stated_value_minor' => 50000, 'currency' => 'GHS', 'valuation_method' => 'counted', 'received_date' => '2026-09-01', 'custodian_id' => 'u2', 'submitted_by' => 'u1', 'evidence_ref' => 'REF-1'],
        ['type' => 'in_kind', 'stated_value_minor' => 30000, 'currency' => 'GHS', 'submitted_by' => 'u3'],
    ],
    'title' => 'Manual — pending',
], 'es');
chk('manualPending es heading translated', str_contains($h, 'Contribuciones manuales'));
chk('manualPending es count plural interpolated', str_contains($h, '2 en espera'));
chk('manualPending es type vocab (cash)', str_contains($h, 'Efectivo'));
chk('manualPending es type vocab (in_kind)', str_contains($h, 'En especie'));
chk('manualPending money + currency', str_contains($h, 'GHS 500.00'));
chk('manualPending evidence rendered', str_contains($h, 'REF-1'));

// manual_pending — unknown type falls back to raw lowercased value
$h = $render("$viewDir/manual_pending.php", [
    'result' => [['type' => 'crypto', 'stated_value_minor' => 100, 'currency' => 'GHS', 'submitted_by' => 'u1']],
    'title' => 'x',
], 'en');
chk('manualPending unknown type fallback', str_contains($h, 'crypto'));
chk('manualPending singular count', str_contains($h, '1 awaiting approval'));

// manual_pending — empty + RTL (ar renders without error and translated heading)
$h = $render("$viewDir/manual_pending.php", ['result' => [], 'title' => 'x'], 'ar');
chk('manualPending empty state (ar)', str_contains($h, 'لا توجد مساهمات يدوية'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
