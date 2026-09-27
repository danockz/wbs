<?php

declare(strict_types=1);

/**
 * i18n wiring test — asserts Phase 1 language-awareness is actually plumbed in:
 * the LocaleFilter is a registered global, App config lists all supported
 * locales, the switch route exists, the layout emits dynamic lang/dir, every
 * locale ships a complete App.php mirroring English keys, and the menu ETag +
 * category labels are locale-aware.
 *
 *   php app/Modules/Shared/I18n/tests/i18n_wiring_test.php
 */

$root = dirname(__DIR__, 5);

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
function slurp(string $p): string
{
    return (string) @file_get_contents($p);
}

echo "config + filter wiring\n";
$filters = slurp($root . '/app/Config/Filters.php');
chk('LocaleFilter imported', str_contains($filters, 'use WBS\Shared\Filters\LocaleFilter;'));
chk('locale alias registered', str_contains($filters, "'locale'") && str_contains($filters, 'LocaleFilter::class'));
chk('locale in global before', (bool) preg_match('/before.\s*=>\s*\[[^\]]*\'locale\'/s', $filters));
chk('locale in global after', (bool) preg_match('/after.\s*=>\s*\[[^\]]*\'locale\'/s', $filters));

$app = slurp($root . '/app/Config/App.php');
$cfg = slurp($root . '/app/Config/Locale.php');
require_once $root . '/app/Modules/Shared/I18n/LocaleResolver.php';
// Pull the supported list out of Config\Locale by eval-free parse.
preg_match('/public array \$supported\s*=\s*\[([^\]]*)\]/', $cfg, $m);
preg_match_all('/\'([a-z]{2,3})\'/', $m[1] ?? '', $sm);
$supported = $sm[1] ?? [];
chk('Config\Locale lists >=2 locales', count($supported) >= 2, implode(',', $supported));
foreach ($supported as $loc) {
    chk("App.supportedLocales includes '$loc'", (bool) preg_match("/supportedLocales\s*=\s*\[[^\]]*'" . $loc . "'/s", $app), 'missing in App.php');
}

echo "switch route + controller\n";
$routes = slurp($root . '/app/Config/Routes.php');
chk('POST prefs/locale route', str_contains($routes, "prefs/locale") && str_contains($routes, 'LocaleController::set'));
chk('switch route has webcsrf', (bool) preg_match('/prefs\/locale.*webcsrf/s', $routes));
chk('LocaleController exists', is_file($root . '/app/Modules/Shared/Controllers/LocaleController.php'));

echo "layout dynamic lang/dir\n";
$layout = slurp($root . '/app/Views/layouts/app.php')
    . slurp($root . '/app/Modules/Shared/Views/_shell_open.php');
chk('layout no hardcoded lang="en"', ! str_contains($layout, '<html lang="en">'), 'still hardcoded');
chk('layout emits dynamic lang', str_contains($layout, '_shell_open.php'), 'no dynamic lang');
chk('layout emits dir', str_contains($layout, 'dir="<?= wbs_shell_esc($wbsDir') || str_contains($layout, 'dir="<?= esc($wbsDir'), 'no dir attr');
chk('layout guards service()', str_contains($layout, "function_exists('service')"), 'unguarded service()');

echo "language files complete (mirror English keys)\n";
$enFile = $root . '/app/Language/en/App.php';
chk('en/App.php exists', is_file($enFile));
$en = require $enFile;
// Flatten keys one level deep for comparison.
$flatten = static function (array $a, string $prefix = '') use (&$flatten): array {
    $out = [];
    foreach ($a as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $out = array_merge($out, $flatten($v, $key));
        } else {
            $out[] = $key;
        }
    }
    return $out;
};
$enKeys = $flatten($en);
chk('en has menu.overview', in_array('menu.overview', $enKeys, true));
chk('en has languageNames.ar', in_array('languageNames.ar', $enKeys, true));

foreach ($supported as $loc) {
    $f = $root . "/app/Language/$loc/App.php";
    if (! is_file($f)) {
        chk("$loc/App.php exists", false, 'missing file');
        continue;
    }
    $arr  = require $f;
    $keys = $flatten($arr);
    $missing = array_diff($enKeys, $keys);
    chk("$loc/App.php mirrors all en keys", $missing === [], 'missing: ' . implode(',', $missing));
}

echo "menu labels + ETag are locale-aware\n";
require_once $root . '/app/Modules/Shared/Navigation/MenuCategory.php';
require_once $root . '/app/Modules/Shared/Navigation/MenuService.php';

use WBS\Shared\Navigation\MenuCategory;
use WBS\Shared\Navigation\MenuService;

// Without the framework, label() must fall back to hardcoded English (never a key).
chk('MenuCategory::label falls back to English', MenuCategory::label('overview') === 'Overview', MenuCategory::label('overview'));
chk('MenuCategory::label unknown key safe', MenuCategory::label('nope') === 'nope');

// ETag varies by locale.
$eEn = MenuService::etagFor(3, null, 'cat123', 'en');
$eFr = MenuService::etagFor(3, null, 'cat123', 'fr');
chk('etagFor differs by locale', $eEn !== $eFr, "$eEn vs $eFr");
chk('etagFor stable for same locale', MenuService::etagFor(3, null, 'cat123', 'fr') === $eFr);
chk('etag contains locale term', str_contains($eFr, 'fr'), $eFr);

echo "unified translation merge layer is wired\n";
// Service binding exists and returns a TranslationRegistry (no DB needed: the
// service degrades to file catalogs when Database::connect() is unavailable).
$sharedServices = (string) file_get_contents($root . '/app/Modules/Shared/Config/Services.php');
chk('Shared Services declares translations()', str_contains($sharedServices, 'function translations('));
chk('translations() registers FileCatalogProvider', str_contains($sharedServices, 'new FileCatalogProvider'));
chk('translations() registers NotificationTemplateProvider', str_contains($sharedServices, 'new NotificationTemplateProvider'));
chk('translations() registers Geo JsonColumnProvider', str_contains($sharedServices, 'new JsonColumnProvider'));
chk('translations() uses version-stamped cache', str_contains($sharedServices, 'versionStamp('));

// Frontend bundle route + controller are present.
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
chk('i18n bundle route registered', str_contains($routes, "i18n/(:segment)") && str_contains($routes, 'TranslationController::bundle'));
chk('TranslationController exists', is_file($root . '/app/Modules/Shared/Controllers/TranslationController.php'));

// The registry + providers merge real shipped catalogs (English-backed).
require_once $root . '/app/Modules/Shared/I18n/Interpolator.php';
require_once $root . '/app/Modules/Shared/I18n/TranslationProvider.php';
require_once $root . '/app/Modules/Shared/I18n/TranslationRegistry.php';
require_once $root . '/app/Modules/Shared/I18n/Providers/FileCatalogProvider.php';

$fcp = new WBS\Shared\I18n\Providers\FileCatalogProvider([
    $root . '/app/Language',
    ...glob($root . '/app/Modules/*/Language', GLOB_ONLYDIR),
]);
$reg = new WBS\Shared\I18n\TranslationRegistry([$fcp], 'en');
chk('merged fr catalog reads a real string', $reg->translate('Identity.login.heading', 'fr') === 'Connexion');
chk('merged catalog English-backs a locale-missing key', $reg->translate('AdminConsole', 'zh') !== '');

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
