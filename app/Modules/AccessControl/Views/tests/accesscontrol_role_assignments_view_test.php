<?php

declare(strict_types=1);

/**
 * AccessControl role-assignments view i18n + render smoke. Asserts the
 * assignmentsView.* keys mirror across all locales, the SELF-CONTAINED
 * role_assignments view references lang('AccessControl.assignmentsView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), PHP singular/plural count,
 * verbatim subject id + role code, org-wide vs scoped-group rendering, localized
 * status + source vocab with raw-value fallback, and the empty state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_role_assignments_view_test.php
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

echo "language file completeness (assignmentsView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['assignmentsView']);
chk('en has assignmentsView.source.delegation', in_array('source.delegation', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['assignmentsView'] ?? []);
    chk("$loc mirrors all en assignmentsView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray assignmentsView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/role_assignments.php");
chk("role_assignments.php calls lang('AccessControl.assignmentsView.", str_contains($src, "lang('AccessControl.assignmentsView."));
chk('role_assignments.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('role_assignments.php includes _locale.php', str_contains($src, '_locale.php'));
chk('role_assignments.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$assignments = [
    ['id' => 'a1', 'role_id' => 'role-elder', 'role_code' => 'elder', 'role_name' => 'Elder', 'scope_group_id' => 'grp-7', 'include_descendants' => 1, 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => null, 'source' => 'direct'],
    ['id' => 'a2', 'role_id' => 'role-admin', 'role_code' => 'admin', 'role_name' => 'Admin', 'scope_group_id' => null, 'include_descendants' => 0, 'status' => 'revoked', 'effective_from' => '2025-01-01', 'effective_to' => '2026-01-01', 'source' => 'delegation'],
];

$h = $render("$viewDir/role_assignments.php", ['assignments' => $assignments, 'subjectId' => 'usr-42'], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Attributions de rôles'));
chk('fr subject id verbatim', str_contains($h, 'usr-42'));
chk('fr plural count interpolated', str_contains($h, '2 attributions'));
chk('fr role code verbatim', str_contains($h, 'elder'));
chk('fr scoped group id verbatim', str_contains($h, 'grp-7'));
chk('fr org-wide localized', str_contains($h, 'À l’échelle de l’organisation'));
chk('fr status localized (Active/Révoquée)', str_contains($h, 'Active') && str_contains($h, 'Révoquée'));
chk('fr source localized (Directe/Délégation)', str_contains($h, 'Directe') && str_contains($h, 'Délégation'));

// singular
$h1 = $render("$viewDir/role_assignments.php", ['assignments' => [$assignments[0]], 'subjectId' => 'usr-42'], 'fr');
chk('fr singular count', str_contains($h1, '1 attribution') && ! str_contains($h1, '1 attributions'));

// unknown status vocab falls back to raw
$hU = $render("$viewDir/role_assignments.php", ['assignments' => [['id' => 'x', 'role_id' => 'z', 'role_code' => 'z', 'role_name' => 'Z', 'scope_group_id' => null, 'include_descendants' => 0, 'status' => 'suspended', 'effective_from' => 'a', 'effective_to' => 'b', 'source' => 'direct']], 'subjectId' => 's'], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'suspended'));

// ar — RTL + empty
$ha = $render("$viewDir/role_assignments.php", ['assignments' => [], 'subjectId' => 'usr-1'], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'تعيينات الأدوار'));
chk('ar empty translated', str_contains($ha, 'لا توجد تعيينات أدوار لهذا الشخص.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
