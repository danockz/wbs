<?php

declare(strict_types=1);

/**
 * AccessControl single-assignment-detail view i18n + render smoke. Asserts the
 * assignmentShowView.* keys mirror across all locales, the SELF-CONTAINED
 * role_assignment_show view references lang('AccessControl.assignmentShowView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), verbatim subject id + role
 * code, org-wide vs scoped group, localized status + source vocab with raw-value
 * fallback (lowercased), the provenance block, and the not-found panel.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_role_assignment_show_view_test.php
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

echo "language file completeness (assignmentShowView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['assignmentShowView']);
chk('en has assignmentShowView.provenanceHeading', in_array('provenanceHeading', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['assignmentShowView'] ?? []);
    chk("$loc mirrors all en assignmentShowView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray assignmentShowView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/role_assignment_show.php");
chk("role_assignment_show.php calls lang('AccessControl.assignmentShowView.", str_contains($src, "lang('AccessControl.assignmentShowView."));
chk('role_assignment_show.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('role_assignment_show.php includes _locale.php', str_contains($src, '_locale.php'));
chk('role_assignment_show.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

echo "render smoke (fr + ar)\n";
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
    function lang(string $key)
    {
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

$assignment = [
    'id' => 'a1', 'subject_id' => 'usr-42', 'role_id' => 'role-elder', 'role_code' => 'elder', 'role_name' => 'Elder',
    'scope_group_id' => 'grp-7', 'include_descendants' => 1, 'status' => 'active',
    'effective_from' => '2026-01-01', 'effective_to' => null, 'issued_by' => 'usr-1', 'source' => 'direct', 'created_at' => '2026-01-01',
];

$h = $render("$viewDir/role_assignment_show.php", ['assignment' => $assignment], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr role name + code verbatim', str_contains($h, 'Elder') && str_contains($h, 'elder'));
chk('fr subject id verbatim', str_contains($h, 'usr-42'));
chk('fr scoped group verbatim', str_contains($h, 'grp-7'));
chk('fr status localized (Active)', str_contains($h, 'Active'));
chk('fr source localized (Directe)', str_contains($h, 'Directe'));
chk('fr issued-by verbatim', str_contains($h, 'usr-1'));
chk('fr provenance block shown', str_contains($h, 'Provenance'));

// org-wide + unknown source falls back to raw
$hU = $render("$viewDir/role_assignment_show.php", ['assignment' => ['id' => 'x', 'subject_id' => 's', 'role_id' => 'z', 'role_code' => 'z', 'role_name' => 'Z', 'scope_group_id' => null, 'include_descendants' => 0, 'status' => 'revoked', 'effective_from' => 'a', 'effective_to' => 'b', 'issued_by' => 'i', 'source' => 'migrated', 'created_at' => 'now']], 'fr');
chk('fr org-wide localized', str_contains($hU, 'À l’échelle de l’organisation'));
chk('fr revoked localized', str_contains($hU, 'Révoquée'));
chk('unknown source falls back to raw', str_contains($hU, 'migrated'));

// not found (null)
$hn = $render("$viewDir/role_assignment_show.php", ['assignment' => null], 'fr');
chk('fr not-found localized', str_contains($hn, 'Cette attribution est introuvable.'));

// ar — RTL + not found
$ha = $render("$viewDir/role_assignment_show.php", ['assignment' => null], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar not-found translated', str_contains($ha, 'لم يُعثر على هذا التعيين.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
