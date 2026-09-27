<?php

declare(strict_types=1);

/**
 * Gamification i18n test — asserts every locale's Gamification.php mirrors the
 * English keys (incl. nested measure/axis/phase groups), that the nine views
 * reference lang('Gamification.*') rather than hardcoded English, and that views
 * render translated strings with graceful fallback for unknown measure/phase
 * values and preserve numeric output.
 *
 * These views extend the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation + interpolation.
 *
 *   php app/Modules/Gamification/Views/tests/gamification_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Gamification/Language';
$viewDir = $root . '/app/Modules/Gamification/Views';

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
$en     = require $langDir . '/en/Gamification.php';
$enKeys = $flatten($en);
chk('en/Gamification.php is array', is_array($en));
chk('en has nested measure.points', in_array('measure.points', $enKeys, true));
chk('en has nested axis.category', in_array('axis.category', $enKeys, true));
chk('en has nested phase.build', in_array('phase.build', $enKeys, true));
chk('en seasonRankedBy keeps {0} and {1}', str_contains((string) $en['seasonRankedBy'], '{0}') && str_contains((string) $en['seasonRankedBy'], '{1}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Gamification.php";
    if (! is_file($f)) {
        chk("$loc/Gamification.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc seasonRankedBy keeps {0}/{1}", str_contains((string) ($arr['seasonRankedBy'] ?? ''), '{0}') && str_contains((string) ($arr['seasonRankedBy'] ?? ''), '{1}'));
    chk("$loc xpSuffix keeps {0}", str_contains((string) ($arr['xpSuffix'] ?? ''), '{0}'));
}

echo "views use lang(), not hardcoded English\n";
$views = ['_board_groups', '_board_subjects', 'achievements', 'balance', 'group_standing', 'ranks', 'standing', 'streaks', 'user_achievements'];
foreach ($views as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Gamification.", str_contains($src, "lang('Gamification."));
}
$bareHeadings = [
    ['achievements', '<h1>Achievements</h1>'],
    ['balance', '<h1>Points balance</h1>'],
    ['group_standing', '<h1>Group standing</h1>'],
    ['ranks', '<h1>Ranks</h1>'],
    ['standing', '<h1>Standing</h1>'],
    ['streaks', '<h1>Streaks</h1>'],
    ['user_achievements', '<h1>Achievements</h1>'],
];
foreach ($bareHeadings as [$v, $needle]) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php no bare $needle", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with fallback\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Gamification') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
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

$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
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

// fr — group board: heading, season/measure interpolation, axis bits, fallback measure
$h = $render("$viewDir/_board_groups.php", [
    'title'  => 'Palmarès des groupes',
    'result' => [
        'season_id' => 'S1',
        'measure'   => 'contribution',
        'category'  => 'Youth',
        'parent'    => 'Region-1',
        'entries'   => [
            ['position' => 1, 'group_id' => 'g1', 'name' => 'Alpha', 'measure_value' => 900, 'depth' => 2, 'members' => 5],
            ['position' => 2, 'group_id' => 'g2', 'measure_value' => 300, 'members' => 1],
        ],
    ],
], 'fr');
chk('fr board_groups translates measure contribution', str_contains($h, 'classé par contribution'));
chk('fr board_groups season interpolated', str_contains($h, 'Saison S1'));
chk('fr board_groups axis category localized', str_contains($h, 'catégorie Youth'));
chk('fr board_groups axis under localized', str_contains($h, 'sous Region-1'));
chk('fr board_groups depth interpolated', str_contains($h, 'profondeur 2'));
chk('fr board_groups member singular', str_contains($h, '1 membre'));

// fr — board with unknown measure falls back to raw value
$h = $render("$viewDir/_board_subjects.php", [
    'title'  => 'x',
    'result' => ['season_id' => 'S2', 'measure' => 'weird_measure', 'within_group' => 'g9', 'entries' => []],
], 'fr');
chk('fr board_subjects unknown measure falls back', str_contains($h, 'weird_measure'));
chk('fr board_subjects within_group localized', str_contains($h, 'dans le groupe g9'));
chk('fr board_subjects empty state translated', str_contains($h, 'Aucun participant classé'));

// fr — achievements: heading, secret pill, phase label, XP, unknown phase fallback
$h = $render("$viewDir/achievements.php", [
    'title'  => 'x',
    'result' => ['achievements' => [
        ['code' => 'a1', 'name' => 'First Win', 'phase' => 'win', 'xp' => 50, 'secret' => true, 'category' => 'starter'],
        ['code' => 'a2', 'name' => 'Odd', 'phase' => 'weird_phase', 'xp' => 10],
    ]],
], 'fr');
chk('fr achievements heading translated', str_contains($h, '<h1>Succès</h1>'));
chk('fr achievements secret label localized', str_contains($h, 'secret'));
chk('fr achievements phase win -> gagner', str_contains($h, 'gagner'));
chk('fr achievements unknown phase falls back', str_contains($h, 'weird_phase'));
chk('fr achievements XP interpolated', str_contains($h, '50 XP'));

// ar — ranks: RTL locale strings + min points interpolation + plural
$h = $render("$viewDir/ranks.php", [
    'title'  => 'x',
    'result' => [
        ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 0],
        ['code' => 'silver', 'name' => 'Silver', 'min_points' => 500],
    ],
], 'ar');
chk('ar ranks heading translated', str_contains($h, 'الرتب'));
chk('ar ranks min points interpolated', str_contains($h, '500') && str_contains($h, 'نقطة'));

// fr — streaks: heading, best/last/frozen interpolation
$h = $render("$viewDir/streaks.php", [
    'title'     => 'x',
    'subjectId' => 's1',
    'result'    => [
        ['streak_code' => 'daily_prayer', 'current_count' => 7, 'best_count' => 12, 'last_event_date' => '2026-09-09', 'freeze_until' => '2026-09-15'],
    ],
], 'fr');
chk('fr streaks heading translated', str_contains($h, 'Séries'));
chk('fr streaks best interpolated', str_contains($h, 'record 12'));
chk('fr streaks frozen interpolated', str_contains($h, 'gelé jusqu’au 2026-09-15'));

// fr — user_achievements: unlocked count + in-progress heading
$h = $render("$viewDir/user_achievements.php", [
    'title'     => 'x',
    'subjectId' => 's1',
    'result'    => [
        'unlocked' => [['achievement_code' => 'a1', 'unlocked_at' => '2026-09-01', 'xp' => 30, 'bonus_points' => 5]],
        'progress' => [['achievement_code' => 'a2', 'current' => 2, 'threshold' => 10]],
    ],
], 'fr');
chk('fr user_achievements unlocked count interpolated', str_contains($h, '1 débloqué'));
chk('fr user_achievements XP + pts interpolated', str_contains($h, '30 XP') && str_contains($h, '+5 pts'));
chk('fr user_achievements in-progress heading', str_contains($h, 'En cours'));

// fr — balance + standing + group_standing quick checks
$h = $render("$viewDir/balance.php", ['title' => 'x', 'result' => ['subject_id' => 's1', 'points' => 123]], 'fr');
chk('fr balance heading translated', str_contains($h, 'Solde de points') && str_contains($h, '123'));
$h = $render("$viewDir/standing.php", ['title' => 'x', 'subjectId' => 's1', 'result' => ['season_id' => 'S1', 'points' => 40, 'position' => 3, 'rank' => 'GOLD']], 'fr');
chk('fr standing heading + position preserved', str_contains($h, '<h1>Classement</h1>') && str_contains($h, '#3'));
$h = $render("$viewDir/group_standing.php", ['title' => 'x', 'result' => ['season_id' => 'S1', 'group_id' => 'g1', 'measure' => 'points', 'measure_value' => 88, 'position' => 2]], 'fr');
chk('fr group_standing heading translated', str_contains($h, 'Classement du groupe') && str_contains($h, '#2'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
