<?php

declare(strict_types=1);

$wbsShellCtx = (isset($wbsShellCtx) && is_array($wbsShellCtx)) ? $wbsShellCtx : [];
$wbsLocale = (string) ($wbsShellCtx['locale'] ?? 'en');
$wbsLangNames = (isset($wbsShellCtx['langNames']) && is_array($wbsShellCtx['langNames'])) ? $wbsShellCtx['langNames'] : [];
$wbsLabelLocale = is_array($wbsLangNames) ? ($wbsLangNames[$wbsLocale] ?? strtoupper($wbsLocale)) : strtoupper($wbsLocale);
?>
<div class="topbar">
    <button type="button" class="wbs-shell-menu-toggle" data-shell-menu-toggle aria-controls="wbs-menu" aria-expanded="false" hidden>
        <span class="sr-only"><?= wbs_shell_esc(function_exists('lang') ? (string) lang('App.menuOpen') : 'Open menu') ?></span>
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <div class="brand">Win<span>·</span>Build<span>·</span>Send</div>
    <div class="nav">
        <a href="/me/dashboard"><?= wbs_shell_esc((string) ($wbsShellCtx['navDashboard'] ?? 'My dashboard')) ?></a>
        <a href="/"><?= wbs_shell_esc((string) ($wbsShellCtx['navStatus'] ?? 'Status')) ?></a>
    </div>
    <details class="lang">
        <summary>
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 2.5 15.3 0 18M12 3c-2.5 2.7-2.5 15.3 0 18"/></svg>
            <?= wbs_shell_esc((string) $wbsLabelLocale) ?>
        </summary>
        <form class="lang__menu" method="post" action="/prefs/locale">
            <?php if (! empty($wbsShellCtx['langCsrf'])): ?>
                <input type="hidden" name="_csrf" value="<?= wbs_shell_esc((string) $wbsShellCtx['langCsrf'], 'attr') ?>">
            <?php endif; ?>
            <input type="hidden" name="return" value="<?= wbs_shell_esc('/' . ltrim((string) ($wbsShellCtx['returnPath'] ?? '/me'), '/'), 'attr') ?>">
            <?php foreach (($wbsShellCtx['langList'] ?? ['en']) as $wbsCode): ?>
                <?php $wbsLangLabel = is_array($wbsLangNames) ? ($wbsLangNames[$wbsCode] ?? strtoupper((string) $wbsCode)) : strtoupper((string) $wbsCode); ?>
                <button type="submit" name="locale" value="<?= wbs_shell_esc((string) $wbsCode, 'attr') ?>"
                    <?= (string) $wbsCode === $wbsLocale ? 'aria-current="true"' : '' ?>>
                    <?= wbs_shell_esc((string) $wbsLangLabel) ?>
                </button>
            <?php endforeach; ?>
        </form>
    </details>
</div>
