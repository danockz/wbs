<?php

declare(strict_types=1);

if (! function_exists('wbs_shell_esc')) {
    function wbs_shell_esc($s, string $c = 'html'): string
    {
        return function_exists('esc')
            ? (string) esc((string) $s, $c)
            : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

$wbsShellCtx = (isset($wbsShellCtx) && is_array($wbsShellCtx)) ? $wbsShellCtx : [];
$wbsShellCtx = array_merge(
    is_array($GLOBALS['wbsShellCtx'] ?? null) ? $GLOBALS['wbsShellCtx'] : [],
    $wbsShellCtx
);

$wbsLocale = '';
if (function_exists('service')) {
    $wbsLocale = (string) (service('request')->getLocale() ?: '');
}
if ($wbsLocale === '') {
    $wbsLocale = (string) ($wbsShellCtx['locale'] ?? '');
}
if ($wbsLocale === '') {
    $wbsLocale = 'en';
}

$wbsRtl = ['ar', 'he', 'fa', 'ur'];
if (function_exists('config')) {
    try {
        $wbsRtl = config(\Config\Locale::class)->rtl ?? $wbsRtl;
    } catch (\Throwable) {
    }
}
$wbsDir = in_array($wbsLocale, $wbsRtl, true) ? 'rtl' : 'ltr';

$wbsVariant = (string) (
    $wbsShellVariant
    ?? $shellVariant
    ?? $wbsShellCtx['variant']
    ?? 'authenticated'
);
if ($wbsVariant === '') {
    $wbsVariant = 'authenticated';
}

$variantDefaults = [
    'public' => ['showMenu' => true,  'loadShellJs' => true],
    'authenticated' => ['showMenu' => true,  'loadShellJs' => true],
    'auth' => ['showMenu' => false, 'loadShellJs' => true],
    'admin' => ['showMenu' => true,  'loadShellJs' => true],
    'minimal' => ['showMenu' => false, 'loadShellJs' => false],
    'error' => ['showMenu' => false, 'loadShellJs' => true],
];
$defaults = $variantDefaults[$wbsVariant] ?? $variantDefaults['authenticated'];

$title = $title ?? ($wbsShellCtx['title'] ?? 'Win–Build–Send');
if (! is_string($title) || $title === '') {
    $title = 'Win–Build–Send';
}

$wbsCss = 'app.css';
$wbsJs  = 'app.js';
if (class_exists(\Config\Assets::class) && function_exists('config')) {
    try {
        $wbsAssets = config(\Config\Assets::class);
        if (is_object($wbsAssets)) {
            $wbsCss = (string) ($wbsAssets->css ?? $wbsCss);
            $wbsJs  = (string) ($wbsAssets->js ?? $wbsJs);
        }
    } catch (\Throwable) {
    }
}

$allowlistScripts = [
    'shell-app-js' => '/assets/js/' . $wbsJs,
];
$calendarJs = '/assets/js/capabilities/calendar.js';
$wbsRoot = defined('ROOTPATH') ? ROOTPATH : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR;
if (is_file($wbsRoot . 'public' . $calendarJs)) {
    $allowlistScripts['calendar'] = $calendarJs;
}

$requestedCaps = [];
if (isset($wbsPageCapabilities) && is_array($wbsPageCapabilities)) {
    $requestedCaps = $wbsPageCapabilities;
} elseif (isset($pageCapabilities) && is_array($pageCapabilities)) {
    $requestedCaps = $pageCapabilities;
} elseif (isset($wbsShellCtx['pageCapabilities']) && is_array($wbsShellCtx['pageCapabilities'])) {
    $requestedCaps = $wbsShellCtx['pageCapabilities'];
}

$loadShellJs = (bool) ($wbsShellLoadAppJs ?? $defaults['loadShellJs']);
$needsAppJs = isset($needsAppJs) ? (bool) $needsAppJs : false;
if ($loadShellJs || $needsAppJs) {
    $requestedCaps[] = 'shell-app-js';
}

$caps = [];
foreach ($requestedCaps as $cap) {
    $cap = strtolower(trim((string) $cap));
    if ($cap !== '' && preg_match('/^[a-z0-9._-]+$/', $cap) === 1 && isset($allowlistScripts[$cap])) {
        $caps[] = $cap;
    }
}
$caps = array_values(array_unique($caps));

$wbsScriptAssets = [];
foreach ($caps as $cap) {
    $wbsScriptAssets[] = $allowlistScripts[$cap];
}
$wbsScriptAssets = array_values(array_unique($wbsScriptAssets));

$wbsLangCfg = function_exists('config') ? config(\Config\Locale::class) : null;
$wbsLangList = (is_object($wbsLangCfg) && isset($wbsLangCfg->supported) && is_array($wbsLangCfg->supported))
    ? $wbsLangCfg->supported
    : ['en'];
$wbsLangNames = function_exists('lang') ? lang('App.languageNames') : [];

$wbsLangCsrf = null;
$wbsReturnPath = '/me';
if (function_exists('service')) {
    $wbsHttpReq = service('request');
    if ($wbsHttpReq !== null && isset($wbsHttpReq->wbsCsrf)) {
        $wbsLangCsrf = (string) $wbsHttpReq->wbsCsrf;
    }
    if ($wbsHttpReq !== null && method_exists($wbsHttpReq, 'getPath')) {
        $wbsReturnPath = (string) ($wbsHttpReq->getPath() ?: '/me');
    }
}

$wbsShellCtx = array_merge($wbsShellCtx, [
    'locale' => $wbsLocale,
    'dir' => $wbsDir,
    'variant' => $wbsVariant,
    'title' => $title,
    'titleHtml' => $wbsTitleHtml ?? ($wbsShellCtx['titleHtml'] ?? null),
    'headExtra' => $wbsHeadExtra ?? ($wbsShellCtx['headExtra'] ?? null),
    'footerExtra' => $wbsFooterExtra ?? ($wbsShellCtx['footerExtra'] ?? null),
    'showMenu' => isset($wbsShowMenu) ? (bool) $wbsShowMenu : (bool) $defaults['showMenu'],
    'cssAsset' => '/assets/css/' . $wbsCss,
    'scriptAssets' => $wbsScriptAssets,
    'pageCapabilities' => $caps,
    'langList' => $wbsLangList,
    'langNames' => is_array($wbsLangNames) ? $wbsLangNames : [],
    'langCsrf' => $wbsLangCsrf,
    'returnPath' => $wbsReturnPath,
    'navDashboard' => (function () {
        $v = function_exists('lang') ? lang('App.navDashboard') : 'My dashboard';
        return (is_string($v) && $v !== 'App.navDashboard') ? $v : 'My dashboard';
    })(),
    'navStatus' => (function () {
        $v = function_exists('lang') ? lang('App.navStatus') : 'Status';
        return (is_string($v) && $v !== 'App.navStatus') ? $v : 'Status';
    })(),
]);

$GLOBALS['wbsShellCtx'] = $wbsShellCtx;
