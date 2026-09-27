<?php

declare(strict_types=1);

/**
 * POINT RULE CRUD wiring test — proves the gamification rules list is no longer
 * read-only: the list has per-rule CRUD controls (New / Edit / Disable), the
 * rule_form view renders create + edit, the form-page + write routes exist and
 * writes are webcsrf-guarded, and the controller has the form-render + PRG
 * actions. Editing is IMMUTABLE (code fixed on edit) and rules are DISABLED not
 * deleted. Plus i18n parity for the admin.rules.form.* block across all locales
 * and a headless render smoke (fr create + ar edit) through the layout harness.
 *
 *   php app/Modules/Gamification/Views/tests/gamification_rule_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Gamification/Language';
$viewDir    = $root . '/app/Modules/Gamification/Views';
$controller = $root . '/app/Modules/Gamification/Controllers/ConfigController.php';
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
echo "language parity (admin.rules.form.* block)\n";
$en = require $langDir . '/en/Gamification.php';
$enForm = $en['admin']['rules']['form'] ?? null;
chk('en has admin.rules.form block', is_array($enForm));
$formKeys = $flatten($enForm ?? []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/Gamification.php";
    $lf = $l['admin']['rules']['form'] ?? [];
    $lk = $flatten(is_array($lf) ? $lf : []);
    chk("$loc mirrors all en form keys", array_diff($formKeys, $lk) === [], implode(',', array_slice(array_diff($formKeys, $lk), 0, 8)));
    chk("$loc has no stray form keys", array_diff($lk, $formKeys) === [], implode(',', array_slice(array_diff($lk, $formKeys), 0, 8)));
}
chk('en form has phase vocab', isset($enForm['phase']['win']));
chk('en form has pointMode vocab', isset($enForm['pointMode']['formula']));
chk('en form has period vocab', isset($enForm['period']['season']));

// ── 2. LIST view controls ────────────────────────────────────────────────────
echo "rules_admin.php exposes CRUD controls\n";
$listSrc = (string) file_get_contents("$viewDir/rules_admin.php");
chk('has New rule button', str_contains($listSrc, 'rules.form.newRule'));
chk('links to create form', str_contains($listSrc, '/gamification/rules/new'));
chk('has per-rule edit link', str_contains($listSrc, '/edit'));
chk('has disable POST form', str_contains($listSrc, '/disable') && str_contains($listSrc, 'method="post"'));
chk('disable asks for confirm()', str_contains($listSrc, 'confirm('));
chk('disable form carries _csrf', str_contains($listSrc, 'name="_csrf"'));
chk('disable only shown for active rules', str_contains($listSrc, '$isActive'));
chk('renders PRG flash messages', str_contains($listSrc, "session('success')") && str_contains($listSrc, "session('error')"));

// ── 3. FORM view ─────────────────────────────────────────────────────────────
echo "rule_form.php is a real create/edit form\n";
$formSrc = (string) file_get_contents("$viewDir/rule_form.php");
chk('extends layouts/app', str_contains($formSrc, "\$this->extend('layouts/app')"));
chk('posts a form', str_contains($formSrc, '<form class="rf-form" method="post"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('has code + event_type + points fields', str_contains($formSrc, 'name="code"') && str_contains($formSrc, 'name="event_type"') && str_contains($formSrc, 'name="points"'));
chk('has phase + point_mode + period selects', str_contains($formSrc, 'name="phase"') && str_contains($formSrc, 'name="point_mode"') && str_contains($formSrc, 'name="period"'));
chk('has requires_review checkbox', str_contains($formSrc, 'name="requires_review"'));
chk('branches on create vs edit', str_contains($formSrc, '$isEdit'));
chk('shows immutability note on edit', str_contains($formSrc, 'immutableNote'));

// ── 4. Routes + webcsrf ──────────────────────────────────────────────────────
echo "routes wired + webcsrf on writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET rules/new -> createRuleForm', (bool) preg_match('#get\(\s*.rules/new.\s*,.*ConfigController::createRuleForm#', $routes));
chk('GET rules/(:segment)/edit -> editRuleForm', (bool) preg_match('#get\(\s*.rules/\(:segment\)/edit.\s*,.*ConfigController::editRuleForm#', $routes));
foreach ([
    'create'  => "ConfigController::createRule'",
    'update'  => 'ConfigController::updateRule/$1',
    'disable' => 'ConfigController::disableRule/$1',
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
foreach (['createRuleForm', 'editRuleForm'] as $fn) {
    chk("ConfigController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create redirects on browser success (PRG)', str_contains($ctrl, "redirect()->to('/gamification/rules/'"));
chk('disable redirects to /gamification/rules', str_contains($ctrl, "redirect()->to('/gamification/rules')"));
chk('editRuleForm unwraps current version', str_contains($ctrl, "\$data['current']"));
chk('listRules passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 6. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — rule_form (fr create, ar edit)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return null; }
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
$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Gamification.php";
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

$hCreate = $render('rule_form', ['csrf' => 'T1', 'mode' => 'create', 'rule' => []], 'fr');
chk('create: heading translated', str_contains($hCreate, 'Nouvelle règle de points'));
chk('create: posts to /gamification/rules', str_contains($hCreate, 'action="/gamification/rules"'));
chk('create: code input not readonly', ! (bool) preg_match('/id="code"[^>]*readonly/', $hCreate));
chk('create: no immutability note', ! str_contains($hCreate, 'versionnées'));

$hEdit = $render('rule_form', [
    'csrf' => 'T2', 'mode' => 'edit',
    'rule' => ['code' => 'attend_service', 'event_type' => 'service.attended', 'points' => 25,
        'phase' => 'build', 'point_mode' => 'formula', 'point_formula' => 'base_points * 2',
        'period' => 'week', 'requires_review' => 1, 'explanation' => 'Attended a service'],
], 'ar');
chk('edit: heading translated', str_contains($hEdit, 'تعديل قاعدة النقاط'));
chk('edit: posts to /gamification/rules/attend_service', str_contains($hEdit, 'action="/gamification/rules/attend_service"'));
chk('edit: code prefilled + readonly', str_contains($hEdit, 'value="attend_service"') && (bool) preg_match('/id="code"[^>]*readonly/', $hEdit));
chk('edit: event_type prefilled', str_contains($hEdit, 'value="service.attended"'));
chk('edit: points prefilled', str_contains($hEdit, 'value="25"'));
chk('edit: build phase selected', (bool) preg_match('/value="build" selected/', $hEdit));
chk('edit: formula mode selected', (bool) preg_match('/value="formula" selected/', $hEdit));
chk('edit: formula prefilled', str_contains($hEdit, 'value="base_points * 2"'));
chk('edit: week period selected', (bool) preg_match('/value="week" selected/', $hEdit));
chk('edit: requires_review checked', str_contains($hEdit, 'name="requires_review" value="1" checked'));
chk('edit: shows immutability note', str_contains($hEdit, 'immuables') || str_contains($hEdit, 'غير قابلة'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
