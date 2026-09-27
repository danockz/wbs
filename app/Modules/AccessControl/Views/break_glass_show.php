<?php
/**
 * BREAK-GLASS session detail (GET /access-control/break-glass/{id}) — the browser
 * face of BreakGlassController::show, which otherwise rendered the generic admin
 * console. Shows one emergency-access session in full: the emergency capability,
 * subject, who opened it, scope, MFA assurance at open time, status, review state,
 * the active window and closure time, and the mandatory reason. When not found
 * ($session is null) the same page renders a localized not-found panel.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.breakGlassShowView.*') with English fallback; fixed
 * vocabularies (status, review state) are localized with a raw-value fallback
 * (lowercased). Ids/codes are server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null $session break-glass session row, or null
 */
$session = $session ?? null;
$found   = is_array($session);
$csrf    = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.breakGlassShowView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.breakGlassShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 880px; margin: 0 auto; padding: 5vh 20px 60px; }


        .badge.pending { color:#fca5a5; border-color:#7f1d1d; }


        .card { background:#0f172aee; border:1px solid #7f1d1d; border-radius:12px; padding:18px 20px; margin-top:18px; }


        h2 { font-size:1rem; color:#fca5a5; margin:0 0 8px; }


        .actwrap form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;
            background:#0b1120; border:1px solid #2a1215; border-radius:10px; padding:10px 12px; }


        .actwrap input[type=text] { font:inherit; color:#e2e8f0; background:#0b1120; border:1px solid #334155;
            border-radius:7px; padding:7px 10px; font-size:.82rem; min-width:190px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <p><a class="back" href="<?= esc($url('break-glass/pending-reviews'), 'attr') ?>">&larr; <?= esc(lang('AccessControl.breakGlassShowView.backToQueue')) ?></a></p>
        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
        <?php if (! $found): ?>
            <h1 style="font-family:inherit;color:inherit"><?= esc(lang('AccessControl.breakGlassShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.breakGlassShowView.notFound')) ?></p>
        <?php else: ?>
            <?php
            $status  = strtolower((string) ($session['status'] ?? ''));
            $review  = strtolower((string) ($session['review_state'] ?? ''));
            $group   = (string) ($session['scope_group_id'] ?? '');
            $incDesc = ! empty($session['include_descendants']);
            ?>
            <h1><?= esc((string) ($session['permission_code'] ?? '')) ?></h1>
            <div class="badges">
                <span class="badge <?= $status ?>"><?= esc($vocab('status', $status)) ?></span>
                <span class="badge <?= $review ?>"><?= esc(lang('AccessControl.breakGlassShowView.colReviewState')) ?>: <?= esc($vocab('review', $review)) ?></span>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.breakGlassShowView.sessionHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colSubject')) ?></span><span class="v mono"><?= esc((string) ($session['subject_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colOpenedBy')) ?></span><span class="v mono"><?= esc((string) ($session['opened_by'] ?? '—')) ?></span></div>
                <div class="row">
                    <span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colScope')) ?></span>
                    <span class="v">
                        <?php if ($group === ''): ?>
                            <?= esc(lang('AccessControl.breakGlassShowView.orgWide')) ?>
                        <?php else: ?>
                            <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.breakGlassShowView.withDescendants')) : '' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colMfa')) ?></span><span class="v"><?= esc((string) ($session['mfa_level'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colReason')) ?></span><span class="v"><?= esc((string) ($session['reason'] ?? '—')) ?></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.breakGlassShowView.windowHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colFrom')) ?></span><span class="v"><?= esc((string) ($session['effective_from'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colTo')) ?></span><span class="v"><?= esc((string) ($session['effective_to'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.breakGlassShowView.colClosedAt')) ?></span><span class="v"><?= esc((string) ($session['closed_at'] ?? '—')) ?></span></div>
            </div>

            <?php
            $sid       = rawurlencode((string) ($session['id'] ?? ''));
            $isActive  = $status === 'active';
            $needsRev  = in_array($status, ['closed', 'expired'], true) && $review !== 'reviewed';
            if ($sid !== '' && ($isActive || $needsRev)):
            ?>
            <div class="card">
                <h2><?= esc(lang('AccessControl.breakGlassShowView.actionsHeading')) ?></h2>
                <div class="actwrap">
                    <?php if ($isActive): ?>
                    <form method="post" action="<?= esc($url('break-glass/' . $sid . '/close'), 'attr') ?>"
                          onsubmit="return confirm('<?= esc(lang('AccessControl.breakGlassShowView.closeConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="btn close"><?= esc(lang('AccessControl.breakGlassShowView.close')) ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($needsRev): ?>
                    <form method="post" action="<?= esc($url('break-glass/' . $sid . '/review'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="outcome" value="justified">
                        <input type="text" name="notes" maxlength="1000" placeholder="<?= esc(lang('AccessControl.breakGlassShowView.notesPh'), 'attr') ?>">
                        <button type="submit" class="btn just"><?= esc(lang('AccessControl.breakGlassShowView.markJustified')) ?></button>
                    </form>
                    <form method="post" action="<?= esc($url('break-glass/' . $sid . '/review'), 'attr') ?>"
                          onsubmit="return confirm('<?= esc(lang('AccessControl.breakGlassShowView.unjustifiedConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="outcome" value="unjustified">
                        <input type="text" name="notes" maxlength="1000" placeholder="<?= esc(lang('AccessControl.breakGlassShowView.notesPh'), 'attr') ?>">
                        <button type="submit" class="btn unjust"><?= esc(lang('AccessControl.breakGlassShowView.markUnjustified')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
