<?php

declare(strict_types=1);

/**
 * COMMITMENT (PLEDGE) CRUD wiring test — proves the VBCS giving-commitments
 * surface grew from a read-only list (create/cancel were JSON-API-only, and the
 * two write routes lacked `webcsrf`) into a full browser CRUD face, mirroring the
 * causes / follow-up-record pattern:
 *
 *   - a new GET /vbcs/commitments/new capture form → VbcsController
 *     ::createCommitmentForm → commitment_form view, with an ACTIVE-cause picker
 *     and a fixed frequency <select>;
 *   - createCommitment PRG-redirects browser callers to the subject's commitments
 *     list with a localized flash (JSON kept for API clients), re-rendering the
 *     form with $error on failure;
 *   - cancelCommitment PRGs back to the list with a localized flash;
 *   - the list view (commitments.php) gains a "New commitment" button + a per-row
 *     Cancel control (only on ACTIVE pledges) posting to the webcsrf-guarded
 *     cancel route, carrying _csrf + subject_id, CSP-clean (no inline on*);
 *   - the two write routes are webcsrf-guarded; the form GET is excluded from menu
 *     coverage (capture action, not a landing page);
 *   - i18n parity for the new Contributions.commitmentForm.* block and the
 *     annual/one_time frequency aliases the service actually emits.
 *
 *   php app/Modules/Contributions/Views/tests/contributions_commitment_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Contributions/Language';
$viewDir    = $root . '/app/Modules/Contributions/Views';
$controller = $root . '/app/Modules/Contributions/Controllers/VbcsController.php';
$service    = $root . '/app/Modules/Contributions/Services/CommitmentService.php';
$routesFile = $root . '/app/Config/Routes.php';
$coverage   = $root . '/app/Modules/Shared/Navigation/MenuCoverage.php';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }
    return $o;
};

// ── 1. i18n parity for the new commitmentForm block + frequency aliases ───────
echo "language parity (Contributions.commitmentForm.* + frequency aliases)\n";
$en      = require $langDir . '/en/Contributions.php';
$enBlock = $en['commitmentForm'] ?? [];
$enKeys  = $flatten($enBlock);
chk('en has commitmentForm block (>= 18 keys)', count($enKeys) >= 18, (string) count($enKeys));
foreach (['heading', 'causeLabel', 'amountLabel', 'frequencyLabel', 'save', 'newCommitment',
    'cancel', 'cancelConfirm', 'cancelYes', 'createdFlash', 'cancelledFlash', 'reminderNote', 'noActiveCauses'] as $k) {
    chk("en commitmentForm.$k present", isset($enBlock[$k]) && $enBlock[$k] !== '');
}
// The service validates against annual + one_time — the picker/list must localize them.
foreach (['monthly', 'quarterly', 'annual', 'one_time'] as $f) {
    chk("en frequency.$f present", isset($en['frequency'][$f]) && $en['frequency'][$f] !== '');
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l  = require $langDir . "/$loc/Contributions.php";
    $lk = $flatten($l['commitmentForm'] ?? []);
    chk("$loc mirrors all en commitmentForm keys", array_diff($enKeys, $lk) === [], implode(',', array_slice(array_diff($enKeys, $lk), 0, 8)));
    chk("$loc has no stray commitmentForm keys", array_diff($lk, $enKeys) === [], implode(',', array_slice(array_diff($lk, $enKeys), 0, 8)));
    foreach (['annual', 'one_time'] as $f) {
        chk("$loc frequency.$f present", isset($l['frequency'][$f]) && $l['frequency'][$f] !== '');
    }
}

// ── 2. Service create/cancel contract (unchanged, but relied on) ──────────────
echo "CommitmentService contract\n";
$svc = (string) file_get_contents($service);
chk('create() exists', (bool) preg_match('/public function create\s*\(/', $svc));
chk('cancel() exists', (bool) preg_match('/public function cancel\s*\(/', $svc));
chk('frequencies include annual + one_time', str_contains($svc, "'annual'") && str_contains($svc, "'one_time'"));
chk('create rejects non-positive amount', str_contains($svc, 'commitment.amount_positive'));

// ── 3. FORM view ─────────────────────────────────────────────────────────────
echo "commitment_form.php capture form\n";
$formSrc = (string) file_get_contents("$viewDir/commitment_form.php");
chk('extends layouts/app', str_contains($formSrc, "\$this->extend('layouts/app')"));
chk('posts to /vbcs/commitments', str_contains($formSrc, 'action="/vbcs/commitments"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('carries subject_id hidden field', str_contains($formSrc, 'name="subject_id"'));
chk('cause is a <select> picker (not free text)', (bool) preg_match('/<select[^>]*name="cause_id"/', $formSrc));
chk('frequency is a <select> picker', (bool) preg_match('/<select[^>]*name="frequency"/', $formSrc));
chk('amount captured as minor units', str_contains($formSrc, 'name="amount_minor"'));
chk('amount required + positive (min=1)', str_contains($formSrc, 'min="1"') && str_contains($formSrc, 'required'));
chk('re-renders $error on failure', str_contains($formSrc, '$error'));
chk('shows reminder-only note', str_contains($formSrc, 'reminderNote'));
chk('handles empty active-cause list', str_contains($formSrc, 'noActiveCauses'));
chk('CSP-clean: no <script>', ! str_contains((string) preg_replace('#/\*.*?\*/#s', '', $formSrc), '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', (string) preg_replace('#/\*.*?\*/#s', '', $formSrc)));

// ── 4. LIST view controls ────────────────────────────────────────────────────
echo "commitments.php list controls\n";
$listSrc = (string) file_get_contents("$viewDir/commitments.php");
chk('has New commitment button', str_contains($listSrc, 'newCommitment'));
chk('links to capture form', str_contains($listSrc, '/vbcs/commitments/new'));
chk('per-row cancel posts to cancel route', str_contains($listSrc, '/cancel"'));
chk('cancel form carries _csrf', str_contains($listSrc, 'name="_csrf"'));
chk('cancel only on ACTIVE pledges', str_contains($listSrc, "\$status === 'active'"));
chk('cancel confirmation copy', str_contains($listSrc, 'cancelConfirm'));
chk('renders PRG flash messages', str_contains($listSrc, "session('success')") && str_contains($listSrc, "session('error')"));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/\son(click|submit|change|input|load)\s*=/i', (string) preg_replace('#/\*.*?\*/#s', '', $listSrc)));

// ── 5. Controller actions ────────────────────────────────────────────────────
echo "controller actions + PRG + JSON\n";
$ctrl = (string) file_get_contents($controller);
chk('createCommitmentForm() renders commitment_form', str_contains($ctrl, 'function createCommitmentForm') && str_contains($ctrl, 'WBS\Contributions\Views\commitment_form'));
chk('form supplies active causes + frequencies', str_contains($ctrl, 'activeCauses()') && str_contains($ctrl, "'one_time'"));
chk('createCommitment PRGs on browser success', (bool) preg_match('/function createCommitment\(.*?redirect\(\)->to\(/s', $ctrl));
chk('createCommitment redirects to subject commitments', str_contains($ctrl, "/commitments'") && str_contains($ctrl, 'createdFlash'));
chk('createCommitment re-renders form on failure', (bool) preg_match('/function createCommitment\(.*?renderForm.*?commitment_form/s', $ctrl));
chk('cancelCommitment PRGs with cancelledFlash', (bool) preg_match('/function cancelCommitment\(.*?cancelledFlash/s', $ctrl));
chk('JSON kept for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'return $this->respondWith($result)'));
chk('list passes csrf token', (bool) preg_match('/function listCommitments\(.*?wbsCsrf/s', $ctrl));

// ── 6. Routes gated + webcsrf ────────────────────────────────────────────────
echo "routes: form GET + write routes webcsrf-guarded\n";
$routes = (string) file_get_contents($routesFile);
chk('GET commitments/new -> createCommitmentForm', (bool) preg_match('#get\(\s*.commitments/new.\s*,.*createCommitmentForm#', $routes));
foreach ([
    'createCommitment' => "post('commitments'",
    'cancelCommitment' => 'cancelCommitment/$1',
] as $label => $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . '.*$#m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}
chk('form GET excluded from menu coverage', str_contains((string) file_get_contents($coverage), 'vbcs/commitments/new'));

// ── 7. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — form (fr) + list with active/cancelled rows (ar)\n";
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
        return is_string($v) ? $v : $key;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
    $renderer = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($file, $data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/$file.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$GLOBALS['__flash'] = [];
$hForm = $render('commitment_form', [
    'csrf' => 'T1', 'subjectId' => 'u-1',
    'causes' => [['id' => 'c-1', 'name' => 'Building fund'], ['id' => 'c-2', 'name' => 'Missions']],
    'frequencies' => ['monthly', 'quarterly', 'annual', 'one_time'],
    'commitment' => [], 'error' => '',
], 'fr');
chk('form: heading translated', str_contains($hForm, 'Nouvel engagement de don'));
chk('form: posts to /vbcs/commitments', str_contains($hForm, 'action="/vbcs/commitments"'));
chk('form: cause option rendered', str_contains($hForm, 'value="c-1"') && str_contains($hForm, 'Building fund'));
chk('form: subject hidden field', str_contains($hForm, 'name="subject_id" value="u-1"'));
chk('form: currency defaults GHS', str_contains($hForm, 'value="GHS"'));
chk('form: one_time frequency localized', str_contains($hForm, 'unique'));

// re-render with error + prefilled values
$hErr = $render('commitment_form', [
    'csrf' => 'T1', 'subjectId' => 'u-1',
    'causes' => [['id' => 'c-1', 'name' => 'Building fund']],
    'frequencies' => ['monthly', 'quarterly', 'annual', 'one_time'],
    'commitment' => ['cause_id' => 'c-1', 'amount_minor' => 5000, 'currency' => 'USD', 'frequency' => 'annual'],
    'error' => 'Amount must be positive',
], 'en');
chk('form: error banner shown', str_contains($hErr, 'Amount must be positive'));
chk('form: amount prefilled', str_contains($hErr, 'value="5000"'));
chk('form: cause preselected', (bool) preg_match('/value="c-1" selected/', $hErr));
chk('form: frequency preselected (annual)', (bool) preg_match('/value="annual" selected/', $hErr));

// empty active-cause state
$hEmpty = $render('commitment_form', ['csrf' => 'T1', 'subjectId' => 'u-1', 'causes' => [], 'frequencies' => ['monthly'], 'commitment' => [], 'error' => ''], 'en');
chk('form: no-active-causes state', str_contains($hEmpty, lang('Contributions.commitmentForm.noActiveCauses')));

// list with an active row (cancel control) + a cancelled row (no control)
$GLOBALS['__flash'] = ['success' => 'تم إنشاء الالتزام.'];
$hList = $render('commitments', [
    'csrf' => 'T2', 'subjectId' => 'u-1',
    'result' => [
        ['id' => 'k-1', 'amount_minor' => 5000, 'currency' => 'GHS', 'frequency' => 'monthly', 'status' => 'active', 'next_due_at' => '2026-10-01'],
        ['id' => 'k-2', 'amount_minor' => 20000, 'currency' => 'GHS', 'frequency' => 'annual', 'status' => 'cancelled'],
    ],
], 'ar');
chk('list: New commitment button links to form', str_contains($hList, '/vbcs/commitments/new'));
chk('list: active row has cancel form', str_contains($hList, 'action="/vbcs/commitments/k-1/cancel"'));
chk('list: cancelled row has NO cancel form', ! str_contains($hList, 'action="/vbcs/commitments/k-2/cancel"'));
chk('list: cancel form carries subject_id', str_contains($hList, 'name="subject_id" value="u-1"'));
chk('list: success flash rendered', str_contains($hList, 'تم إنشاء الالتزام.'));
chk('list: no untranslated commitmentForm keys leaked', ! str_contains($hList, 'Contributions.commitmentForm'));
$GLOBALS['__flash'] = [];

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
