<?php

declare(strict_types=1);

/**
 * AWARDS PENDING approve/reject WORKFLOW wiring test — proves the pending
 * award-approval queue is no longer read-only: each held award now renders inline
 * approve/reject forms POSTing to the webcsrf-guarded
 * /gamification/awards/{id}/{approve|reject} routes, the controller PRGs back to
 * the queue with a localized flash (JSON kept for API clients) and passes the CSRF
 * token, and the queue uses the time_ago + redact_id presentation helpers. Plus
 * i18n parity for the new keys and a headless render smoke (layout-bound harness).
 *
 *   php app/Modules/Gamification/Views/tests/awards_pending_workflow_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$controller = $root . '/app/Modules/Gamification/Controllers/AwardsController.php';
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
$need = ['approve', 'reject', 'notesLabel', 'reasonLabel', 'rejectConfirm', 'approvedFlash', 'rejectedFlash'];
$enAp = (require $langDir . '/en/Gamification.php')['admin']['awardsPending'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $ap = (require $langDir . "/$loc/Gamification.php")['admin']['awardsPending'];
    foreach ($need as $k) {
        chk("$loc awardsPending.$k present", isset($ap[$k]) && $ap[$k] !== '');
    }
    chk("$loc mirrors all en awardsPending keys", array_diff(array_keys($enAp), array_keys($ap)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enAp), array_keys($ap))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "awards_pending.php exposes approve/reject controls\n";
$src = (string) file_get_contents("$viewDir/awards_pending.php");
chk('has approve form to /gamification/awards/.../approve', str_contains($src, '/approve"'));
chk('has reject form to /gamification/awards/.../reject', str_contains($src, '/reject"'));
chk('reject asks for confirm()', str_contains($src, 'confirm('));
chk('reject reason is required', str_contains($src, 'name="reason" required'));
chk('approve has optional notes', str_contains($src, 'name="notes"'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('uses time_ago helper', str_contains($src, 'time_ago('));
chk('uses redact_id helper on subject', str_contains($src, 'redact_id('));
chk('guard-loads time helper for headless', str_contains($src, 'time_helper.php'));
chk('guard-loads redactor helper for headless', str_contains($src, 'redactor_helper.php'));
chk('rows gated on a ledger id', str_contains($src, "\$lid !== ''"));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondDecision PRG helper', str_contains($ctrl, 'private function respondDecision'));
chk('PRG redirects to the queue', str_contains($ctrl, "redirect()->to('/gamification/pending')"));
chk('approve flashes approvedFlash', str_contains($ctrl, "respondDecision(\$result, 'approvedFlash')"));
chk('reject flashes rejectedFlash', str_contains($ctrl, "respondDecision(\$result, 'rejectedFlash')"));
chk('scope-deny also PRGs for browsers', str_contains($ctrl, "redirect()->to('/gamification/pending')->with('error'"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('pending passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "approve/reject routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['approve', 'reject'] as $act) {
    if (preg_match('#^.*AwardsController::' . $act . '/\$1.*$#m', $routes, $m)) {
        chk("$act route present", true);
        chk("$act keeps authorize:gamification.manage,any", str_contains($m[0], 'authorize:gamification.manage,any'));
        chk("$act webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$act route present", false);
    }
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
        if (array_shift($p) !== 'Gamification') { return $key; }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/awards_pending.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = [];
$h = $render(['csrf' => 'CSRFA', 'awards' => [
    ['id' => 'lg-1', 'subject_id' => 'user-0000000000ZZ9', 'points' => 25, 'entry_type' => 'award',
        'rule_id' => 'attend_service', 'source_ref' => 'evt-1', 'explanation' => 'Attended a service',
        'created_at' => '2026-09-01 08:00:00'],
    ['id' => 'lg-2', 'subject_id' => 'user-0000000000YY8', 'points' => 10, 'entry_type' => 'award',
        'created_at' => '2026-09-05 09:00:00'],
]], 'fr');
chk('fr: approve form to /gamification/awards/lg-1/approve', str_contains($h, 'action="/gamification/awards/lg-1/approve"'));
chk('fr: reject form to /gamification/awards/lg-1/reject', str_contains($h, 'action="/gamification/awards/lg-1/reject"'));
chk('fr: approve label translated', str_contains($h, 'Approuver'));
chk('fr: reject label translated', str_contains($h, 'Rejeter'));
chk('fr: both rows get action panels', substr_count($h, '/approve"') === 2 && substr_count($h, '/reject"') === 2);
chk('fr: csrf on every form (4 total)', substr_count($h, 'value="CSRFA"') === 4);
chk('fr: subject id redacted', str_contains($h, '…000ZZ9') && ! str_contains($h, 'user-0000000000ZZ9'));

$GLOBALS['__flash'] = ['success' => 'Récompense approuvée.'];
$hF = $render(['csrf' => 'CSRFA', 'awards' => []], 'fr');
chk('empty state shown', str_contains($hF, 'Aucune récompense') || str_contains($hF, 'awaiting') || str_contains($hF, lang('Gamification.admin.awardsPending.empty')));
chk('success flash rendered', str_contains($hF, 'Récompense approuvée.'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
