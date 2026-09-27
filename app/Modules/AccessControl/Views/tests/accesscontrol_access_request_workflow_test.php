<?php

declare(strict_types=1);

/**
 * ACCESS-REQUEST approval WORKFLOW wiring test — proves the maker-checker
 * approval surface is no longer read-only: the pending queue has per-request
 * Approve/Reject forms + a View link, the detail page has a status-aware action
 * panel (Approve/Reject when pending; Renew/Revoke when active), the four review
 * routes are webcsrf-guarded (in addition to authorize:access.request.approve),
 * and the controller does PRG (redirect + flash) for browsers while keeping JSON
 * for API clients. Plus i18n parity for the new action/flash keys and a headless
 * render smoke of both self-contained views (fr pending, ar detail).
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_access_request_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/AccessRequestController.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. i18n parity for new action + flash keys ───────────────────────────────
echo "language parity (new action/flash keys)\n";
$viewKeys = ['approve', 'reject', 'view', 'notePh', 'rejectConfirm', 'approvedFlash', 'rejectedFlash', 'revokedFlash', 'renewedFlash'];
$showKeys = ['actionsHeading', 'approve', 'reject', 'revoke', 'renew', 'notePh', 'reasonPh', 'backToQueue', 'rejectConfirm', 'revokeConfirm'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    foreach ($viewKeys as $k) {
        chk("$loc accessReqView.$k present", isset($l['accessReqView'][$k]));
    }
    foreach ($showKeys as $k) {
        chk("$loc accessReqShowView.$k present", isset($l['accessReqShowView'][$k]));
    }
}

// ── 2. Pending queue controls ────────────────────────────────────────────────
echo "access_requests_pending.php exposes review controls\n";
$q = (string) file_get_contents("$viewDir/access_requests_pending.php");
chk('has approve POST form', str_contains($q, '/approve') && str_contains($q, 'method="post"'));
chk('has reject POST form', str_contains($q, '/reject'));
chk('reject asks for confirm()', str_contains($q, 'confirm('));
chk('has optional note field', str_contains($q, 'name="note"'));
chk('has View link to detail', str_contains($q, 'accessReqView.view'));
chk('forms carry _csrf', substr_count($q, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($q, "session('success')") && str_contains($q, "session('error')"));
chk('url helper is test-safe', str_contains($q, "function_exists('base_url')"));

// ── 3. Detail action panel ───────────────────────────────────────────────────
echo "access_request_show.php has a status-aware action panel\n";
$d = (string) file_get_contents("$viewDir/access_request_show.php");
chk('has actions heading', str_contains($d, 'accessReqShowView.actionsHeading'));
chk('approve/reject shown when pending', str_contains($d, '$isPending'));
chk('renew/revoke shown when active', str_contains($d, '$isActive'));
chk('has approve form', str_contains($d, '/approve'));
chk('has reject form', str_contains($d, '/reject'));
chk('has revoke form (reason required)', str_contains($d, '/revoke') && str_contains($d, 'name="reason" required'));
chk('has renew form (extra_days)', str_contains($d, '/renew') && str_contains($d, 'name="extra_days"'));
chk('forms carry _csrf', substr_count($d, 'name="_csrf"') >= 4);
chk('has back-to-queue link', str_contains($d, 'accessReqShowView.backToQueue'));
chk('renders PRG flash messages', str_contains($d, "session('success')") && str_contains($d, "session('error')"));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "review routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['approve', 'reject', 'revoke', 'renew'] as $act) {
    if (preg_match('#^.*AccessRequestController::' . $act . '/\$1.*$#m', $routes, $m)) {
        chk("$act route present", true);
        chk("$act keeps authorize:access.request.approve", str_contains($m[0], 'authorize:access.request.approve'));
        chk("$act webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$act route present", false);
    }
}

// ── 5. Controller PRG ────────────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondDecision PRG helper', str_contains($ctrl, 'private function respondDecision'));
chk('PRG redirects to the request detail', str_contains($ctrl, "redirect()->to('/access-requests/'"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('pending passes csrf token', str_contains($ctrl, 'wbsCsrf'));
chk('show passes csrf token', substr_count($ctrl, 'wbsCsrf') >= 2);

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — queue (fr) + detail (ar, pending & active)\n";
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

$hQ = $render('access_requests_pending', ['csrf' => 'T1', 'requests' => [
    ['id' => 'ar-1', 'grant_type' => 'role', 'role_id' => 'r-admin', 'subject_id' => 'u-1',
        'requested_by' => 'u-2', 'conflict_state' => 'none', 'reason' => 'Cover leave', 'created_at' => '2026-09-01'],
]], 'fr');
chk('queue: fr lang=fr dir=ltr', str_contains($hQ, 'lang="fr"') && str_contains($hQ, 'dir="ltr"'));
chk('queue: approve posts to /access-requests/ar-1/approve', str_contains($hQ, 'action="/access-requests/ar-1/approve"'));
chk('queue: reject posts to /access-requests/ar-1/reject', str_contains($hQ, 'action="/access-requests/ar-1/reject"'));
chk('queue: view links to /access-requests/ar-1', str_contains($hQ, 'href="/access-requests/ar-1"'));
chk('queue: approve label translated', str_contains($hQ, 'Approuver'));

$GLOBALS['__flash'] = ['success' => 'OK done'];
$hQ2 = $render('access_requests_pending', ['csrf' => 'T1', 'requests' => []], 'fr');
chk('queue: empty state + flash rendered', str_contains($hQ2, 'OK done'));
$GLOBALS['__flash'] = [];

$hDp = $render('access_request_show', ['csrf' => 'T2', 'request' => [
    'id' => 'ar-9', 'grant_type' => 'permission', 'permission_code' => 'contribution.manage',
    'subject_id' => 'u-1', 'requested_by' => 'u-2', 'status' => 'pending', 'conflict_state' => 'none',
]], 'ar');
chk('detail(pending): ar lang=ar dir=rtl', str_contains($hDp, 'lang="ar"') && str_contains($hDp, 'dir="rtl"'));
chk('detail(pending): approve form present', str_contains($hDp, 'action="/access-requests/ar-9/approve"'));
chk('detail(pending): reject form present', str_contains($hDp, 'action="/access-requests/ar-9/reject"'));
chk('detail(pending): no revoke form', ! str_contains($hDp, '/access-requests/ar-9/revoke'));

$hDa = $render('access_request_show', ['csrf' => 'T3', 'request' => [
    'id' => 'ar-9', 'grant_type' => 'permission', 'permission_code' => 'contribution.manage',
    'subject_id' => 'u-1', 'requested_by' => 'u-2', 'status' => 'approved', 'conflict_state' => 'none',
]], 'en');
chk('detail(active): renew form present', str_contains($hDa, 'action="/access-requests/ar-9/renew"'));
chk('detail(active): revoke form present', str_contains($hDa, 'action="/access-requests/ar-9/revoke"'));
chk('detail(active): no approve form', ! str_contains($hDa, '/access-requests/ar-9/approve'));

$hNf = $render('access_request_show', ['csrf' => 'T4', 'request' => null], 'en');
chk('detail(not-found): renders not-found, no action panel', str_contains($hNf, 'was not found') && ! str_contains($hNf, '/approve'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
