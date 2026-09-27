<?php

declare(strict_types=1);

/**
 * MANUAL / IN-KIND giving MAKER capture form test.
 *
 * Closes the last back-office write-gap in the manual-giving maker-checker: the
 * maker (submit) step previously had NO browser form and its POST route carried
 * NO webcsrf. This pins:
 *   - GET  /vbcs/manual/new  -> VbcsController::submitManualForm renders the
 *          bespoke `manual_form` capture page (active-cause picker, fixed type
 *          vocabulary, in-kind category + hours, minor-unit value);
 *   - POST /vbcs/manual      -> submitManual PRGs to the pending queue on success
 *          and re-renders the form with $error + old values on failure; JSON kept
 *          for API; route now webcsrf-guarded + gated by contribution.manage;
 *   - i18n `Contributions.manualForm.*` parity across 6 locales;
 *   - the view is CSP-clean and binds a hidden _csrf;
 *   - headless render smoke (fr fresh + ar re-render with error/sticky).
 *
 *   php app/Modules/Contributions/Views/tests/manual_capture_form_test.php
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
echo "language parity (Contributions.manualForm.*)\n";
$enM = (require $langDir . '/en/Contributions.php')['manualForm'] ?? [];
chk('en manualForm block present (>= 30 keys)', count($enM) >= 30, (string) count($enM));
chk('en has type + category vocab', isset($enM['typeCash'], $enM['typeInKind'], $enM['catGoods'], $enM['catTime']));
chk('en has save + submittedFlash', isset($enM['save'], $enM['submittedFlash']));
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m = (require $langDir . "/$loc/Contributions.php")['manualForm'] ?? [];
    chk("$loc mirrors all en manualForm keys", array_diff(array_keys($enM), array_keys($m)) === [],
        'missing: ' . implode(',', array_diff(array_keys($enM), array_keys($m))));
    chk("$loc has no stray manualForm keys", array_diff(array_keys($m), array_keys($enM)) === [],
        'extra: ' . implode(',', array_diff(array_keys($m), array_keys($enM))));
}

// ── 2. Controller wiring ─────────────────────────────────────────────────────
echo "controller: submitManualForm + submitManual funnel\n";
$ctrl = (string) file_get_contents($controller);
chk('submitManualForm renders manual_form via renderForm', (bool) preg_match('/function submitManualForm.*?renderForm\(.*?manual_form/s', $ctrl));
chk('submitManualForm supplies active causes', (bool) preg_match('/function submitManualForm.*?activeCauses\(\)/s', $ctrl));
chk('submitManual PRGs to pending on success', (bool) preg_match("#function submitManual\(\).*?redirect\(\)->to\('/vbcs/manual/pending'\)#s", $ctrl));
chk('submitManual re-renders form on failure with error', (bool) preg_match("/function submitManual\(\).*?'error'\s*=>\s*\(string\)\s*\\\$result->message/s", $ctrl));
chk('submitManual keeps JSON for API', (bool) preg_match('/function submitManual\(\).*?wantsJson\(\)/s', $ctrl));
chk('submitManual passes submitter as maker', (bool) preg_match("/function submitManual\(\).*?currentUserId\('actor_id'\)/s", $ctrl));

// ── 3. Routes ────────────────────────────────────────────────────────────────
echo "routes: maker form + guarded submit\n";
$routes = (string) file_get_contents($routesFile);
chk('GET vbcs/manual/new present', (bool) preg_match('#\$routes->get\(\'manual/new\',[^\n]*submitManualForm#', $routes));
if (preg_match("#\\\$routes->post\('manual',[^\n]*submitManual'[^\n]*#", $routes, $m)) {
    chk('POST vbcs/manual webcsrf-guarded', str_contains($m[0], 'webcsrf'));
    chk('POST vbcs/manual gated contribution.manage', str_contains($m[0], 'authorize:contribution.manage'));
} else {
    chk('POST vbcs/manual present', false);
}

// ── 4. View controls ─────────────────────────────────────────────────────────
echo "manual_form.php controls\n";
$src = (string) file_get_contents("$viewDir/manual_form.php");
chk('posts to /vbcs/manual', str_contains($src, 'action="/vbcs/manual"'));
chk('binds hidden _csrf to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('cause is a picker (select), not free text', str_contains($src, '<select id="cause_id" name="cause_id" required>'));
chk('type is a fixed-vocab select', str_contains($src, 'name="type"'));
chk('in-kind category + hours captured', str_contains($src, 'name="in_kind_category"') && str_contains($src, 'name="in_kind_hours"'));
chk('value in minor units field', str_contains($src, 'name="stated_value_minor"'));
chk('shows the maker-checker review note', str_contains($src, "('reviewNote')"));
chk('no-active-causes empty state', str_contains($src, "('noActiveCauses')"));
$noC = (string) preg_replace('#/\*.*?\*/#s', '', $src);
chk('CSP-clean: no <script>', ! str_contains($noC, '<script'));
chk('CSP-clean: no inline on* handlers', ! (bool) preg_match('/<[^>]*\son(click|submit|change|input|load)\s*=/i', $noC));

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — fr fresh + ar error/sticky\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
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
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__cLang'] = require $langDir . "/$loc/Contributions.php";
    $renderer = new class {
        public function extend($x) { return ''; }
        public function section($x) { return ''; }
        public function endSection() { return ''; }
    };
    $bound = Closure::bind(function () use ($data, $viewDir) {
        extract($data);
        ob_start();
        include "$viewDir/manual_form.php";
        return (string) ob_get_clean();
    }, $renderer, $renderer);
    return $bound();
};

$causes = [['id' => 'c-1', 'name' => 'Building Fund'], ['id' => 'c-2', 'name' => 'Missions']];
$hFr = $render(['csrf' => 'MK-FR', 'causes' => $causes, 'types' => ['cash', 'cheque', 'bank_transfer', 'in_kind'],
    'categories' => ['goods', 'services', 'time'], 'record' => [], 'error' => '', 'title' => 't'], 'fr');
chk('fr: form posts to /vbcs/manual', str_contains($hFr, 'action="/vbcs/manual"'));
chk('fr: csrf token rendered', str_contains($hFr, 'value="MK-FR"'));
chk('fr: cause options rendered', str_contains($hFr, 'value="c-1"') && str_contains($hFr, 'Building Fund'));
chk('fr: submit label translated', str_contains($hFr, esc($GLOBALS['__cLang']['manualForm']['save'])));
chk('fr: no leaked manualForm.* keys', ! str_contains($hFr, 'Contributions.manualForm.'));

$hAr = $render(['csrf' => 'MK-AR', 'causes' => $causes, 'types' => ['cash', 'cheque', 'bank_transfer', 'in_kind'],
    'categories' => ['goods', 'services', 'time'],
    'record' => ['cause_id' => 'c-2', 'type' => 'in_kind', 'in_kind_category' => 'time', 'in_kind_hours' => '5', 'currency' => 'GHS'],
    'error' => 'Hours must be positive.', 'title' => 't'], 'ar');
chk('ar: error banner shown', str_contains($hAr, 'Hours must be positive.'));
chk('ar: sticky cause selected', (bool) preg_match('/value="c-2" selected/', $hAr));
chk('ar: sticky type selected', (bool) preg_match('/value="in_kind" selected/', $hAr));
chk('ar: sticky in-kind category selected', (bool) preg_match('/value="time" selected/', $hAr));
chk('ar: sticky hours value', str_contains($hAr, 'value="5"'));

// empty-causes guard
$hEmpty = $render(['csrf' => 'X', 'causes' => [], 'types' => ['cash'], 'categories' => ['goods'], 'record' => [], 'error' => '', 'title' => 't'], 'en');
chk('empty: no form when no active causes', ! str_contains($hEmpty, 'action="/vbcs/manual"'));
chk('empty: shows no-active-causes copy', str_contains($hEmpty, esc($enM['noActiveCauses'])));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
