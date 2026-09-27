<?php

declare(strict_types=1);

/**
 * ROLE CRUD wiring test — proves the role catalogue is no longer read-only: the
 * list view exposes create/edit/delete controls (delete hidden for system
 * roles), the role_form view renders create + edit with a permission picker, the
 * form-page + write routes exist and writes are webcsrf-guarded, and the
 * controller has the matching form-render + PRG actions. Plus i18n parity for
 * roleForm.* / rolesView action keys across all locales, and a headless render
 * smoke (fr create + ar edit).
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_role_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/AccessControl/Language';
$viewDir    = $root . '/app/Modules/AccessControl/Views';
$controller = $root . '/app/Modules/AccessControl/Controllers/RoleController.php';
$service    = $root . '/app/Modules/AccessControl/Services/RoleService.php';
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
echo "language parity (roleForm.* + rolesView action keys)\n";
$en = require $langDir . '/en/AccessControl.php';
chk('en has roleForm block', isset($en['roleForm']) && is_array($en['roleForm']));
$formKeys = $flatten($en['roleForm']);
$actionKeys = ['newRole', 'edit', 'delete', 'system', 'deleteConfirm'];
foreach ($actionKeys as $k) {
    chk("en rolesView.$k present", isset($en['rolesView'][$k]));
}
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l = require $langDir . "/$loc/AccessControl.php";
    $lk = isset($l['roleForm']) ? $flatten($l['roleForm']) : [];
    chk("$loc mirrors all en roleForm keys", array_diff($formKeys, $lk) === [], implode(',', array_diff($formKeys, $lk)));
    chk("$loc has no stray roleForm keys", array_diff($lk, $formKeys) === [], implode(',', array_diff($lk, $formKeys)));
    foreach ($actionKeys as $k) {
        chk("$loc rolesView.$k present", isset($l['rolesView'][$k]));
    }
}

// ── 2. LIST view controls ────────────────────────────────────────────────────
echo "roles.php exposes CRUD controls\n";
$rolesSrc = (string) file_get_contents("$viewDir/roles.php");
chk('has New role button', str_contains($rolesSrc, 'rolesView.newRole'));
chk('links to create form (roles/new)', str_contains($rolesSrc, 'roles/new'));
chk('has per-role edit link', str_contains($rolesSrc, '/edit'));
chk('has delete POST form', str_contains($rolesSrc, '/delete') && str_contains($rolesSrc, 'method="post"'));
chk('delete hidden for system roles', str_contains($rolesSrc, '! $isSystem'));
chk('delete asks for confirm()', str_contains($rolesSrc, 'confirm('));
chk('inline form carries _csrf', str_contains($rolesSrc, 'name="_csrf"'));
chk('renders PRG flash messages', str_contains($rolesSrc, "session('success')") && str_contains($rolesSrc, "session('error')"));
chk('url helper is test-safe', str_contains($rolesSrc, "function_exists('base_url')"));

// ── 3. FORM view ─────────────────────────────────────────────────────────────
echo "role_form.php is a real create/edit form\n";
$formSrc = (string) file_get_contents("$viewDir/role_form.php");
chk('posts a form', str_contains($formSrc, '<form method="post"'));
chk('carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('has code + name + description fields', str_contains($formSrc, 'name="code"') && str_contains($formSrc, 'name="name"') && str_contains($formSrc, 'name="description"'));
chk('has permissions[] checkbox picker', str_contains($formSrc, 'name="permissions[]"'));
chk('branches on create vs edit', str_contains($formSrc, '$isEdit'));
chk('warns on system role', str_contains($formSrc, 'systemWarn'));
chk('includes _locale.php', str_contains($formSrc, "include __DIR__ . '/_locale.php'"));
chk('url helper is test-safe', str_contains($formSrc, "function_exists('base_url')"));

// ── 4. Routes + webcsrf + service ────────────────────────────────────────────
echo "routes wired + webcsrf on writes + catalog service\n";
$routes = (string) file_get_contents($routesFile);
chk('GET roles/new -> createForm', (bool) preg_match('/get\(\s*.new.\s*,.*RoleController::createForm/', $routes));
chk('GET roles/(:segment)/edit -> editForm', (bool) preg_match('#get\(\s*.\(:segment\)/edit.\s*,.*RoleController::editForm#', $routes));
foreach ([
    'create'         => "RoleController::create'",
    'update'         => 'RoleController::update/$1',
    'setPermissions' => 'RoleController::setPermissions/$1',
    'delete'         => 'RoleController::delete/$1',
] as $label => $needle) {
    if (preg_match('/^.*' . preg_quote($needle, '/') . '.*$/m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label route webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}
chk('RoleService::listPermissionCatalog exists', str_contains((string) file_get_contents($service), 'function listPermissionCatalog'));

// ── 5. Controller actions ────────────────────────────────────────────────────
echo "controller actions\n";
$ctrl = (string) file_get_contents($controller);
foreach (['createForm', 'editForm'] as $fn) {
    chk("RoleController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create applies permissions on success', str_contains($ctrl, 'setPermissions') && str_contains($ctrl, "redirect()->to('/roles/'"));
chk('delete redirects to /roles', str_contains($ctrl, "redirect()->to('/roles')"));
chk('index passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 6. Headless render smoke ─────────────────────────────────────────────────
echo "render smoke — role_form (fr create, ar edit)\n";
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

$catalog = [
    ['code' => 'contribution.refund.approve', 'description' => 'Approve refunds'],
    ['code' => 'group.create', 'description' => 'Create groups'],
];

$hCreate = $render("$viewDir/role_form.php", [
    'csrf' => 'TOK1', 'mode' => 'create', 'role' => [], 'catalog' => $catalog,
], 'fr');
chk('create: fr lang=fr dir=ltr', str_contains($hCreate, 'lang="fr"') && str_contains($hCreate, 'dir="ltr"'));
chk('create: heading translated', str_contains($hCreate, 'Nouveau rôle'));
chk('create: posts to /roles', str_contains($hCreate, 'action="/roles"'));
chk('create: renders permission checkboxes', substr_count($hCreate, 'name="permissions[]"') === 2);
chk('create: code not readonly', ! str_contains($hCreate, 'readonly'));

$hEdit = $render("$viewDir/role_form.php", [
    'csrf' => 'TOK2', 'mode' => 'edit',
    'role' => ['id' => 'r-1', 'code' => 'finance', 'name' => 'Finance', 'is_system' => 0, 'permissions' => ['group.create']],
    'catalog' => $catalog,
], 'ar');
chk('edit: ar lang=ar dir=rtl', str_contains($hEdit, 'lang="ar"') && str_contains($hEdit, 'dir="rtl"'));
chk('edit: heading translated', str_contains($hEdit, 'تعديل دور'));
chk('edit: posts to /roles/r-1', str_contains($hEdit, 'action="/roles/r-1"'));
chk('edit: code prefilled + readonly', str_contains($hEdit, 'value="finance"') && str_contains($hEdit, 'readonly'));
chk('edit: current permission pre-checked', (bool) preg_match('/value="group.create"\s+checked/', $hEdit));
chk('edit: unselected permission not checked', (bool) preg_match('/value="contribution.refund.approve">/', $hEdit));

$hSys = $render("$viewDir/role_form.php", [
    'csrf' => 'T', 'mode' => 'edit',
    'role' => ['id' => 'r-2', 'code' => 'org_admin', 'name' => 'Org admin', 'is_system' => 1, 'permissions' => []],
    'catalog' => $catalog,
], 'en');
chk('edit system role: shows protection warning', str_contains($hSys, 'system role'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
