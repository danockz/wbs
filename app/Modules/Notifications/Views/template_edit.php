<?php
/**
 * Edit notification template — POST inserts the next version.
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
<?= esc(lang('Notifications.templates.editHeading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width:720px; margin:0 auto; padding:5vh 20px 60px; }


        button { margin-top:14px; border:0; border-radius:8px; padding:9px 18px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Notifications.templates.editHeading')) ?></h1>
    <p class="hint"><?= esc(lang('Notifications.templates.versionHint')) ?></p>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <p><a href="/notifications/templates"><?= esc(lang('Notifications.templates.heading')) ?></a></p>
    <form method="post" action="/notifications/templates/<?= esc($tid, 'attr') ?>">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <label for="sub"><?= esc(lang('Notifications.templates.fSubject')) ?></label>
        <input id="sub" name="subject" maxlength="255" value="<?= esc((string) ($t['subject'] ?? ''), 'attr') ?>">
        <label for="body"><?= esc(lang('Notifications.templates.fBody')) ?></label>
        <textarea id="body" name="body" required><?= esc((string) ($t['body'] ?? '')) ?></textarea>
        <label for="ak"><?= esc(lang('Notifications.templates.fAudienceKind')) ?></label>
        <select id="ak" name="audience_kind">
            <?php $kind = (string) ($t['audience_kind'] ?? ''); ?>
            <option value=""<?= $kind === '' ? ' selected' : '' ?>><?= esc(lang('Notifications.templates.audienceBase')) ?></option>
            <option value="membership"<?= $kind === 'membership' ? ' selected' : '' ?>><?= esc(lang('Notifications.templates.kindMembership')) ?></option>
            <option value="platform"<?= $kind === 'platform' ? ' selected' : '' ?>><?= esc(lang('Notifications.templates.kindPlatform')) ?></option>
        </select>
        <label for="ar"><?= esc(lang('Notifications.templates.fAudienceRole')) ?></label>
        <input id="ar" name="audience_role" maxlength="40" value="<?= esc((string) ($t['audience_role'] ?? ''), 'attr') ?>">
        <button type="submit"><?= esc(lang('Notifications.templates.saveNewVersion')) ?></button>
    </form>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
