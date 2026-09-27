<?php

declare(strict_types=1);

/**
 * REFUND maker-checker approve/execute WORKFLOW wiring test — proves the refund
 * queue (request -> approve -> execute) is a real console: each open request
 * renders an inline action form POSTing to the webcsrf-guarded
 * /contributions/refunds/{id}/{approve|execute} routes, the controller PRGs back
 * to the queue with a localized flash while keeping JSON for API clients, and the
 * queue uses redact_id + time_ago. `requested` rows show approve (with SoD note);
 * `approved` rows show execute (optional provider fields). Plus i18n parity and a
 * headless render smoke (layout-bound harness).
 *
 *   php app/Modules/Contributions/Views/tests/refund_pending_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Contributions/Language';
$viewDir    = $root . '/app/Modules/Contributions/Views';
$controller = $root . '/app/Modules/Contributions/Controllers/RefundController.php';
$service    = $root . '/app/Modules/Contributions/Services/RefundService.php';
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
echo "language parity (refundPending keys)\n";
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};
$enM = $flatten((require $langDir . '/en/Contributions.php')['refundPending'] ?? []);
chk('en defines refundPending.*', $enM !== []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = $flatten((require $langDir . "/$loc/Contributions.php")['refundPending'] ?? []);
    $miss = array_diff($enM, $m);
    chk("$loc mirrors all en refundPending keys", $miss === [], 'missing: ' . implode(',', $miss));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "refund_pending.php exposes approve/execute controls\n";
$src = (string) file_get_contents("$viewDir/refund_pending.php");
chk('extends layouts/app', str_contains($src, "extend('layouts/app')"));
chk('approve form to /contributions/refunds/.../approve', str_contains($src, '/approve"'));
chk('execute form to /contributions/refunds/.../execute', str_contains($src, '/execute"'));
chk('approve asks for confirm()', str_contains($src, 'approveConfirm'));
chk('execute asks for confirm()', str_contains($src, 'executeConfirm'));
chk('execute carries optional provider field', str_contains($src, 'name="provider"'));
chk('execute carries optional provider_refund_id field', str_contains($src, 'name="provider_refund_id"'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('uses redact_id on requester/donor', str_contains($src, 'redact_id('));
chk('uses time_ago on request age', str_contains($src, 'time_ago('));
chk('guard-loads helpers headless', str_contains($src, 'time_helper.php') && str_contains($src, 'redactor_helper.php'));
chk('rows gated on a refund id', str_contains($src, "\$rid !== ''"));
chk('approve only on requested status', str_contains($src, "\$status === 'requested'"));
chk('execute only on approved status', str_contains($src, "\$status === 'approved'"));
chk('shows SoD note', str_contains($src, 'sodNote'));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has pending() console action', str_contains($ctrl, 'public function pending('));
chk('pending renders refund_pending view', str_contains($ctrl, 'WBS\Contributions\Views\refund_pending'));
chk('pending passes csrf token (wbsCsrf)', str_contains($ctrl, 'wbsCsrf'));
chk('has respondRefundDecision PRG helper', str_contains($ctrl, 'private function respondRefundDecision'));
chk('PRG redirects to the refund queue', str_contains($ctrl, "redirect()->to('/contributions/refunds/pending')"));
chk('approve flashes approvedFlash', str_contains($ctrl, "respondRefundDecision(\$result, 'approvedFlash')"));
chk('execute flashes executedFlash', str_contains($ctrl, "respondRefundDecision(\$result, 'executedFlash')"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('approve uses server-side approver id', str_contains($ctrl, "currentUserId('approver_id')"));

// ── 4. Service read helper ───────────────────────────────────────────────────
echo "service read helper\n";
$svc = (string) file_get_contents($service);
chk('pending() read helper exists', str_contains($svc, 'public function pending('));
chk('pending() lists open statuses only', str_contains($svc, "['requested', 'approved']"));
chk('pending() joins the contribution', str_contains($svc, 'contributions c'));

// ── 5. Routes gated + webcsrf ────────────────────────────────────────────────
echo "routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
chk('GET refunds/pending console gated', (bool) preg_match('#get\(\x27refunds/pending\x27.*?RefundController::pending.*?contribution.refund.approve#s', $routes));
foreach (['approve', 'execute'] as $fn) {
    if (preg_match('#^.*RefundController::' . $fn . '/\$1.*$#m', $routes, $m)) {
        chk("$fn route present", true);
        chk("$fn keeps authorize:contribution.refund.approve", str_contains($m[0], 'authorize:contribution.refund.approve'));
        chk("$fn webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$fn route present", false);
    }
}
chk('request route stays JSON (no webcsrf)', (bool) preg_match('#RefundController::request/\$1.*$#m', $routes, $mm) && ! str_contains($mm[0], 'webcsrf'));

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — queue (fr) + flash + empty\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__flash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Contributions') { return $key; }
        $v = $GLOBALS['__cLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/refund_pending.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = [];
$h = $render(['csrf' => 'RF1', 'title' => 't', 'result' => [
    ['id' => 'rr-1', 'amount_minor' => 25000, 'currency' => 'GHS', 'status' => 'requested',
        'requested_by' => 'user-0000000000REQ01', 'reason' => 'duplicate charge', 'created_at' => '2026-09-01 08:00:00',
        'contribution_amount_minor' => 50000, 'donor_id' => 'user-0000000000DON01'],
    ['id' => 'rr-2', 'amount_minor' => 10000, 'currency' => 'GHS', 'status' => 'approved',
        'requested_by' => 'user-0000000000REQ02', 'approved_by' => 'user-0000000000APR02', 'created_at' => '2026-09-01 09:00:00',
        'contribution_amount_minor' => 10000, 'donor_id' => 'user-0000000000DON02'],
]], 'fr');
chk('queue: approve posts to /contributions/refunds/rr-1/approve', str_contains($h, 'action="/contributions/refunds/rr-1/approve"'));
chk('queue: requested row has NO execute form', ! str_contains($h, 'action="/contributions/refunds/rr-1/execute"'));
chk('queue: execute posts to /contributions/refunds/rr-2/execute', str_contains($h, 'action="/contributions/refunds/rr-2/execute"'));
chk('queue: approved row has NO approve form', ! str_contains($h, 'action="/contributions/refunds/rr-2/approve"'));
chk('queue: approve label translated', str_contains($h, 'Approuver'));
chk('queue: formats money (GHS 250.00)', str_contains($h, 'GHS 250.00'));
chk('queue: requester id redacted', str_contains($h, '…0REQ01') && ! str_contains($h, 'user-0000000000REQ01'));
chk('queue: both forms carry csrf', substr_count($h, 'value="RF1"') === 2);
chk('queue: status pills rendered', str_contains($h, 'rf-pill requested') && str_contains($h, 'rf-pill approved'));

$GLOBALS['__flash'] = ['success' => 'Remboursement approuvé.'];
$hF = $render(['csrf' => 'RF1', 'title' => 't', 'result' => []], 'fr');
chk('queue: empty state shown', str_contains($hF, lang('Contributions.refundPending.empty')));
chk('queue: success flash rendered', str_contains($hF, 'Remboursement approuvé'));
$GLOBALS['__flash'] = [];

echo "\n" . ($fail === 0 ? "PASS" : "FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
