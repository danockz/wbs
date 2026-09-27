<?php

declare(strict_types=1);

/**
 * ABAC POLICY CRUD wiring test — proves the policy catalogue is no longer
 * read-only: the list table has an Actions column (edit / enable-disable /
 * delete), the abac_policy_form view renders create + edit, the form-page +
 * write routes exist and writes are webcsrf-guarded, and the controller has the
 * form-render + PRG actions. Plus i18n parity for abacForm.* / abacView action
 * keys across all locales and a headless render smoke (fr create + ar edit).
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_abac_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/AbacPolicyController.php';
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

// ── 1. i18n parity ───────────────────────────────────────────────────────────
echo "language parity (abacForm.* + abacView action keys)\n";
$en = require $langDir . '/en/AccessControl.php';
chk('en has abacForm block', isset($en['abacForm']) && is_array($en['abacForm']));
$formKeys = $flatten($en['abacForm']);
$actionKeys = ['colActions', 'newPolicy', 'edit', 'enable', 'disable', 'delete', 'deleteConfirm'];
foreach ($actionKeys as $k) {
    chk("en abacView.$k present", isset($en['abacView'][$k]));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    $lk = isset($l['abacForm']) ? $flatten($l['abacForm']) : [];
    chk("$loc mirrors all en abacForm keys", array_diff($formKeys, $lk) === [], implode(',', array_diff($formKeys, $lk)));
    chk("$loc has no stray abacForm keys", array_diff($lk, $formKeys) === [], implode(',', array_diff($lk, $formKeys)));
    foreach ($actionKeys as $k) {
        chk("$loc abacView.$k present", isset($l['abacView'][$k]));
    }
}

// ── 2. LIST view controls ────────────────────────────────────────────────────
echo "abac_policies.php exposes CRUD controls\n";
$listSrc = (string) file_get_contents("$viewDir/abac_policies.php");
chk('has New policy button', str_contains($listSrc, 'abacView.newPolicy'));
chk('links to create form', str_contains($listSrc, 'abac-policies/new'));
chk('has Actions column header', str_contains($listSrc, 'abacView.colActions'));
chk('has per-row edit link', str_contains($listSrc, '/edit'));
chk('has enable/disable POST form', str_contains($listSrc, '/enabled') && str_contains($listSrc, 'method="post"'));
chk('has delete POST form', str_contains($listSrc, '/delete'));
chk('delete asks for confirm()', str_contains($listSrc, 'confirm('));
chk('inline forms carry _csrf', substr_count($listSrc, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($listSrc, "session('success')") && str_contains($listSrc, "session('error')"));
chk('url helper is test-safe', str_contains($listSrc, "function_exists('base_url')"));

// ── 3. FORM view ─────────────────────────────────────────────────────────────
echo "abac_policy_form.php is a real create/edit form\n";
$formSrc = (string) file_get_contents("$viewDir/abac_policy_form.php");
chk('posts a form', str_contains($formSrc, '<form method="post"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('has code + action_pattern + condition fields', str_contains($formSrc, 'name="code"') && str_contains($formSrc, 'name="action_pattern"') && str_contains($formSrc, 'name="condition"'));
chk('has effect + priority + enabled fields', str_contains($formSrc, 'name="effect"') && str_contains($formSrc, 'name="priority"') && str_contains($formSrc, 'name="enabled"'));
chk('branches on create vs edit', str_contains($formSrc, '$isEdit'));
chk('includes _locale.php', str_contains($formSrc, "include __DIR__ . '/_locale.php'"));
chk('url helper is test-safe', str_contains($formSrc, "function_exists('base_url')"));

// ── 4. Routes + webcsrf ──────────────────────────────────────────────────────
echo "routes wired + webcsrf on writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET abac-policies/new -> createForm', (bool) preg_match('/get\(\s*.new.\s*,.*AbacPolicyController::createForm/', $routes));
chk('GET abac-policies/(:segment)/edit -> editForm', (bool) preg_match('#get\(\s*.\(:segment\)/edit.\s*,.*AbacPolicyController::editForm#', $routes));
foreach ([
    'create'     => "AbacPolicyController::create'",
    'update'     => 'AbacPolicyController::update/$1',
    'setEnabled' => 'AbacPolicyController::setEnabled/$1',
    'delete'     => 'AbacPolicyController::delete/$1',
] as $label => $needle) {
    if (preg_match('/^.*' . preg_quote($needle, '/') . '.*$/m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label route webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 5. Controller actions ────────────────────────────────────────────────────
echo "controller actions\n";
$ctrl = (string) file_get_contents($controller);
foreach (['createForm', 'editForm'] as $fn) {
    chk("AbacPolicyController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create redirects on browser success (PRG)', str_contains($ctrl, "redirect()->to('/abac-policies/'"));
chk('setEnabled/delete redirect to /abac-policies', substr_count($ctrl, "redirect()->to('/abac-policies')") >= 2);
chk('index passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — abac_policy_form (fr create, ar edit)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('service')) {
    function service($x = null) { return new class { function getLocale() { return $GLOBALS['__aLoc'] ?? 'en'; } }; }
}
if (! function_exists('config')) {
    function config($c) { return new class { public array $rtl = ['ar', 'he', 'fa', 'ur']; }; }
}
if (! function_exists('lang')) {
    function lang(string $key) {
        $p = explode('.', $key);
        if (array_shift($p) !== 'AccessControl') { return $key; }
        $v = $GLOBALS['__aLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) { return $key; }
            $v = $v[$seg];
        }
        return $v;
    }
}
$render = static function (string $file, array $data, string $loc) use ($langDir): string {
    $GLOBALS['__aLoc']  = $loc;
    $GLOBALS['__aLang'] = require $langDir . "/$loc/AccessControl.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

$hCreate = $render("$viewDir/abac_policy_form.php", ['csrf' => 'T1', 'mode' => 'create', 'policy' => []], 'fr');
chk('create: fr lang=fr dir=ltr', str_contains($hCreate, 'lang="fr"') && str_contains($hCreate, 'dir="ltr"'));
chk('create: heading translated', str_contains($hCreate, 'Nouvelle politique ABAC'));
chk('create: posts to /abac-policies', str_contains($hCreate, 'action="/abac-policies"'));
chk('create: code not readonly', ! str_contains($hCreate, 'readonly'));

$hEdit = $render("$viewDir/abac_policy_form.php", [
    'csrf' => 'T2', 'mode' => 'edit',
    'policy' => ['id' => 'p-1', 'code' => 'deny_x', 'effect' => 'deny', 'action_pattern' => 'contribution.*',
        'priority' => 5, 'enabled' => 0, 'condition' => ['all' => []]],
], 'ar');
chk('edit: ar lang=ar dir=rtl', str_contains($hEdit, 'lang="ar"') && str_contains($hEdit, 'dir="rtl"'));
chk('edit: posts to /abac-policies/p-1', str_contains($hEdit, 'action="/abac-policies/p-1"'));
chk('edit: code prefilled + readonly', str_contains($hEdit, 'value="deny_x"') && str_contains($hEdit, 'readonly'));
chk('edit: action pattern prefilled', str_contains($hEdit, 'value="contribution.*"'));
chk('edit: deny effect selected', (bool) preg_match('/value="deny" selected/', $hEdit));
chk('edit: disabled -> enabled unchecked', (bool) preg_match('/id="enabled"[^>]*value="1">/', $hEdit));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
