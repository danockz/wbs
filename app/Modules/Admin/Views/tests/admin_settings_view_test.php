<?php

declare(strict_types=1);

/**
 * Admin settings-view i18n + render smoke. Covers the two dead-link fixes in the
 * Admin module: the settings console (GET /admin) and the providers page
 * (GET /admin/providers). Asserts Admin.settings.* / Admin.providers.* key parity
 * across locales, that each SELF-CONTAINED view references lang(), includes
 * _locale.php, emits a dynamic <html lang dir>, and renders localized strings
 * with correct direction (RTL for Arabic), fixed-vocabulary + raw-value
 * fallbacks, PHP singular/plural counts, and verbatim data.
 *
 *   php app/Modules/Admin/Views/tests/admin_settings_view_test.php
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
$en = require $langDir . '/en/Admin.php';
foreach (['settings', 'providers'] as $sec) {
    $enKeys = $flatten($en[$sec]);
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $m    = require $langDir . "/$loc/Admin.php";
        $keys = $flatten($m[$sec] ?? []);
        chk("$loc mirrors all en Admin.$sec keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
        chk("$loc has no stray Admin.$sec keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
    }
}

echo "view localized + self-contained locale wiring\n";
foreach (['settings.php' => 'Admin.settings.', 'providers.php' => 'Admin.providers.'] as $file => $prefix) {
    $src = (string) file_get_contents("$viewDir/$file");
    chk("$file calls lang('$prefix", str_contains($src, "lang('$prefix"));
    chk("$file has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$file includes _locale.php", str_contains($src, '_locale.php'));
    chk("$file emits dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
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

// settings.php (fr): both tables, bool value, structured JSON, org-wide + group flag
$hs = $render("$viewDir/settings.php", [
    'settings' => [
        ['key' => 'branding.primary_color', 'value' => '#123456', 'version' => 3, 'updated_at' => '2026-05-01 10:00:00'],
        ['key' => 'payments.enabled', 'value' => true, 'version' => 1, 'updated_at' => '2026-05-02 11:00:00'],
        ['key' => 'retention.policy', 'value' => ['days' => 90, 'mode' => 'soft'], 'version' => 2, 'updated_at' => null],
    ],
    'flags' => [
        ['flag_key' => 'beta.newui', 'group_id' => null, 'enabled' => 1, 'updated_at' => '2026-05-03 09:00:00'],
        ['flag_key' => 'beta.newui', 'group_id' => 'grp-123', 'enabled' => 0, 'updated_at' => '2026-05-04 09:00:00'],
    ],
], 'fr');
chk('settings fr lang=fr dir=ltr', str_contains($hs, 'lang="fr"') && str_contains($hs, 'dir="ltr"'));
chk('settings fr heading translated', str_contains($hs, 'Paramètres de l’organisation'));
chk('settings fr plural settings count', str_contains($hs, '3 paramètres'));
chk('settings fr flags count', str_contains($hs, '2 indicateurs'));
chk('settings fr key verbatim', str_contains($hs, 'branding.primary_color'));
chk('settings fr bool value localized (Activé)', str_contains($hs, 'Activé'));
chk('settings fr structured value as JSON', str_contains($hs, 'days') && str_contains($hs, '90') && str_contains($hs, '<code>'));
chk('settings fr org-wide scope label', str_contains($hs, 'Toute l’organisation'));
chk('settings fr group id verbatim', str_contains($hs, 'grp-123'));

// providers.php (fr): status/category localized + raw fallback, verbatim, never-tested
$hp = $render("$viewDir/providers.php", [
    'providers' => [
        ['display_name' => 'Stripe Live', 'adapter_code' => 'stripe_v1', 'adapter_version' => 1, 'category' => 'payment', 'status' => 'active', 'tested_at' => '2026-06-01 08:00:00'],
        ['display_name' => '', 'adapter_code' => 'weird_x', 'adapter_version' => 2, 'category' => 'weirdcat', 'status' => 'weirdstate', 'tested_at' => null],
    ],
], 'fr');
chk('providers fr heading translated', str_contains($hp, 'Connexions de fournisseurs'));
chk('providers fr plural count', str_contains($hp, '2 fournisseurs'));
chk('providers fr status active localized', str_contains($hp, 'Actif'));
chk('providers fr category payment localized', str_contains($hp, 'Paiement'));
chk('providers fr unknown status falls back (Weirdstate)', str_contains($hp, 'Weirdstate'));
chk('providers fr unknown category falls back (Weirdcat)', str_contains($hp, 'Weirdcat'));
chk('providers fr name verbatim', str_contains($hp, 'Stripe Live'));
chk('providers fr adapter verbatim + version', str_contains($hp, 'stripe_v1') && str_contains($hp, 'v1'));
chk('providers fr no-name fallback', str_contains($hp, 'Connexion sans nom'));
chk('providers fr never-tested fallback', str_contains($hp, 'Jamais'));

// empty + RTL (ar)
$hse = $render("$viewDir/settings.php", ['settings' => [], 'flags' => []], 'ar');
chk('settings ar lang=ar dir=rtl', str_contains($hse, 'lang="ar"') && str_contains($hse, 'dir="rtl"'));
chk('settings ar settings empty translated', str_contains($hse, 'لا توجد إعدادات مهيّأة.'));
chk('settings ar flags empty translated', str_contains($hse, 'لا توجد أعلام ميزات مهيّأة.'));
$hpe = $render("$viewDir/providers.php", ['providers' => []], 'ar');
chk('providers ar dir=rtl + empty', str_contains($hpe, 'dir="rtl"') && str_contains($hpe, 'لا توجد اتصالات مزوّدين مهيّأة.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
