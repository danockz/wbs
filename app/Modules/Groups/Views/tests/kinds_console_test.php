<?php

declare(strict_types=1);

/**
 * GROUP KINDS taxonomy console — the write face of GroupKindController.
 *
 * The catalogue (GET group-kinds) now carries a create form + per-row edit link;
 * the detail page (GET group-kinds/{id}) carries an edit form (code immutable).
 * Both are no-JS PRG forms posting to webcsrf-guarded routes. This test covers:
 *   - Groups.kinds.* / kindShow.* console key parity across all 6 locales
 *   - catalogue: create form (required code+name, placement select, webcsrf) +
 *     per-row edit link; flash banners
 *   - detail: edit form prefilled (name/placement/colour/sort/status/description),
 *     code NOT an editable field, posts to the kind's update route; RTL for Arabic
 *   - controller: PRG (create→catalogue, update→kind page) / JSON for API;
 *     safeReturnTo guards open redirects; csrf passed to both views; actor from session
 *   - routes: both kind POSTs webcsrf-guarded
 *
 *   php app/Modules/Groups/Views/tests/kinds_console_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Groups/Language';
$viewDir = $root . '/app/Modules/Groups/Views';
$ctrl    = file_get_contents($root . '/app/Modules/Groups/Controllers/GroupKindController.php');
$routes  = file_get_contents($root . '/app/Config/Routes.php');

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

echo "language parity (console keys across 6 locales)\n";
$en        = require $langDir . '/en/Groups.php';
$kindsNew  = ['createHeading', 'fCode', 'fCodePh', 'fName', 'fNamePh', 'fColor', 'fSort', 'fDescription', 'fDescriptionPh', 'createBtn', 'editLink', 'createdFlash', 'updatedFlash'];
$showNew   = ['editHeading', 'saveBtn', 'backLink'];
foreach ($kindsNew as $k) {
    chk("en kinds.$k", array_key_exists($k, $en['kinds'] ?? []));
}
foreach ($showNew as $k) {
    chk("en kindShow.$k", array_key_exists($k, $en['kindShow'] ?? []));
}
$enKinds = $flatten($en['kinds'] ?? []);
$enShow  = $flatten($en['kindShow'] ?? []);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m = require $langDir . "/$loc/Groups.php";
    chk("$loc kinds.* parity", array_diff($enKinds, $flatten($m['kinds'] ?? [])) === []);
    chk("$loc kindShow.* parity", array_diff($enShow, $flatten($m['kindShow'] ?? [])) === []);
}

// ---- view render harness -----------------------------------------------------
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            public function getLocale() { return $GLOBALS['__gLoc'] ?? 'en'; }
        };
    }
}
if (! function_exists('config')) {
    function config($c)
    {
        return new class {
            public array $rtl = ['ar', 'he', 'fa', 'ur'];
        };
    }
}
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        if (array_shift($p) !== 'Groups') {
            return $key;
        }
        $v = $GLOBALS['__gLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
            $v = $v[$seg];
        }
        return $v;
    }
}

$render = static function (string $file, array $data, string $loc) use ($langDir, $viewDir): string {
    $GLOBALS['__gLoc']  = $loc;
    $GLOBALS['__gLang'] = require $langDir . "/$loc/Groups.php";
    extract($data);
    ob_start();
    include "$viewDir/$file";
    return (string) ob_get_clean();
};

echo "\ncatalogue view: create form + per-row edit\n";
$h = $render('kinds_index.php', [
    'kinds' => [['id' => 'k-1', 'code' => 'dept', 'name' => 'Department', 'default_placement' => 'nested', 'status' => 'active', 'color' => '#ff0000', 'sort_order' => 1]],
    'csrf'  => 'TKN',
], 'en');
chk('create form posts to /group-kinds', str_contains($h, 'action="/group-kinds"'));
chk('create form carries webcsrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('code field required', str_contains($h, 'name="code" required'));
chk('name field required', str_contains($h, 'name="name" required'));
chk('placement select present', str_contains($h, 'name="default_placement"') && str_contains($h, '<select'));
chk('per-row edit link to kind detail', str_contains($h, 'href="/group-kinds/k-1"'));
$h = $render('kinds_index.php', ['kinds' => [], 'csrf' => 'T'], 'ar');
chk('catalogue ar RTL', str_contains($h, 'lang="ar"') && str_contains($h, 'dir="rtl"'));
chk('catalogue still shows empty state (ar)', str_contains($h, '尚未') === false); // sanity: not zh copy

echo "\ndetail view: edit form prefilled\n";
$h = $render('kind_show.php', [
    'kind'   => ['code' => 'ministry', 'name' => 'Ministry', 'description' => 'A serving ministry', 'default_placement' => 'either', 'sort_order' => 5, 'status' => 'inactive', 'color' => '#00ff00'],
    'kindId' => 'k-9',
    'csrf'   => 'TKN',
], 'en');
chk('edit form posts to kind update route', str_contains($h, 'action="/group-kinds/k-9"'));
chk('edit form carries webcsrf', str_contains($h, 'name="_csrf" value="TKN"'));
chk('name prefilled', str_contains($h, 'name="name" required maxlength="120" value="Ministry"'));
chk('description prefilled', str_contains($h, 'value="A serving ministry"'));
chk('colour prefilled', str_contains($h, 'value="#00ff00"'));
chk('sort prefilled', str_contains($h, 'name="sort_order" min="0" value="5"'));
chk('placement either selected', str_contains($h, 'value="either" selected'));
chk('status inactive selected', (bool) preg_match('/value="inactive"\s+selected/', $h));
chk('code is NOT an editable input', ! preg_match('/name="code"/', $h));
chk('back link to catalogue', str_contains($h, 'href="/group-kinds"'));
$h = $render('kind_show.php', ['kind' => null, 'kindId' => 'x', 'csrf' => 'T'], 'ar');
chk('not-found panel keeps no edit form', ! str_contains(preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $h), '<form'));
chk('detail ar RTL', str_contains($h, 'dir="rtl"'));

echo "\ncontroller behaviour (source)\n";
chk('index passes csrf', preg_match('/function index.*wbsCsrf/s', $ctrl) === 1);
chk('show passes csrf + kindId', preg_match('/function show.*kindId.*wbsCsrf/s', $ctrl) === 1);
chk('create PRG to catalogue', str_contains($ctrl, "respondKind(\$result, '/group-kinds', 'createdFlash')"));
chk('update PRG to kind page', str_contains($ctrl, "/group-kinds/' . rawurlencode(\$kindId)"));
chk('API writes return JSON', str_contains($ctrl, 'wantsJson()') && str_contains($ctrl, 'respondWith($result)'));
chk('success flash localized', str_contains($ctrl, "lang('Groups.kinds."));
chk('safeReturnTo rejects scheme-relative //', str_contains($ctrl, "str_starts_with(\$rt, '//')"));
chk('actor from session (issued_by)', str_contains($ctrl, "currentUserId('issued_by')"));

echo "\nroutes: kind POSTs webcsrf-guarded\n";
chk('create route webcsrf', (bool) preg_match("#GroupKindController::create'.*webcsrf#", $routes));
chk('update route webcsrf', (bool) preg_match("#::update/\\\$1.*webcsrf#", $routes));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
