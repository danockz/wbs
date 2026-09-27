<?php

declare(strict_types=1);

/**
 * AccessControl delegation-chain view i18n + render smoke. Asserts the
 * delegationChainView.* keys mirror across all locales, the SELF-CONTAINED
 * delegations_chain view references lang('AccessControl.delegationChainView.*'),
 * includes _locale.php, emits a dynamic <html lang dir>, and renders localized
 * strings with correct direction (RTL for Arabic), PHP singular/plural count,
 * verbatim delegator→delegate ids and permission code, depth, org-wide vs scoped
 * group, localized status vocab with raw-value fallback, and the empty state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_delegations_chain_view_test.php
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

echo "language file completeness (delegationChainView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['delegationChainView']);
chk('en has delegationChainView.status.active', in_array('status.active', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['delegationChainView'] ?? []);
    chk("$loc mirrors all en delegationChainView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray delegationChainView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/delegations_chain.php");
chk("delegations_chain.php calls lang('AccessControl.delegationChainView.", str_contains($src, "lang('AccessControl.delegationChainView."));
chk('delegations_chain.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('delegations_chain.php includes _locale.php', str_contains($src, '_locale.php'));
chk('delegations_chain.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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
    ['id' => 'd1', 'delegator_id' => 'usr-1', 'delegate_id' => 'usr-2', 'permission_code' => 'events.manage', 'scope_group_id' => 'grp-9', 'include_descendants' => 1, 'depth' => 1, 'status' => 'active', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-01'],
    ['id' => 'd2', 'delegator_id' => 'usr-2', 'delegate_id' => 'usr-3', 'permission_code' => 'events.manage', 'scope_group_id' => null, 'include_descendants' => 0, 'depth' => 2, 'status' => 'revoked', 'effective_from' => '2026-02-01', 'effective_to' => '2026-06-01'],
];

$h = $render("$viewDir/delegations_chain.php", ['delegations' => $rows], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Chaîne de délégation'));
chk('fr plural count interpolated', str_contains($h, '2 délégations'));
chk('fr delegator+delegate verbatim', str_contains($h, 'usr-1') && str_contains($h, 'usr-2') && str_contains($h, 'usr-3'));
chk('fr permission code verbatim', str_contains($h, 'events.manage'));
chk('fr scoped group verbatim', str_contains($h, 'grp-9'));
chk('fr org-wide localized', str_contains($h, 'À l’échelle de l’organisation'));
chk('fr status localized (Active/Révoquée)', str_contains($h, 'Active') && str_contains($h, 'Révoquée'));

// singular
$h1 = $render("$viewDir/delegations_chain.php", ['delegations' => [$rows[0]]], 'fr');
chk('fr singular count', str_contains($h1, '1 délégation') && ! str_contains($h1, '1 délégations'));

// unknown status falls back to raw
$hU = $render("$viewDir/delegations_chain.php", ['delegations' => [['id' => 'x', 'delegator_id' => 'a', 'delegate_id' => 'b', 'permission_code' => 'p', 'scope_group_id' => null, 'include_descendants' => 0, 'depth' => 1, 'status' => 'paused', 'effective_from' => 'a', 'effective_to' => 'b']]], 'fr');
chk('unknown status falls back to raw', str_contains($hU, 'paused'));

// ar — RTL + empty
$ha = $render("$viewDir/delegations_chain.php", ['delegations' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'سلسلة التفويض'));
chk('ar empty translated', str_contains($ha, 'لا توجد تفويضات في هذه السلسلة.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
