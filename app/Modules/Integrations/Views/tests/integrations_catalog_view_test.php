<?php

declare(strict_types=1);

/**
 * Integrations catalog-view i18n + render smoke. Asserts Integrations.* key
 * parity across locales, that the SELF-CONTAINED catalog view references
 * lang('Integrations.*'), includes _locale.php, emits a dynamic <html lang dir>,
 * and renders localized strings with correct direction (RTL for Arabic),
 * localized-with-fallback category, PHP singular/plural count, capabilities from
 * BOTH array and JSON-string shapes, verbatim data, and the no-caps fallback.
 *
 *   php app/Modules/Integrations/Views/tests/integrations_catalog_view_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Integrations/Language';
$viewDir = $root . '/app/Modules/Integrations/Views';

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
$en     = require $langDir . '/en/Integrations.php';
$enKeys = $flatten($en);
chk('en has nested category.payment', in_array('category.payment', $enKeys, true));
chk('en has heading + count + countOne', in_array('heading', $enKeys, true) && in_array('count', $enKeys, true) && in_array('countOne', $enKeys, true));

foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
    $keys = $flatten(require $langDir . "/$loc/Integrations.php");
    chk("$loc mirrors all en keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_diff($enKeys, $keys)));
    chk("$loc has no stray keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_diff($keys, $enKeys)));
}

echo "view localized + self-contained locale wiring\n";
$src = (string) file_get_contents("$viewDir/catalog.php");
chk("catalog.php calls lang('Integrations.", str_contains($src, "lang('Integrations."));
chk('catalog.php has no hardcoded lang="en"', ! str_contains($src, 'lang="en"'));
chk('catalog.php includes _locale.php', str_contains($src, '_locale.php'));
chk('catalog.php emits dynamic <html lang dir>', str_contains($src, '_shell_open.php'));
foreach (['<h1>Integration catalog', 'No declared capabilities', 'No active adapters'] as $needle) {
    chk("catalog.php no bare '$needle'", ! str_contains($src, $needle));
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
            function getLocale() { return $GLOBALS['__iLoc'] ?? 'en'; }
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
        if (array_shift($p) !== 'Integrations') {
            return $key;
        }
        $v = $GLOBALS['__iLang'];
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
    $GLOBALS['__iLoc']  = $loc;
    $GLOBALS['__iLang'] = require $langDir . "/$loc/Integrations.php";
    extract($data);
    ob_start();
    include $file;
    return (string) ob_get_clean();
};

// fr — plural, category label, caps from array AND json-string, verbatim, no-caps
$h = $render("$viewDir/catalog.php", [
    'adapters' => [
        ['code' => 'stripe_v1', 'display_name' => 'Stripe', 'category' => 'payment', 'family' => 'card_psp', 'version' => 1, 'capabilities' => ['charge.create', 'refund.create']],
        ['code' => 'twilio_v1', 'display_name' => 'Twilio', 'category' => 'notification', 'family' => 'sms', 'version' => 2, 'capabilities' => '["sms.send"]'],
        ['code' => 'mystery_v1', 'display_name' => 'Mystery', 'category' => 'weird_cat', 'family' => '', 'version' => 1, 'capabilities' => []],
    ],
], 'fr');
chk('fr lang=fr dir=ltr', str_contains($h, 'lang="fr"') && str_contains($h, 'dir="ltr"'));
chk('fr heading translated', str_contains($h, 'Catalogue d'));
chk('fr plural count interpolated', str_contains($h, '3 adaptateurs'));
chk('fr category payment translated', str_contains($h, 'Paiement'));
chk('fr category notification translated', str_contains($h, 'Notification'));
chk('fr unknown category falls back (Weird Cat)', str_contains($h, 'Weird Cat'));
chk('fr display name verbatim', str_contains($h, 'Stripe'));
chk('fr code verbatim', str_contains($h, 'stripe_v1'));
chk('fr cap from array verbatim', str_contains($h, 'charge.create'));
chk('fr cap from json-string verbatim', str_contains($h, 'sms.send'));
chk('fr version interpolated', str_contains($h, 'v2'));
chk('fr no-caps fallback translated', str_contains($h, 'Aucune capacité déclarée'));

// singular
$h1 = $render("$viewDir/catalog.php", ['adapters' => [['code' => 'x_v1', 'display_name' => 'X', 'category' => 'payment', 'family' => 'f', 'version' => 1, 'capabilities' => ['a']]]], 'fr');
chk('fr singular count', str_contains($h1, '1 adaptateur') && ! str_contains($h1, '1 adaptateurs'));

// ar — RTL + empty
$ha = $render("$viewDir/catalog.php", ['adapters' => []], 'ar');
chk('ar lang=ar dir=rtl', str_contains($ha, 'lang="ar"') && str_contains($ha, 'dir="rtl"'));
chk('ar heading translated', str_contains($ha, 'كتالوج التكاملات'));
chk('ar empty catalog translated', str_contains($ha, 'لا توجد محوّلات نشطة في الكتالوج.'));

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
