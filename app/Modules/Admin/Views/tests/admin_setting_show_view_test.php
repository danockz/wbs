<?php

declare(strict_types=1);

/**
 * Admin setting_show view test — covers the bespoke single-setting page that
 * replaced respondAdmin's generic console for AdminController::getSetting.
 * Asserts Admin.settingShow.* key parity across locales, that the SELF-CONTAINED
 * view references lang(), includes _locale.php and emits a dynamic <html lang
 * dir>, and that it renders localized copy with correct direction (RTL for
 * Arabic), booleans as localized Enabled/Disabled, scalars/JSON verbatim, and an
 * explicit not-set state.
 *
 *   php app/Modules/Admin/Views/tests/admin_setting_show_view_test.php
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

echo "language parity for settingShow block\n";
$en = require $langDir . '/en/Admin.php';
chk('en has settingShow block', isset($en['settingShow']) && is_array($en['settingShow']));
$enKeys = $flatten(['settingShow' => $en['settingShow']]);
sort($enKeys);
foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $m    = require $langDir . "/$loc/Admin.php";
    $keys = $flatten(['settingShow' => $m['settingShow'] ?? []]);
    sort($keys);
    $diff = array_merge(array_diff($enKeys, $keys), array_diff($keys, $enKeys));
    chk("$loc mirrors en settingShow keys (" . count($keys) . ')', $diff === [], implode(',', $diff));
}

echo "static checks\n";
$src = (string) file_get_contents("$viewDir/setting_show.php");
chk("calls lang('Admin.settingShow.", str_contains($src, "lang('Admin.settingShow."));
chk('no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('includes _locale.php', str_contains($src, '_locale.php'));
chk('emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));

echo "render smoke\n";
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
        if (array_shift($p) !== 'Admin') {
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
    $GLOBALS['__aLang'] = require $langDir . "/$loc/Admin.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — scalar value verbatim, key verbatim
$h = $render("$viewDir/setting_show.php", ['key' => 'branding.primary_color', 'value' => '#123456'], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Paramètre de l’organisation'));
chk('fr key verbatim', str_contains($h, 'branding.primary_color'));
chk('fr scalar value verbatim', str_contains($h, '#123456'));

// bool true -> localized Enabled
$h = $render("$viewDir/setting_show.php", ['key' => 'payments.enabled', 'value' => true], 'en');
chk('bool true localized', str_contains($h, lang('Admin.boolTrue')) || str_contains($h, 'Enabled'));

// structured value -> JSON
$h = $render("$viewDir/setting_show.php", ['key' => 'retention.policy', 'value' => ['days' => 90, 'mode' => 'soft']], 'en');
chk('structured value as JSON', str_contains($h, 'days') && str_contains($h, '90') && str_contains($h, 'soft'));

// null -> not-set state
$h = $render("$viewDir/setting_show.php", ['key' => 'missing.key', 'value' => null], 'en');
chk('null value shows not-set', str_contains($h, 'Not set'));
chk('null value still shows key', str_contains($h, 'missing.key'));

// ar RTL
$h = $render("$viewDir/setting_show.php", ['key' => 'k', 'value' => null], 'ar');
chk('ar rtl + translated not-set', str_contains($h, 'dir="rtl"') && str_contains($h, 'غير مضبوط'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
