<?php

declare(strict_types=1);

/**
 * AccessControl rules-view i18n + render smoke. Asserts the rulesView.* keys
 * mirror across locales, the SELF-CONTAINED rules view references
 * lang('AccessControl.rulesView.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction
 * (RTL for Arabic), PHP singular/plural count, verbatim rule code/name/action,
 * localized fixed vocab (effect/scope) with raw-value fallback, the enabled/
 * disabled state, and the facet filter chip.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_rules_view_test.php
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

echo "language file completeness (rulesView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['rulesView']);
chk('en has rulesView.effect.allow', in_array('effect.allow', $enKeys, true));
chk('en has rulesView.scope.descendants_only', in_array('scope.descendants_only', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['rulesView'] ?? []);
    chk("$loc mirrors all en rulesView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray rulesView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/rules.php");
chk("rules.php calls lang('AccessControl.rulesView.", str_contains($src, "lang('AccessControl.rulesView."));
chk('rules.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('rules.php includes _locale.php', str_contains($src, '_locale.php'));
chk('rules.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$rules = [
    ['id' => 'ru1', 'facet' => 'access', 'code' => 'deny_after_hours', 'name' => 'Deny after hours',
        'effect' => 'deny', 'action_pattern' => 'contribution.*', 'scope_mode' => 'descendants_only',
        'priority' => 10, 'enabled' => 1, 'description' => 'Blocks writes outside business hours.'],
    ['id' => 'ru2', 'facet' => 'access', 'code' => 'allow_leads', 'name' => 'Allow leads',
        'effect' => 'allow', 'action_pattern' => '*', 'scope_mode' => 'self', 'priority' => 20, 'enabled' => 0],
];

$h = $render("$viewDir/rules.php", ['rules' => $rules, 'facet' => 'access'], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Catalogue des règles'));
chk('fr plural count interpolated', str_contains($h, '2 règles'));
chk('fr rule name verbatim', str_contains($h, 'Deny after hours'));
chk('fr rule code verbatim', str_contains($h, 'deny_after_hours'));
chk('fr action pattern verbatim', str_contains($h, 'contribution.*'));
chk('fr effect localized (Refuser)', str_contains($h, 'Refuser'));
chk('fr scope localized (Descendants uniquement)', str_contains($h, 'Descendants uniquement'));
chk('fr enabled/disabled localized', str_contains($h, 'Activée') && str_contains($h, 'Désactivée'));
chk('fr facet chip shown', str_contains($h, 'Facette') && str_contains($h, 'access'));
chk('fr description shown', str_contains($h, 'Blocks writes outside business hours.'));

// singular + no facet
$h1 = $render("$viewDir/rules.php", ['rules' => [$rules[0]], 'facet' => null], 'fr');
chk('fr singular count', str_contains($h1, '1 règle') && ! str_contains($h1, '1 règles'));

// unknown vocab falls back to raw value
$hU = $render("$viewDir/rules.php", ['rules' => [['id' => 'x', 'code' => 'z', 'name' => 'Z', 'effect' => 'escalate', 'scope_mode' => 'weird', 'enabled' => 1]], 'facet' => null], 'fr');
chk('unknown effect falls back to raw', str_contains($hU, 'escalate'));
chk('unknown scope falls back to raw', str_contains($hU, 'weird'));

// ar — RTL + empty
$ha = $render("$viewDir/rules.php", ['rules' => [], 'facet' => null], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'فهرس القواعد'));
chk('ar empty translated', str_contains($ha, 'لم تُعرَّف أي قواعد بعد.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
