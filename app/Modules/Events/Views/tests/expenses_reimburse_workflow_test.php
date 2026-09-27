<?php

declare(strict_types=1);

/**
 * EXPENSE REIMBURSE workflow wiring test — proves the maker-checker COMPLETION
 * step (approved → reimbursed) is now reachable from the browser approvals
 * launcher, completing the expense lifecycle UI (submit → approve/reject →
 * reimburse). The launcher queue now includes `approved` expenses, each rendering
 * a reimburse form (payment reference required) instead of approve/reject; the
 * controller dispatcher routes `action=reimburse` to ExpenseService::reimburse and
 * queries both `submitted` and `approved`; the route stays webcsrf-guarded. The
 * view also uses the time_ago + redact_id presentation helpers. Plus i18n parity
 * for the new keys and a headless render smoke over a mixed queue.
 *
 *   php app/Modules/Events/Views/tests/expenses_reimburse_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Events/Language';
$viewDir    = $root . '/app/Modules/Events/Views';
$controller = $root . '/app/Modules/Events/Controllers/ExpenseController.php';
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
echo "language parity (new reimburse keys)\n";
$need = ['reimburse', 'paymentRefLabel'];
$enExp = (require $langDir . '/en/Events.php')['expenses'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $exp = (require $langDir . "/$loc/Events.php")['expenses'];
    foreach ($need as $k) {
        chk("$loc expenses.$k present", isset($exp[$k]) && $exp[$k] !== '');
    }
    // full key parity with en (guards the shared approvals test's invariant too).
    chk("$loc mirrors all en expenses keys", array_diff(array_keys($enExp), array_keys($exp)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enExp), array_keys($exp))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "expenses.php exposes a stage-aware reimburse control\n";
$src = (string) file_get_contents("$viewDir/expenses.php");
chk('has status-aware branch', str_contains($src, "\$status === 'approved'"));
chk('reimburse button posts action=reimburse', str_contains($src, 'value="reimburse"'));
chk('reimburse form has required payment_reference', str_contains($src, 'name="payment_reference" required'));
chk('still has approve/reject for submitted', str_contains($src, 'value="approve"') && str_contains($src, 'value="reject"'));
chk('uses time_ago helper', str_contains($src, 'time_ago('));
chk('uses redact_id helper on submitter', str_contains($src, 'redact_id('));
chk('guard-loads time helper for headless', str_contains($src, 'time_helper.php'));
chk('guard-loads redactor helper for headless', str_contains($src, 'redactor_helper.php'));
chk('approved rows show approved amount', str_contains($src, "\$money(\$x, 'approved_amount_minor')"));
chk('reimburse form binds _csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));

// ── 3. Controller dispatch ───────────────────────────────────────────────────
echo "controller dispatches reimburse + widens the queue\n";
$ctrl = (string) file_get_contents($controller);
chk("dispatcher handles action=reimburse", str_contains($ctrl, "elseif (\$action === 'reimburse')"));
chk('reimburse passes payment_reference', str_contains($ctrl, "\$svc->reimburse(\$expenseId, \$actorId, (string) (\$in['payment_reference'] ?? '')"));
chk('queue includes submitted + approved', substr_count($ctrl, "pendingForOrg(\$this->orgId(), ['submitted', 'approved'])") >= 2);

// ── 4. Route still guarded ───────────────────────────────────────────────────
echo "POST /events/expenses stays webcsrf + authorize guarded\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*ExpenseController::approvalsDispatch.*$#m', $routes, $m)) {
    chk('dispatch route present', true);
    chk('dispatch webcsrf-guarded', str_contains($m[0], 'webcsrf'));
    chk('dispatch authorize-guarded', str_contains($m[0], 'authorize:event.expense.approve,any'));
} else {
    chk('dispatch route present', false);
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — mixed queue (fr): submitted → approve/reject, approved → reimburse\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('base_url')) {
    function base_url($p = '') { return 'https://public.test/' . ltrim((string) $p, '/'); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__eLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Events') { return $key; }
        $v = $GLOBALS['__eLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__eLoc']  = $loc;
    $GLOBALS['__eLang'] = require $langDir . "/$loc/Events.php";
    extract($data);
    ob_start();
    include "$viewDir/expenses.php";
    return (string) ob_get_clean();
};

$h = $render(['csrf' => 'CSRFZ', 'expenses' => [
    ['id' => 'ex-1', 'event_title' => 'Sunday Celebration', 'event_id' => 'e-1', 'category' => 'Catering',
        'description' => 'Refreshments', 'currency' => 'GHS', 'amount_minor' => 12500, 'status' => 'submitted',
        'submitted_by' => 'user-0000000000AAA111', 'created_at' => '2026-09-01 08:00:00'],
    ['id' => 'ex-2', 'event_title' => 'Youth Camp', 'event_id' => 'e-2', 'category' => 'Transport',
        'description' => 'Bus hire', 'currency' => 'GHS', 'amount_minor' => 40000, 'approved_amount_minor' => 38000,
        'status' => 'approved', 'submitted_by' => 'user-0000000000BBB222', 'created_at' => '2026-09-05 09:00:00'],
]], 'fr');

// Split into per-expense article segments so per-row assertions can't leak
// across rows.
$articles = array_slice(explode('<article', $h), 1);
$seg = static function (string $xid) use ($articles): string {
    foreach ($articles as $a) {
        if (str_contains($a, 'value="' . $xid . '"')) {
            return $a;
        }
    }
    return '';
};
$row1 = $seg('ex-1');
$row2 = $seg('ex-2');

chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('submitted row (ex-1) shows approve button', str_contains($row1, 'value="approve"'));
chk('submitted row (ex-1) has NO reimburse button', $row1 !== '' && ! str_contains($row1, 'value="reimburse"'));
chk('approved row (ex-2) shows reimburse button', str_contains($row2, 'value="reimburse"'));
chk('approved row (ex-2) has NO approve button', $row2 !== '' && ! str_contains($row2, 'value="approve"'));
chk('reimburse label translated (fr)', str_contains($h, 'Rembourser'));
chk('payment reference label translated (fr)', str_contains($h, 'Référence de paiement'));
chk('approved row shows approved amount 380.00', str_contains($h, '380.00 GHS'));
chk('submitter id redacted (not raw)', str_contains($h, '…AAA111') && ! str_contains($h, 'user-0000000000AAA111'));
chk('both forms carry the csrf token', substr_count($h, 'value="CSRFZ"') === 2);
chk('both forms post to the expenses endpoint', substr_count($h, 'action="https://public.test/events/expenses"') === 2);

// old value refill only on the errored row.
$h2 = $render(['csrf' => 'CSRFZ', 'old' => ['expense_id' => 'ex-2', 'payment_reference' => 'CHQ-77'], 'error' => 'expense.bad_state',
    'expenses' => [
        ['id' => 'ex-2', 'event_title' => 'Youth Camp', 'event_id' => 'e-2', 'category' => 'Transport',
            'description' => 'Bus hire', 'currency' => 'GHS', 'amount_minor' => 40000, 'approved_amount_minor' => 38000,
            'status' => 'approved', 'submitted_by' => 'user-x', 'created_at' => '2026-09-05 09:00:00'],
    ]], 'fr');
chk('errored reimburse row refills payment_reference', str_contains($h2, 'value="CHQ-77"'));
chk('error banner rendered', str_contains($h2, 'expense.bad_state'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
