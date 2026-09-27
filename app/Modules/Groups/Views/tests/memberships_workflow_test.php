<?php

declare(strict_types=1);

/**
 * GROUP-MEMBERSHIP approve/reject WORKFLOW wiring test — proves the reviewer queue
 * is no longer read-only: each pending request renders inline approve/reject forms
 * POSTing to the webcsrf-guarded /memberships/{id}/{approve|reject} routes (each
 * carrying an open-redirect-guarded return path back to this group's queue), the
 * controller PRGs back with a localized flash while keeping JSON for API clients,
 * and the queue uses the redact_id + time_ago presentation helpers. Plus i18n
 * parity for the new keys and a headless render smoke.
 *
 *   php app/Modules/Groups/Views/tests/memberships_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Groups/Language';
$viewDir    = $root . '/app/Modules/Groups/Views';
$controller = $root . '/app/Modules/Groups/Controllers/GroupMembershipController.php';
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
$need = ['approve', 'reject', 'noteLabel', 'reasonLabel', 'approveConfirm', 'rejectConfirm', 'approvedFlash', 'rejectedFlash'];
$enP = (require $langDir . '/en/Groups.php')['pending'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $p = (require $langDir . "/$loc/Groups.php")['pending'];
    foreach ($need as $k) {
        chk("$loc pending.$k present", isset($p[$k]) && $p[$k] !== '');
    }
    chk("$loc mirrors all en pending keys", array_diff(array_keys($enP), array_keys($p)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enP), array_keys($p))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "memberships_pending.php exposes approve/reject controls\n";
$q = (string) file_get_contents("$viewDir/memberships_pending.php");
chk('has approve forms', str_contains($q, '/approve'));
chk('has reject forms', str_contains($q, '/reject'));
chk('approve asks for confirm()', str_contains($q, 'approveConfirm'));
chk('reject asks for confirm()', str_contains($q, 'rejectConfirm'));
chk('approve has optional note', str_contains($q, 'name="note"'));
chk('reject has optional reason', str_contains($q, 'name="reason"'));
chk('forms carry a return path', str_contains($q, 'name="return"'));
chk('forms carry _csrf', substr_count($q, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($q, "session('success')") && str_contains($q, "session('error')"));
chk('uses redact_id on user id', str_contains($q, 'redact_id('));
chk('uses time_ago on queue age', str_contains($q, 'time_ago('));
chk('guard-loads helpers headless', str_contains($q, 'time_helper.php') && str_contains($q, 'redactor_helper.php'));
chk('return path targets this group queue', str_contains($q, "'/groups/' . rawurlencode(\$groupId) . '/memberships/pending'"));
chk('url helper is test-safe', str_contains($q, "function_exists('base_url')"));

// ── 3. Controller PRG + guard + csrf ─────────────────────────────────────────
echo "controller PRG + safe-return + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondMembershipDecision PRG helper', str_contains($ctrl, 'private function respondMembershipDecision'));
chk('approve flashes approvedFlash', str_contains($ctrl, "respondMembershipDecision(\$result, 'Groups.pending.approvedFlash')"));
chk('reject flashes rejectedFlash', str_contains($ctrl, "respondMembershipDecision(\$result, 'Groups.pending.rejectedFlash')"));
chk('PRG uses safeReturn()', str_contains($ctrl, 'redirect()->to($this->safeReturn())'));
chk('has safeReturn open-redirect guard', str_contains($ctrl, 'private function safeReturn'));
chk('safeReturn only allows /groups paths', str_contains($ctrl, "str_starts_with(\$return, '/groups')"));
chk('safeReturn blocks protocol-relative //', str_contains($ctrl, "! str_starts_with(\$return, '//')"));
chk('browser note maps to evidence', str_contains($ctrl, "\$evidence = ['note' => \$note]"));
chk('both still return JSON for API clients', substr_count($ctrl, 'if (! $this->wantsJson())') >= 1 && str_contains($ctrl, 'respondWith($result)'));
chk('pending passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "approve/reject routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['approve', 'reject'] as $act) {
    if (preg_match('#^.*GroupMembershipController::' . $act . '/\$1.*$#m', $routes, $m)) {
        chk("$act route present", true);
        chk("$act keeps authorize:group.change.approve", str_contains($m[0], 'authorize:group.change.approve'));
        chk("$act webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$act route present", false);
    }
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — queue (fr active) + flash + empty\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Groups') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/memberships_pending.php";
    return (string) ob_get_clean();
};

$GLOBALS['__flash'] = [];
$h = $render(['csrf' => 'CS1', 'groupId' => 'grp-7', 'pending' => [
    ['id' => 'mm-1', 'user_id' => 'user-0000000000AAA9', 'membership_type' => 'member', 'role' => 'none',
        'source' => 'self_join', 'joined_at' => '2026-09-01 08:00:00'],
]], 'fr');
chk('queue: fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('queue: approve posts to /memberships/mm-1/approve', str_contains($h, 'action="/memberships/mm-1/approve"'));
chk('queue: reject posts to /memberships/mm-1/reject', str_contains($h, 'action="/memberships/mm-1/reject"'));
chk('queue: return path present', str_contains($h, 'value="/groups/grp-7/memberships/pending"'));
chk('queue: approve label translated', str_contains($h, 'Approuver'));
chk('queue: user id redacted', str_contains($h, '…00AAA9') && ! str_contains($h, 'user-0000000000AAA9'));
chk('queue: both forms carry csrf', substr_count($h, 'value="CS1"') === 2);

$GLOBALS['__flash'] = ['success' => 'Adhésion approuvée.'];
$hF = $render(['csrf' => 'CS1', 'groupId' => 'grp-7', 'pending' => []], 'fr');
chk('queue: empty state shown', str_contains($hF, lang('Groups.pending.empty')));
chk('queue: success flash rendered', str_contains($hF, 'Adhésion approuvée.'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
