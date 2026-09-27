<?php

declare(strict_types=1);

/**
 * Locale/direction resolver for the SELF-CONTAINED Geo venues page
 * (venues) that renders its own <html> instead of extending
 * layouts/app.php. Include at the top of the view, then use $wbsLocale /
 * $wbsDir on the <html> tag so the page is locale-aware and RTL-correct too.
 *
 * Guards function_exists('service'/'config') so the view still renders in CLI /
 * test harnesses where the framework isn't booted (falls back to en / ltr).
 * Mirrors app/Modules/Referrals/Views/_locale.php.
 */

$wbsLocale = 'en';
if (function_exists('service')) {
    $wbsLocale = service('request')->getLocale() ?: 'en';
}
$wbsRtl = ['ar', 'he', 'fa', 'ur'];
if (function_exists('config')) {
    $wbsRtl = config(\Config\Locale::class)->rtl ?? $wbsRtl;
}
$wbsDir = in_array($wbsLocale, $wbsRtl, true) ? 'rtl' : 'ltr';

// Small helper: interpolate {0}, {1}, … into a lang() string. intl-independent.
$li = static function (string $key, string ...$args): string {
    $s = lang($key);
    foreach ($args as $i => $v) {
        $s = str_replace('{' . $i . '}', $v, $s);
    }

    return $s;
};
