<?php

declare(strict_types=1);

/**
 * AccessControl delegations-received view i18n + render smoke. Asserts the
 * delegationsRecvView.* keys mirror across all locales, the SELF-CONTAINED
 * delegations_received view references lang('AccessControl.delegationsRecvView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), PHP singular/plural count,
 * verbatim subject + delegator ids and permission code, org-wide vs scoped group,
 * localized status vocab with raw-value fallback, and the empty state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_delegations_received_view_test.php
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

echo "language file completeness (delegationsRecvView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['delegationsRecvView']);
chk('en has delegationsRecvView.colFromWhom', in_array('colFromWhom', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['delegationsRecvView'] ?? []);
    chk("$loc mirrors all en delegationsRecvView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray delegationsRecvView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/delegations_received.php");
chk("delegations_received.php calls lang('AccessControl.delegationsRecvView.", str_contains($src, "lang('AccessControl.delegationsRecvView."));
chk('delegations_received.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('delegations_received.php includes _locale.php', str_contains($src, '_locale.php'));
chk('delegations_received.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$rows = [
    ['id' => 'd1', 'delegator_id' => 'usr-boss', 'delegate_id' => 'usr-42', 'permission_code' => 'groups.manage', 'scope_group_id' => 'grp-5', 'include_descendants' => 1, 'depth' => 1, 'status' => 'active', 'effective_to' => '2026-06-01'],
    ['id' => 'd2', 'delegator_id' => 'usr-boss', 'delegate_id' => 'usr-42', 'permission_code' => 'events.read', 'scope_group_id' => null, 'include_descendants' => 0, 'depth' => 2, 'status' => 'expired', 'effective_to' => '2026-01-01'],
];

$h = $render("$viewDir/delegations_received.php", ['delegations' => $rows, 'subjectId' => 'usr-42'], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Délégations reçues'));
chk('fr subject id verbatim', str_contains($h, 'usr-42'));
chk('fr plural count interpolated', str_contains($h, '2 délégations'));
chk('fr delegator id verbatim', str_contains($h, 'usr-boss'));
chk('fr permission codes verbatim', str_contains($h, 'groups.manage') && str_contains($h, 'events.read'));
chk('fr scoped group verbatim', str_contains($h, 'grp-5'));
chk('fr org-wide localized', str_contains($h, 'À l’échelle de l’organisation'));
chk('fr status localized (Active/Expirée)', str_contains($h, 'Active') && str_contains($h, 'Expirée'));

// singular
$h1 = $render("$viewDir/delegations_received.php", ['delegations' => [$rows[0]], 'subjectId' => 'usr-42'], 'fr');
chk('fr singular count', str_contains($h1, '1 délégation') && ! str_contains($h1, '1 délégations'));

// unknown status falls back to raw
$hU = $render("$viewDir/delegations_received.php", ['delegations' => [['id' => 'x', 'delegator_id' => 'a', 'delegate_id' => 'b', 'permission_code' => 'p', 'scope_group_id' => null, 'include_descendants' => 0, 'depth' => 1, 'status' => 'paused', 'effective_to' => 'b']], 'subjectId' => 's'], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'paused'));

// ar — RTL + empty
$ha = $render("$viewDir/delegations_received.php", ['delegations' => [], 'subjectId' => 'usr-1'], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'التفويضات المستلمة'));
chk('ar empty translated', str_contains($ha, 'لا يحمل هذا الشخص أي تفويضات.'));

// delegate_id + scope_group_id are entity references → the inline re-delegate form
// must render a person picker (roster) and a groups picker when those lists are
// supplied, and fall back to bounded text inputs otherwise.
echo "re-delegate entity-reference pickers\n";
$people = [
    ['id' => 'usr-42', 'display_name' => 'Ama Owusu'],   // == subject, must be excluded
    ['id' => 'usr-77', 'display_name' => 'Kofi Mensah'],
];
$grpList = [
    ['id' => 'grp-1', 'name' => 'Greater Accra', 'depth' => 0],
    ['id' => 'grp-2', 'name' => 'Accra Central', 'depth' => 1],
];
$hp = $render("$viewDir/delegations_received.php", [
    'delegations' => [], 'subjectId' => 'usr-42', 'delegates' => $people, 'groups' => $grpList,
], 'fr');
chk('delegate picker: renders <select id="rd_delegate">', str_contains($hp, '<select id="rd_delegate" name="delegate_id" required>'));
chk('delegate picker: option value = user id + name', str_contains($hp, 'value="usr-77"') && str_contains($hp, 'Kofi Mensah'));
chk('delegate picker: excludes the subject themselves', ! str_contains($hp, 'value="usr-42"'));
chk('delegate picker: none option present', str_contains($hp, lang('AccessControl.delegationsRecvView.fDelegateNone')));
chk('scope picker: renders <select id="rd_group">', str_contains($hp, '<select id="rd_group" name="scope_group_id">'));
chk('scope picker: option value = group id + name', str_contains($hp, 'value="grp-1"') && str_contains($hp, 'Greater Accra'));
chk('scope picker: org-wide none option present', str_contains($hp, lang('AccessControl.delegationsRecvView.fScopeGroupNone')));
// no lists → bounded free-text fallbacks
$hpNo = $render("$viewDir/delegations_received.php", ['delegations' => [], 'subjectId' => 'usr-42'], 'fr');
chk('delegate picker: bounded text fallback when no roster', str_contains($hpNo, 'id="rd_delegate" name="delegate_id" required maxlength="64"'));
chk('scope picker: bounded text fallback when no groups', str_contains($hpNo, 'id="rd_group" name="scope_group_id" maxlength="64"'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
