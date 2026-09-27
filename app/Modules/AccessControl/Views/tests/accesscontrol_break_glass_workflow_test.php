<?php

declare(strict_types=1);

/**
 * BREAK-GLASS review WORKFLOW wiring test — proves the emergency-access review
 * surface is no longer read-only: the review queue has per-session
 * Justified/Unjustified review forms + a View link, the detail page has a
 * status-aware action panel (Close when active; Justified/Unjustified review when
 * closed/expired and not yet reviewed), the close/review routes are
 * webcsrf-guarded (in addition to their authorize gates), and the controller does
 * PRG (redirect + flash) for browsers while keeping JSON for API clients. Plus
 * i18n parity for the new action/flash keys and a headless render smoke of both
 * self-contained views.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_break_glass_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/BreakGlassController.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "language parity (new action/flash keys)\n";
$viewKeys = ['view', 'markJustified', 'markUnjustified', 'notesPh', 'unjustifiedConfirm', 'closedFlash', 'reviewedFlash'];
$showKeys = ['actionsHeading', 'close', 'markJustified', 'markUnjustified', 'notesPh', 'closeConfirm', 'unjustifiedConfirm', 'backToQueue'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    foreach ($viewKeys as $k) {
        chk("$loc breakGlassView.$k present", isset($l['breakGlassView'][$k]));
    }
    foreach ($showKeys as $k) {
        chk("$loc breakGlassShowView.$k present", isset($l['breakGlassShowView'][$k]));
    }
}

// ── 2. Review queue controls ─────────────────────────────────────────────────
echo "break_glass_pending.php exposes review controls\n";
$q = (string) file_get_contents("$viewDir/break_glass_pending.php");
chk('has review POST forms', substr_count($q, '/review') >= 2 && str_contains($q, 'method="post"'));
chk('justified outcome posted', str_contains($q, 'value="justified"'));
chk('unjustified outcome posted', str_contains($q, 'value="unjustified"'));
chk('unjustified asks for confirm()', str_contains($q, 'confirm('));
chk('has optional notes field', str_contains($q, 'name="notes"'));
chk('has View link to detail', str_contains($q, 'breakGlassView.view'));
chk('forms carry _csrf', substr_count($q, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($q, "session('success')") && str_contains($q, "session('error')"));
chk('url helper is test-safe', str_contains($q, "function_exists('base_url')"));

// ── 3. Detail action panel ───────────────────────────────────────────────────
echo "break_glass_show.php has a status-aware action panel\n";
$d = (string) file_get_contents("$viewDir/break_glass_show.php");
chk('has actions heading', str_contains($d, 'breakGlassShowView.actionsHeading'));
chk('close shown when active', str_contains($d, '$isActive'));
chk('review shown when needs review', str_contains($d, '$needsRev'));
chk('needsRev gates closed/expired + not reviewed', str_contains($d, "in_array(\$status, ['closed', 'expired'], true)") && str_contains($d, "\$review !== 'reviewed'"));
chk('has close form', str_contains($d, '/close'));
chk('has justified + unjustified review forms', str_contains($d, 'value="justified"') && str_contains($d, 'value="unjustified"'));
chk('close asks for confirm()', str_contains($d, 'closeConfirm'));
chk('forms carry _csrf', substr_count($d, 'name="_csrf"') >= 3);
chk('has back-to-queue link', str_contains($d, 'breakGlassShowView.backToQueue'));
chk('renders PRG flash messages', str_contains($d, "session('success')") && str_contains($d, "session('error')"));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "close/review routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*BreakGlassController::close/\$1.*$#m', $routes, $m)) {
    chk('close route present', true);
    chk('close keeps authorize:access.break_glass', str_contains($m[0], 'authorize:access.break_glass,any'));
    chk('close webcsrf-guarded', str_contains($m[0], 'webcsrf'));
} else {
    chk('close route present', false);
}
if (preg_match('#^.*BreakGlassController::review/\$1.*$#m', $routes, $m)) {
    chk('review route present', true);
    chk('review keeps authorize:access.break_glass.review', str_contains($m[0], 'authorize:access.break_glass.review,any'));
    chk('review webcsrf-guarded', str_contains($m[0], 'webcsrf'));
} else {
    chk('review route present', false);
}

// ── 5. Controller PRG ────────────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondDecision PRG helper', str_contains($ctrl, 'private function respondDecision'));
chk('PRG redirects to the session detail', str_contains($ctrl, "redirect()->to('/break-glass/'"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('pendingReviews passes csrf token', str_contains($ctrl, 'wbsCsrf'));
chk('show passes csrf token', substr_count($ctrl, 'wbsCsrf') >= 2);

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — queue (fr) + detail (ar active, en closed, not-found)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__aLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'AccessControl') { return $key; }
        $v = $GLOBALS['__aLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__aLoc']  = $loc;
    $GLOBALS['__aLang'] = require $langDir . "/$loc/AccessControl.php";
    extract($data);
    ob_start();
    include "$viewDir/$file.php";
    return (string) ob_get_clean();
};

$hQ = $render('break_glass_pending', ['csrf' => 'T1', 'sessions' => [
    ['id' => 'bg-1', 'permission_code' => 'finance.export', 'subject_id' => 'u-1', 'opened_by' => 'u-2',
        'status' => 'closed', 'mfa_level' => 'strong', 'reason' => 'Outage', 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-01'],
]], 'fr');
chk('queue: fr lang=fr dir=ltr', str_contains($hQ, 'lang="fr"') && str_contains($hQ, 'dir="ltr"'));
chk('queue: justified posts to /break-glass/bg-1/review', str_contains($hQ, 'action="/break-glass/bg-1/review"'));
chk('queue: view links to /break-glass/bg-1', str_contains($hQ, 'href="/break-glass/bg-1"'));
chk('queue: justified label translated', str_contains($hQ, 'Justifié'));

$GLOBALS['__flash'] = ['error' => 'Self review blocked'];
$hQ2 = $render('break_glass_pending', ['csrf' => 'T1', 'sessions' => []], 'fr');
chk('queue: empty + error flash rendered', str_contains($hQ2, 'Self review blocked'));
$GLOBALS['__flash'] = [];

$hDa = $render('break_glass_show', ['csrf' => 'T2', 'session' => [
    'id' => 'bg-9', 'permission_code' => 'finance.export', 'subject_id' => 'u-1', 'opened_by' => 'u-2',
    'status' => 'active', 'review_state' => 'pending', 'mfa_level' => 'strong', 'reason' => 'Outage',
]], 'ar');
chk('detail(active): ar lang=ar dir=rtl', str_contains($hDa, 'lang="ar"') && str_contains($hDa, 'dir="rtl"'));
chk('detail(active): close form present', str_contains($hDa, 'action="/break-glass/bg-9/close"'));
chk('detail(active): no review form', ! str_contains($hDa, '/break-glass/bg-9/review'));

$hDc = $render('break_glass_show', ['csrf' => 'T3', 'session' => [
    'id' => 'bg-9', 'permission_code' => 'finance.export', 'subject_id' => 'u-1', 'opened_by' => 'u-2',
    'status' => 'closed', 'review_state' => 'pending', 'mfa_level' => 'strong', 'reason' => 'Outage',
]], 'en');
chk('detail(closed,unreviewed): review forms present', str_contains($hDc, 'action="/break-glass/bg-9/review"'));
chk('detail(closed,unreviewed): no close form', ! str_contains($hDc, '/break-glass/bg-9/close'));

$hDr = $render('break_glass_show', ['csrf' => 'T4', 'session' => [
    'id' => 'bg-9', 'permission_code' => 'finance.export', 'subject_id' => 'u-1', 'opened_by' => 'u-2',
    'status' => 'closed', 'review_state' => 'reviewed', 'mfa_level' => 'strong', 'reason' => 'Outage',
]], 'en');
chk('detail(reviewed): no action panel', ! str_contains($hDr, '/break-glass/bg-9/review') && ! str_contains($hDr, '/break-glass/bg-9/close'));

$hNf = $render('break_glass_show', ['csrf' => 'T5', 'session' => null], 'en');
chk('detail(not-found): renders not-found, no actions', str_contains($hNf, 'not found') && ! str_contains($hNf, '/review'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
