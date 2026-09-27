<?php

declare(strict_types=1);

/**
 * Locale/direction resolver for the SHARED views that render their own <html>
 * instead of extending layouts/app.php (error_page.php). Sets $wbsLocale /
 * $wbsDir for a locale-aware, RTL-correct <html lang dir>.
 *
 * Guards function_exists('service'/'config') so these pages still render in CLI /
 * test harnesses where the framework isn't booted (falls back to en/ltr).
 * Mirrors app/Modules/Shared/Views/presenter/_locale.php.
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

/** lang() with an explicit fallback, so a missing key degrades instead of leaking. */
$T = static function (string $key, string $fallback = ''): string {
    $v = lang($key);

    return is_string($v) && $v !== '' && $v !== $key ? $v : $fallback;
};
