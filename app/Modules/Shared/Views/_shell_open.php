<?php

declare(strict_types=1);

/**
 * Shared HTML shell — open (doctype through topbar).
 *
 * Locals are $wbs* so they never clobber a view's $req / $esc / $status.
 *
 * Optional caller vars:
 *   $title          string page title (brand suffix added unless $wbsTitleHtml set)
 *   $wbsTitleHtml   pre-escaped inner <title> HTML
 *   $wbsHeadExtra   extra markup for <head>
 *   $wbsPageStyles  callable that echoes extra <head> markup
 *   $csrf           used by the locale switcher
 */

if (! function_exists('wbs_shell_esc')) {
    function wbs_shell_esc($s, string $c = 'html'): string
    {
        return function_exists('esc')
            ? (string) esc((string) $s, $c)
            : htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

if (! isset($wbsLocale)) {
    $wbsLocale = 'en';
    if (function_exists('service')) {
        $wbsLocale = service('request')->getLocale() ?: 'en';
    }
}
if (! isset($wbsDir)) {
    $wbsRtl = ['ar', 'he', 'fa', 'ur'];
    if (function_exists('config')) {
        $wbsRtl = config(\Config\Locale::class)->rtl ?? $wbsRtl;
    }
    $wbsDir = in_array($wbsLocale, $wbsRtl, true) ? 'rtl' : 'ltr';
}

if (! isset($title) || ! is_string($title) || $title === '') {
    $title = 'Win–Build–Send';
}

$wbsCss = 'app.css';
$wbsJs  = 'app.js';
if (class_exists(\Config\Assets::class) && function_exists('config')) {
    try {
        $wbsAssets = config(\Config\Assets::class);
        if (is_object($wbsAssets)) {
            $wbsCss = $wbsAssets->css ?? $wbsCss;
            $wbsJs  = $wbsAssets->js ?? $wbsJs;
        }
    } catch (\Throwable) {
    }
}
$needsAppJs = $needsAppJs ?? false;
?>
<!DOCTYPE html>
<html lang="<?= wbs_shell_esc($wbsLocale, 'attr') ?>" dir="<?= wbs_shell_esc($wbsDir, 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        if (! empty($wbsTitleHtml)) {
            echo $wbsTitleHtml;
        } else {
            echo wbs_shell_esc($title) . ' — Win–Build–Send';
        }
    ?></title>
    <?php include __DIR__ . '/_tokens.php'; ?>
    <link rel="stylesheet" href="/assets/css/<?= wbs_shell_esc($wbsCss, 'attr') ?>">
    <?php
        if (isset($wbsPageStyles) && is_callable($wbsPageStyles)) {
            $wbsPageStyles();
        }
        if (! empty($wbsHeadExtra)) {
            echo $wbsHeadExtra;
        }
    ?>
</head>
<body>
    <div class="topbar">
        <div class="brand">Win<span>·</span>Build<span>·</span>Send</div>
        <?php
            $wbsNavDash = function_exists('lang') ? lang('App.navDashboard') : 'My dashboard';
            if (! is_string($wbsNavDash) || $wbsNavDash === 'App.navDashboard') {
                $wbsNavDash = 'My dashboard';
            }
            $wbsNavStatus = function_exists('lang') ? lang('App.navStatus') : 'Status';
            if (! is_string($wbsNavStatus) || $wbsNavStatus === 'App.navStatus') {
                $wbsNavStatus = 'Status';
            }
        ?>
        <div class="nav">
            <a href="/me/dashboard"><?= wbs_shell_esc($wbsNavDash) ?></a>
            <a href="/"><?= wbs_shell_esc($wbsNavStatus) ?></a>
        </div>
        <?php
            $wbsLangCfg = function_exists('config') ? config(\Config\Locale::class) : null;
            $wbsLangList = (is_object($wbsLangCfg) && isset($wbsLangCfg->supported) && is_array($wbsLangCfg->supported))
                ? $wbsLangCfg->supported
                : ['en'];
            $wbsLangNames = function_exists('lang') ? lang('App.languageNames') : [];
            $wbsLangCsrf = null;
            if (function_exists('service')) {
                $wbsHttpReq = service('request');
                $wbsLangCsrf = ($wbsHttpReq !== null && isset($wbsHttpReq->wbsCsrf)) ? (string) $wbsHttpReq->wbsCsrf : null;
            }
            $wbsReturnPath = '/me';
            if (function_exists('service')) {
                $wbsHttpReq = service('request');
                if ($wbsHttpReq !== null && method_exists($wbsHttpReq, 'getPath')) {
                    $wbsReturnPath = $wbsHttpReq->getPath() ?: '/me';
                }
            }
        ?>
        <details class="lang">
            <summary>
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
                <?= wbs_shell_esc(is_array($wbsLangNames) ? ($wbsLangNames[$wbsLocale] ?? strtoupper((string) $wbsLocale)) : strtoupper((string) $wbsLocale)) ?>
            </summary>
            <form class="lang__menu" method="post" action="/prefs/locale">
                <?php if ($wbsLangCsrf !== null && $wbsLangCsrf !== ''): ?>
                    <input type="hidden" name="_csrf" value="<?= wbs_shell_esc($wbsLangCsrf, 'attr') ?>">
                <?php endif; ?>
                <input type="hidden" name="return" value="<?= wbs_shell_esc('/' . ltrim((string) $wbsReturnPath, '/'), 'attr') ?>">
                <?php foreach ($wbsLangList as $wbsCode): ?>
                    <button type="submit" name="locale" value="<?= wbs_shell_esc($wbsCode, 'attr') ?>"
                        <?= $wbsCode === $wbsLocale ? 'aria-current="true"' : '' ?>>
                        <?= wbs_shell_esc(is_array($wbsLangNames) ? ($wbsLangNames[$wbsCode] ?? strtoupper((string) $wbsCode)) : strtoupper((string) $wbsCode)) ?>
                    </button>
                <?php endforeach; ?>
            </form>
        </details>
    </div>
