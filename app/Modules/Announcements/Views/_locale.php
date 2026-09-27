<?php

declare(strict_types=1);

$wbsLocale = 'en';
if (function_exists('service')) {
    $wbsLocale = service('request')->getLocale() ?: 'en';
}
$wbsRtl = ['ar', 'he', 'fa', 'ur'];
if (function_exists('config')) {
    $wbsRtl = config(\Config\Locale::class)->rtl ?? $wbsRtl;
}
$wbsDir = in_array($wbsLocale, $wbsRtl, true) ? 'rtl' : 'ltr';

$li = static function (string $key, string ...$args): string {
    $s = lang($key);
    foreach ($args as $i => $v) {
        $s = str_replace('{' . $i . '}', $v, $s);
    }

    return $s;
};
