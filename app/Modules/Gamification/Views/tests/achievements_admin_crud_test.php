<?php

declare(strict_types=1);

/**
 * ACHIEVEMENTS ADMIN CATALOG CRUD wiring test — proves the ACHIEVEMENT
 * definitions catalog (previously JSON-API-only, with NO admin list view) now
 * has a full inline no-JS CRUD face, mirroring the ranks/badges/streaks catalogs:
 *
 *   - a new GET /gamification/achievement-definitions list route → AwardsController
 *     ::listAchievementDefinitions → achievements_admin view (org-wide, incl. secret
 *     AND inactive rows), passing triggers/phases/csrf;
 *   - the "New achievement" form and each row's "Edit" form both POST to the
 *     webcsrf-guarded upsert route /gamification/achievements (define() is an
 *     UPSERT keyed on code, so one route serves create + edit — no PATCH on the
 *     no-JS path);
 *   - a per-row Disable form POSTs to /achievements/{code}/disable (status flip,
 *     unlocks kept);
 *   - defineAchievement/disableAchievement PRG back to the list with a localized
 *     flash while keeping JSON for API clients;
 *   - trigger_type + phase render as <select> pickers, trigger_config as a JSON
 *     textarea; the view is CSP-clean (no <script>, no inline on* handlers);
 *   - i18n parity for the new admin.achievements.* block across all 6 locales.
 *
 *   php app/Modules/Gamification/Views/tests/achievements_admin_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$controller = $root . '/app/Modules/Gamification/Controllers/AwardsController.php';
$service    = $root . '/app/Modules/Gamification/Services/AchievementService.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for the new admin.achievements block ──────────────────────
echo "language parity (admin.achievements.* — all locales)\n";
$flat = static function (array $a, string $p = '') use (&$flat): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flat($v, $key)) : $o[] = $key;
    }
    sort($o);

    return $o;
};
$enBlock = (require $langDir . '/en/Gamification.php')['admin']['achievements'];
$enKeys  = $flat($enBlock);
chk('en achievements block non-trivial (>= 40 keys)', count($enKeys) >= 40, (string) count($enKeys));
// Fixed-vocab maps must be complete.
foreach (['points', 'count', 'streak', 'combo', 'first_time', 'cumulative_points', 'rank_reached', 'custom'] as $t) {
    chk("en trigger.$t present", isset($enBlock['form']['trigger'][$t]) && $enBlock['form']['trigger'][$t] !== '');
}
foreach (['general', 'win', 'build', 'send'] as $ph) {
    chk("en phase.$ph present", isset($enBlock['form']['phase'][$ph]) && $enBlock['form']['phase'][$ph] !== '');
}
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $block = (require $langDir . "/$loc/Gamification.php")['admin']['achievements'] ?? [];
    $keys  = $flat($block);
    $miss  = array_diff($enKeys, $keys);
    $extra = array_diff($keys, $enKeys);
    chk("$loc mirrors all en achievements keys", $miss === [] && $extra === [],
        'missing: ' . implode(',', $miss) . ' extra: ' . implode(',', $extra));
}

// ── 2. Service exposes admin list + trigger vocabulary ───────────────────────
echo "service: admin list + trigger vocabulary\n";
$svc = (string) file_get_contents($service);
chk('listForAdmin() present', str_contains($svc, 'function listForAdmin'));
chk('listForAdmin is org-wide (group_id NULL)', (bool) preg_match('/function listForAdmin.*?where\(.group_id., null\)/s', $svc));
chk('listForAdmin does NOT filter status (incl. inactive)',
    (bool) preg_match('/function listForAdmin.*?getResultArray/s', $svc)
    && ! (bool) preg_match("/function listForAdmin.*?where\('status'.*?getResultArray/s", $svc));
chk('triggerTypes() exposes the trigger vocabulary', str_contains($svc, 'function triggerTypes'));

// ── 3. View controls ─────────────────────────────────────────────────────────
echo "achievements_admin.php exposes create/edit/disable controls\n";
$src = (string) file_get_contents("$viewDir/achievements_admin.php");
chk('create/edit form posts to /gamification/achievements', str_contains($src, 'action="/gamification/achievements"'));
chk('has a New (create) panel', str_contains($src, '$defineForm([], true)'));
chk('has a per-row Edit panel', str_contains($src, '$defineForm($'));
chk('code locked on edit path', str_contains($src, 'codeLocked'));
chk('trigger_type is a <select> picker', (bool) preg_match('/<select name="trigger_type"/', $src));
chk('phase is a <select> picker', (bool) preg_match('/<select name="phase"/', $src));
chk('trigger_config is a JSON textarea', str_contains($src, 'name="trigger_config"'));
chk('secret is a checkbox', str_contains($src, 'name="secret"') && str_contains($src, 'type="checkbox"'));
chk('has a Disable form', str_contains($src, '/disable"'));
chk('disable confirmation copy', str_contains($src, 'disableConfirm'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('progressive-enhancement <details> (no-JS friendly)', str_contains($src, '<details'));
// CSP cleanliness — check against source with the PHP doc-comment stripped so the
// header's prose ("no <script>") isn't a false positive.
$srcNoComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script> tag', ! str_contains($srcNoComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $srcNoComments));

// ── 4. Controller PRG + list wiring + JSON kept ──────────────────────────────
echo "controller: list route + PRG + csrf + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('listAchievementDefinitions() present', str_contains($ctrl, 'function listAchievementDefinitions'));
chk('list renders achievements_admin view', str_contains($ctrl, 'WBS\Gamification\Views\achievements_admin'));
chk('list passes triggers + phases + csrf', str_contains($ctrl, "'triggers'") && str_contains($ctrl, "'phases'") && str_contains($ctrl, "'csrf'"));
chk('list uses listForAdmin', str_contains($ctrl, 'listForAdmin'));
chk('defineAchievement PRGs via respondConfigDecision',
    (bool) preg_match('/function defineAchievement\(.*?respondConfigDecision/s', $ctrl));
chk('defineAchievement redirects to achievement-definitions list',
    (bool) preg_match("/function defineAchievement\(.*?'\/gamification\/achievement-definitions'/s", $ctrl));
chk('disableAchievement PRGs with disabledFlash',
    (bool) preg_match('/function disableAchievement\(.*?disabledFlash/s', $ctrl));
chk('JSON kept for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

// ── 5. Routes gated + webcsrf ────────────────────────────────────────────────
echo "routes: list gated; write routes webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*achievement-definitions.*listAchievementDefinitions.*$#m', $routes, $m)) {
    chk('GET achievement-definitions route present', true);
    chk('list route authorize:gamification.manage', str_contains($m[0], 'authorize:gamification.manage'));
} else {
    chk('GET achievement-definitions route present', false);
}
$writeRoutes = [
    'defineAchievement'  => "post('achievements'",
    'disableAchievement' => 'disableAchievement/$1',
];
foreach ($writeRoutes as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label keeps authorize:gamification.manage", str_contains($m[0], 'authorize:gamification.manage'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — new + edit + disable + empty (fr)\n";
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

$GLOBALS['__flash'] = ['success' => 'Enregistré.'];
$h = $render('achievements_admin', [
    'csrf'     => 'CA',
    'triggers' => ['points', 'count', 'streak', 'combo', 'first_time', 'cumulative_points', 'rank_reached', 'custom'],
    'phases'   => ['general', 'win', 'build', 'send'],
    'achievements' => [
        [
            'code' => 'first_follow_up', 'name' => 'First Follow-up', 'trigger_type' => 'count',
            'phase' => 'build', 'trigger_config' => '{"count":1}', 'xp' => 50, 'bonus_points' => 10,
            'bonus_rule_code' => 'br1', 'category' => 'build', 'icon' => '🌱', 'color' => '#22c55e',
            'sort_order' => 1, 'description' => 'Log your first follow-up.', 'secret' => 0, 'status' => 'active',
        ],
        [
            'code' => 'secret_saint', 'name' => 'Secret Saint', 'trigger_type' => 'cumulative_points',
            'phase' => 'general', 'trigger_config' => '{"threshold":1000}', 'xp' => 500, 'secret' => 1, 'status' => 'inactive',
        ],
    ],
], 'fr');
chk('render: new-achievement create panel posts to /gamification/achievements', str_contains($h, 'action="/gamification/achievements"'));
chk('render: row edit prefilled name', str_contains($h, 'value="First Follow-up"'));
chk('render: locked code hidden field on edit', str_contains($h, 'name="code" value="first_follow_up"'));
chk('render: trigger_type option pre-selected on edit', (bool) preg_match('/value="count" selected/', $h));
chk('render: phase option pre-selected on edit', (bool) preg_match('/value="build" selected/', $h));
chk('render: trigger_config JSON shown in textarea (HTML-escaped)', str_contains($h, esc('{"count":1}')));
chk('render: disable form for the row', str_contains($h, 'action="/gamification/achievements/first_follow_up/disable"'));
chk('render: inactive row marked disabled', str_contains($h, 'ach-disabled'));
chk('render: secret row shows secret tag', str_contains($h, lang('Gamification.admin.achievements.secret')));
chk('render: csrf token present', str_contains($h, 'value="CA"'));
chk('render: success flash rendered', str_contains($h, 'Enregistré.'));
chk('render: no untranslated Gamification.* keys leaked', ! str_contains($h, 'Gamification.admin.achievements'));

$GLOBALS['__flash'] = [];
$he = $render('achievements_admin', ['csrf' => 'CA', 'triggers' => ['points'], 'phases' => ['general'], 'achievements' => []], 'fr');
chk('render: empty state + create panel still shown',
    str_contains($he, lang('Gamification.admin.achievements.empty')) && str_contains($he, 'action="/gamification/achievements"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
