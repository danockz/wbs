<?php

declare(strict_types=1);

/**
 * Gamification admin READ views i18n + render smoke — the 20 browser faces that
 * replaced the generic admin console (respondAdmin) across the Awards, Config and
 * FollowUps read endpoints. Each view extends the shared layouts/app (which emits
 * the locale-aware <html lang dir>), so this test focuses on:
 *   - Gamification.admin.<block>.* key parity across all 6 locales,
 *   - views calling lang('Gamification.admin.<block>.') (not hardcoded English),
 *   - render smoke: populated + empty + not-found states, {0} interpolation,
 *     vocabulary (cadence/visibility/phase) raw-value fallback.
 *
 *   php app/Modules/Gamification/Views/tests/admin_views_test.php
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

// view file => lang admin sub-block it must reference
$views = [
    'awards_pending'          => 'awardsPending',
    'ranks_admin'             => 'ranks',
    'rank_show'               => 'rankShow',
    'achievement_show'        => 'achievementShow',
    'streaks_admin'           => 'streaks',
    'streak_show'             => 'streakShow',
    'badges_admin'            => 'badges',
    'badge_show'              => 'badgeShow',
    'rules_admin'             => 'rules',
    'rule_show'               => 'ruleShow',
    'activity_catalog'        => 'catalog',
    'activity_categories'     => 'categories',
    'activity_category_show'  => 'categoryShow',
    'config_list'             => 'config',
    'config_show'             => 'configShow',
    'followup_types'          => 'followupTypes',
    'followup_type_show'      => 'followupTypeShow',
    'followup_methods'        => 'followupMethods',
    'followup_method_show'    => 'followupMethodShow',
    'followup_show'           => 'followup',
];

echo "language file completeness (admin block parity across 6 locales)\n";
$en    = require $langDir . '/en/Gamification.php';
$enAdm = $flatten($en['admin'] ?? []);
chk('en defines Gamification.admin.*', $enAdm !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Gamification.php";
    $keys = $flatten($m['admin'] ?? []);
    chk("$loc mirrors all en admin keys", array_diff($enAdm, $keys) === [], 'missing: ' . implode(',', array_slice(array_diff($enAdm, $keys), 0, 8)));
    chk("$loc has no stray admin keys", array_diff($keys, $enAdm) === [], 'extra: ' . implode(',', array_slice(array_diff($keys, $enAdm), 0, 8)));
}

echo "views reference lang('Gamification.admin.<block>.')\n";
foreach ($views as $file => $block) {
    $src = (string) file_get_contents("$viewDir/$file.php");
    chk("$file.php calls lang('Gamification.admin.$block.", str_contains($src, "lang('Gamification.admin.$block."));
    chk("$file.php has no hardcoded <h1>English literal", ! preg_match('/<h1>[A-Za-z][^<]*<\/h1>/', $src));
}

echo "render smoke\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
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
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer           = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/$file.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

// awards_pending — populated + count + empty
$h = $render('awards_pending', ['awards' => [
    ['subject_id' => 'u1', 'points' => 50, 'entry_type' => 'earn', 'rule_id' => 'r1', 'explanation' => 'Attended', 'created_at' => '2026-09-10'],
]], 'fr');
chk('awards_pending fr title', str_contains($h, 'en attente d’approbation'));
chk('awards_pending shows subject + points', str_contains($h, 'u1') && str_contains($h, '50'));
chk('awards_pending singular count interpolated', str_contains($h, '1 entrée'));
$h = $render('awards_pending', ['awards' => []], 'ar');
chk('awards_pending empty (ar)', str_contains($h, 'لا توجد جوائز'));

// ranks_admin — minPoints interpolation + plural count
$h = $render('ranks_admin', ['tiers' => [
    ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 0, 'status' => 'active'],
    ['code' => 'silver', 'name' => 'Silver', 'min_points' => 500, 'status' => 'active'],
]], 'es');
chk('ranks_admin es title', str_contains($h, 'Definiciones de rango'));
chk('ranks_admin minPoints interpolated', str_contains($h, '500 pts'));
chk('ranks_admin plural count interpolated', str_contains($h, '2 entradas'));

// rank_show — populated + not-found
$h = $render('rank_show', ['tier' => ['code' => 'gold', 'name' => 'Gold', 'min_points' => 1000, 'status' => 'active']], 'en');
chk('rank_show shows fields', str_contains($h, 'Gold') && str_contains($h, '1000'));
$h = $render('rank_show', ['tier' => null], 'fr');
chk('rank_show not-found (fr)', str_contains($h, 'introuvable'));

// achievement_show — secret pill + not-found
$h = $render('achievement_show', ['achievement' => ['code' => 'a1', 'name' => 'First', 'secret' => true, 'xp' => 20, 'category' => 'starter']], 'en');
chk('achievement_show secret label', str_contains($h, 'Secret'));
$h = $render('achievement_show', ['achievement' => null], 'zh');
chk('achievement_show not-found (zh)', str_contains($h, '找不到该成就'));

// streaks_admin — cadence vocab + grace + fallback
$h = $render('streaks_admin', ['streaks' => [
    ['code' => 'dp', 'name' => 'Daily Prayer', 'cadence' => 'daily', 'default_grace_days' => 2, 'status' => 'active'],
]], 'fr');
chk('streaks_admin cadence daily localized (fr)', str_contains($h, 'Quotidien'));
chk('streaks_admin grace interpolated (fr)', str_contains($h, '2 jours de grâce'));
$h = $render('streaks_admin', ['streaks' => [['code' => 'x', 'name' => 'X', 'cadence' => 'fortnightly', 'default_grace_days' => 0]]], 'en');
chk('streaks_admin cadence raw-value fallback', str_contains($h, 'fortnightly'));

// streak_show — not-found
$h = $render('streak_show', ['streak' => null], 'en');
chk('streak_show not-found', str_contains($h, 'could not be found'));

// badges_admin — visibility vocab + permanence
$h = $render('badges_admin', ['badges' => [
    ['code' => 'b1', 'name' => 'Star', 'visibility' => 'public', 'permanent' => 1],
]], 'pt');
chk('badges_admin visibility public localized (pt)', str_contains($h, 'Público'));
chk('badges_admin permanent label (pt)', str_contains($h, 'Permanente'));
$h = $render('badges_admin', ['badges' => [['code' => 'b', 'name' => 'B', 'visibility' => 'squad']]], 'en');
chk('badges_admin visibility raw-value fallback', str_contains($h, 'squad'));

// badge_show — not-found
$h = $render('badge_show', ['badge' => null], 'ar');
chk('badge_show not-found (ar)', str_contains($h, 'تعذّر العثور على هذه الشارة'));

// rules_admin — points + review flag
$h = $render('rules_admin', ['rules' => [
    ['code' => 'attend', 'version' => 2, 'points' => 10, 'event_type' => 'attendance', 'requires_review' => 1, 'status' => 'active'],
]], 'en');
chk('rules_admin points interpolated', str_contains($h, '10 pts'));
chk('rules_admin review flag shown', str_contains($h, 'Requires review'));

// rule_show — current + history + not-found
$h = $render('rule_show', ['rule' => [
    'code' => 'attend', 'current' => ['version' => 2, 'points' => 10, 'event_type' => 'attendance', 'status' => 'active'],
    'versions' => [['version' => 2, 'points' => 10, 'status' => 'active'], ['version' => 1, 'points' => 5, 'status' => 'retired']],
]], 'en');
chk('rule_show shows current + history', str_contains($h, 'Version history') && str_contains($h, 'v1'));
$h = $render('rule_show', ['rule' => null], 'fr');
chk('rule_show not-found (fr)', str_contains($h, 'introuvable'));

// activity_catalog — nested phase/category/activity + phase label
$h = $render('activity_catalog', ['catalog' => [
    'win' => ['phase' => 'win', 'categories' => [
        'c1' => ['category' => ['code' => 'evg', 'name' => 'Evangelism'], 'activities' => [
            ['code' => 'invite', 'name' => 'Invite', 'points' => 5, 'point_mode' => 'fixed', 'event_type' => 'invite'],
        ]],
    ]],
]], 'fr');
chk('activity_catalog phase win localized (fr)', str_contains($h, 'Gagner'));
chk('activity_catalog shows activity + points', str_contains($h, 'Invite') && str_contains($h, '5 pts'));
$h = $render('activity_catalog', ['catalog' => []], 'en');
chk('activity_catalog empty', str_contains($h, 'catalog is empty'));

// activity_categories — phase vocab
$h = $render('activity_categories', ['categories' => [
    ['code' => 'evg', 'name' => 'Evangelism', 'phase' => 'win', 'status' => 'active'],
]], 'es');
chk('activity_categories phase win localized (es)', str_contains($h, 'Ganar'));

// activity_category_show — activity count + not-found
$h = $render('activity_category_show', ['category' => ['code' => 'evg', 'name' => 'Evangelism', 'phase' => 'win', 'activity_count' => 7, 'status' => 'active']], 'en');
chk('activity_category_show shows activity count', str_contains($h, '7'));
$h = $render('activity_category_show', ['category' => null], 'en');
chk('activity_category_show not-found', str_contains($h, 'could not be found'));

// config_list — value + editability
$h = $render('config_list', ['config' => [
    'rollup_awards' => ['value' => false, 'type' => 'bool', 'description' => 'Mint ancestor awards', 'is_editable' => true],
]], 'en');
chk('config_list shows key + bool value', str_contains($h, 'rollup_awards') && str_contains($h, 'false'));
chk('config_list editable label', str_contains($h, 'Editable'));
$h = $render('config_list', ['config' => []], 'fr');
chk('config_list empty (fr)', str_contains($h, 'Aucune configuration'));

// config_show — value + not-found
$h = $render('config_show', ['entry' => ['key' => 'group_credit_mode', 'value' => 'per_group', 'type' => 'string', 'is_editable' => false]], 'en');
chk('config_show shows value + read-only', str_contains($h, 'per_group') && str_contains($h, 'Read-only'));
$h = $render('config_show', ['entry' => null], 'en');
chk('config_show not-found', str_contains($h, 'could not be found'));

// followup_types — phase + requiresOutcome + nextDays
$h = $render('followup_types', ['types' => [
    ['code' => 'visit', 'name' => 'Home Visit', 'phase' => 'build', 'requires_outcome' => 1, 'default_next_days' => 7, 'status' => 'active'],
]], 'fr');
chk('followup_types phase build localized (fr)', str_contains($h, 'Bâtir'));
chk('followup_types nextDays interpolated (fr)', str_contains($h, 'suivi dans 7j'));

// followup_type_show — not-found
$h = $render('followup_type_show', ['type' => null], 'en');
chk('followup_type_show not-found', str_contains($h, 'could not be found'));

// followup_methods — populated + empty
$h = $render('followup_methods', ['methods' => [
    ['code' => 'call', 'name' => 'Phone Call', 'multiplier_key' => 'call_mult', 'status' => 'active'],
]], 'en');
chk('followup_methods shows method', str_contains($h, 'Phone Call') && str_contains($h, 'call_mult'));
$h = $render('followup_methods', ['methods' => []], 'ar');
chk('followup_methods empty (ar)', str_contains($h, 'لا توجد طرق متابعة'));

// followup_method_show — not-found
$h = $render('followup_method_show', ['method' => null], 'en');
chk('followup_method_show not-found', str_contains($h, 'could not be found'));

// followup_show — pastoral fields + needs + not-found
$h = $render('followup_show', ['followup' => [
    'subject_user_id' => 's1', 'follower_user_id' => 'f1', 'type_code' => 'visit', 'method_code' => 'call',
    'status' => 'completed', 'performed_at' => '2026-09-09', 'summary' => 'Good chat',
    'outcome' => 'Encouraged', 'spiritual_health' => 'Growing', 'needs' => ['prayer', 'housing'],
    'next_follow_up_at' => '2026-09-20',
]], 'en');
chk('followup_show shows subject/follower', str_contains($h, 's1') && str_contains($h, 'f1'));
chk('followup_show shows pastoral fields', str_contains($h, 'Good chat') && str_contains($h, 'Growing'));
chk('followup_show shows needs', str_contains($h, 'prayer') && str_contains($h, 'housing'));
$h = $render('followup_show', ['followup' => null], 'fr');
chk('followup_show not-found (fr)', str_contains($h, 'introuvable'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
