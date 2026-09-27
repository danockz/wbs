<?php
/**
 * Must-ack inbox. Recipients acknowledge each published announcement.
 *
 * @var list<array<string,mixed>> $announcements
 * @var string $csrf
 */
$rows = $announcements ?? [];
$csrf = $csrf ?? '';
$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Announcements.inboxMetaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width:720px; margin:0 auto; padding:5vh 20px 60px; }


        button { margin-top:10px; border:0; border-radius:8px; padding:8px 16px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Announcements.inboxHeading')) ?></h1>
    <p class="sub"><?= esc(lang('Announcements.inboxSub')) ?></p>
    <p><a href="/announcements"><?= esc(lang('Announcements.consoleLink')) ?></a></p>
    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <?php if ($rows === []): ?>
        <p class="empty"><?= esc(lang('Announcements.inboxEmpty')) ?></p>
    <?php endif; ?>
    <?php foreach ($rows as $a): ?>
        <?php $id = (string) ($a['id'] ?? ''); ?>
        <article>
            <h2><?= esc((string) ($a['title'] ?? '')) ?></h2>
            <div class="body"><?= esc((string) ($a['body'] ?? '')) ?></div>
            <form method="post" action="/announcements/<?= esc($id, 'attr') ?>/ack">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <button type="submit"><?= esc(lang('Announcements.ackBtn')) ?></button>
            </form>
        </article>
    <?php endforeach; ?>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
