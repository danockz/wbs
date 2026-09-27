<?php
/**
 * PUBLIC cloaked-link INVITATION + prospect-capture funnel (GET r/{code}).
 *
 * The browser face of ReferralController::land, which otherwise only spoke JSON.
 * Shown to an ANONYMOUS visitor who followed someone's referral link:
 *   - a branded welcome (campaign label only — the sponsor id is NEVER shown);
 *   - a "continue to destination" link to the typed redirect target; and
 *   - a CONSENT-GATED prospect-capture form that POSTs to /r/{code}/prospect.
 *
 * PRIVACY (hard constraint): prospect data is private and only stored after the
 * visitor ticks the consent box. This page collects NO precise GPS (the two-flag
 * GPS consent gate lives in the authenticated contact book, not this public
 * funnel). The person who invited the visitor is never revealed.
 *
 * SELF-CONTAINED page: renders its own <html> via _locale.php for a locale-aware
 * <html lang dir> (RTL for Arabic). All copy via lang('Referrals.invite.*') with
 * English fallback.
 *
 * CSP-SAFE / NO-JS: no <script>, no inline on* handlers. The consent gate is a
 * plain required checkbox enforced server-side (captureProspect rejects without
 * consent) — no JavaScript needed. The submit posts the double-submit `_csrf`
 * token minted by renderForm().
 *
 * @var string      $code      Opaque link code (for the form action).
 * @var string|null $campaign  Optional campaign label (safe to show).
 * @var string      $linkType  member|event|giving|course|streaming (destination copy).
 * @var string      $redirect  Typed redirect / destination URL.
 * @var string      $csrf      Double-submit CSRF token (from renderForm).
 */
$code     = $code ?? '';
$campaign = $campaign ?? null;
$linkType = $linkType ?? 'member';
$redirect = $redirect ?? '';
$csrf     = $csrf ?? '';
/** @var list<string> decisionInputs — optional decision types offered on this landing (referrer group config) */
$decisionInputs = $decisionInputs ?? [];

include __DIR__ . '/_locale.php';

$destKey = [
    'member'    => 'Referrals.invite.destMember',
    'event'     => 'Referrals.invite.destEvent',
    'giving'    => 'Referrals.invite.destGiving',
    'course'    => 'Referrals.invite.destCourse',
    'streaming' => 'Referrals.invite.destStreaming',
][$linkType] ?? 'Referrals.invite.destMember';

$success = function_exists('session') ? session('success') : null;
$error   = function_exists('session') ? session('error') : null;
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.invite.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<meta name="robots" content="noindex,nofollow">
<style>


        
        
        
        .wrap { max-width: 560px; margin: 0 auto; padding: 6vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:26px 24px; margin:0 0 18px; }


        h2 { font-size:1.15rem; margin:0 0 6px; }


        input[type=text], input[type=email] { width:100%; background:#0b1120; border:1px solid #334155;
            border-radius:10px; padding:11px 13px; color:#e2e8f0; font-size:.98rem; }


        input:focus { outline:2px solid #7c3aed; border-color:#7c3aed; }


        .consent { display:flex; gap:10px; align-items:flex-start; margin:16px 0 4px;
            background:#0b1120; border:1px solid #334155; border-radius:10px; padding:12px 14px; }


        .consent input { margin-top:3px; flex:0 0 auto; width:18px; height:18px; accent-color:#7c3aed; }


        .consent label { margin:0; font-weight:500; color:#e2e8f0; }


        .note { color:#64748b; font-size:.78rem; line-height:1.45; margin:8px 0 0; }


        button { width:100%; margin-top:16px; background:linear-gradient(135deg,#7c3aed,#9333ea); color:#fff;
            font-weight:700; border:0; border-radius:12px; padding:14px 18px; font-size:1.02rem; cursor:pointer; }


        button:hover { filter:brightness(1.08); }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if ($success !== null): ?>
            <div class="flash ok"><?= esc($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="flash err"><?= esc($error) ?></div>
        <?php endif; ?>

        <section class="card">
            <h1><?= esc(lang('Referrals.invite.heading')) ?></h1>
            <p class="sub"><?= esc(lang('Referrals.invite.sub')) ?></p>
            <?php if ($campaign !== null && $campaign !== ''): ?>
                <span class="campaign"><?= esc(lang('Referrals.invite.campaignPrefix')) ?>: <?= esc($campaign) ?></span>
            <?php endif; ?>

            <?php if ($redirect !== ''): ?>
                <a class="cta" href="<?= esc($redirect, 'attr') ?>"><?= esc(lang($destKey)) ?></a>
                <p class="ctanote"><?= esc(lang('Referrals.invite.continueNote')) ?></p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2><?= esc(lang('Referrals.invite.joinHeading')) ?></h2>
            <p class="sub"><?= esc(lang('Referrals.invite.joinSub')) ?></p>

            <form method="post" action="/r/<?= esc(rawurlencode($code), 'attr') ?>/prospect" novalidate>
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

                <label for="display_name"><?= esc(lang('Referrals.invite.nameLbl')) ?></label>
                <input type="text" id="display_name" name="display_name"
                       placeholder="<?= esc(lang('Referrals.invite.namePh'), 'attr') ?>" autocomplete="name">

                <label for="email"><?= esc(lang('Referrals.invite.emailLbl')) ?></label>
                <input type="email" id="email" name="email"
                       placeholder="<?= esc(lang('Referrals.invite.emailPh'), 'attr') ?>" autocomplete="email">

                <label for="phone"><?= esc(lang('Referrals.invite.phoneLbl')) ?></label>
                <input type="tel" id="phone" name="phone" maxlength="40"
                       placeholder="<?= esc(lang('Referrals.invite.phonePh'), 'attr') ?>" autocomplete="tel">

                <?php if ($decisionInputs !== []): ?>
                <div class="consent">
                    <div style="font-size:.85rem;font-weight:700;color:#e2e8f0;"><?= esc(lang('Referrals.invite.decisionSection')) ?></div>
                    <label for="decision_type" style="display:block;margin-top:8px;font-size:.85rem;"><?= esc(lang('Referrals.invite.decisionLbl')) ?></label>
                    <select id="decision_type" name="decision_type" style="width:100%;margin-top:4px;">
                        <option value=""><?= esc(lang('Referrals.invite.decisionNone')) ?></option>
                        <?php foreach ($decisionInputs as $dt): ?>
                            <option value="<?= esc($dt) ?>"><?= esc(ucwords(str_replace('_', ' ', $dt))) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="decision_date" style="display:block;margin-top:8px;font-size:.85rem;"><?= esc(lang('Referrals.invite.decisionDateLbl')) ?></label>
                    <input type="date" id="decision_date" name="decision_date" max="<?= esc(date('Y-m-d')) ?>" style="width:100%;margin-top:4px;">
                    <label for="decision_note" style="display:block;margin-top:8px;font-size:.85rem;"><?= esc(lang('Referrals.invite.decisionNoteLbl')) ?></label>
                    <input id="decision_note" name="decision_note" maxlength="255" style="width:100%;margin-top:4px;">
                    <p class="note" style="margin-top:8px;"><?= esc(lang('Referrals.invite.decisionHint')) ?></p>
                </div>
                <?php endif; ?>

                <div class="consent">
                    <input type="checkbox" id="consent" name="consent" value="1" required>
                    <label for="consent"><?= esc(lang('Referrals.invite.consentLbl')) ?></label>
                </div>
                <p class="note"><?= esc(lang('Referrals.invite.consentNote')) ?></p>

                <button type="submit"><?= esc(lang('Referrals.invite.submitBtn')) ?></button>
            </form>
        </section>

        <p class="privacy"><?= esc(lang('Referrals.invite.privacyNote')) ?></p>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
