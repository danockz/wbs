<?php

declare(strict_types=1);

/**
 * CUSTOM-ADAPTER SDK dashboard write-UI test — proves the custom-adapters index
 * (GET /integrations/custom-adapters) is no longer JSON-only: it renders the
 * allowlisted impl classes with a register form and every org adapter with the
 * STAGE-APPROPRIATE lifecycle control (contract-test on draft, advance on
 * contract_tested, approve on security_review, activate on approved, revoke on any
 * live stage). Each control POSTs to a webcsrf-guarded route; the controller PRGs
 * browser writes back with a localized flash (JSON kept for API clients) and the
 * submitter/approver come from the session (SoD by identity). Plus i18n parity for
 * the new custom.* keys and a headless render smoke (self-contained page harness,
 * mirrors integrations_catalog_view_test).
 *
 *   php app/Modules/Integrations/Views/tests/integrations_custom_adapters_view_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Integrations/Language';
$viewDir    = $root . '/app/Modules/Integrations/Views';
$controller = $root . '/app/Modules/Integrations/Controllers/CustomAdapterController.php';
$routesFile = $root . '/app/Config/Routes.php';

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

// ── 1. i18n parity for custom.* ──────────────────────────────────────────────
echo "language parity (custom.*)\n";
$en      = require $langDir . '/en/Integrations.php';
$enCustom = $flatten(['custom' => $en['custom']]);
$need = ['metaTitle', 'heading', 'sub', 'empty', 'registerHeading', 'implClassLabel', 'registerBtn', 'noAllowlist',
    'contractTestBtn', 'advanceBtn', 'approveBtn', 'approveConfirm', 'activateBtn', 'activateConfirm', 'revokeBtn',
    'revokeConfirm', 'sodHint', 'registeredFlash', 'contractTestedFlash', 'advancedFlash', 'approvedFlash',
    'activatedFlash', 'revokedFlash', 'status.draft', 'status.contract_tested', 'status.security_review',
    'status.approved', 'status.active', 'status.revoked'];
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Integrations.php";
    $c = $l['custom'] ?? [];
    $flat = $flatten($c);
    foreach ($need as $k) {
        chk("$loc custom.$k present", in_array($k, $flat, true));
    }
    chk("$loc mirrors all en custom keys", array_diff($enCustom, $flatten(['custom' => $c])) === [],
        'missing: ' . implode(',', array_diff($enCustom, $flatten(['custom' => $c]))));
    chk("$loc no stray custom keys", array_diff($flatten(['custom' => $c]), $enCustom) === [],
        'extra: ' . implode(',', array_diff($flatten(['custom' => $c]), $enCustom)));
}

// ── 2. View controls ─────────────────────────────────────────────────────────
echo "custom_adapters.php exposes lifecycle controls\n";
$src = (string) file_get_contents("$viewDir/custom_adapters.php");
chk('register form posts to /integrations/custom-adapters', str_contains($src, 'action="/integrations/custom-adapters"'));
chk('register form has impl_class select', str_contains($src, 'name="impl_class"'));
chk('contract-test form present', str_contains($src, '/contract-test"'));
chk('advance form present (to security_review)', str_contains($src, '/advance"') && str_contains($src, 'name="to_status" value="security_review"'));
chk('approve form present', str_contains($src, '/approve"'));
chk('activate form present', str_contains($src, '/activate"'));
chk('revoke form present', str_contains($src, '/revoke"'));
chk('approve asks confirm()', str_contains($src, 'approveConfirm'));
chk('activate asks confirm()', str_contains($src, 'activateConfirm'));
chk('revoke asks confirm()', str_contains($src, 'revokeConfirm'));
chk('SoD hint on approve', str_contains($src, 'sodHint'));
chk('forms carry _csrf bound to $csrf', (bool) preg_match('/name="_csrf" value="<\?= esc\(\$csrf/', $src));
chk('renders PRG flash messages', str_contains($src, "session('success')") && str_contains($src, "session('error')"));
chk('self-contained locale wiring', str_contains($src, '_locale.php') && str_contains($src, '_shell_open.php'));
chk('does NOT expose submitter/approver id field', ! str_contains($src, 'name="submitted_by"') && ! str_contains($src, 'name="approver_id"'));

// ── 3. Controller PRG + csrf ─────────────────────────────────────────────────
echo "controller PRG + csrf + SoD-by-identity\n";
$ctrl = (string) file_get_contents($controller);
chk('has respondAdapterDecision PRG helper', str_contains($ctrl, 'private function respondAdapterDecision'));
chk('index renders bespoke view + csrf', str_contains($ctrl, 'WBS\Integrations\Views\custom_adapters') && str_contains($ctrl, "wbsCsrf"));
chk('index passes the allowlist', str_contains($ctrl, "'allowlist' => IntegrationServices::customAdapterRegistry()->classes()"));
chk('index keeps JSON for API clients', (bool) preg_match('/function index\(\).*?if \(\$this->wantsJson\(\)\)/s', $ctrl));
chk('register PRGs with registeredFlash', (bool) preg_match('/function register\(.*?registeredFlash/s', $ctrl));
chk('approve PRGs with approvedFlash', (bool) preg_match('/function approve\(.*?approvedFlash/s', $ctrl));
chk('activate PRGs with activatedFlash', (bool) preg_match('/function activate\(.*?activatedFlash/s', $ctrl));
chk('revoke PRGs with revokedFlash', (bool) preg_match('/function revoke\(.*?revokedFlash/s', $ctrl));
chk('PRG keeps JSON + redirects to dashboard', str_contains($ctrl, 'if ($this->wantsJson())') && str_contains($ctrl, "'/integrations/custom-adapters'"));
chk('approve/register use actorId (session identity)', str_contains($ctrl, '$this->actorId()'));

// ── 4. Routes webcsrf-guarded ────────────────────────────────────────────────
echo "custom-adapter write routes gated + webcsrf\n";
$routes = (string) file_get_contents($routesFile);
foreach (['CustomAdapterController::register', 'contract-test\', \'\\WBS\\Integrations\\Controllers\\CustomAdapterController::contractTest/$1',
    'CustomAdapterController::advance/$1', 'CustomAdapterController::approve/$1',
    'CustomAdapterController::activate/$1', 'CustomAdapterController::revoke/$1'] as $needle) {
    if (preg_match('#^.*' . preg_quote($needle, '#') . ".*$#m", $routes, $mm)) {
        chk("$needle route present", true);
        chk("$needle webcsrf-guarded", str_contains($mm[0], 'webcsrf'));
        chk("$needle still provider.configure", str_contains($mm[0], 'authorize:provider.configure'));
    } else {
        chk("$needle route present", false);
    }
}

// ── 5. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — custom adapters (fr) across stages + ar RTL\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) {
        return new class {
            function getLocale() { return $GLOBALS['__iLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c) {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('session')) {
    function session($k = null) { return $GLOBALS['__iFlash'][$k] ?? null; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Integrations') { return $key; }
        $v = $GLOBALS['__iLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__iLoc']  = $loc;
    $GLOBALS['__iLang'] = require $langDir . "/$loc/Integrations.php";
    extract($data);
    ob_start();
    include "$viewDir/custom_adapters.php";
    return (string) ob_get_clean();
};

$mkAdapter = static fn (string $id, string $status): array => [
    'id' => $id, 'code' => 'acme_' . $id, 'display_name' => 'Acme ' . $id, 'version' => 1,
    'category' => 'payment', 'family' => 'acme', 'impl_class' => 'WBS\\Integrations\\Sdk\\Acme',
    'status' => $status, 'submitted_by' => 'user-1',
];

$GLOBALS['__iFlash'] = [];
$h = $render([
    'csrf'      => 'IA1',
    'allowlist' => ['WBS\\Integrations\\Sdk\\AcmePayAdapter', 'WBS\\Integrations\\Sdk\\FooAdapter'],
    'adapters'  => [
        $mkAdapter('draft1', 'draft'),
        $mkAdapter('ct1', 'contract_tested'),
        $mkAdapter('sr1', 'security_review'),
        $mkAdapter('ap1', 'approved'),
        $mkAdapter('ac1', 'active'),
        $mkAdapter('rv1', 'revoked'),
    ],
], 'fr');
chk('smoke: lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('smoke: register form lists allowlist short class', str_contains($h, '>AcmePayAdapter<') && str_contains($h, '>FooAdapter<'));
chk('smoke: draft shows contract-test only', str_contains($h, 'action="/integrations/custom-adapters/draft1/contract-test"'));
chk('smoke: draft has NO approve', ! str_contains($h, 'custom-adapters/draft1/approve'));
chk('smoke: contract_tested shows advance', str_contains($h, 'action="/integrations/custom-adapters/ct1/advance"'));
chk('smoke: security_review shows approve', str_contains($h, 'action="/integrations/custom-adapters/sr1/approve"'));
chk('smoke: approved shows activate', str_contains($h, 'action="/integrations/custom-adapters/ap1/activate"'));
chk('smoke: active shows revoke (only)', str_contains($h, 'custom-adapters/ac1/revoke') && ! str_contains($h, 'custom-adapters/ac1/approve'));
chk('smoke: revoked shows NO actions', ! str_contains($h, 'custom-adapters/rv1/revoke') && ! str_contains($h, 'custom-adapters/rv1/activate'));
chk('smoke: status label localized (fr Brouillon)', str_contains($h, 'Brouillon'));
chk('smoke: count interpolated (fr)', str_contains($h, '6 adaptateurs personnalisés'));
chk('smoke: register button localized (fr)', str_contains($h, 'Enregistrer'));
chk('smoke: every action form carries csrf', substr_count($h, 'name="_csrf" value="IA1"') >= 6);

// flash
$GLOBALS['__iFlash'] = ['success' => 'Adaptateur approuvé.'];
$hs = $render(['csrf' => 'IA1', 'allowlist' => [], 'adapters' => []], 'fr');
chk('smoke: success flash rendered', str_contains($hs, 'Adaptateur approuvé.') && str_contains($hs, 'flash ok'));
chk('smoke: empty state shown', str_contains($hs, lang('Integrations.custom.empty')));
chk('smoke: no-allowlist hint shown', str_contains($hs, lang('Integrations.custom.noAllowlist')));
$GLOBALS['__iFlash'] = ['error' => 'Transition invalide.'];
$he = $render(['csrf' => 'IA1', 'allowlist' => [], 'adapters' => []], 'fr');
chk('smoke: error flash rendered', str_contains($he, 'Transition invalide.') && str_contains($he, 'flash err'));
$GLOBALS['__iFlash'] = [];

// ar RTL
$ha = $render(['csrf' => 'IA1', 'allowlist' => [], 'adapters' => [$mkAdapter('sr2', 'security_review')]], 'ar');
chk('smoke(ar): lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('smoke(ar): approve button localized', str_contains($ha, lang('Integrations.custom.approveBtn')));
chk('smoke(ar): security_review status localized', str_contains($ha, 'مراجعة أمنية'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
