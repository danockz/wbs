<?php
/**
 * BREAK-GLASS review queue (GET /access-control/break-glass/pending-reviews) — the
 * browser face of BreakGlassController::pendingReviews, which otherwise rendered
 * the generic admin console. Lists emergency-access sessions that are closed or
 * expired and still awaiting the mandatory post-use review, oldest first, showing
 * the emergency capability, who invoked it and for whom, the mandatory reason, the
 * MFA assurance at open time and the active window.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.breakGlassView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. The status
 * vocabulary is localized with a raw-value fallback. Ids/codes are server data
 * shown verbatim & escaped. This is a READ queue; the review action lives on its
 * own webcsrf-guarded POST route.
 *
 * @var list<array<string,mixed>> $sessions break-glass sessions awaiting review
 */
$sessions = $sessions ?? [];
$count    = count($sessions);
$csrf     = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vocab = static function (string $group, string $value): string {
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.breakGlassView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.breakGlassView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        .perm { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.86rem; font-weight:700; color:#fecaca; }


        .status { font-size:.72rem; border:1px solid #9a3412; color:#fdba74; border-radius:999px; padding:2px 10px; }


        .when { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }


        .reason { color:#cbd5e1; font-size:.9rem; margin:0; }


        .btn.view { color:#fdba74; border-color:#9a3412; margin-inline-start:auto; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('AccessControl.breakGlassView.heading')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.breakGlassView.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.breakGlassView.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.breakGlassView.countOne' : 'AccessControl.breakGlassView.count', (string) $count)) ?></p>
            <?php foreach ($sessions as $s): ?>
                <?php $sid = rawurlencode((string) ($s['id'] ?? '')); ?>
                <article class="bg">
                    <div class="top">
                        <span class="perm"><?= esc((string) ($s['permission_code'] ?? '')) ?></span>
                        <span class="status"><?= esc($vocab('status', strtolower((string) ($s['status'] ?? '')))) ?></span>
                        <span class="when"><?= esc(lang('AccessControl.breakGlassView.colWindow')) ?>: <?= esc((string) ($s['effective_from'] ?? '—')) ?> → <?= esc((string) ($s['effective_to'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag"><?= esc(lang('AccessControl.breakGlassView.colSubject')) ?>: <span class="mono"><?= esc((string) ($s['subject_id'] ?? '—')) ?></span></span>
                        <span class="tag"><?= esc(lang('AccessControl.breakGlassView.colOpenedBy')) ?>: <span class="mono"><?= esc((string) ($s['opened_by'] ?? '—')) ?></span></span>
                        <span class="tag mfa"><?= esc(lang('AccessControl.breakGlassView.colMfa')) ?>: <?= esc((string) ($s['mfa_level'] ?? '—')) ?></span>
                    </div>
                    <?php if (! empty($s['reason'])): ?>
                        <p class="reason"><span class="lbl"><?= esc(lang('AccessControl.breakGlassView.colReason')) ?></span><?= esc((string) $s['reason']) ?></p>
                    <?php endif; ?>
                    <?php if ($sid !== ''): ?>
                    <div class="acts">
                        <form method="post" action="<?= esc($url('break-glass/' . $sid . '/review'), 'attr') ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="outcome" value="justified">
                            <input type="text" name="notes" maxlength="1000" placeholder="<?= esc(lang('AccessControl.breakGlassView.notesPh'), 'attr') ?>">
                            <button type="submit" class="btn just"><?= esc(lang('AccessControl.breakGlassView.markJustified')) ?></button>
                        </form>
                        <form method="post" action="<?= esc($url('break-glass/' . $sid . '/review'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.breakGlassView.unjustifiedConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="outcome" value="unjustified">
                            <button type="submit" class="btn unjust"><?= esc(lang('AccessControl.breakGlassView.markUnjustified')) ?></button>
                        </form>
                        <a class="btn view" href="<?= esc($url('break-glass/' . $sid), 'attr') ?>"><?= esc(lang('AccessControl.breakGlassView.view')) ?></a>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
