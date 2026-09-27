<?php

declare(strict_types=1);

/**
 * AccessControl i18n test.
 *
 * AccessControl has NO bespoke views — its 7 admin controllers render through the
 * shared admin console (BaseController::respondAdmin). Localizing the module
 * therefore means (1) the admin-console CHROME strings and (2) the title/subtitle
 * each controller passes. This test asserts:
 *   - every locale's AdminConsole.php + AccessControl.php mirror the English keys
 *     (no missing, no stray),
 *   - the admin console view uses the localized chrome (no hardcoded English),
 *   - every controller respondAdmin() title/labelled-subtitle uses lang(),
 *   - the admin console renders localized chrome end-to-end (fr) with fallback.
 *
 *   php app/Modules/AccessControl/Controllers/tests/accesscontrol_i18n_test.php
 */

$root = dirname(__DIR__, 5);

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
$locales = ['fr', 'es', 'pt', 'zh', 'ar'];

echo "language completeness — AdminConsole (shared chrome)\n";
$enAc = require $root . '/app/Language/en/AdminConsole.php';
$enAcKeys = $flatten($enAc);
chk('en/AdminConsole.php has consoleTitle', in_array('consoleTitle', $enAcKeys, true));
chk('en/AdminConsole.php has noRecords', in_array('noRecords', $enAcKeys, true));
foreach ($locales as $loc) {
    $f = $root . "/app/Language/$loc/AdminConsole.php";
    if (! is_file($f)) {
        chk("$loc/AdminConsole.php exists", false);
        continue;
    }
    $keys = $flatten(require $f);
    chk("$loc AdminConsole mirrors en", array_diff($enAcKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enAcKeys, $keys)));
    chk("$loc AdminConsole no stray keys", array_diff($keys, $enAcKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enAcKeys)));
}

echo "language completeness — AccessControl (titles/subtitles)\n";
$en = require $root . '/app/Modules/AccessControl/Language/en/AccessControl.php';
$enKeys = $flatten($en);
chk('en/AccessControl.php has roles', in_array('roles', $enKeys, true));
chk('en/AccessControl.php has subjectSub with {0}', str_contains((string) ($en['subjectSub'] ?? ''), '{0}'));
foreach ($locales as $loc) {
    $f = $root . "/app/Modules/AccessControl/Language/$loc/AccessControl.php";
    if (! is_file($f)) {
        chk("$loc/AccessControl.php exists", false);
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    chk("$loc AccessControl mirrors en", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc AccessControl no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    chk("$loc keeps {0} in subjectSub", str_contains((string) ($arr['subjectSub'] ?? ''), '{0}'));
    chk("$loc keeps {0} in facetSub", str_contains((string) ($arr['facetSub'] ?? ''), '{0}'));
}

echo "admin console view localized (no hardcoded chrome)\n";
$view = (string) file_get_contents($root . '/app/Modules/Shared/Views/admin_console.php');
chk('view defines a lang() fallback helper $t', str_contains($view, "function_exists('lang')"));
chk('view localizes the badge', str_contains($view, "AdminConsole.badge"));
chk('view localizes noRecords', str_contains($view, "AdminConsole.noRecords"));
chk('view no hardcoded >No records.<', ! str_contains($view, '>No records.</div>'));
chk('view no hardcoded record<?= plural', ! str_contains($view, "record<?= \$count === 1"));

echo "controllers pass lang() titles (no hardcoded English)\n";
$ctrls = ['Role', 'Rule', 'AbacPolicy', 'AccessRequest', 'BreakGlass', 'Delegation', 'RoleAssignment'];
foreach ($ctrls as $c) {
    $src = (string) file_get_contents($root . "/app/Modules/AccessControl/Controllers/{$c}Controller.php");
    // No respondAdmin call should carry a bare quoted Title-Case string arg.
    $hardcoded = (bool) preg_match("/respondAdmin\\([^;]*?,\\s*'[A-Z][A-Za-z ]+'/s", $src);
    chk("{$c}Controller has no hardcoded respondAdmin title", ! $hardcoded);
    chk("{$c}Controller uses lang('AccessControl.", str_contains($src, "lang('AccessControl."));
}

echo "render smoke (fr) — admin console chrome + fallback\n";
$GLOBALS['__L'] = [
    'AdminConsole'  => require $root . '/app/Language/fr/AdminConsole.php',
    'AccessControl' => require $root . '/app/Modules/AccessControl/Language/fr/AccessControl.php',
];
if (! function_exists('lang')) {
    function lang(string $key)
    {
        $p = explode('.', $key);
        $ns = array_shift($p);
        if (! isset($GLOBALS['__L'][$ns])) {
            return $key;
        }
        $v = $GLOBALS['__L'][$ns];
        foreach ($p as $s) {
            if (! is_array($v) || ! array_key_exists($s, $v)) {
                return $key;
            }
            $v = $v[$s];
        }
        return $v;
    }
}
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
$render = static function (array $data) use ($root): string {
    $view = new class {
        function extend($x) { return ''; }
        function section($x) { return ''; }
        function endSection() { return ''; }
    };
    $b = Closure::bind(function () use ($root, $data) {
        extract($data);
        ob_start();
        include $root . '/app/Modules/Shared/Views/admin_console.php';
        return ob_get_clean();
    }, $view, get_class($view));
    return (string) $b();
};
// A list result with a French title from the module lang file + empty result.
$html = $render(['title' => lang('AccessControl.roles'), 'subtitle' => '', 'ok' => true, 'result' => ['data' => [['id' => 'r1', 'name' => 'Admin']]]]);
chk('fr admin console shows module title Rôles', str_contains($html, 'Rôles'), substr($html, 0, 100));
chk('fr admin console badge translated (admin)', str_contains($html, 'admin')); // fr badge is "admin"
chk('fr admin console record label translated', str_contains($html, 'enregistrement'));
$empty = $render(['title' => lang('AccessControl.roles'), 'subtitle' => '', 'ok' => true, 'result' => ['data' => []]]);
chk('fr admin console empty state translated', str_contains($empty, 'Aucun enregistrement.'));
$err = $render(['title' => lang('AccessControl.accessRequest'), 'subtitle' => 'x', 'ok' => false, 'result' => ['title' => 'NOT_FOUND', 'status' => 404, 'detail' => 'gone']]);
chk('fr admin console status line translated', str_contains($err, 'statut 404'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
