<?php
/**
 * PUBLIC "link expired / not found" page for an unknown or inactive cloaked code
 * (GET r/{code} when the link doesn't resolve). The browser face of the
 * not-found branch of ReferralController::land.
 *
 * PRIVACY: reveals nothing about the sponsor or whether the code ever existed —
 * a friendly dead end that still invites the visitor to the public home page.
 *
 * SELF-CONTAINED page: renders its own <html> via _locale.php. CSP-safe / no-JS.
 *
 * @var string $code
 */
$code = $code ?? '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.inviteExpired.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<meta name="robots" content="noindex,nofollow">
<style>


        
        
        
        .wrap { max-width: 480px; margin:0 auto; padding: 6vh 20px; text-align:center; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:32px 26px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <section class="card">
            <h1><?= esc(lang('Referrals.inviteExpired.heading')) ?></h1>
            <p class="sub"><?= esc(lang('Referrals.inviteExpired.sub')) ?></p>
            <a class="cta" href="<?= esc(base_url(), 'attr') ?>"><?= esc(lang('Referrals.inviteExpired.homeBtn')) ?></a>
        </section>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
