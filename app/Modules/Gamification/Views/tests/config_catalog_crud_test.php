<?php

declare(strict_types=1);

/**
 * CONFIG CATALOG CRUD WORKFLOW wiring test — proves the three gamification config
 * catalogs (BADGES, RANK TIERS, STREAK definitions) are no longer read-only lists:
 * each view now renders an inline "New" create form + per-row Edit (prefilled) and
 * Disable controls posting to the webcsrf-guarded upsert/disable routes; the
 * AwardsController PRGs browser writes back to the list with a localized flash while
 * keeping JSON for API clients and passing the CSRF token to the list views. Plus
 * i18n parity for the new form keys and a headless render smoke (layout-bound harness).
 *
 * Because every define() is an UPSERT keyed on (code, group), a single POST-to-define
 * route serves both create and edit — no PATCH needed on the no-JS path.
 *
 *   php app/Modules/Gamification/Views/tests/config_catalog_crud_test.php
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

// Shared form keys every catalog must expose in all 6 locales.
$commonForm = ['codeLabel', 'codePh', 'codeLocked', 'nameLabel', 'sortLabel', 'iconLabel', 'iconPh',
    'saveNew', 'saveEdit', 'view', 'edit', 'disable', 'disableConfirm',
    'createdFlash', 'updatedFlash', 'disabledFlash'];
$extra = [
    'badges'  => ['newBadge', 'visibilityLabel', 'expiryLabel', 'descriptionLabel', 'permanentLabel'],
    'ranks'   => ['newTier', 'minPointsLabel', 'colorLabel', 'colorPh'],
    'streaks' => ['newStreak', 'cadenceLabel', 'graceLabel', 'descriptionLabel'],
];

// ── 1. i18n parity for the new form.* keys ───────────────────────────────────
echo "language parity (new form keys, all catalogs)\n";
foreach (['badges', 'ranks', 'streaks'] as $sec) {
    $enForm = (require $langDir . '/en/Gamification.php')['admin'][$sec]['form'];
    foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $form = (require $langDir . "/$loc/Gamification.php")['admin'][$sec]['form'] ?? [];
        foreach (array_merge($commonForm, $extra[$sec]) as $k) {
            chk("$loc $sec.form.$k present", isset($form[$k]) && $form[$k] !== '');
        }
        chk("$loc mirrors all en $sec.form keys", array_diff(array_keys($enForm), array_keys($form)) === [],
            'missing: ' . implode(',', array_diff(array_keys($enForm), array_keys($form))));
    }
}

// ── 2. View controls per catalog ─────────────────────────────────────────────
$views = [
    'badges_admin'  => ['/gamification/badges', 'newBadge'],
    'ranks_admin'   => ['/gamification/ranks', 'newTier'],
    'streaks_admin' => ['/gamification/streak-definitions', 'newStreak'],
];
foreach ($views as $file => [$postPath, $newKey]) {
    echo "$file.php exposes create/edit/disable controls\n";
    $src = (string) file_get_contents("$viewDir/$file.php");
    chk("$file: create/edit form posts to $postPath", str_contains($src, 'action="' . $postPath . '"'));
    chk("$file: has a New (create) panel", str_contains($src, '$defineForm([], true)'));
    chk("$file: has a per-row Edit panel", str_contains($src, '$defineForm($'));
    chk("$file: has a Disable form", str_contains($src, '/disable"'));
    chk("$file: disable asks for confirm()", str_contains($src, 'disableConfirm'));
    chk("$file: forms carry _csrf bound to \$csrf", (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
    chk("$file: renders PRG flash messages", str_contains($src, "session('success')") && str_contains($src, "session('error')"));
    chk("$file: code locked on edit path", str_contains($src, 'codeLocked'));
    chk("$file: progressive-enhancement <details> (no-JS friendly)", str_contains($src, '<details'));
}

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondConfigDecision PRG helper', str_contains($ctrl, 'private function respondConfigDecision'));
chk('PRG defaults created/updated flash from result marker', str_contains($ctrl, "! empty(\$result->data['updated']) ? 'updatedFlash' : 'createdFlash'"));
foreach (['defineBadge' => '/gamification/badges', 'defineRank' => '/gamification/rank-definitions', 'defineStreak' => '/gamification/streak-definitions'] as $fn => $path) {
    chk("$fn PRGs via respondConfigDecision", (bool) preg_match('/function ' . $fn . '\(.*?respondConfigDecision/s', $ctrl));
    chk("$fn redirects to $path", str_contains($ctrl, "'" . $path . "'"));
}
foreach (['disableBadge', 'disableRank', 'disableStreak'] as $fn) {
    chk("$fn PRGs with disabledFlash", (bool) preg_match('/function ' . $fn . '\(.*?disabledFlash/s', $ctrl));
}
chk('list methods pass csrf token', substr_count($ctrl, "'csrf'") >= 3 && str_contains($ctrl, 'wbsCsrf'));
chk('JSON kept for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "define/disable routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
$routeChecks = [
    'defineBadge'   => "post('badges'",
    'disableBadge'  => 'disableBadge/$1',
    'defineRank'    => "post('ranks'",
    'disableRank'   => 'disableRank/$1',
    'defineStreak'  => "post('streak-definitions'",
    'disableStreak' => 'disableStreak/$1',
];
foreach ($routeChecks as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label keeps authorize:gamification.manage,any", str_contains($m[0], 'authorize:gamification.manage,any'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 5. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — each catalog (fr) create + edit + disable + empty\n";
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
        return $v;
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

$GLOBALS['__flash'] = [];
// Badges
$hb = $render('badges_admin', ['csrf' => 'CB', 'badges' => [
    ['code' => 'first_win', 'name' => 'First Win', 'visibility' => 'public', 'permanent' => 1, 'status' => 'active', 'sort_order' => 1],
]], 'fr');
chk('badges: create panel posts to /gamification/badges', str_contains($hb, 'action="/gamification/badges"'));
chk('badges: row edit form present (prefilled name)', str_contains($hb, 'value="First Win"'));
chk('badges: locked code hidden field on edit', str_contains($hb, 'name="code" value="first_win"'));
chk('badges: disable form for the row', str_contains($hb, 'action="/gamification/badges/first_win/disable"'));
chk('badges: visibility select has group option', str_contains($hb, 'value="group"'));
chk('badges: csrf present', str_contains($hb, 'value="CB"'));

// Ranks
$hr = $render('ranks_admin', ['csrf' => 'CR', 'tiers' => [
    ['code' => 'bronze', 'name' => 'Bronze', 'min_points' => 100, 'status' => 'active', 'sort_order' => 1],
]], 'fr');
chk('ranks: create panel posts to /gamification/ranks', str_contains($hr, 'action="/gamification/ranks"'));
chk('ranks: row edit prefilled min_points', str_contains($hr, 'value="100"'));
chk('ranks: disable form for the row', str_contains($hr, 'action="/gamification/ranks/bronze/disable"'));
chk('ranks: newTier label translated', str_contains($hr, lang('Gamification.admin.ranks.form.newTier')));

// Streaks
$hs = $render('streaks_admin', ['csrf' => 'CS', 'streaks' => [
    ['code' => 'daily_prayer', 'name' => 'Daily Prayer', 'cadence' => 'daily', 'default_grace_days' => 1, 'status' => 'active'],
]], 'fr');
chk('streaks: create panel posts to /gamification/streak-definitions', str_contains($hs, 'action="/gamification/streak-definitions"'));
chk('streaks: row edit prefilled name', str_contains($hs, 'value="Daily Prayer"'));
chk('streaks: cadence select has weekly option', str_contains($hs, 'value="weekly"'));
chk('streaks: disable form for the row', str_contains($hs, 'action="/gamification/streak-definitions/daily_prayer/disable"'));

// Empty + flash
$GLOBALS['__flash'] = ['success' => 'Enregistré.'];
$he = $render('badges_admin', ['csrf' => 'CB', 'badges' => []], 'fr');
chk('empty state + create panel still shown', str_contains($he, lang('Gamification.admin.badges.empty')) && str_contains($he, 'action="/gamification/badges"'));
chk('success flash rendered', str_contains($he, 'Enregistré.'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
