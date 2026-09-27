<?php
/**
 * Public sign-in page (browser web-session flow). Posts a normal HTML form to
 * POST /login with a double-submit CSRF token. Self-contained, sandbox-safe.
 *
 * @var string      $csrf    signed CSRF token (also set as HttpOnly cookie)
 * @var string      $return  same-site relative path to return to after login
 * @var string|null $error   error message to display, if any
 */
$return = $return ?? '/me';
$error  = $error ?? null;
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.login.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .card { width:100%; max-width:400px; background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:30px 28px; }


        input:focus { outline:none; border-color:#22d3ee; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="auth-screen">

<form class="card" method="post" action="/login">
        <h1><?= esc(lang('Identity.login.heading')) ?></h1>
        <div class="sub"><?= esc(lang('Identity.login.welcome')) ?></div>

        <?php if ($error !== null): ?>
            <div class="err"><?= esc($error) ?></div>
        <?php endif; ?>

        <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
        <input type="hidden" name="return" value="<?= esc($return) ?>">

        <label for="email"><?= esc(lang('Identity.login.email')) ?></label>
        <input id="email" name="email" type="email" required autocomplete="username" placeholder="<?= esc(lang('Identity.login.emailPh'), 'attr') ?>">

        <label for="password"><?= esc(lang('Identity.login.password')) ?></label>
        <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••">

        <button class="btn" type="submit"><?= esc(lang('Identity.login.submit')) ?></button>

        <div class="alt"><?= esc(lang('Identity.login.notMember')) ?> <a href="/g"><?= esc(lang('Identity.login.findGroup')) ?></a></div>
    </form>

</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
