<?php

declare(strict_types=1);

/**
 * Contributions i18n test — asserts every locale's Contributions.php mirrors the
 * English keys (incl. nested status/frequency enum groups), that the six views
 * reference lang('Contributions.*') rather than hardcoded English, and that
 * views render translated strings with graceful fallback for unknown enum values
 * and preserve numeric/money output.
 *
 * These views extend the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation + interpolation,
 * not per-view <html>.
 *
 *   php app/Modules/Contributions/Views/tests/contributions_i18n_test.php
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

echo "language file completeness\n";
$en     = require $langDir . '/en/Contributions.php';
$enKeys = $flatten($en);
chk('en/Contributions.php is array', is_array($en));
chk('en has donors', in_array('donors', $enKeys, true));
chk('en has nested status.active', in_array('status.active', $enKeys, true));
chk('en has nested frequency.monthly', in_array('frequency.monthly', $enKeys, true));
chk('en percentOfGoal keeps {0}', str_contains((string) $en['percentOfGoal'], '{0}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Contributions.php";
    if (! is_file($f)) {
        chk("$loc/Contributions.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc percentOfGoal keeps {0}", str_contains((string) ($arr['percentOfGoal'] ?? ''), '{0}'));
    chk("$loc consecutiveMonths keeps {0}", str_contains((string) ($arr['consecutiveMonths'] ?? ''), '{0}'));
}

echo "views use lang(), not hardcoded English\n";
$views = ['cause_donors', 'cause_progress', 'commitments', 'metrics', 'partnership_status', 'partnership_tiers'];
foreach ($views as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Contributions.", str_contains($src, "lang('Contributions."));
}
$pairs = [
    ['cause_donors', '<h1>Donors</h1>'],
    ['cause_progress', '<h1>Cause progress</h1>'],
    ['commitments', '<h1>Giving commitments</h1>'],
    ['metrics', '<h1>Giving metrics</h1>'],
    ['partnership_status', '<h1>Partnership status</h1>'],
    ['partnership_tiers', '<h1>Partnership tiers</h1>'],
];
foreach ($pairs as [$v, $needle]) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php no bare $needle", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback\n";
$curLoc = 'fr';
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
                return $key; // missing -> key, so views can detect + fall back
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
if (! function_exists('redact_name')) {
    function redact_name(string $n): string
    {
        return $n; // identity for the harness; production redaction untested here
    }
}
if (! function_exists('time_tag')) {
    function time_tag($t): string
    {
        return '<time>' . htmlspecialchars((string) $t) . '</time>';
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
    $renderer           = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
        function run(string $file, array $data): string
        {
            $this->__data = $data;
            extract($data);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        }
    };
    // The view uses $this->extend/section — bind $this to the renderer.
    $bound = Closure::bind(function () use ($file, $data) {
        extract($data);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

// fr — cause_progress: heading, percent interpolation, money preserved
$h = $render("$viewDir/cause_progress.php", [
    'result' => ['cause_id' => 'c1', 'currency' => 'GHS', 'raised_minor' => 150000, 'target_minor' => 500000, 'percent' => 30, 'donor_count' => 12, 'target_count' => 100, 'status' => 'open'],
    'title'  => 'x',
], 'fr');
chk('fr cause_progress heading translated', str_contains($h, 'Progression de la cause'));
chk('fr cause_progress percent interpolated', str_contains($h, '30') && str_contains($h, 'de l’objectif'));
chk('fr cause_progress money preserved', str_contains($h, '1,500.00') && str_contains($h, 'GHS'));
chk('fr cause_progress Raised label translated', str_contains($h, 'Collecté'));

// fr — commitments: enum label + frequency + fallback for unknown status
$h = $render("$viewDir/commitments.php", [
    'result' => [
        ['id' => 'a', 'amount_minor' => 5000, 'currency' => 'GHS', 'frequency' => 'monthly', 'status' => 'active', 'next_due_at' => '2026-10-01', 'created_at' => '2026-01-01'],
        ['id' => 'b', 'amount_minor' => 9900, 'currency' => 'GHS', 'frequency' => 'weird_freq', 'status' => 'weird_status'],
    ],
    'subjectId' => 's1',
    'title'     => 'x',
], 'fr');
chk('fr commitments heading translated', str_contains($h, 'Engagements de don'));
chk('fr commitments status active -> actif', str_contains($h, 'actif'));
chk('fr commitments frequency monthly -> mensuel', str_contains($h, 'mensuel'));
chk('fr commitments falls back on unknown status', str_contains($h, 'weird_status'));
chk('fr commitments falls back on unknown frequency', str_contains($h, 'weird_freq'));

// fr — cause_donors: heading, anonymous label, count plural
$h = $render("$viewDir/cause_donors.php", [
    'result' => [
        ['subject_id' => null, 'display_name' => 'Anonymous', 'amount_minor' => 2000, 'currency' => 'GHS', 'given_at' => '2026-09-01'],
        ['subject_id' => 'u2', 'display_name' => 'Amélie Dupont', 'amount_minor' => 3000, 'currency' => 'GHS'],
    ],
    'causeId' => 'c1',
    'title'   => 'x',
], 'fr');
chk('fr donors heading translated', str_contains($h, 'Donateurs'));
chk('fr donors anonymous label localized', str_contains($h, 'Anonyme'));
chk('fr donors plural gifts', str_contains($h, ' dons'));

// ar — partnership_tiers: RTL locale strings + min-months interpolation
$h = $render("$viewDir/partnership_tiers.php", [
    'result' => [
        ['code' => 'gold', 'name' => 'Gold', 'min_pgv_minor' => 100000, 'min_consecutive_months' => 6, 'color' => '#f5c518'],
    ],
    'title' => 'x',
], 'ar');
chk('ar tiers heading translated', str_contains($h, 'مستويات الشراكة'));
chk('ar tiers min months interpolated', str_contains($h, '6') && str_contains($h, 'أشهر متتالية'));

// fr — metrics + partnership_status quick checks
$h = $render("$viewDir/metrics.php", ['result' => ['subject_id' => 's1', 'pgv_minor' => 1000, 'ggv_minor' => 2000, 'ggv_raw_minor' => 2500, 'multiplier_bps' => 12500, 'direct_recruits' => 3, 'downline_size' => 40, 'computed_at' => '2026-09-01'], 'title' => 'x'], 'fr');
chk('fr metrics heading translated', str_contains($h, 'Indicateurs de don'));
chk('fr metrics multiplier preserved', str_contains($h, '1.25×'));
$h = $render("$viewDir/partnership_status.php", ['result' => ['subject_id' => 's1', 'pgv_minor' => 1000, 'ggv_minor' => 2000, 'direct_recruits' => 3, 'downline_size' => 40, 'current_level_code' => 'GOLD', 'consecutive_months_given' => 5, 'last_giving_at' => '2026-09-01'], 'title' => 'x'], 'fr');
chk('fr status heading translated', str_contains($h, 'Statut de partenariat'));
chk('fr status level code preserved', str_contains($h, 'GOLD'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
