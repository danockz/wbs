<?php

declare(strict_types=1);

$wbsShellCtx = (isset($wbsShellCtx) && is_array($wbsShellCtx)) ? $wbsShellCtx : [];
?>
<!DOCTYPE html>
<html lang="<?= wbs_shell_esc((string) ($wbsShellCtx['locale'] ?? 'en'), 'attr') ?>" dir="<?= wbs_shell_esc((string) ($wbsShellCtx['dir'] ?? 'ltr'), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php
        if (! empty($wbsShellCtx['titleHtml'])) {
            echo $wbsShellCtx['titleHtml'];
        } else {
            echo wbs_shell_esc((string) ($wbsShellCtx['title'] ?? 'Win–Build–Send')) . ' — Win–Build–Send';
        }
    ?></title>
    <?php include __DIR__ . '/../_tokens.php'; ?>
    <link rel="stylesheet" href="<?= wbs_shell_esc((string) ($wbsShellCtx['cssAsset'] ?? '/assets/css/app.css'), 'attr') ?>">
    <?php
        if (isset($wbsPageStyles) && is_callable($wbsPageStyles)) {
            $wbsPageStyles();
        }
        if (! empty($wbsShellCtx['headExtra'])) {
            echo $wbsShellCtx['headExtra'];
        }
    ?>
</head>
<body>
