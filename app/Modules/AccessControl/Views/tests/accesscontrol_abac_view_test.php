<?php

declare(strict_types=1);

/**
 * AccessControl ABAC-policies-view i18n + render smoke. Asserts the abacView.*
 * keys mirror across locales, the SELF-CONTAINED abac_policies view references
 * lang('AccessControl.abacView.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction
 * (RTL for Arabic), PHP singular/plural count, verbatim policy code/action,
 * localized effect vocab with raw-value fallback, and the enabled/disabled state.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_abac_view_test.php
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

echo "language file completeness (abacView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['abacView']);
chk('en has abacView.effect.allow', in_array('effect.allow', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['abacView'] ?? []);
    chk("$loc mirrors all en abacView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray abacView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/abac_policies.php");
chk("abac_policies.php calls lang('AccessControl.abacView.", str_contains($src, "lang('AccessControl.abacView."));
chk('abac_policies.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('abac_policies.php includes _locale.php', str_contains($src, '_locale.php'));
chk('abac_policies.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$policies = [
    ['id' => 'p1', 'code' => 'block_pii_export', 'effect' => 'deny', 'action_pattern' => 'export.*', 'priority' => 5, 'enabled' => 1],
    ['id' => 'p2', 'code' => 'allow_self_read', 'effect' => 'allow', 'action_pattern' => 'profile.read', 'priority' => 50, 'enabled' => 0],
];

$h = $render("$viewDir/abac_policies.php", ['policies' => $policies], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Catalogue des politiques ABAC'));
chk('fr plural count interpolated', str_contains($h, '2 politiques'));
chk('fr policy code verbatim', str_contains($h, 'block_pii_export'));
chk('fr action pattern verbatim', str_contains($h, 'export.*'));
chk('fr effect localized (Refuser/Autoriser)', str_contains($h, 'Refuser') && str_contains($h, 'Autoriser'));
chk('fr enabled/disabled localized', str_contains($h, 'Activée') && str_contains($h, 'Désactivée'));

// singular
$h1 = $render("$viewDir/abac_policies.php", ['policies' => [$policies[0]]], 'fr');
chk('fr singular count', str_contains($h1, '1 politique') && ! str_contains($h1, '1 politiques'));

// unknown effect vocab falls back to raw
$hU = $render("$viewDir/abac_policies.php", ['policies' => [['id' => 'x', 'code' => 'z', 'effect' => 'audit', 'action_pattern' => '*', 'enabled' => 1]]], 'fr');
chk('unknown effect falls back to raw', str_contains($hU, 'audit'));

// ar — RTL + empty
$ha = $render("$viewDir/abac_policies.php", ['policies' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'فهرس سياسات ABAC'));
chk('ar empty translated', str_contains($ha, 'لم تُعرَّف أي سياسات بعد.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
