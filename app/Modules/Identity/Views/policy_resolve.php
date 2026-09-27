<?php
/**
 * Effective identity policy (GET /identity/policies/{countryCode}/resolve) — the
 * browser face of IdentityPolicyController::resolve, which otherwise rendered the
 * generic admin console. Shows the RESOLVED policy for a country code after
 * fallbacks (exact country → org default `*` → built-in defaults): minimum age,
 * phone default region, email/phone uniqueness and minor allowance.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy via lang('Identity.policyResolve.*') with English
 * fallback. Values are server data shown verbatim & escaped.
 *
 * @var array<string,mixed> $effective resolved policy
 * @var string              $countryCode the requested country code
 */
$effective   = is_array($effective ?? null) ? $effective : [];
$countryCode = $countryCode ?? '';

include __DIR__ . '/_locale.php';

$yn = static fn (bool $b): string => $b ? lang('Identity.policyResolve.yes') : lang('Identity.policyResolve.no');
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.policyResolve.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        .cc { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#67e8f9;
            border:1px solid #0e7490; border-radius:6px; padding:2px 8px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        .note { color:#64748b; font-size:.82rem; margin-top:12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Identity.policyResolve.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.policyResolve.requestedLabel')) ?>: <span class="cc"><?= esc($countryCode !== '' ? $countryCode : '*') ?></span></p>

        <div class="card">
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.effectiveCountry')) ?></span><span class="v cc"><?= esc((string) ($effective['country_code'] ?? '*')) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.minAge')) ?></span><span class="v"><?= esc((string) (int) ($effective['min_age'] ?? 0)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.phoneRegion')) ?></span><span class="v"><?= esc((string) ($effective['phone_default_region'] ?? '—')) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.emailUnique')) ?></span><span class="v"><?= esc($yn((bool) ($effective['require_email_unique'] ?? false))) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.phoneUnique')) ?></span><span class="v"><?= esc($yn((bool) ($effective['require_phone_unique'] ?? false))) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.allowMinor')) ?></span><span class="v"><?= esc($yn((bool) ($effective['allow_minor'] ?? false))) ?></span></div>
            <?php if (! empty($effective['notes'])): ?>
                <div class="row"><span class="k"><?= esc(lang('Identity.policyResolve.notes')) ?></span><span class="v" style="font-weight:400"><?= esc((string) $effective['notes']) ?></span></div>
            <?php endif; ?>
        </div>
        <p class="note"><?= esc(lang('Identity.policyResolve.fallbackNote')) ?></p>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
