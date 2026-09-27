<?php
/**
 * Edit certificate template — POST creates the NEXT version.
 *
 * @var array<string,mixed> $template
 * @var string $csrf
 */
$t    = $template ?? [];
$tid  = (string) ($t['id'] ?? '');
$csrf = $csrf ?? '';
$session  = function_exists('session') ? session() : null;
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.certificate.editHeading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width:720px; margin:0 auto; padding:5vh 20px 60px; }


        button { margin-top:14px; border:0; border-radius:8px; padding:9px 18px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Events.certificate.editHeading')) ?></h1>
    <p class="hint"><?= esc(lang('Events.certificate.versionHint')) ?></p>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <p><a href="/certificates/templates"><?= esc(lang('Events.certificate.templatesHeading')) ?></a>
       · <a href="/certificates/templates/<?= esc($tid, 'attr') ?>/preview"><?= esc(lang('Events.certificate.preview')) ?></a></p>
    <form method="post" action="/certificates/templates/<?= esc($tid, 'attr') ?>">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <label for="c-name"><?= esc(lang('Events.certificate.colName')) ?></label>
        <input id="c-name" name="name" required maxlength="120" value="<?= esc((string) ($t['name'] ?? ''), 'attr') ?>">
        <label for="c-type"><?= esc(lang('Events.certificate.fEventType')) ?></label>
        <input id="c-type" name="event_type" maxlength="60" value="<?= esc((string) ($t['event_type'] ?? ''), 'attr') ?>">
        <label for="c-signer"><?= esc(lang('Events.certificate.fSignerRole')) ?></label>
        <input id="c-signer" name="signer_role" maxlength="80" value="<?= esc((string) ($t['signer_role'] ?? ''), 'attr') ?>">
        <label for="c-body"><?= esc(lang('Events.certificate.fBodyTemplate')) ?></label>
        <textarea id="c-body" name="body_template" required><?= esc((string) ($t['body_template'] ?? '')) ?></textarea>
        <button type="submit"><?= esc(lang('Events.certificate.saveNewVersion')) ?></button>
    </form>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
