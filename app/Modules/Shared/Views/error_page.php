<?php

declare(strict_types=1);

/**
 * SHARED HTTP PROBLEM PAGE (WBS\Shared\Views\error_page).
 *
 * What a person sees when a request dies in a FILTER — an expired session (401),
 * a permission the PDP refused (403), a stale CSRF token (403) or a rate limit
 * (429) — instead of the `application/problem+json` envelope that API clients get
 * from the very same refusal. Rendered by `WBS\Shared\Http\ProblemResponder`,
 * which is also what decides that this caller wanted a page at all.
 *
 * SELF-CONTAINED: own <html lang dir> (RTL-correct for Arabic) via the sibling
 * _locale.php, inline tokens + styles, no external assets (CSP-safe), no JS.
 * Every string flows through lang('App.err.*') with an English fallback, so a
 * missing key degrades to readable copy rather than leaking an `App.err.x` token.
 *
 * The page never composes a URL: `$actionHref` / `$secondaryHref` arrive already
 * same-site-guarded from the responder, and the only dynamic values shown are the
 * status number, the machine title and an optional reference token.
 *
 * @var int         $status         HTTP status code
 * @var string      $title          machine title (UNAUTHENTICATED, ACCESS_DENIED, …)
 * @var string      $detail         stable message key (the JSON `detail`)
 * @var string      $heading        localized heading
 * @var string      $explanation    localized body copy
 * @var string|null $actionLabel    primary action label (null = no action)
 * @var string|null $actionHref     primary action target (same-site relative)
 * @var string|null $secondaryLabel secondary link label
 * @var string|null $secondaryHref  secondary link target
 * @var string|null $reference      muted reference token for support conversations
 * @var int|null    $retryAfter     seconds to wait (429 only)
 * @var string      $accent         token colour for this failure family
 */

$status      = (int) ($status ?? 500);
$title       = (string) ($title ?? 'ERROR');
$detail      = (string) ($detail ?? '');
$heading     = (string) ($heading ?? '');
$explanation = (string) ($explanation ?? '');
$actionLabel = isset($actionLabel) ? (string) $actionLabel : null;
$actionHref  = isset($actionHref) ? (string) $actionHref : null;
$secondaryLabel = isset($secondaryLabel) ? (string) $secondaryLabel : null;
$secondaryHref  = isset($secondaryHref) ? (string) $secondaryHref : null;
$reference   = isset($reference) ? (string) $reference : null;
$retryAfter  = isset($retryAfter) ? (int) $retryAfter : null;
$accent      = (string) ($accent ?? '#f06548');

require __DIR__ . '/_locale.php';

if ($heading === '') {
    $heading = $T('App.err.generic', 'This request could not be completed');
}
?>

<?php ob_start(); ?>
<?= esc($heading) ?> · <?= esc($T('App.brand', 'Win–Build–Send')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<meta name="robots" content="noindex, nofollow">
<style>

        
        
        .card { width:100%; max-width:34rem; background:var(--wbs-surface); border:1px solid var(--wbs-border);
            border-radius:var(--wbs-radius-lg); padding:34px 30px 28px; text-align:center;
            box-shadow:0 18px 40px -24px #000; }

        .badge { display:inline-flex; align-items:center; justify-content:center; width:74px; height:74px;
            border-radius:50%; border:1px solid <?= esc($accent, 'attr') ?>66; background:<?= esc($accent, 'attr') ?>14;
            color:<?= esc($accent, 'attr') ?>; font-size:1.7rem; font-weight:800; font-variant-numeric:tabular-nums;
            margin-bottom:16px; }

        .why { color:var(--wbs-muted); font-size:.93rem; line-height:1.65; margin:0 auto 24px; max-width:30rem; }

        .btn:focus-visible { outline:2px solid var(--wbs-text); outline-offset:2px; }

        .alt a { color:var(--wbs-info); text-decoration:none; }

        .alt a:hover { text-decoration:underline; }

        .ref { margin-top:22px; padding-top:16px; border-top:1px solid var(--wbs-border);
            display:flex; flex-wrap:wrap; gap:8px 14px; justify-content:center; align-items:baseline; }

        .ref .k { font-size:.62rem; text-transform:uppercase; letter-spacing:.07em; color:var(--wbs-faint); }

        .note { margin-top:14px; font-size:.76rem; color:var(--wbs-faint); line-height:1.6; }

        .card .brand { margin-top:20px; font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; color:var(--wbs-faint); }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/_shell_open.php'; ?>

<div class="auth-screen">

<main class="card">
    <div class="badge" aria-hidden="true"><?= $status ?></div>

    <h1><?= esc($heading) ?></h1>

    <?php if ($explanation !== ''): ?>
        <p class="why"><?= esc($explanation) ?></p>
    <?php endif; ?>

    <?php if ($actionLabel !== null && $actionLabel !== '' && $actionHref !== null && $actionHref !== ''): ?>
        <a class="btn" href="<?= esc($actionHref, 'attr') ?>"><?= esc($actionLabel) ?></a>
    <?php endif; ?>

    <?php if ($secondaryLabel !== null && $secondaryLabel !== '' && $secondaryHref !== null && $secondaryHref !== ''): ?>
        <div class="alt"><a href="<?= esc($secondaryHref, 'attr') ?>"><?= esc($secondaryLabel) ?></a></div>
    <?php endif; ?>

    <div class="ref">
        <span class="k"><?= esc($T('App.err.statusLabel', 'HTTP status')) ?></span>
        <code><?= $status ?> <?= esc($title) ?></code>
        <?php if ($retryAfter !== null && $retryAfter > 0): ?>
            <span class="k"><?= esc($T('App.err.retryLabel', 'Retry after')) ?></span>
            <code><?= $retryAfter ?>s</code>
        <?php endif; ?>
        <?php
        // One machine token a member can quote to support: the caller's reference
        // when it gave one, otherwise the problem's own message key.
        $refToken = $reference !== null && $reference !== '' ? $reference : $detail;
        ?>
        <?php if ($refToken !== ''): ?>
            <span class="k"><?= esc($T('App.err.reference', 'Reference')) ?></span>
            <code><?= esc($refToken) ?></code>
        <?php endif; ?>
    </div>

    <p class="note"><?= esc($T('App.err.helpNote', 'If this keeps happening, quote the reference above to your group leader or an administrator.')) ?></p>
    <div class="brand"><?= esc($T('App.brand', 'Win–Build–Send')) ?></div>
</main>

</div>

<?php include __DIR__ . '/_shell_close.php'; ?>
