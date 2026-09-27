<?php
/** Invalid/expired invite link. Self-contained, sandbox-safe. */
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.invalid.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        p { color:#94a3b8; line-height:1.6; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="auth-screen">

<div class="box">
        <div class="mark">⏳</div>
        <h1><?= esc(lang('Identity.invalid.heading')) ?></h1>
        <p><?= esc(lang('Identity.invalid.bodyPre')) ?> <a href="/g"><?= esc(lang('Identity.invalid.bodyLink')) ?></a> <?= esc(lang('Identity.invalid.bodyPost')) ?></p>
    </div>

</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
