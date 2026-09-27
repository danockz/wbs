<?php

declare(strict_types=1);

/**
 * MANUAL-AWARD ACTION forms wiring test — closes the last section-B CRUD gap:
 * the manual award actions (grant/revoke a badge, unlock/re-evaluate an
 * achievement, record a streak for a member) were JSON-API-only, had NO browser
 * controls, and several POST routes were MISSING webcsrf. This proves each
 * definition DETAIL page now carries a no-JS, CSP-safe, webcsrf-guarded action
 * form with an active-member picker, PRG flash, and 6-locale i18n parity.
 *
 *   - badge_show:       grant + revoke forms → /gamification/badges/{code}/grant|revoke
 *   - achievement_show: unlock + reevaluate forms → /gamification/achievements/{code}/unlock|reevaluate
 *   - streak_show:      record form → /gamification/streak-definitions/{code}/record
 *   - all five POST routes carry authorize:gamification.manage + webcsrf
 *   - detail controllers pass $roster + mint $csrf (renderForm)
 *   - action controller methods PRG back to the detail page with a localized flash
 *   - i18n parity for the new *.actions.* blocks across all 6 locales
 *
 *   php app/Modules/Gamification/Views/tests/award_actions_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$controller = $root . '/app/Modules/Gamification/Controllers/AwardsController.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for the new *.actions.* blocks ────────────────────────────
echo "language parity (badgeShow/achievementShow/streakShow .actions.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$en = require $langDir . '/en/Gamification.php';
foreach (['badgeShow', 'achievementShow', 'streakShow'] as $blk) {
    chk("en admin.$blk.actions present", isset($en['admin'][$blk]['actions']) && is_array($en['admin'][$blk]['actions']));
}
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $arr = require $langDir . "/$loc/Gamification.php";
    foreach (['badgeShow', 'achievementShow', 'streakShow'] as $blk) {
        $enKeys = $flat($en['admin'][$blk]['actions'] ?? []);
        $keys   = $flat($arr['admin'][$blk]['actions'] ?? []);
        $miss   = array_diff($enKeys, $keys);
        $extra  = array_diff($keys, $enKeys);
        chk("$loc mirrors $blk.actions keys", $miss === [] && $extra === [],
            'missing: ' . implode(',', $miss) . ' extra: ' . implode(',', $extra));
    }
}

// ── 2. Routes: five action routes gated + webcsrf ────────────────────────────
echo "routes: manual-award POSTs authorize:gamification.manage + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
$actionRoutes = [
    'badge grant'        => "badges/(:segment)/grant",
    'badge revoke'       => "badges/(:segment)/revoke",
    'achievement unlock' => "achievements/(:segment)/unlock'",
    'achievement reeval' => "achievements/(:segment)/reevaluate'",
    'streak record'      => "streak-definitions/(:segment)/record",
];
foreach ($actionRoutes as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label authorize:gamification.manage", str_contains($m[0], 'authorize:gamification.manage'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false, $needle);
    }
}
// The legacy subject-in-path routes must ALSO now carry webcsrf.
foreach (['recordStreak/$1', 'achievements/unlock', "subjects/(:segment)/reevaluate'"] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("legacy route webcsrf: $needle", str_contains($m[0], 'webcsrf'));
    } else {
        chk("legacy route present: $needle", false);
    }
}

// ── 3. Controller: renderForm passes roster + PRG action helper ──────────────
echo "controller: roster + renderForm + PRG action helper\n";
$ctrl = (string) file_get_contents($controller);
chk('awardRoster() helper present', str_contains($ctrl, 'function awardRoster'));
chk('awardRoster uses listMembers active', (bool) preg_match('/function awardRoster.*?listMembers\(.*?active.*?\)/s', $ctrl));
chk('respondAwardAction() PRG helper present', str_contains($ctrl, 'function respondAwardAction'));
chk('respondAwardAction keeps JSON for API', (bool) preg_match('/function respondAwardAction.*?wantsJson\(\).*?respondWith/s', $ctrl));
chk('respondAwardAction redirects on error', (bool) preg_match('/function respondAwardAction.*?redirect\(\)->to.*?with\(.error./s', $ctrl));
foreach (['showBadge', 'showAchievement', 'showStreak'] as $m) {
    chk("$m uses renderForm", (bool) preg_match("/function $m\\(.*?renderForm/s", $ctrl));
    chk("$m passes roster", (bool) preg_match("/function $m\\(.*?'roster'/s", $ctrl));
}
chk('unlockAchievementForCode present (code-in-path browser alias)', str_contains($ctrl, 'function unlockAchievementForCode'));
chk('reevaluateForCode present', str_contains($ctrl, 'function reevaluateForCode'));
chk('recordStreakForCode present', str_contains($ctrl, 'function recordStreakForCode'));
chk('grantBadge PRGs via respondAwardAction', (bool) preg_match('/function grantBadge\(.*?respondAwardAction/s', $ctrl));
chk('revokeBadge PRGs via respondAwardAction', (bool) preg_match('/function revokeBadge\(.*?respondAwardAction/s', $ctrl));

// ── 4. Views: CSP-clean action forms with member picker + csrf ───────────────
echo "views: action forms are no-JS, CSP-safe, csrf-bound\n";
$checks = [
    'badge_show' => ['/grant', '/revoke'],
    'achievement_show' => ['/unlock', '/reevaluate'],
    'streak_show' => ['/record'],
];
foreach ($checks as $view => $acts) {
    $src = (string) file_get_contents("$viewDir/$view.php");
    foreach ($acts as $a) {
        chk("$view posts to {$a} action", str_contains($src, $a . '"') || str_contains($src, $a . '"><'));
    }
    chk("$view carries _csrf bound to \$csrf", (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
    chk("$view has a member picker <select name=\"subject_id\">", str_contains($src, 'name="subject_id"'));
    chk("$view renders PRG flash", str_contains($src, "session('success')") && str_contains($src, "session('error')"));
    // CSP cleanliness (strip doc comment first).
    $noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    chk("$view CSP-clean: no <script>", ! str_contains($noC, '<script'));
    chk("$view CSP-clean: no inline on* handlers", ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noC));
}

// ── 5. Headless render smoke (fr) ────────────────────────────────────────────
echo "render smoke — badge/achievement/streak action forms (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Gamification') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$render = static function (string $view, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir, $view) {
        extract($data);
        ob_start();
        include "$viewDir/$view.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$roster = [
    ['id' => 'u1', 'display_name' => 'Ama Mensah'],
    ['id' => 'u2', 'display_name' => 'Kofi Boateng'],
];
$GLOBALS['__flash'] = ['success' => 'Fait.'];

$hb = $render('badge_show', ['badge' => ['code' => 'zealot', 'name' => 'Zealot', 'visibility' => 'public'], 'roster' => $roster, 'csrf' => 'CB'], 'fr');
chk('badge render: grant form action', str_contains($hb, 'action="/gamification/badges/zealot/grant"'));
chk('badge render: revoke form action', str_contains($hb, 'action="/gamification/badges/zealot/revoke"'));
chk('badge render: roster option present', str_contains($hb, 'value="u1"') && str_contains($hb, 'Ama Mensah'));
chk('badge render: csrf token', str_contains($hb, 'value="CB"'));
chk('badge render: flash shown', str_contains($hb, 'Fait.'));
chk('badge render: no leaked keys', ! str_contains($hb, 'Gamification.admin.badgeShow.actions'));

$ha = $render('achievement_show', ['achievement' => ['code' => 'firstfruit', 'name' => 'First Fruit', 'status' => 'active'], 'roster' => $roster, 'csrf' => 'CA'], 'fr');
chk('achievement render: unlock form action', str_contains($ha, 'action="/gamification/achievements/firstfruit/unlock"'));
chk('achievement render: reevaluate form action', str_contains($ha, 'action="/gamification/achievements/firstfruit/reevaluate"'));
chk('achievement render: notes field', str_contains($ha, 'name="notes"'));
chk('achievement render: no leaked keys', ! str_contains($ha, 'Gamification.admin.achievementShow.actions'));

$hs = $render('streak_show', ['streak' => ['code' => 'daily', 'name' => 'Daily', 'status' => 'active'], 'roster' => $roster, 'csrf' => 'CS'], 'fr');
chk('streak render: record form action', str_contains($hs, 'action="/gamification/streak-definitions/daily/record"'));
chk('streak render: season field', str_contains($hs, 'name="season_id"'));
chk('streak render: roster option present', str_contains($hs, 'Kofi Boateng'));
chk('streak render: no leaked keys', ! str_contains($hs, 'Gamification.admin.streakShow.actions'));

// not-found detail still safe (no action forms, no crash)
$hnf = $render('badge_show', ['badge' => null, 'roster' => [], 'csrf' => 'X'], 'fr');
chk('badge render: not-found panel, no grant form', ! str_contains($hnf, '/grant"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
