<?php

declare(strict_types=1);

/**
 * STAGE-AWARE RECOMMENDATIONS dashboard wiring test — proves the recommendations
 * surface (journey/members/{id}/recommendations) grew from a JSON-only endpoint
 * whose generic data-page render could not display the nested per-stage payload
 * into a bespoke stage-aware dashboard:
 *
 *   - JourneyController::recommendations renders WBS\Journey\Views\recommendations
 *     for browsers (JSON kept for API clients), passing the grouped stages,
 *     totals, current stage, and a friendly error on failure;
 *   - the view renders one card per target stage, each grouping the linked
 *     earning activities / activity categories / follow-up types, with a phase
 *     chip + position (current/next/ahead) chip, and deep-links back to the
 *     member's journey detail;
 *   - it is READ-ONLY (no CSRF form), self-contained + locale-aware (RTL), and
 *     redacts the member id;
 *   - the dead generic PageSpec (journey_recommendations) + its Pages lang blocks
 *     were removed (the bespoke view replaces them);
 *   - i18n parity for the new Journey.admin.recommendations.* block across locales.
 *
 *   php app/Modules/Journey/Views/tests/journey_recommendations_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Journey/Language';
$viewDir    = $root . '/app/Modules/Journey/Views';
$controller = $root . '/app/Modules/Journey/Controllers/JourneyController.php';
$routesFile = $root . '/app/Config/Routes.php';
$specsFile  = $root . '/app/Modules/Shared/Navigation/PageSpecs.php';

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
    sort($o);
    return $o;
};

// ── 1. i18n parity for the new recommendations block ─────────────────────────
echo "language parity (admin.recommendations.* — all locales)\n";
$enBlock = (require $langDir . '/en/Journey.php')['admin']['recommendations'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en recommendations block non-trivial (>= 20 keys)', count($enKeys) >= 20, (string) count($enKeys));
foreach (['metaTitle', 'heading', 'member', 'backToMember', 'goToJourney', 'currentStage',
    'scopeLabel', 'scope_both', 'scope_current', 'scope_next', 'applyBtn', 'empty', 'stageEmpty',
    'posCurrent', 'posNext', 'posAhead', 'grpActivities', 'grpCategories', 'grpFollowUps', 'ptsTag'] as $k) {
    chk("en recommendations.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $block = (require $langDir . "/$loc/Journey.php")['admin']['recommendations'] ?? [];
    $keys  = $flatten($block);
    chk("$loc mirrors all en recommendations keys", $keys === $enKeys,
        'diff: ' . implode(',', array_slice(array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys)), 0, 8)));
}

// ── 2. Controller renders the bespoke view + JSON ────────────────────────────
echo "controller: bespoke view + JSON + scope-check\n";
$ctrl = (string) file_get_contents($controller);
chk('recommendations renders WBS\Journey\Views\recommendations', str_contains($ctrl, 'WBS\Journey\Views\recommendations'));
chk('passes grouped stages + totals', (bool) preg_match('/function recommendations\(.*?\'stages\'.*?\'totals\'/s', $ctrl));
chk('passes current_stage + scope', (bool) preg_match('/function recommendations\(.*?current_stage.*?\'scope\'/s', $ctrl));
chk('surfaces friendly error on failure', (bool) preg_match('/function recommendations\(.*?\'error\'/s', $ctrl));
chk('JSON kept for API clients', (bool) preg_match('/function recommendations\(.*?if \(\$this->wantsJson\(\)\)/s', $ctrl));
chk('stays scope-checked', (bool) preg_match('/function recommendations\(.*?authorizeGroupScope\(self::SCOPE_ACTION/s', $ctrl));
// Isolate the recommendations() body (up to the next method) and assert it does
// not fall back to the generic data-page render.
$recBody = '';
if (preg_match('/function recommendations\(.*?\n    (?:public|private|protected) function /s', $ctrl, $mm)) {
    $recBody = $mm[0];
}
chk('no longer uses the generic respondPage for this action', $recBody !== '' && ! str_contains($recBody, 'respondPage('));

// ── 3. Dead generic PageSpec + lang removed ──────────────────────────────────
echo "dead journey_recommendations PageSpec + lang removed\n";
$specs = (string) file_get_contents($specsFile);
chk('journey_recommendations PageSpec removed', ! str_contains($specs, "'journey_recommendations'"));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $pages = (string) file_get_contents($root . "/app/Language/$loc/Pages.php");
    chk("$loc Pages: journey_recommendations block removed", ! str_contains($pages, "'journey_recommendations'"));
}

// ── 4. Reachability: member detail links to recommendations ──────────────────
echo "member.php links to the recommendations dashboard\n";
$member = (string) file_get_contents("$viewDir/member.php");
chk('member page links to /recommendations', str_contains($member, '/recommendations'));
chk('member link carries group context', str_contains($member, "/recommendations<?= \$ctx !== ''"));

// ── 5. View structure ────────────────────────────────────────────────────────
echo "recommendations.php dashboard structure\n";
$src = (string) file_get_contents("$viewDir/recommendations.php");
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
chk('redacts member id', str_contains($src, 'redact_id('));
chk('guard-loads redactor helper', str_contains($src, 'redactor_helper.php'));
chk('one card per stage (iterates $stages)', str_contains($src, 'foreach ($stages as $st'));
chk('groups activities / categories / follow-up types', str_contains($src, "\$st['activities']") && str_contains($src, "\$st['categories']") && str_contains($src, "\$st['follow_up_types']"));
chk('renders a scope selector (GET form)', str_contains($src, 'name="scope"') && str_contains($src, 'method="get"'));
chk('deep-links back to member journey', str_contains($src, "'/journey/members/' . rawurlencode(\$user_id)"));
chk('renders friendly error', str_contains($src, '$error'));
chk('renders empty state', str_contains($src, "\$L('empty')"));
$noComments = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('READ-ONLY: no POST form', ! (bool) preg_match('/method="post"/i', $noComments));
chk('CSP-clean: no <script> tag', ! str_contains($noComments, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', $noComments));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — with stages (fr) + empty + error\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__jLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Journey') { return $key; }
        $v = $GLOBALS['__jLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return is_string($v) ? $v : $key;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__jLoc']  = $loc;
    $GLOBALS['__jLang'] = require $langDir . "/$loc/Journey.php";
    extract($data);
    ob_start();
    include "$viewDir/recommendations.php";
    return (string) ob_get_clean();
};

$stages = [
    [
        'code' => 'new_believer', 'name' => 'New Believer', 'phase' => 'win', 'position' => 'current',
        'activities' => [['code' => 'attend_foundation', 'name' => 'Attend Foundation School', 'phase' => 'win', 'points' => 20, 'icon' => '📖']],
        'categories' => [['code' => 'discipleship', 'name' => 'Discipleship', 'phase' => 'build', 'color' => '#a78bfa']],
        'follow_up_types' => [['code' => 'welcome_call', 'name' => 'Welcome call', 'phase' => 'build']],
    ],
    [
        'code' => 'growing', 'name' => 'Growing', 'phase' => 'build', 'position' => 'next',
        'activities' => [], 'categories' => [], 'follow_up_types' => [],
    ],
];

$h = $render([
    'user_id' => 'user-0000000000ABC12', 'group_id' => 'grp-5',
    'current_stage' => 'new_believer', 'has_journey' => true,
    'stages' => $stages, 'totals' => ['activities' => 1, 'categories' => 1, 'follow_up_types' => 1],
    'scope' => 'both', 'error' => '',
], 'fr');
chk('render: lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('render: member id redacted', str_contains($h, '…0ABC12') && ! str_contains($h, 'user-0000000000ABC12' . '<'));
chk('render: stage names shown', str_contains($h, 'New Believer') && str_contains($h, 'Growing'));
chk('render: linked activity shown', str_contains($h, 'Attend Foundation School'));
chk('render: points tag rendered', str_contains($h, '20'));
chk('render: linked category shown', str_contains($h, 'Discipleship'));
chk('render: linked follow-up type shown', str_contains($h, 'Welcome call'));
chk('render: empty stage note for the next stage', str_contains($h, lang('Journey.admin.recommendations.stageEmpty')));
chk('render: scope selector preselects "both"', (bool) preg_match('/<option value="both" selected/', $h));
chk('render: deep-links to member journey', str_contains($h, 'href="/journey/members/user-0000000000ABC12?group_id=grp-5"'));
chk('render: recommendations GET action carries group_id', str_contains($h, 'name="group_id" value="grp-5"'));
chk('render: no untranslated recommendations keys leaked', ! str_contains($h, 'Journey.admin.recommendations'));

$hEmpty = $render([
    'user_id' => 'user-0000000000ABC12', 'group_id' => null,
    'current_stage' => 'growing', 'has_journey' => true,
    'stages' => [], 'totals' => ['activities' => 0, 'categories' => 0, 'follow_up_types' => 0],
    'scope' => 'both', 'error' => '',
], 'en');
chk('render empty: shows empty state', str_contains($hEmpty, lang('Journey.admin.recommendations.empty')));
chk('render empty: org-wide scope label', str_contains($hEmpty, lang('Journey.admin.recommendations.orgWide')));

$hErr = $render([
    'user_id' => 'user-0000000000ABC12', 'group_id' => 'grp-5',
    'current_stage' => null, 'has_journey' => false,
    'stages' => [], 'totals' => [], 'scope' => 'both',
    'error' => 'No stage ladder for this context; seed or define the ladder first.',
], 'en');
chk('render error: shows the friendly failure', str_contains($hErr, 'No stage ladder for this context'));
chk('render error: no-journey badge shown', str_contains($hErr, lang('Journey.admin.recommendations.noJourneyYet')));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
