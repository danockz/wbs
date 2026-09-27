<?php
/**
 * Public self-join confirmation. Self-contained, sandbox-safe.
 *
 * @var string $slug
 * @var bool   $pending  true = awaiting leader approval; false = active now
 */
$slug      = $slug ?? '';
$pending   = ! empty($pending);
$setup_url = $setup_url ?? null;
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc($pending ? lang('Groups.joinDone.titlePending') : lang('Groups.joinDone.titleWelcome')) ?> — <?= esc(lang('Groups.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .box { max-width:480px; }


        p { color:#94a3b8; line-height:1.6; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?= view('WBS\Groups\Views\_public_nav', ['viewer' => $viewer ?? null, 'csrf' => $csrf ?? null]) ?>
    <div class="box">
        <?php if ($pending): ?>
            <div class="mark">📨</div>
            <h1><?= esc(lang('Groups.joinDone.headingPending')) ?></h1>
            <p><?= esc(lang('Groups.joinDone.bodyPending')) ?></p>
        <?php else: ?>
            <div class="mark">🎉</div>
            <h1><?= esc(lang('Groups.joinDone.headingWelcome')) ?></h1>
            <p><?= esc(lang('Groups.joinDone.bodyWelcome')) ?></p>
        <?php endif; ?>

        <?php if ($setup_url !== null): ?>
            <p style="margin-top:20px;"><?= esc(lang('Groups.joinDone.setPasswordIntro')) ?></p>
            <a class="btn" href="<?= esc($setup_url) ?>"><?= esc(lang('Groups.joinDone.setPassword')) ?></a>
            <p style="font-size:.8rem;color:#64748b;margin-top:14px;">
                <?= esc(lang('Groups.joinDone.linkExpires')) ?>
            </p>
        <?php else: ?>
            <a class="btn" href="/g/<?= esc($slug) ?>"><?= esc(lang('Groups.joinDone.backToGroup')) ?></a>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
