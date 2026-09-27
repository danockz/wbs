<?php

declare(strict_types=1);

/**
 * AccessControl rule-detail view i18n + render smoke. Asserts the ruleShowView.*
 * keys mirror across all locales, the SELF-CONTAINED rule_show view references
 * lang('AccessControl.ruleShowView.*'), includes _locale.php, emits a dynamic
 * <html lang dir>, and renders localized strings with correct direction (RTL for
 * Arabic), verbatim facet/code/action, localized effect + scope-mode vocab with
 * raw-value fallback (lowercased), condition/params rendered, the hand-picked
 * group set, the enabled/disabled state, and the not-found panel when $rule is null.
 *
 *   php app/Modules/AccessControl/Views/tests/accesscontrol_rule_show_view_test.php
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

echo "language file completeness (ruleShowView.*)\n";
$en     = require $langDir . '/en/AccessControl.php';
$enKeys = $flatten($en['ruleShowView']);
chk('en has ruleShowView.scope.descendants_only', in_array('scope.descendants_only', $enKeys, true));
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/AccessControl.php";
    $keys = $flatten($m['ruleShowView'] ?? []);
    chk("$loc mirrors all en ruleShowView keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray ruleShowView keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/rule_show.php");
chk("rule_show.php calls lang('AccessControl.ruleShowView.", str_contains($src, "lang('AccessControl.ruleShowView."));
chk('rule_show.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('rule_show.php includes _locale.php', str_contains($src, '_locale.php'));
chk('rule_show.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

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

$rule = [
    'id' => 'ru1', 'facet' => 'events', 'code' => 'block_after_close', 'name' => 'Block after close',
    'description' => 'No edits once closed', 'effect' => 'deny', 'action_pattern' => 'events.edit',
    'condition' => '{"status":"closed"}', 'effect_params' => null,
    'scope_group_id' => null, 'scope_mode' => 'self_and_descendants', 'priority' => 10, 'enabled' => 1,
    'scope_groups' => ['grp-1', 'grp-2'],
];

$h = $render("$viewDir/rule_show.php", ['rule' => $rule], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr name verbatim', str_contains($h, 'Block after close'));
chk('fr facet verbatim', str_contains($h, 'events'));
chk('fr action pattern verbatim', str_contains($h, 'events.edit'));
chk('fr effect localized (Refuser)', str_contains($h, 'Refuser'));
chk('fr scope mode localized', str_contains($h, 'Soi et descendants'));
chk('fr condition rendered', str_contains($h, 'closed'));
chk('fr scope groups verbatim', str_contains($h, 'grp-1') && str_contains($h, 'grp-2'));
chk('fr enabled localized', str_contains($h, 'Activée'));

// unknown effect + scope fall back to raw (lowercased)
$hU = $render("$viewDir/rule_show.php", ['rule' => ['id' => 'x', 'facet' => 'f', 'code' => 'c', 'name' => 'N', 'description' => null, 'effect' => 'ESCALATE', 'action_pattern' => '*', 'condition' => null, 'effect_params' => null, 'scope_group_id' => null, 'scope_mode' => 'weird_mode', 'priority' => 1, 'enabled' => 0, 'scope_groups' => []]], 'fr');
chk('unknown effect falls back to raw lowercased', str_contains($hU, 'escalate'));
chk('unknown scope mode falls back to raw', str_contains($hU, 'weird_mode'));
chk('empty scope groups localized', str_contains($hU, 'Aucun groupe sélectionné.'));
chk('disabled localized', str_contains($hU, 'Désactivée'));

// not found (null)
$hn = $render("$viewDir/rule_show.php", ['rule' => null], 'fr');
chk('fr not-found localized', str_contains($hn, 'Cette règle est introuvable.'));

// ar — RTL + not found
$ha = $render("$viewDir/rule_show.php", ['rule' => null], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar not-found translated', str_contains($ha, 'لم يُعثر على هذه القاعدة.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
