<?php

declare(strict_types=1);

/**
 * ACCOUNT-MERGE approve/reject/cancel WORKFLOW wiring test — proves the identity
 * merge review surface is no longer read-only: the pending queue renders per-request
 * approve/reject forms + a View link, the detail page has a status-aware decision
 * panel (approve/reject/cancel only while pending), the write routes are
 * webcsrf-guarded (on top of authorize:identity.manage), and the controller PRGs
 * to the merge detail with a localized flash while keeping JSON for API clients.
 * Account ids are shown via redact_id and request age via time_ago. Plus i18n
 * parity for the new keys and a headless render smoke of both self-contained views.
 *
 *   php app/Modules/Identity/Views/tests/merges_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Identity/Language';
$viewDir    = $root . '/app/Modules/Identity/Views';
$controller = $root . '/app/Modules/Identity/Controllers/AccountController.php';
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
$pendKeys = ['approve', 'reject', 'view', 'noteLabel', 'approveConfirm', 'rejectConfirm'];
$showKeys = ['actionsHeading', 'approve', 'reject', 'cancel', 'noteLabel', 'approveConfirm', 'rejectConfirm', 'cancelConfirm', 'backToQueue', 'approvedFlash', 'rejectedFlash', 'cancelledFlash'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Identity.php";
    foreach ($pendKeys as $k) {
        chk("$loc mergesPending.$k present", isset($l['mergesPending'][$k]) && $l['mergesPending'][$k] !== '');
    }
    foreach ($showKeys as $k) {
        chk("$loc mergeShow.$k present", isset($l['mergeShow'][$k]) && $l['mergeShow'][$k] !== '');
    }
}

// ── 2. Pending queue controls ────────────────────────────────────────────────
echo "merges_pending.php exposes approve/reject controls\n";
$q = (string) file_get_contents("$viewDir/merges_pending.php");
chk('has approve forms', str_contains($q, '/approve'));
chk('has reject forms', str_contains($q, '/reject'));
chk('approve asks for confirm()', str_contains($q, 'approveConfirm'));
chk('reject asks for confirm()', str_contains($q, 'rejectConfirm'));
chk('has optional note field', str_contains($q, 'name="note"'));
chk('has View link to detail', str_contains($q, 'mergesPending.view'));
chk('forms carry _csrf', substr_count($q, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($q, "session('success')") && str_contains($q, "session('error')"));
chk('uses redact_id on account ids', str_contains($q, 'redact_id('));
chk('uses time_ago on request age', str_contains($q, 'time_ago('));
chk('guard-loads helpers headless', str_contains($q, 'time_helper.php') && str_contains($q, 'redactor_helper.php'));
chk('url helper is test-safe', str_contains($q, "function_exists('base_url')"));

// ── 3. Detail decision panel ─────────────────────────────────────────────────
echo "merge_show.php has a status-aware decision panel\n";
$d = (string) file_get_contents("$viewDir/merge_show.php");
chk('has actions heading', str_contains($d, 'mergeShow.actionsHeading'));
chk('panel gated on pending status', str_contains($d, "\$status === 'pending'"));
chk('has approve/reject/cancel forms', str_contains($d, '/approve') && str_contains($d, '/reject') && str_contains($d, '/cancel'));
chk('cancel asks for confirm()', str_contains($d, 'cancelConfirm'));
chk('forms carry _csrf', substr_count($d, 'name="_csrf"') >= 3);
chk('has back-to-queue link', str_contains($d, 'mergeShow.backToQueue'));
chk('renders PRG flash messages', str_contains($d, "session('success')") && str_contains($d, "session('error')"));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "approve/reject/cancel routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['approveMerge', 'rejectMerge', 'cancelMerge'] as $fn) {
    if (preg_match('#^.*AccountController::' . $fn . '/\$1.*$#m', $routes, $m)) {
        chk("$fn route present", true);
        chk("$fn keeps authorize:identity.manage", str_contains($m[0], 'authorize:identity.manage'));
        chk("$fn webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$fn route present", false);
    }
}

// ── 5. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondMergeDecision PRG helper', str_contains($ctrl, 'private function respondMergeDecision'));
chk('PRG redirects to the merge detail', str_contains($ctrl, "redirect()->to('/identity/merges/'"));
chk('approve flashes approvedFlash', str_contains($ctrl, "respondMergeDecision(\$result, \$mergeId, 'approvedFlash')"));
chk('reject flashes rejectedFlash', str_contains($ctrl, "respondMergeDecision(\$result, \$mergeId, 'rejectedFlash')"));
chk('cancel flashes cancelledFlash', str_contains($ctrl, "respondMergeDecision(\$result, \$mergeId, 'cancelledFlash')"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('pendingMerges passes csrf token', str_contains($ctrl, 'wbsCsrf'));
chk('showMerge passes csrf token', substr_count($ctrl, 'wbsCsrf') >= 2);

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — queue (fr) + detail (ar pending, en approved, not-found)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__idLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Identity') { return $key; }
        $v = $GLOBALS['__idLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__idLoc']  = $loc;
    $GLOBALS['__idLang'] = require $langDir . "/$loc/Identity.php";
    extract($data);
    ob_start();
    include "$viewDir/$file.php";
    return (string) ob_get_clean();
};

$GLOBALS['__flash'] = [];
$hQ = $render('merges_pending', ['csrf' => 'T1', 'merges' => [
    ['id' => 'mg-1', 'primary_user_id' => 'user-0000000000PRIM', 'duplicate_user_id' => 'user-0000000000DUPE',
        'requested_by' => 'user-0000000000REQ1', 'reason' => 'Same person, two signups', 'created_at' => '2026-09-01 08:00:00'],
]], 'fr');
chk('queue: fr lang=fr dir=ltr', str_contains($hQ, 'lang="fr"') && str_contains($hQ, 'dir="ltr"'));
chk('queue: approve posts to /identity/merges/mg-1/approve', str_contains($hQ, 'action="/identity/merges/mg-1/approve"'));
chk('queue: reject posts to /identity/merges/mg-1/reject', str_contains($hQ, 'action="/identity/merges/mg-1/reject"'));
chk('queue: view links to /identity/merges/mg-1', str_contains($hQ, 'href="/identity/merges/mg-1"'));
chk('queue: approve label translated', str_contains($hQ, 'Approuver'));
chk('queue: account ids redacted', str_contains($hQ, '…00PRIM') && ! str_contains($hQ, 'user-0000000000PRIM'));

$GLOBALS['__flash'] = ['error' => 'SoD self-approval'];
$hQ2 = $render('merges_pending', ['csrf' => 'T1', 'merges' => []], 'fr');
chk('queue: empty + error flash rendered', str_contains($hQ2, 'SoD self-approval'));
$GLOBALS['__flash'] = [];

$hDp = $render('merge_show', ['csrf' => 'T2', 'merge' => [
    'id' => 'mg-9', 'primary_user_id' => 'u-1', 'duplicate_user_id' => 'u-2', 'status' => 'pending',
    'reason' => 'dup', 'requested_by' => 'u-3', 'reviews' => [],
]], 'ar');
chk('detail(pending): ar lang=ar dir=rtl', str_contains($hDp, 'lang="ar"') && str_contains($hDp, 'dir="rtl"'));
chk('detail(pending): approve form present', str_contains($hDp, 'action="/identity/merges/mg-9/approve"'));
chk('detail(pending): cancel form present', str_contains($hDp, 'action="/identity/merges/mg-9/cancel"'));

$hDa = $render('merge_show', ['csrf' => 'T3', 'merge' => [
    'id' => 'mg-9', 'primary_user_id' => 'u-1', 'duplicate_user_id' => 'u-2', 'status' => 'approved',
    'reason' => 'dup', 'requested_by' => 'u-3', 'reviews' => [],
]], 'en');
chk('detail(approved): no decision panel', ! str_contains($hDa, '/identity/merges/mg-9/approve') && ! str_contains($hDa, '/identity/merges/mg-9/cancel'));

$hNf = $render('merge_show', ['csrf' => 'T4', 'merge' => null], 'en');
chk('detail(not-found): renders not-found, no actions', str_contains($hNf, 'could not be found') && ! str_contains($hNf, '/approve'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
