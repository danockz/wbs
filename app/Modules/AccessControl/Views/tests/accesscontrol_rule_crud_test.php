<?php

declare(strict_types=1);

/**
 * RULE CRUD wiring test — proves the rule catalogue is no longer read-only: the
 * list view exposes create/edit/enable-disable/delete controls, the rule_form
 * view renders a create + edit form, the routes for the form pages + writes
 * exist and the write routes are webcsrf-guarded, and the controller has the
 * matching form-render + PRG actions. Plus i18n parity for the new ruleForm.* and
 * rulesView action keys across all locales, and a headless render smoke of the
 * form (fr + ar) so the self-contained page works without the framework booted.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_rule_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/RuleController.php';
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

// ── 1. i18n parity for ruleForm.* + the new rulesView action keys ────────────
echo "language parity (ruleForm.* + rulesView action keys)\n";
$en      = require $langDir . '/en/AccessControl.php';
chk('en has ruleForm block', isset($en['ruleForm']) && is_array($en['ruleForm']));
$formKeys = $flatten($en['ruleForm']);
$actionKeys = ['newRule', 'edit', 'enable', 'disable', 'delete', 'deleteConfirm'];
foreach ($actionKeys as $k) {
    chk("en rulesView.$k present", isset($en['rulesView'][$k]));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    $lk = isset($l['ruleForm']) ? $flatten($l['ruleForm']) : [];
    $missing = array_diff($formKeys, $lk);
    $stray   = array_diff($lk, $formKeys);
    chk("$loc mirrors all en ruleForm keys", $missing === [], implode(',', $missing));
    chk("$loc has no stray ruleForm keys", $stray === [], implode(',', $stray));
    foreach ($actionKeys as $k) {
        chk("$loc rulesView.$k present", isset($l['rulesView'][$k]));
    }
}

// ── 2. LIST view exposes write controls ──────────────────────────────────────
echo "rules.php exposes CRUD controls\n";
$rulesSrc = (string) file_get_contents("$viewDir/rules.php");
chk('has New rule button', str_contains($rulesSrc, "rulesView.newRule"));
chk('links to create form (rules/new)', str_contains($rulesSrc, "rules/new"));
chk('has per-rule edit link (rules/{id}/edit)', str_contains($rulesSrc, "/edit"));
chk('has enable/disable POST form', str_contains($rulesSrc, "/enabled") && str_contains($rulesSrc, 'method="post"'));
chk('has delete POST form', str_contains($rulesSrc, "/delete"));
chk('delete asks for confirm()', str_contains($rulesSrc, 'onsubmit') && str_contains($rulesSrc, 'confirm('));
chk('inline forms carry _csrf token', substr_count($rulesSrc, 'name="_csrf"') >= 2);
chk('renders PRG flash messages', str_contains($rulesSrc, "session('success')") && str_contains($rulesSrc, "session('error')"));
chk('url helper is test-safe (function_exists guard)', str_contains($rulesSrc, "function_exists('base_url')"));

// ── 3. rule_form view: create + edit ─────────────────────────────────────────
echo "rule_form.php is a real create/edit form\n";
$formSrc = (string) file_get_contents("$viewDir/rule_form.php");
chk('posts a form', str_contains($formSrc, '<form method="post"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('has code + name + facet fields', str_contains($formSrc, 'name="code"') && str_contains($formSrc, 'name="name"') && str_contains($formSrc, 'name="facet"'));
chk('has condition + effect_params JSON fields', str_contains($formSrc, 'name="condition"') && str_contains($formSrc, 'name="effect_params"'));
chk('has scope_mode + scope_group_id fields', str_contains($formSrc, 'name="scope_mode"') && str_contains($formSrc, 'name="scope_group_id"'));
chk('has priority + effect + enabled fields', str_contains($formSrc, 'name="priority"') && str_contains($formSrc, 'name="effect"') && str_contains($formSrc, 'name="enabled"'));
chk('branches on create vs edit', str_contains($formSrc, "\$isEdit"));
chk('includes _locale.php', str_contains($formSrc, "include __DIR__ . '/_locale.php'"));
chk('url helper is test-safe', str_contains($formSrc, "function_exists('base_url')"));

// ── 4. Routes wired + webcsrf on writes ──────────────────────────────────────
echo "routes wired + webcsrf on writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET rules/new -> createForm', (bool) preg_match('/get\(\s*.new.\s*,.*RuleController::createForm/', $routes));
chk('GET rules/(:segment)/edit -> editForm', (bool) preg_match('#get\(\s*.\(:segment\)/edit.\s*,.*RuleController::editForm#', $routes));
// each write route must carry the webcsrf filter
foreach ([
    'create'     => 'RuleController::create\'',
    'update'     => 'RuleController::update/$1',
    'setEnabled' => 'RuleController::setEnabled/$1',
    'delete'     => 'RuleController::delete/$1',
] as $label => $needle) {
    if (preg_match('/^.*' . preg_quote($needle, '/') . '.*$/m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label route webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 5. Controller has the new actions + browser PRG ──────────────────────────
echo "controller actions\n";
$ctrl = (string) file_get_contents($controller);
foreach (['createForm', 'editForm'] as $fn) {
    chk("RuleController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create redirects on browser success (PRG)', str_contains($ctrl, "redirect()->to('/rules/'"));
chk('setEnabled/delete redirect to /rules', substr_count($ctrl, "redirect()->to('/rules')") >= 2);
chk('index passes csrf token to view', str_contains($ctrl, 'wbsCsrf'));

// ── 6. Headless render smoke of the FORM (fr create + ar edit) ───────────────
echo "render smoke — rule_form (fr create, ar edit)\n";
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

$hCreate = $render("$viewDir/rule_form.php", [
    'csrf' => 'TOK123', 'mode' => 'create', 'rule' => [], 'facets' => ['access', 'membership'], 'facet' => 'membership',
], 'fr');
chk('create: fr lang=fr dir=ltr', str_contains($hCreate, 'lang="fr"') && str_contains($hCreate, 'dir="ltr"'));
chk('create: heading translated', str_contains($hCreate, 'Nouvelle règle'));
chk('create: csrf embedded', str_contains($hCreate, 'value="TOK123"'));
chk('create: posts to /rules', str_contains($hCreate, 'action="/rules"'));
chk('create: facet not locked (no readonly code)', ! str_contains($hCreate, 'readonly'));
chk('create: enabled checked by default', str_contains($hCreate, 'id="enabled"') && str_contains($hCreate, ' checked'));

$hEdit = $render("$viewDir/rule_form.php", [
    'csrf' => 'TOK9', 'mode' => 'edit',
    'rule' => [
        'id' => 'ru-1', 'facet' => 'membership', 'code' => 'mbr.x', 'name' => 'My rule',
        'effect' => 'adjust', 'priority' => 30, 'scope_mode' => 'self', 'enabled' => 0,
        'condition' => ['all' => []], 'effect_params' => ['to_stage' => 'first_timer'],
    ],
    'facets' => ['access', 'membership'],
], 'ar');
chk('edit: ar lang=ar dir=rtl', str_contains($hEdit, 'lang="ar"') && str_contains($hEdit, 'dir="rtl"'));
chk('edit: heading translated', str_contains($hEdit, 'تعديل قاعدة'));
chk('edit: posts to /rules/ru-1', str_contains($hEdit, 'action="/rules/ru-1"'));
chk('edit: code prefilled + readonly', str_contains($hEdit, 'value="mbr.x"') && str_contains($hEdit, 'readonly'));
chk('edit: name prefilled', str_contains($hEdit, 'value="My rule"'));
chk('edit: effect_params JSON pretty-printed into textarea', str_contains($hEdit, 'to_stage') && str_contains($hEdit, 'first_timer'));
chk('edit: disabled rule -> enabled not checked', (bool) preg_match('/id="enabled"[^>]*value="1">/', $hEdit));

// scope_group_id is an entity reference → org groups picker when a groups list is
// supplied (indented by depth), with a "org-wide" none option, current-value
// preselection, stale preservation, and a bounded text fallback otherwise.
echo "scope_group_id entity-reference picker\n";
$grpList = [
    ['id' => 'g-nat', 'name' => 'Ghana National', 'type' => 'national', 'depth' => 0],
    ['id' => 'g-reg', 'name' => 'Greater Accra', 'type' => 'region', 'depth' => 1],
];
$hPick = $render("$viewDir/rule_form.php", [
    'csrf' => 'T', 'mode' => 'create', 'rule' => ['scope_mode' => 'groups', 'scope_group_id' => 'g-reg'],
    'facets' => ['access'], 'groups' => $grpList,
], 'fr');
chk('scope picker: renders <select id="scope_group_id">', str_contains($hPick, '<select id="scope_group_id" name="scope_group_id">'));
chk('scope picker: option value = group id + name', str_contains($hPick, 'value="g-nat"') && str_contains($hPick, 'Ghana National'));
chk('scope picker: current scope preselected', (bool) preg_match('/value="g-reg" selected/', $hPick));
chk('scope picker: org-wide none option present', str_contains($hPick, lang('AccessControl.ruleForm.scopeGroupNone')));
$hStale = $render("$viewDir/rule_form.php", [
    'csrf' => 'T', 'mode' => 'edit', 'rule' => ['id' => 'r1', 'scope_group_id' => 'archived-g'],
    'facets' => ['access'], 'groups' => $grpList,
], 'fr');
chk('scope picker: stale scope preserved as selected option', (bool) preg_match('/value="archived-g" selected/', $hStale));
$hNo = $render("$viewDir/rule_form.php", [
    'csrf' => 'T', 'mode' => 'create', 'rule' => ['scope_group_id' => 'g9'], 'facets' => ['access'],
], 'fr');
chk('scope picker: bounded text fallback when no groups', str_contains($hNo, 'id="scope_group_id" name="scope_group_id" maxlength="64"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
