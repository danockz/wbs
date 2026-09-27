<?php

declare(strict_types=1);

/**
 * DELEGATION re-delegate / revoke WORKFLOW wiring test — proves the delegation
 * surface is no longer read-only: the "received" page has a re-delegate (create)
 * form and per-row revoke on active delegations (plus a link into the chain), the
 * chain page has per-row revoke on active nodes, the create/revoke routes are
 * webcsrf-guarded, and the controller does PRG (redirect + flash) for browsers
 * while keeping JSON for API clients — with an open-redirect guard on the posted
 * return path. Plus i18n parity for the new keys and a headless render smoke of
 * both self-contained views across active/revoked rows.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_delegations_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/DelegationController.php';
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
$recvKeys = ['createdFlash', 'revokedFlash', 'redelegHeading', 'redelegHint', 'fDelegate', 'fDelegatePh',
    'fPermission', 'fPermissionPh', 'fScopeMode', 'scopeSelf', 'scopeSelfDesc', 'scopeDescOnly',
    'fScopeGroup', 'fScopeGroupPh', 'fDurationDays', 'fPurpose', 'fPurposePh', 'redelegSubmit',
    'viewChain', 'revoke', 'revokeConfirm', 'reasonPh'];
$chainKeys = ['revoke', 'revokeConfirm', 'reasonPh'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    foreach ($recvKeys as $k) {
        chk("$loc delegationsRecvView.$k present", isset($l['delegationsRecvView'][$k]));
    }
    foreach ($chainKeys as $k) {
        chk("$loc delegationChainView.$k present", isset($l['delegationChainView'][$k]));
    }
}

// ── 2. Received page controls ────────────────────────────────────────────────
echo "delegations_received.php exposes re-delegate + revoke controls\n";
$r = (string) file_get_contents("$viewDir/delegations_received.php");
chk('has re-delegate create form posting to /delegations', str_contains($r, 'action="<?= esc($url(\'delegations\')'));
chk('create form has delegate_id/permission_code/purpose/duration_days', str_contains($r, 'name="delegate_id"') && str_contains($r, 'name="permission_code"') && str_contains($r, 'name="purpose"') && str_contains($r, 'name="duration_days"'));
chk('create form has scope_mode select', str_contains($r, 'name="scope_mode"') && str_contains($r, 'value="self_and_descendants"') && str_contains($r, 'value="descendants_only"'));
chk('per-row revoke form', str_contains($r, '/revoke'));
chk('revoke asks for confirm()', str_contains($r, 'confirm('));
chk('revoke reason is required', str_contains($r, 'name="reason" required'));
chk('revoke carries a return path', str_contains($r, 'name="return"'));
chk('has view-chain link', str_contains($r, '/chain'));
chk('forms carry _csrf', substr_count($r, 'name="_csrf"') >= 2);
chk('revoke gated on active status', str_contains($r, "\$status === 'active'"));
chk('renders PRG flash messages', str_contains($r, "session('success')") && str_contains($r, "session('error')"));
chk('url helper is test-safe', str_contains($r, "function_exists('base_url')"));

// ── 3. Chain page controls ───────────────────────────────────────────────────
echo "delegations_chain.php exposes per-row revoke\n";
$c = (string) file_get_contents("$viewDir/delegations_chain.php");
chk('per-row revoke form', str_contains($c, '/revoke'));
chk('revoke asks for confirm()', str_contains($c, 'confirm('));
chk('revoke reason is required', str_contains($c, 'name="reason" required'));
chk('revoke carries a return path', str_contains($c, 'name="return"'));
chk('revoke gated on active status', str_contains($c, "\$status === 'active'"));
chk('return path is the chain of this delegation', str_contains($c, "'/delegations/' . rawurlencode(\$delegationId) . '/chain'"));
chk('forms carry _csrf', str_contains($c, 'name="_csrf"'));
chk('renders PRG flash messages', str_contains($c, "session('success')") && str_contains($c, "session('error')"));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "create/revoke routes webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*DelegationController::create.*$#m', $routes, $m)) {
    chk('create route present', true);
    chk('create webcsrf-guarded', str_contains($m[0], 'webcsrf'));
} else {
    chk('create route present', false);
}
if (preg_match('#^.*DelegationController::revoke/\$1.*$#m', $routes, $m)) {
    chk('revoke route present', true);
    chk('revoke webcsrf-guarded', str_contains($m[0], 'webcsrf'));
} else {
    chk('revoke route present', false);
}
// still inside the auth group.
chk('delegations group requires auth', (bool) preg_match("#group\('delegations', \['filter' => 'auth'\]#", $routes));

// ── 5. Controller PRG + open-redirect guard ──────────────────────────────────
echo "controller PRG + safe-return guard\n";
$ctrl = (string) file_get_contents($controller);
chk('create PRG redirects to the delegate page', str_contains($ctrl, "'/delegations/received/' . rawurlencode(\$delegate)"));
chk('create flashes createdFlash on success', str_contains($ctrl, 'delegationsRecvView.createdFlash'));
chk('revoke flashes revokedFlash on success', str_contains($ctrl, 'delegationsRecvView.revokedFlash'));
chk('revoke PRG uses safeReturn()', str_contains($ctrl, 'redirect()->to($this->safeReturn())'));
chk('has safeReturn open-redirect guard', str_contains($ctrl, 'private function safeReturn'));
chk('safeReturn only allows /delegations paths', str_contains($ctrl, "str_starts_with(\$return, '/delegations')"));
chk('safeReturn blocks protocol-relative //', str_contains($ctrl, "! str_starts_with(\$return, '//')"));
chk('both still return JSON for API clients', substr_count($ctrl, 'if (! $this->wantsJson())') >= 2 && str_contains($ctrl, 'respondWith($result)'));
chk('views receive csrf token', substr_count($ctrl, 'wbsCsrf') >= 2);

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — received (fr active+revoked) + chain (ar active) + flash\n";
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

$hR = $render('delegations_received', ['csrf' => 'T1', 'subjectId' => 'u-9', 'delegations' => [
    ['id' => 'dg-a', 'permission_code' => 'finance.export', 'delegator_id' => 'u-1', 'status' => 'active',
        'depth' => 1, 'scope_group_id' => 'g-1', 'include_descendants' => true, 'effective_to' => '2026-10-01'],
    ['id' => 'dg-b', 'permission_code' => 'finance.close', 'delegator_id' => 'u-2', 'status' => 'revoked',
        'depth' => 2, 'scope_group_id' => '', 'effective_to' => '2026-09-01'],
]], 'fr');
chk('received: fr lang=fr dir=ltr', str_contains($hR, 'lang="fr"') && str_contains($hR, 'dir="ltr"'));
chk('received: re-delegate form posts to /delegations', str_contains($hR, 'action="/delegations"'));
chk('received: active row has revoke to /delegations/dg-a/revoke', str_contains($hR, 'action="/delegations/dg-a/revoke"'));
chk('received: active row has chain link', str_contains($hR, 'href="/delegations/dg-a/chain"'));
chk('received: revoked row has NO revoke form', ! str_contains($hR, 'action="/delegations/dg-b/revoke"'));
chk('received: return path is this subject page', str_contains($hR, 'value="/delegations/received/u-9"'));
chk('received: submit label translated', str_contains($hR, 'Déléguer'));

$GLOBALS['__flash'] = ['success' => 'Délégation créée.'];
$hR2 = $render('delegations_received', ['csrf' => 'T1', 'subjectId' => 'u-9', 'delegations' => []], 'fr');
chk('received: empty list still shows re-delegate form + success flash', str_contains($hR2, 'action="/delegations"') && str_contains($hR2, 'Délégation créée.'));
$GLOBALS['__flash'] = [];

$hC = $render('delegations_chain', ['csrf' => 'T2', 'delegationId' => 'dg-root', 'delegations' => [
    ['id' => 'dg-root', 'permission_code' => 'finance.export', 'delegator_id' => 'u-1', 'delegate_id' => 'u-2',
        'status' => 'active', 'depth' => 1, 'scope_group_id' => 'g-1', 'include_descendants' => true,
        'effective_from' => '2026-09-01', 'effective_to' => '2026-10-01'],
    ['id' => 'dg-child', 'permission_code' => 'finance.export', 'delegator_id' => 'u-2', 'delegate_id' => 'u-3',
        'status' => 'revoked', 'depth' => 2, 'scope_group_id' => 'g-1', 'include_descendants' => true,
        'effective_from' => '2026-09-02', 'effective_to' => '2026-09-20'],
]], 'ar');
chk('chain: ar lang=ar dir=rtl', str_contains($hC, 'lang="ar"') && str_contains($hC, 'dir="rtl"'));
chk('chain: active node has revoke to /delegations/dg-root/revoke', str_contains($hC, 'action="/delegations/dg-root/revoke"'));
chk('chain: revoked node has NO revoke form', ! str_contains($hC, 'action="/delegations/dg-child/revoke"'));
chk('chain: return path is this chain', str_contains($hC, 'value="/delegations/dg-root/chain"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
