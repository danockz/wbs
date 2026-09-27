<?php
/**
 * Public "group not found" page (404). Self-contained, sandbox-safe.
 *
 * @var string $slug
 */
$slug = $slug ?? '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.notFound.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        p { color:#94a3b8; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="box">
        <h1>404</h1>
        <p><?= str_replace('{0}', '<strong>' . esc($slug) . '</strong>', esc(lang('Groups.notFound.body'))) ?></p>
        <p><a href="/g"><?= esc(lang('Groups.notFound.browseAll')) ?></a></p>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
