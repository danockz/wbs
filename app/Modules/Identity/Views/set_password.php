<?php
/**
 * Set-password / accept-invite form. Posts to POST /set-password with a CSRF
 * token and the single-use invite token. Self-contained, sandbox-safe.
 *
 * @var string      $csrf
 * @var string      $token    the single-use credential-setup token
 * @var string      $purpose  'invite' | 'password_reset'
 * @var string      $email
 * @var string      $name
 * @var string|null $error
 */
$purpose = $purpose ?? 'invite';
$email   = $email ?? '';
$name    = $name ?? '';
$error   = $error ?? null;
$isReset = $purpose === 'password_reset';
include __DIR__ . '/_locale.php';
$title   = $isReset ? lang('Identity.setpw.titleReset') : lang('Identity.setpw.titleInvite');
$intro   = $isReset
    ? lang('Identity.setpw.introReset')
    : ($name !== '' ? $li('Identity.setpw.introInviteNamed', $name) : lang('Identity.setpw.introInvite'));
?>

<?php ob_start(); ?>
<?= esc($title) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .card { width:100%; max-width:420px; background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:30px 28px; }


        input:focus { outline:none; border-color:#22d3ee; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="auth-screen">

<form class="card" method="post" action="/set-password">
        <h1><?= esc($title) ?></h1>
        <div class="sub"><?= esc($intro) ?></div>

        <?php if ($email !== ''): ?>
            <div class="email"><?= esc($email) ?></div>
        <?php endif; ?>

        <?php if ($error !== null): ?>
            <div class="err"><?= esc($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
        <input type="hidden" name="token" value="<?= esc($token) ?>">

        <label for="password"><?= esc(lang('Identity.setpw.newPassword')) ?></label>
        <input id="password" name="password" type="password" required autocomplete="new-password" minlength="12" placeholder="<?= esc(lang('Identity.setpw.newPasswordPh'), 'attr') ?>">
        <div class="hint"><?= esc(lang('Identity.setpw.hint')) ?></div>

        <label for="password_confirm"><?= esc(lang('Identity.setpw.confirm')) ?></label>
        <input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password" placeholder="<?= esc(lang('Identity.setpw.confirmPh'), 'attr') ?>">

        <button class="btn" type="submit"><?= esc($isReset ? lang('Identity.setpw.submitReset') : lang('Identity.setpw.submitInvite')) ?></button>
    </form>

</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
