<?php

declare(strict_types=1);

/**
 * IDENTITY-POLICY upsert WRITE-UI wiring test — proves the per-jurisdiction
 * identity policy surface is no longer read-only: the console renders a top
 * "add a policy" form plus an inline edit form per existing row, both posting to
 * the webcsrf-guarded upsert route (on top of authorize:identity.manage), and the
 * controller PRGs back to /identity/policies with a localized flash while keeping
 * JSON for API clients. Plus i18n parity for the new editor keys and a headless
 * render smoke of the self-contained view (fr rows + edit forms, ar RTL, empty).
 *
 *   php app/Modules/Identity/Views/tests/policy_editor_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Identity/Language';
$viewDir    = $root . '/app/Modules/Identity/Views';
$controller = $root . '/app/Modules/Identity/Controllers/IdentityPolicyController.php';
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
echo "language parity (new editor keys)\n";
$editorKeys = [
    'editHeading', 'editSub', 'countryLabel', 'countryPh', 'countryHelp',
    'minAgeLabel', 'phoneRegionLabel', 'phoneRegionPh', 'requireEmailUnique',
    'requirePhoneUnique', 'allowMinorLabel', 'notesLabel', 'notesPh',
    'save', 'saveConfirm', 'savedFlash', 'editExistingHint', 'on', 'off',
];
$enPolicies = (require $langDir . '/en/Identity.php')['policies'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Identity.php";
    chk("$loc has policies block", isset($l['policies']) && is_array($l['policies']));
    foreach ($editorKeys as $k) {
        chk("$loc policies.$k present", isset($l['policies'][$k]) && $l['policies'][$k] !== '');
    }
    // full key-set parity against en
    chk("$loc mirrors all en policies keys", array_keys($l['policies']) === array_keys($enPolicies),
        implode(',', array_diff(array_keys($enPolicies), array_keys($l['policies']))));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "identity_policies.php exposes upsert controls\n";
$v = (string) file_get_contents("$viewDir/identity_policies.php");
chk('renders an add-policy editor', str_contains($v, 'policies.editHeading'));
chk('has country_code input', str_contains($v, 'name="country_code"'));
chk('has min_age input', str_contains($v, 'name="min_age"'));
chk('has phone_default_region input', str_contains($v, 'name="phone_default_region"'));
chk('has require_email_unique checkbox', str_contains($v, 'name="require_email_unique"'));
chk('has require_phone_unique checkbox', str_contains($v, 'name="require_phone_unique"'));
chk('has allow_minor checkbox', str_contains($v, 'name="allow_minor"'));
chk('has notes input', str_contains($v, 'name="notes"'));
// The surface is no-JS / CSP-safe: every form carries a STATIC action (no inline
// onsubmit/handler that our CSP would block), and the add form posts to the
// segment-less endpoint with country_code in the body.
chk('no inline event handlers (CSP-safe)', ! str_contains($v, 'onsubmit=') && ! str_contains($v, 'wbsPolicySubmit'));
chk('no inline <script> block', ! preg_match('/<script\b/', $v));
chk('add form posts to segment-less create route', str_contains($v, "\$url('identity/policies')"));
chk('forms carry _csrf', substr_count($v, 'name="_csrf"') >= 1);
chk('per-row edit form in details', str_contains($v, '<details>') && str_contains($v, 'policies.editExistingHint'));
chk('renders PRG flash messages', str_contains($v, "session('success')") && str_contains($v, "session('error')"));
chk('url helper is test-safe', str_contains($v, "function_exists('base_url')"));
chk('posts to identity/policies path', str_contains($v, "identity/policies/"));
chk('edit form country is read-only', str_contains($v, 'readonly'));

// ── 3. Route webcsrf-guarded ─────────────────────────────────────────────────
echo "upsert route gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
if (preg_match('#^.*IdentityPolicyController::upsert/\$1.*$#m', $routes, $m)) {
    chk('upsert route present', true);
    chk('upsert keeps authorize:identity.manage', str_contains($m[0], 'authorize:identity.manage'));
    chk('upsert webcsrf-guarded', str_contains($m[0], 'webcsrf'));
} else {
    chk('upsert route present', false);
}
// index/resolve stay GET (no webcsrf needed)
chk('index route present (GET console)', (bool) preg_match('#IdentityPolicyController::index#', $routes));

// ── 4. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondPolicyUpsert PRG helper', str_contains($ctrl, 'private function respondPolicyUpsert'));
chk('PRG redirects to the policy console', str_contains($ctrl, "redirect()->to('/identity/policies')"));
chk('upsert flashes savedFlash', str_contains($ctrl, "lang('Identity.policies.savedFlash')"));
chk('still returns JSON for API clients', str_contains($ctrl, 'if (! $this->wantsJson())') && str_contains($ctrl, 'respondWith($result)'));
chk('index passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — console (fr rows, ar RTL, empty)\n";
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

$rows = [
    ['country_code' => '*', 'min_age' => 13, 'phone_default_region' => 'GH',
        'require_email_unique' => true, 'require_phone_unique' => false, 'allow_minor' => false, 'notes' => 'default'],
    ['country_code' => 'GH', 'min_age' => 18, 'phone_default_region' => 'GH',
        'require_email_unique' => true, 'require_phone_unique' => true, 'allow_minor' => true, 'notes' => ''],
];

$GLOBALS['__flash'] = ['success' => 'Identity policy saved.'];
$h = $render('identity_policies', ['csrf' => 'T1', 'policies' => $rows], 'fr');
chk('console: fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('console: add form posts to segment-less create route', str_contains($h, 'action="/identity/policies"'));
chk('console: edit form for GH row', str_contains($h, 'action="/identity/policies/GH"'));
chk('console: GH edit country read-only value', str_contains($h, 'value="GH" readonly') || (str_contains($h, 'value="GH"') && str_contains($h, 'readonly')));
chk('console: save label translated (fr)', str_contains($h, 'Enregistrer la politique'));
chk('console: success flash rendered', str_contains($h, 'Identity policy saved.'));
chk('console: count line rendered', str_contains($h, '2 politiques') || str_contains($h, '2'));
chk('console: checkbox state reflects row', str_contains($h, 'name="allow_minor" value="1" checked'));
$GLOBALS['__flash'] = [];

$hAr = $render('identity_policies', ['csrf' => 'T2', 'policies' => $rows], 'ar');
chk('console: ar lang=ar dir=rtl', str_contains($hAr, 'lang="ar"') && str_contains($hAr, 'dir="rtl"'));

$hEmpty = $render('identity_policies', ['csrf' => 'T3', 'policies' => []], 'en');
chk('console: empty state shown', str_contains($hEmpty, 'No identity policies'));
chk('console: add form present even when empty', str_contains($hEmpty, 'name="country_code"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
