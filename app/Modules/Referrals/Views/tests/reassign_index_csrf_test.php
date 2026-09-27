<?php

declare(strict_types=1);

/**
 * Sponsor-reassignment maker-checker CSRF wiring test — a regression guard for a
 * real hole found in the final write-UI sweep: the reassign_index dashboard mints
 * a double-submit CSRF token + cookie and every form carries a hidden _csrf field,
 * BUT the four POST routes (submit / approve / reject / cancel) were missing the
 * `webcsrf` filter, so those tokens were never validated. This test asserts:
 *   - every actionable form in reassign_index.php carries name="_csrf",
 *   - all four write routes are webcsrf-guarded (approve/reject keep the
 *     authorize:sponsor.reassign.approve checker gate; submit/cancel stay auth),
 *   - the read routes (pending/show) remain GET without webcsrf,
 *   - the controller mints the token + cookie and PRGs browsers to the dashboard.
 *
 *   php app/Modules/Referrals/Views/tests/reassign_index_csrf_test.php
 */

$root       = dirname(__DIR__, 5);
$viewDir    = $root . '/app/Modules/Referrals/Views';
$controller = $root . '/app/Modules/Referrals/Controllers/SponsorReassignmentController.php';
$routesFile = $root . '/app/Config/Routes.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

// ── 1. View forms carry _csrf ────────────────────────────────────────────────
echo "reassign_index.php forms carry the double-submit token\n";
$v = (string) file_get_contents("$viewDir/reassign_index.php");
chk('approve form present', str_contains($v, '/approve"'));
chk('reject form present', str_contains($v, '/reject"'));
chk('cancel form present', str_contains($v, '/cancel"'));
chk('submit (new request) form present', str_contains($v, 'action="/referrals/sponsor-reassignments"'));
// one hidden _csrf per form: approve, reject, cancel, submit = 4 (at least).
chk('every form has a hidden _csrf field', substr_count($v, 'name="_csrf"') >= 4,
    'found ' . substr_count($v, 'name="_csrf"'));
chk('tokens bound to $csrf', str_contains($v, 'value="<?= esc($csrf'));

// ── 2. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "sponsor-reassignment write routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
$checks = [
    'SponsorReassignmentController::submit'      => ['auth'],
    'SponsorReassignmentController::approve/$1'   => ['auth', 'authorize:sponsor.reassign.approve'],
    'SponsorReassignmentController::reject/$1'    => ['auth', 'authorize:sponsor.reassign.approve'],
    'SponsorReassignmentController::cancel/$1'    => ['auth'],
];
foreach ($checks as $handler => $mustHave) {
    // match the POST line for this handler
    $q = preg_quote($handler, '#');
    if (preg_match('#\$routes->post\([^\n]*' . $q . '[^\n]*#', $routes, $m)) {
        chk("$handler POST route present", true);
        chk("$handler webcsrf-guarded", str_contains($m[0], 'webcsrf'));
        foreach ($mustHave as $f) {
            chk("$handler keeps $f", str_contains($m[0], $f));
        }
    } else {
        chk("$handler POST route present", false);
    }
}
// read routes stay GET, no webcsrf.
if (preg_match('#\$routes->get\([^\n]*SponsorReassignmentController::pending[^\n]*#', $routes, $mp)) {
    chk('pending is a GET read (no webcsrf)', ! str_contains($mp[0], 'webcsrf'));
}
if (preg_match('#\$routes->get\([^\n]*SponsorReassignmentController::show/\$1[^\n]*#', $routes, $ms)) {
    chk('show is a GET read (no webcsrf)', ! str_contains($ms[0], 'webcsrf'));
}

// ── 3. Controller mints token + cookie + PRG ─────────────────────────────────
echo "controller mints double-submit token/cookie and PRGs browsers\n";
$ctrl = (string) file_get_contents($controller);
chk('mints a csrf token', str_contains($ctrl, 'issueCsrf()'));
chk('sets the wbs_csrf cookie', str_contains($ctrl, "'name'     => 'wbs_csrf'") || str_contains($ctrl, "'wbs_csrf'"));
chk('renders reassign_index for browsers', str_contains($ctrl, 'reassign_index'));
chk('passes csrf to the view', str_contains($ctrl, "'csrf'"));
chk('PRGs browsers after a mutation', str_contains($ctrl, 'redirect()->to(self::DASHBOARD)'));
chk('still returns JSON for API clients', str_contains($ctrl, 'if ($this->wantsJson())'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
