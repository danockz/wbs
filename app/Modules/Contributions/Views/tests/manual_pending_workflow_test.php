<?php

declare(strict_types=1);

/**
 * MANUAL / IN-KIND giving approve/reject WORKFLOW wiring test — proves the
 * maker-checker approval queue is no longer read-only: each submitted record
 * renders inline approve/reject forms POSTing to the webcsrf-guarded
 * /vbcs/manual/{id}/{approve|reject} routes (path-bug fix: the forms + PRG
 * previously targeted the non-existent /contributions/manual/* group and 404'd),
 * the controller PRGs back to the queue with a localized flash while keeping JSON
 * for API clients, and the queue uses the redact_id + time_ago presentation
 * helpers. Approve carries an optional user_id to attribute the giving. The
 * action forms are CSP-clean (no inline on* handlers). Plus i18n parity for the
 * new keys and a headless render smoke (layout-bound harness).
 *
 *   php app/Modules/Contributions/Views/tests/manual_pending_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Contributions/Language';
$viewDir    = $root . '/app/Modules/Contributions/Views';
$controller = $root . '/app/Modules/Contributions/Controllers/VbcsController.php';
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
$need = ['approve', 'reject', 'reasonLabel', 'attributeLabel', 'attributePh', 'approveConfirm', 'rejectConfirm', 'approvedFlash', 'rejectedFlash'];
$enM = (require $langDir . '/en/Contributions.php')['manualPending'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m = (require $langDir . "/$loc/Contributions.php")['manualPending'];
    foreach ($need as $k) {
        chk("$loc manualPending.$k present", isset($m[$k]) && $m[$k] !== '');
    }
    chk("$loc mirrors all en manualPending keys", array_diff(array_keys($enM), array_keys($m)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enM), array_keys($m))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "manual_pending.php exposes approve/reject controls\n";
$src = (string) file_get_contents("$viewDir/manual_pending.php");
chk('has approve form to /vbcs/manual/.../approve', str_contains($src, 'action="/vbcs/manual/') && str_contains($src, '/approve"'));
chk('has reject form to /vbcs/manual/.../reject', str_contains($src, 'action="/vbcs/manual/') && str_contains($src, '/reject"'));
chk('no stale /contributions/manual/* form actions', ! str_contains($src, 'action="/contributions/manual/'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', (string) preg_replace('#/\*.*?\*/#s', '', $src)));
chk('links to the maker capture form', str_contains($src, 'href="/vbcs/manual/new"'));
chk('approve carries optional user_id attribution', str_contains($src, 'name="user_id"'));
chk('reject has optional reason', str_contains($src, 'name="reason"'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('uses redact_id on submitter/custodian', str_contains($src, 'redact_id('));
chk('uses time_ago on submission age', str_contains($src, 'time_ago('));
chk('guard-loads helpers headless', str_contains($src, 'time_helper.php') && str_contains($src, 'redactor_helper.php'));
chk('rows gated on a record id', str_contains($src, "\$rid !== ''"));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondManualDecision PRG helper', str_contains($ctrl, 'private function respondManualDecision'));
chk('PRG redirects to the queue', str_contains($ctrl, "redirect()->to('/vbcs/manual/pending')"));
chk('approve flashes approvedFlash', str_contains($ctrl, "respondManualDecision(\$result, 'approvedFlash')"));
chk('reject flashes rejectedFlash', str_contains($ctrl, "respondManualDecision(\$result, 'rejectedFlash')"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('pendingManual passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "approve/reject routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['approveManual', 'rejectManual'] as $fn) {
    if (preg_match('#^.*VbcsController::' . $fn . '/\$1.*$#m', $routes, $m)) {
        chk("$fn route present", true);
        chk("$fn keeps authorize:contribution.manage", str_contains($m[0], 'authorize:contribution.manage'));
        chk("$fn webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$fn route present", false);
    }
}
// Maker step: capture form GET + webcsrf-guarded submit POST (the write-gap fix).
if (preg_match('#\$routes->get\([^\n]*VbcsController::submitManualForm[^\n]*#', $routes, $mgf)) {
    chk('submitManualForm GET route present', true);
    chk('maker form is a read (no webcsrf)', ! str_contains($mgf[0], 'webcsrf'));
    chk('maker form gated by contribution.manage', str_contains($mgf[0], 'authorize:contribution.manage'));
} else {
    chk('submitManualForm GET route present', false);
}
if (preg_match("#\\\$routes->post\('manual',[^\n]*VbcsController::submitManual'[^\n]*#", $routes, $msp)) {
    chk('submitManual POST route present', true);
    chk('submitManual POST now webcsrf-guarded', str_contains($msp[0], 'webcsrf'));
    chk('submitManual POST gated by contribution.manage', str_contains($msp[0], 'authorize:contribution.manage'));
} else {
    chk('submitManual POST route present', false);
}

// ── 5. Headless render smoke (layout-bound harness) ──────────────────────────
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
        include "$viewDir/manual_pending.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = [];
$h = $render(['csrf' => 'MC1', 'title' => 't', 'result' => [
    ['id' => 'mr-1', 'type' => 'in_kind', 'stated_value_minor' => 50000, 'currency' => 'GHS',
        'valuation_method' => 'appraised', 'received_date' => '2026-09-01', 'created_at' => '2026-09-01 08:00:00',
        'custodian_id' => 'user-0000000000CUST1', 'submitted_by' => 'user-0000000000SUBM1', 'evidence_ref' => 'DOC-9'],
]], 'fr');
chk('queue: approve posts to /vbcs/manual/mr-1/approve', str_contains($h, 'action="/vbcs/manual/mr-1/approve"'));
chk('queue: reject posts to /vbcs/manual/mr-1/reject', str_contains($h, 'action="/vbcs/manual/mr-1/reject"'));
chk('queue: approve label translated', str_contains($h, 'Approuver'));
chk('queue: formats money (GHS 500.00)', str_contains($h, 'GHS 500.00'));
chk('queue: submitter id redacted', str_contains($h, '…0SUBM1') && ! str_contains($h, 'user-0000000000SUBM1'));
chk('queue: custodian id redacted', str_contains($h, '…0CUST1') && ! str_contains($h, 'user-0000000000CUST1'));
chk('queue: both forms carry csrf', substr_count($h, 'value="MC1"') === 2);

$GLOBALS['__flash'] = ['success' => 'Don manuel approuvé et enregistré.'];
$hF = $render(['csrf' => 'MC1', 'title' => 't', 'result' => []], 'fr');
chk('queue: empty state shown', str_contains($hF, lang('Contributions.manualPending.empty')));
chk('queue: success flash rendered', str_contains($hF, 'Don manuel approuvé'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
