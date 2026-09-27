<?php

declare(strict_types=1);

/**
 * CAUSE CRUD wiring test — proves the causes module grew from create-only (JSON
 * API) into a full browser CRUD surface: a directory list with per-cause controls
 * (New / Edit / activate-close / Delete), a cause_form view that renders create +
 * edit, a cause_show detail, the form-page + write routes gated + webcsrf, the
 * controller's form-render + PRG actions, and the CauseService's new
 * list/show/update/setStatus/delete methods. Plus i18n parity for the
 * Contributions.causes.* block and a headless render smoke through the layout
 * harness (fr create + ar edit).
 *
 *   php app/Modules/Contributions/Views/tests/contributions_cause_crud_test.php
 */

$root       = dirname(__DIR__, 5);
$langDir    = $root . '/app/Modules/Contributions/Language';
$viewDir    = $root . '/app/Modules/Contributions/Views';
$controller = $root . '/app/Modules/Contributions/Controllers/CauseController.php';
$service    = $root . '/app/Modules/Contributions/Services/CauseService.php';
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
echo "language parity (Contributions.causes.* block)\n";
$en = require $langDir . '/en/Contributions.php';
chk('en has causes block', isset($en['causes']) && is_array($en['causes']));
chk('en has causes.form block', isset($en['causes']['form']));
chk('en has status vocab', isset($en['causes']['status']['active']));
chk('en has visibility vocab', isset($en['causes']['visibility']['group']));
$keys = $flatten($en['causes'] ?? []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $l  = require $langDir . "/$loc/Contributions.php";
    $lk = isset($l['causes']) ? $flatten($l['causes']) : [];
    chk("$loc mirrors all en causes keys", array_diff($keys, $lk) === [], implode(',', array_slice(array_diff($keys, $lk), 0, 8)));
    chk("$loc has no stray causes keys", array_diff($lk, $keys) === [], implode(',', array_slice(array_diff($lk, $keys), 0, 8)));
}
// menu label parity for the new Giving > Causes item
foreach (['en', 'fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $app = require $root . "/app/Language/$loc/App.php";
    chk("$loc App.menuItems.giving_causes present", isset($app['menuItems']['giving_causes']));
}

// ── 2. Service methods ───────────────────────────────────────────────────────
echo "CauseService has full CRUD\n";
$svc = (string) file_get_contents($service);
foreach (['list', 'show', 'update', 'setStatus', 'delete'] as $m) {
    chk("CauseService::$m exists", (bool) preg_match('/public function ' . $m . '\s*\(/', $svc));
}
chk('delete is SAFE (closes when contributions exist)', str_contains($svc, "'deleted' => false") && str_contains($svc, 'contribution_intents'));
chk('update validates currency length', str_contains($svc, "strlen(\$currency) !== 3"));
chk('setStatus restricts to draft|active|closed', str_contains($svc, "['draft', 'active', 'closed']"));

// ── 3. LIST view controls ────────────────────────────────────────────────────
echo "causes.php exposes CRUD controls\n";
$listSrc = (string) file_get_contents("$viewDir/causes.php");
chk('extends layouts/app', str_contains($listSrc, "\$this->extend('layouts/app')"));
chk('has New cause button', str_contains($listSrc, 'form.newCause'));
chk('links to create form', str_contains($listSrc, '/causes/new'));
chk('has per-cause edit link', str_contains($listSrc, '/edit'));
chk('has status POST forms (activate/close)', str_contains($listSrc, '/status') && str_contains($listSrc, 'value="active"') && str_contains($listSrc, 'value="closed"'));
chk('has delete POST form', str_contains($listSrc, '/delete') && str_contains($listSrc, 'method="post"'));
chk('delete asks for confirm()', str_contains($listSrc, 'confirm('));
chk('inline forms carry _csrf', substr_count($listSrc, 'name="_csrf"') >= 3);
chk('renders PRG flash messages', str_contains($listSrc, "session('success')") && str_contains($listSrc, "session('error')"));

// ── 4. FORM + SHOW views ─────────────────────────────────────────────────────
echo "cause_form.php + cause_show.php\n";
$formSrc = (string) file_get_contents("$viewDir/cause_form.php");
chk('form extends layouts/app', str_contains($formSrc, "\$this->extend('layouts/app')"));
chk('form posts a form', str_contains($formSrc, '<form class="cf-form" method="post"'));
chk('form carries _csrf', str_contains($formSrc, 'name="_csrf"'));
chk('form has name + currency + target_minor fields', str_contains($formSrc, 'name="name"') && str_contains($formSrc, 'name="currency"') && str_contains($formSrc, 'name="target_minor"'));
chk('form has visibility select', str_contains($formSrc, 'name="visibility"'));
chk('form branches on create vs edit', str_contains($formSrc, '$isEdit'));
$showSrc = (string) file_get_contents("$viewDir/cause_show.php");
chk('show extends layouts/app', str_contains($showSrc, "\$this->extend('layouts/app')"));
chk('show has not-found panel', str_contains($showSrc, 'notFound'));

// ── 5. Routes + gating + webcsrf ─────────────────────────────────────────────
echo "routes wired + gated + webcsrf on writes\n";
$routes = (string) file_get_contents($routesFile);
chk('GET causes -> index (auth)', (bool) preg_match("#get\('',\s*'[^']*CauseController::index'[^)]*auth#", $routes));
chk('GET causes/new -> createForm', (bool) preg_match('#get\(\s*.new.\s*,.*CauseController::createForm#', $routes));
chk('GET causes/(:segment)/edit -> editForm', (bool) preg_match('#get\(\s*.\(:segment\)/edit.\s*,.*CauseController::editForm#', $routes));
chk('GET causes/(:segment) -> show', (bool) preg_match('#get\(\s*.\(:segment\).\s*,.*CauseController::show#', $routes));
chk('contribute path still public (ratelimit, no auth)', (bool) preg_match('#contribute.*ratelimit:contribution\.checkout#', $routes));
foreach ([
    'create'    => "CauseController::create'",
    'update'    => 'CauseController::update/$1',
    'setStatus' => 'CauseController::setStatus/$1',
    'delete'    => 'CauseController::delete/$1',
] as $label => $needle) {
    if (preg_match('/^.*' . preg_quote($needle, '/') . '.*$/m', $routes, $m)) {
        chk("$label route present", true);
        chk("$label gated contribution.manage", str_contains($m[0], 'authorize:contribution.manage'));
        chk("$label webcsrf-guarded", str_contains($m[0], 'webcsrf'));
    } else {
        chk("$label route present", false);
    }
}

// ── 6. Controller actions ────────────────────────────────────────────────────
echo "controller actions\n";
$ctrl = (string) file_get_contents($controller);
foreach (['index', 'show', 'createForm', 'editForm', 'create', 'update', 'setStatus', 'delete'] as $fn) {
    chk("CauseController::$fn exists", (bool) preg_match('/public function ' . $fn . '\s*\(/', $ctrl));
}
chk('create redirects on browser success (PRG)', str_contains($ctrl, "redirect()->to('/causes/'"));
chk('delete distinguishes closed-instead flash', str_contains($ctrl, 'closedInsteadFlash'));
chk('index passes csrf token', str_contains($ctrl, 'wbsCsrf'));

// ── 7. Headless render smoke (layout-bound harness) ──────────────────────────
echo "render smoke — cause_form (fr create, ar edit)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html') { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (! function_exists('session')) {
    function session($k = null) { return null; }
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
        return $v;
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

$hCreate = $render('cause_form', ['csrf' => 'T1', 'mode' => 'create', 'cause' => []], 'fr');
chk('create: heading translated', str_contains($hCreate, 'Nouvelle cause'));
chk('create: posts to /causes', str_contains($hCreate, 'action="/causes"'));
chk('create: currency defaults GHS', str_contains($hCreate, 'value="GHS"'));

$hEdit = $render('cause_form', [
    'csrf' => 'T2', 'mode' => 'edit',
    'cause' => ['id' => 'c-1', 'name' => 'Building fund', 'purpose' => 'New hall',
        'visibility' => 'public', 'currency' => 'USD', 'target_minor' => 500000, 'target_count' => 100],
], 'ar');
chk('edit: heading translated', str_contains($hEdit, 'تعديل قضية'));
chk('edit: posts to /causes/c-1', str_contains($hEdit, 'action="/causes/c-1"'));
chk('edit: name prefilled', str_contains($hEdit, 'value="Building fund"'));
chk('edit: currency prefilled', str_contains($hEdit, 'value="USD"'));
chk('edit: public visibility selected', (bool) preg_match('/value="public" selected/', $hEdit));
chk('edit: target_minor prefilled', str_contains($hEdit, 'value="500000"'));
chk('edit: target_count prefilled', str_contains($hEdit, 'value="100"'));

// show render smoke
$hShow = $render('cause_show', ['cause' => ['id' => 'c-1', 'name' => 'Building fund', 'status' => 'active',
    'visibility' => 'group', 'currency' => 'GHS', 'target_minor' => 500000]], 'en');
chk('show: renders name', str_contains($hShow, 'Building fund'));
chk('show: renders formatted target', str_contains($hShow, 'GHS 5,000.00'));
$hNf = $render('cause_show', ['cause' => null], 'en');
chk('show: not-found panel when null', str_contains($hNf, 'could not be found'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail > 0 ? 1 : 0);
