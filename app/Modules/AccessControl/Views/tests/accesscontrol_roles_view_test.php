<?php

declare(strict_types=1);

/**
 * AccessControl roles-view i18n + render smoke. Asserts the rolesView.* keys
 * mirror across locales, the SELF-CONTAINED roles view references
 * lang('AccessControl.rolesView.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction
 * (RTL for Arabic), PHP singular/plural count, verbatim role/permission data,
 * and permission-collapse ("+N more").
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_roles_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/AccessControl/Language';
$viewDir = $root . '/app/Modules/AccessControl/Views';

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

echo "language file completeness (rolesView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en);
chk('en has rolesView.heading', in_array('rolesView.heading', $enKeys, true));
chk('en has rolesView.count + countOne', in_array('rolesView.count', $enKeys, true) && in_array('rolesView.countOne', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/AccessControl.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/roles.php");
chk("roles.php calls lang('AccessControl.rolesView.", str_contains($src, "lang('AccessControl.rolesView."));
chk('roles.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('roles.php includes _locale.php', str_contains($src, '_locale.php'));
chk('roles.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Role catalogue', 'No permissions', 'No roles defined'] as $needle) {
    chk("roles.php no bare '$needle'", ! str_contains($src, $needle));
}

echo "render smoke (fr + ar)\n";
if (! function_exists('esc')) {
    function esc($s, $c = 'html')
    {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    }
}
if (! function_exists('service')) {
    function service($x = null)
    {
        return new class {
            function getLocale() { return $GLOBALS['__aLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'AccessControl') {
            return $key;
        }
        $v = $GLOBALS['__aLang'];
        foreach ($p as $seg) {
            if (! is_array($v) || ! array_key_exists($seg, $v)) {
                return $key;
            }
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

// fr — plural, verbatim code/name/perms, collapse "+N more", empty-perms role
$manyPerms = [];
for ($i = 1; $i <= 11; $i++) {
    $manyPerms[] = 'perm.code.' . $i;
}
$h = $render("$viewDir/roles.php", [
    'roles' => [
        ['id' => 'r1', 'code' => 'org_admin', 'name' => 'Org Admin', 'permissions' => $manyPerms],
        ['id' => 'r2', 'code' => 'member', 'name' => 'Member', 'permissions' => []],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Catalogue des rôles'));
chk('fr plural count interpolated', str_contains($h, '2 rôles'));
chk('fr role name verbatim', str_contains($h, 'Org Admin'));
chk('fr role code verbatim', str_contains($h, 'org_admin'));
chk('fr permission code verbatim', str_contains($h, 'perm.code.1'));
chk('fr collapses to +N more (11 perms, max 8)', str_contains($h, '+3 de plus'));
chk('fr no-perms role translated', str_contains($h, 'Aucune permission'));

// singular
$h1 = $render("$viewDir/roles.php", ['roles' => [['id' => 'x', 'code' => 'solo', 'name' => 'Solo', 'permissions' => ['a']]]], 'fr');
chk('fr singular count', str_contains($h1, '1 rôle') && ! str_contains($h1, '1 rôles'));

// ar — RTL + empty
$ha = $render("$viewDir/roles.php", ['roles' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'دليل الأدوار'));
chk('ar empty roles translated', str_contains($ha, 'لم تُحدَّد أي أدوار بعد.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
