<?php

declare(strict_types=1);

/**
 * Journey INVOLVEMENT-triage UI surfacing test.
 *
 * The read-side involvement engine (bands + per-member quantum snapshots) is
 * already unit-tested. This pins the UI that SURFACES it to leaders WITHOUT
 * forking the render chain:
 *
 *   - pipeline():   passes `triage_mode` + a minted `csrf` to the self-contained
 *                   pipeline view; the view shows a basis badge (time vs
 *                   involvement), swaps the triage caption, and — only in
 *                   involvement mode — offers a webcsrf-guarded "recompute"
 *                   form that POSTs to /journey/involvement/recompute.
 *   - membersAtStage(): its dynamic $extract, in involvement mode, OVERRIDES the
 *                   static PageSpec columns with the quantum-of-work figures,
 *                   FLATTENS each row's involvement.* bundle to top-level keys the
 *                   generic presenter reads, and supplies a runtime subOverride
 *                   caption. Time mode leaves the legacy columns untouched.
 *   - the shared presenter honours `subOverride` (backward-safe).
 *   - recomputeInvolvement(): a gated + webcsrf POST that calls
 *                   InvolvementService::recomputeContext and PRGs to the pipeline.
 *   - 6-locale i18n parity for the new Journey.* keys.
 *
 *   php app/Modules/Journey/Views/tests/journey_involvement_surfacing_test.php
 */

$root      = dirname(__DIR__, 5);
$langDir   = $root . '/app/Modules/Journey/Language';
$viewDir   = $root . '/app/Modules/Journey/Views';
$ctrlFile  = $root . '/app/Modules/Journey/Controllers/JourneyController.php';
$presenter = $root . '/app/Modules/Shared/Views/presenter/page.php';
$routes    = $root . '/app/Config/Routes.php';
$pagesLang = $root . '/app/Language';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for the new keys ──────────────────────────────────────────
echo "i18n: new Journey.* keys across 6 locales\n";
$newJourney = ['triageSubInvolvement', 'basisLabel', 'basisTime', 'basisInvolvement', 'rosterInvolvementNote', 'recomputeBtn', 'recomputedFlash'];
$newPages   = ['colParticipation', 'colSponsors', 'colGiving', 'colPoints', 'colQuantum', 'colLastActivity'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $j = require $langDir . "/$loc/Journey.php";
    foreach ($newJourney as $k) {
        chk("$loc Journey.$k present + non-empty", isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '');
    }
    $p = require $pagesLang . "/$loc/Pages.php";
    foreach ($newPages as $k) {
        chk("$loc Pages.common.$k present", isset($p['common'][$k]) && is_string($p['common'][$k]) && $p['common'][$k] !== '');
    }
}
// recomputedFlash MUST carry the {0} count placeholder in every locale.
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $j = require $langDir . "/$loc/Journey.php";
    chk("$loc recomputedFlash has {0} placeholder", str_contains((string) $j['recomputedFlash'], '{0}'));
}

// ── 2. Controller wiring ─────────────────────────────────────────────────────
echo "controller: pipeline + membersAtStage + recompute wiring\n";
$ctrl = (string) file_get_contents($ctrlFile);
chk('pipeline passes triage_mode to the view', (bool) preg_match("/'triage_mode'\s*=>/", $ctrl));
chk('pipeline mints csrf for the recompute form', (bool) preg_match("/'csrf'\s*=>\s*\(string\)\s*\(\\\$this->request->wbsCsrf/", $ctrl));
chk('membersAtStage overrides columns in involvement mode', (bool) preg_match('/triage_mode.*?===\s*.involvement/s', $ctrl) && str_contains($ctrl, "\$extra['columns']"));
chk('membersAtStage flattens involvement.* to top-level keys', str_contains($ctrl, "\$extra['rows'][\$i]['quantum']") && str_contains($ctrl, "\$extra['rows'][\$i]['participation']"));
chk('membersAtStage adds involvement quantum column', str_contains($ctrl, "'Pages.common.colQuantum'"));
chk('membersAtStage supplies runtime subOverride', str_contains($ctrl, "'subOverride'") && str_contains($ctrl, "lang('Journey.rosterInvolvementNote')"));
chk('recomputeInvolvement calls recomputeContext', (bool) preg_match('/function recomputeInvolvement.*?recomputeContext/s', $ctrl));
chk('recomputeInvolvement PRGs to the pipeline', (bool) preg_match("#function recomputeInvolvement.*?redirect\(\)->to\(\\\$to\)#s", $ctrl));
chk('recomputeInvolvement interpolates the refreshed count', (bool) preg_match("/function recomputeInvolvement.*?str_replace\('\{0\}'/s", $ctrl));
chk('recomputeInvolvement keeps JSON for API', (bool) preg_match('/function recomputeInvolvement.*?wantsJson\(\)/s', $ctrl));

// ── 3. Route wiring ──────────────────────────────────────────────────────────
echo "routes: recompute POST gated + webcsrf\n";
$rt = (string) file_get_contents($routes);
if (preg_match('#\$routes->post\([^\n]*JourneyController::recomputeInvolvement[^\n]*#', $rt, $m)) {
    chk('POST involvement/recompute present', true);
    chk('recompute is webcsrf-guarded', str_contains($m[0], 'webcsrf'));
    chk('recompute requires gamification.manage', str_contains($m[0], 'authorize:gamification.manage'));
} else {
    chk('POST involvement/recompute present', false);
}

// ── 4. Presenter honours subOverride ─────────────────────────────────────────
echo "presenter: subOverride hook\n";
$pre = (string) file_get_contents($presenter);
chk('presenter applies subOverride when provided', str_contains($pre, "\$page['subOverride']") && str_contains($pre, '$sub = $page[\'subOverride\']'));

// ── 5. View controls (static) ────────────────────────────────────────────────
echo "pipeline.php controls\n";
$src = (string) file_get_contents($viewDir . '/pipeline.php');
chk('view reads $triage_mode', str_contains($src, '$triage_mode'));
chk('view shows a basis badge', str_contains($src, "lang('Journey.basisLabel')") && str_contains($src, "lang('Journey.basisInvolvement')") && str_contains($src, "lang('Journey.basisTime')"));
chk('view swaps triage caption on mode', str_contains($src, 'triageSubInvolvement') && str_contains($src, "lang('Journey.triageSub')"));
chk('recompute form posts to the guarded route', str_contains($src, 'action="/journey/involvement/recompute"'));
chk('recompute form carries _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('recompute form only in involvement mode', (bool) preg_match('/if \(\$byInvolve\):.*?involvement\/recompute/s', $src));
chk('view renders PRG flash', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));

// ── 6. Render smoke: involvement vs time mode ────────────────────────────────
echo "render smoke — pipeline involvement (en) + time (fr)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { public function getLocale() { return $GLOBALS['__jLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__sess'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
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
$GLOBALS['__sess'] = [];
$render = static function (array $data, string $loc) use ($viewDir, $langDir): string {
    $GLOBALS['__jLoc']  = $loc;
    $GLOBALS['__jLang'] = require $langDir . "/$loc/Journey.php";
    extract($data);
    ob_start();
    include $viewDir . '/pipeline.php';
    return (string) ob_get_clean();
};
$stages = [
    ['code' => 'first_timer', 'name' => 'First Timer', 'phase' => 'win', 'order' => 1, 'count' => 5, 'hot' => 4, 'warm' => 1, 'cold' => 0],
];

$en = require $langDir . '/en/Journey.php';
$h  = $render(['group_id' => 'grp-1', 'total' => 5, 'triage' => ['hot' => 4, 'warm' => 1, 'cold' => 0], 'triage_mode' => 'involvement', 'stages' => $stages, 'csrf' => 'TKN9'], 'en');
chk('involvement: basis badge shows involvement label', str_contains($h, esc($en['basisInvolvement'])));
chk('involvement: triage caption is the involvement variant', str_contains($h, esc($en['triageSubInvolvement'])));
chk('involvement: recompute form rendered with csrf', str_contains($h, 'action="/journey/involvement/recompute"') && str_contains($h, 'value="TKN9"'));
chk('involvement: group_id carried into recompute form', str_contains($h, 'name="group_id" value="grp-1"'));
chk('involvement: recompute button labelled', str_contains($h, esc($en['recomputeBtn'])));

$fr = require $langDir . '/fr/Journey.php';
$h2 = $render(['group_id' => null, 'total' => 5, 'triage' => ['hot' => 4, 'warm' => 1, 'cold' => 0], 'triage_mode' => 'time_in_stage', 'stages' => $stages, 'csrf' => 'TKN9'], 'fr');
chk('time mode: basis badge shows time label', str_contains($h2, esc($fr['basisTime'])));
chk('time mode: legacy triage caption used', str_contains($h2, esc($fr['triageSub'])));
chk('time mode: NO recompute form', ! str_contains($h2, 'action="/journey/involvement/recompute"'));

// ── 7. Flash render smoke ────────────────────────────────────────────────────
$GLOBALS['__sess'] = ['success' => 'Recomputed involvement for 7 members.'];
$h3 = $render(['group_id' => null, 'total' => 5, 'triage' => ['hot' => 4, 'warm' => 1, 'cold' => 0], 'triage_mode' => 'involvement', 'stages' => $stages, 'csrf' => 'T'], 'en');
chk('flash: success message rendered', str_contains($h3, 'Recomputed involvement for 7 members.'));
$GLOBALS['__sess'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
