<?php
/**
 * MFA step-up challenge screen. Reached after the password step when the ADAPTIVE
 * risk policy requires a second factor. The exact requirement ($required) is
 * carried from the risk score at login; a code from an authenticator app OR a
 * one-time recovery code satisfies it. Posts to POST /mfa with a CSRF token; the
 * pending principal travels in the signed HttpOnly wbs_mfa cookie.
 *
 * @var string      $csrf
 * @var string      $return
 * @var string|null $error
 * @var string      $required  adaptive requirement: 'high' | 'low' | 'none'
 */
$return   = $return ?? '/me';
$error    = $error ?? null;
$required = $required ?? 'high';
include __DIR__ . '/_locale.php';
$sub      = $required === 'high'
    ? lang('Identity.mfa.subHigh')
    : lang('Identity.mfa.subLow');
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.mfa.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .card { width:100%; max-width:400px; background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:30px 28px; text-align:center; }


        .mark { font-size:2.6rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="auth-screen">

<form class="card" method="post" action="/mfa">
        <div class="mark">🔐</div>
        <h1><?= esc(lang('Identity.mfa.heading')) ?></h1>
        <div class="sub"><?= esc($sub) ?></div>

        <?php if ($error !== null): ?>
            <div class="err"><?= esc($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
        <input type="hidden" name="return" value="<?= esc($return) ?>">

        <input class="code" name="code" autocomplete="one-time-code"
               maxlength="14" required autofocus placeholder="••••••">

        <button class="btn" type="submit"><?= esc(lang('Identity.mfa.submit')) ?></button>

        <div class="hint" style="margin-top:14px;color:#64748b;font-size:.8rem;">
            <?= esc(lang('Identity.mfa.recovery')) ?>
        </div>

        <div class="alt"><a href="/login"><?= esc(lang('Identity.mfa.back')) ?></a></div>
    </form>

</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
