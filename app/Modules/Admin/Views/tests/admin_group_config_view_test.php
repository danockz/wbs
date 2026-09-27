<?php

declare(strict_types=1);

/**
 * Admin effective-config view i18n + render smoke. Asserts Admin.* key parity
 * across locales, that the SELF-CONTAINED group_config view references
 * lang('Admin.*'), includes _locale.php, emits a dynamic <html lang dir>, and
 * renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback decision + inheritance mode, boolean value labels,
 * JSON-encoded structured values, verbatim capability/group/source, and the
 * no-effective-value + null-source fallbacks.
 *
 *   php app/Modules/Admin/Views/tests/admin_group_config_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Admin/Language';
$viewDir = $root . '/app/Modules/Admin/Views';

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

echo "language file completeness\n";
$en     = require $langDir . '/en/Admin.php';
$enKeys = $flatten($en);
chk('en has nested decision.child_override', in_array('decision.child_override', $enKeys, true));
chk('en has nested inheritance.inherit_only', in_array('inheritance.inherit_only', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/Admin.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/group_config.php");
chk("group_config.php calls lang('Admin.", str_contains($src, "lang('Admin."));
chk('group_config.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('group_config.php includes _locale.php', str_contains($src, '_locale.php'));
chk('group_config.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Effective configuration', 'No effective value', 'Inheritance mode</'] as $needle) {
    chk("group_config.php no bare '$needle'", ! str_contains($src, $needle));
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
            function getLocale() { return $GLOBALS['__admLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Admin') {
            return $key;
        }
        $v = $GLOBALS['__admLang'];
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
    $GLOBALS['__admLoc']  = $loc;
    $GLOBALS['__admLang'] = require $langDir . "/$loc/Admin.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — inherited decision, boolean value, verbatim capability/source/version
$h = $render("$viewDir/group_config.php", [
    'capability' => 'events.rsvp',
    'group_id'   => 'grp-child',
    'config'     => [
        'value'            => true,
        'source_group_id'  => 'grp-ancestor',
        'version'          => 4,
        'inheritance_mode' => 'ancestor_default_child_override',
        'decision'         => 'inherited_from_ancestor',
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Configuration effective'));
chk('fr capability verbatim', str_contains($h, 'events.rsvp'));
chk('fr group verbatim', str_contains($h, 'grp-child'));
chk('fr decision inherited translated', str_contains($h, 'un anc'));
chk('fr boolean value -> Activé', str_contains($h, 'Activé'));
chk('fr source group verbatim', str_contains($h, 'grp-ancestor'));
chk('fr version verbatim', str_contains($h, '>4<'));
chk('fr inheritance mode translated', str_contains($h, 'peut remplacer'));

// structured value -> JSON, and no-effective-value + null source
$hj = $render("$viewDir/group_config.php", [
    'capability' => 'limits',
    'group_id'   => 'g',
    'config'     => ['value' => ['max' => 5], 'source_group_id' => 'g', 'version' => 1, 'inheritance_mode' => 'child_owned', 'decision' => 'own_child_owned'],
], 'fr');
chk('fr structured value JSON-encoded', str_contains($hj, '{&quot;max&quot;:5}') || str_contains($hj, '{"max":5}'));

$hn = $render("$viewDir/group_config.php", [
    'capability' => 'x', 'group_id' => 'g',
    'config'     => ['value' => null, 'source_group_id' => null, 'version' => null, 'inheritance_mode' => null, 'decision' => 'no_effective_value'],
], 'fr');
chk('fr no-effective-value translated', str_contains($hn, 'Aucune valeur effective'));
chk('fr null source translated', str_contains($hn, 'aucun'));

// ar — RTL, boolean false
$ha = $render("$viewDir/group_config.php", [
    'capability' => 'x', 'group_id' => 'g',
    'config'     => ['value' => false, 'source_group_id' => 'g', 'version' => 2, 'inheritance_mode' => 'not_inheritable', 'decision' => 'own_not_inheritable'],
], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'الإعداد الفعّال'));
chk('ar boolean false translated', str_contains($ha, 'مُعطّل'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
