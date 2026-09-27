<?php

declare(strict_types=1);

/**
 * Locale/direction resolver for the SHARED page presenter (presenter/page.php),
 * which renders its own <html> instead of extending layouts/app.php. Sets
 * $wbsLocale / $wbsDir for a locale-aware, RTL-correct <html lang dir>.
 *
 * Guards function_exists('service'/'config') so the presenter still renders in
 * CLI / test harnesses where the framework isn't booted (falls back to en/ltr).
 * Mirrors app/Modules/Reporting/Views/_locale.php.
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
