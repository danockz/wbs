<?php

declare(strict_types=1);

/**
 * Reporting i18n test — asserts every locale's Reporting.php mirrors the English
 * keys (nested funnel/member groups), that the two views reference
 * lang('Reporting.*') rather than hardcoded English, and that views render
 * translated strings, interpolate {0}/{1} placeholders, keep free-form service
 * data verbatim, and preserve numeric output.
 *
 * Both views extend the shared layouts/app (which already emits dynamic
 * <html lang dir>), so this test focuses on copy translation + interpolation.
 *
 *   php app/Modules/Reporting/Views/tests/reporting_i18n_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Reporting/Language';
$viewDir = $root . '/app/Modules/Reporting/Views';

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
$en     = require $langDir . '/en/Reporting.php';
$enKeys = $flatten($en);
chk('en has nested funnel.title', in_array('funnel.title', $enKeys, true));
chk('en has nested member.welcome', in_array('member.welcome', $enKeys, true));
chk('en welcome keeps {0}', str_contains((string) $en['member']['welcome'], '{0}'));
chk('en ptsToRank keeps {0} and {1}', str_contains((string) $en['member']['ptsToRank'], '{0}') && str_contains((string) $en['member']['ptsToRank'], '{1}'));
chk('en funnel.asOf keeps {0}', str_contains((string) $en['funnel']['asOf'], '{0}'));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $f = $langDir . "/$loc/Reporting.php";
    if (! is_file($f)) {
        chk("$loc/Reporting.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc welcome keeps {0}", str_contains((string) ($arr['member']['welcome'] ?? ''), '{0}'));
    chk("$loc ptsToRank keeps {0}/{1}", str_contains((string) ($arr['member']['ptsToRank'] ?? ''), '{0}') && str_contains((string) ($arr['member']['ptsToRank'] ?? ''), '{1}'));
}

echo "views use lang(), not hardcoded English\n";
foreach (['funnel', 'member_dashboard'] as $v) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php calls lang('Reporting.", str_contains($src, "lang('Reporting."));
}
$bare = [
    ['funnel', '<h1>Win · Build · Send funnel</h1>'],
    ['funnel', '<h2>Win — acquisition'],
    ['member_dashboard', '<h2>Standing</h2>'],
    ['member_dashboard', '<h2>My groups</h2>'],
    ['member_dashboard', 'Your personal dashboard'],
];
foreach ($bare as [$v, $needle]) {
    $src = (string) file_get_contents("$viewDir/$v.php");
    chk("$v.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar) with interpolation & verbatim data\n";
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Reporting') {
            return $key;
        }
        $v = $GLOBALS['__rLang'];
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
    $GLOBALS['__rLang'] = require $langDir . "/$loc/Reporting.php";
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

// fr funnel — headings translated, {0} metadata interpolated, values verbatim
$h = $render("$viewDir/funnel.php", [
    'result' => [
        'funnel' => [
            'win'   => ['prospects' => 42, 'members_unique' => '<5', 'referral_conversions' => 7],
            'build' => ['course_enrollments' => 10, 'verified_giving_minor' => 999],
            'send'  => ['points_awarded' => 1234],
        ],
        'metadata' => ['as_of' => '2026-10-08', 'source_state' => 'live', 'data_completeness' => 'full', 'suppression_threshold' => 5],
    ],
], 'fr');
chk('fr funnel title translated', str_contains($h, 'Entonnoir Gagner · Bâtir · Envoyer'));
chk('fr funnel win heading translated', str_contains($h, 'Gagner — acquisition'));
chk('fr funnel prospects label translated', str_contains($h, 'Prospects'));
chk('fr funnel asOf interpolated', str_contains($h, 'À la date du : 2026-10-08'));
chk('fr funnel source interpolated', str_contains($h, 'Source : live'));
chk('fr funnel suppression interpolated', str_contains($h, 'Seuil de suppression : 5'));
chk('fr funnel numeric value verbatim', str_contains($h, '>42<') && str_contains($h, '>1234<'));
chk('fr funnel suppression marker verbatim', str_contains($h, '&lt;5'));

// ar funnel — RTL strings + na fallback for missing metadata
$h = $render("$viewDir/funnel.php", [
    'result' => ['funnel' => ['win' => [], 'build' => [], 'send' => []], 'metadata' => []],
], 'ar');
chk('ar funnel title translated', str_contains($h, 'مسار الكسب · البناء · الإرسال'));
chk('ar funnel na fallback interpolated', str_contains($h, 'حتى تاريخ: غير متاح'));
chk('ar funnel send heading translated', str_contains($h, 'الإرسال — القيادة والتحفيز'));

// fr member_dashboard — welcome/{0}, ptsToRank {0}/{1}, verbatim names, dates
$h = $render("$viewDir/member_dashboard.php", [
    'result' => [
        'profile' => ['display_name' => 'Marie', 'member_since' => '2025-01-15'],
        'gamification' => [
            'points' => 320, 'season_year' => 2026,
            'rank' => ['name' => 'Silver'], 'next_rank' => ['name' => 'Gold'],
            'points_to_next' => 80, 'progress_pct' => 60,
            'badges' => ['count' => 3, 'items' => [['name' => 'Faithful', 'code' => 'FTH', 'awarded_at' => '2026-02-01']]],
            'achievements' => ['unlocked_count' => 2, 'unlocked' => [['name' => 'Starter', 'code' => 'ST', 'xp' => 50, 'category' => 'Core', 'unlocked_at' => '2026-03-01']], 'in_progress' => []],
            'streaks' => [['name' => 'Prayer', 'code' => 'PR', 'current_count' => 4, 'best_count' => 9, 'cadence' => 'daily']],
        ],
        'events' => [['title' => 'Youth Camp', 'rsvp_state' => 'going', 'starts_at' => '2026-11-01', 'mode' => 'in_person', 'timezone' => 'GMT']],
        'certificates' => [['event_title' => 'Baptism Class', 'status' => 'issued', 'issued_at' => '2026-04-01', 'verification_id' => 'VID-9', 'id' => 'c1', 'download_ref' => 'r']],
        'courses' => ['active' => 1, 'completed' => 2, 'items' => [['title' => 'Foundations', 'status' => 'completed', 'category' => 'Core', 'completed_at' => '2026-05-01']]],
        'groups' => [['name' => 'Cell A', 'role' => 'leader', 'type' => 'cell', 'group_path' => ['Region', 'Area'], 'joined_at' => '2025-06-01']],
        'milestones' => ['achieved' => [['icon' => '⭐', 'label' => 'First step']], 'upcoming' => [['icon' => '🎯', 'label' => 'Ten steps', 'remaining' => 3]]],
        'metadata' => ['as_of' => '2026-10-08'],
    ],
], 'fr');
chk('fr member welcome interpolated + name verbatim', str_contains($h, 'Bienvenue, Marie'));
chk('fr member since interpolated', str_contains($h, 'membre depuis'));
chk('fr member standing translated', str_contains($h, 'Situation'));
chk('fr member season interpolated', str_contains($h, 'Saison 2026'));
chk('fr member ptsToRank {0}/{1} interpolated', str_contains($h, '80 pts pour Gold'));
chk('fr member ptsToGo interpolated', str_contains($h, 'encore 80 pts'));
chk('fr member pctToNext interpolated', str_contains($h, '60 % vers le rang suivant'));
chk('fr member milestones translated', str_contains($h, 'Mes jalons'));
chk('fr member toGo interpolated', str_contains($h, 'encore 3'));
chk('fr member xp interpolated', str_contains($h, '50 XP'));
chk('fr member best interpolated', str_contains($h, 'Meilleure : 9'));
chk('fr member streak name verbatim', str_contains($h, 'Prayer'));
chk('fr member events heading translated', str_contains($h, 'Événements à venir'));
chk('fr member event title verbatim', str_contains($h, 'Youth Camp'));
chk('fr member rsvp state verbatim', str_contains($h, 'going'));
chk('fr member certs heading translated', str_contains($h, 'Mes certificats'));
chk('fr member verifyId translated + id verbatim', str_contains($h, 'ID de vérification') && str_contains($h, 'VID-9'));
chk('fr member downloadPdf translated', str_contains($h, 'Télécharger le PDF'));
chk('fr member learning translated', str_contains($h, 'Mes apprentissages'));
chk('fr member completedOn interpolated', str_contains($h, 'Terminé'));
chk('fr member groups translated', str_contains($h, 'Mes groupes'));
chk('fr member group name/role verbatim', str_contains($h, 'Cell A') && str_contains($h, 'leader'));
chk('fr member group path verbatim', str_contains($h, 'Region › Area'));
chk('fr member joined interpolated', str_contains($h, 'Rejoint'));
chk('fr member meta interpolated', str_contains($h, 'À la date du 2026-10-08'));
chk('fr member points numeric preserved', str_contains($h, '>320<'));

// ar member_dashboard — empty states + fallbacks + RTL
$h = $render("$viewDir/member_dashboard.php", [
    'result' => [
        'profile' => [],
        'gamification' => ['points' => 0, 'rank' => [], 'badges' => ['count' => 0, 'items' => []], 'achievements' => ['unlocked_count' => 0, 'unlocked' => [], 'in_progress' => []], 'streaks' => []],
        'events' => [], 'certificates' => [], 'courses' => ['active' => 0, 'completed' => 0, 'items' => []], 'groups' => [],
        'milestones' => ['achieved' => [], 'upcoming' => []],
        'metadata' => ['as_of' => '2026-10-08'],
    ],
], 'ar');
chk('ar member welcome fallback name translated', str_contains($h, 'مرحبًا، عضو'));
chk('ar member unranked fallback translated', str_contains($h, 'بلا رتبة'));
chk('ar member no events translated', str_contains($h, 'لا توجد فعاليات قادمة'));
chk('ar member no certs translated', str_contains($h, 'لا توجد شهادات بعد'));
chk('ar member no groups translated', str_contains($h, 'لست عضوًا في أي مجموعة بعد'));
chk('ar member meta interpolated', str_contains($h, 'حتى 2026-10-08'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
