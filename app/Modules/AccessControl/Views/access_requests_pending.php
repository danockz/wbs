<?php
/**
 * ACCESS-REQUEST approval queue (GET /access-control/access-requests/pending) —
 * the browser face of AccessRequestController::pending, which otherwise rendered
 * the generic admin console. Lists the maker-checker access requests nominated to
 * the current approver, oldest first, showing what is requested, for whom, the
 * business justification, and any separation-of-duties conflict flag.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.accessReqView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. Fixed
 * vocabularies (grant type, conflict state) are localized with a raw-value
 * fallback. Subject/approver ids and codes are server data shown verbatim &
 * escaped. This is a READ queue; the approve/reject actions live on their own
 * webcsrf-guarded POST routes.
 *
 * @var list<array<string,mixed>> $requests pending access-request rows
 */
$requests = $requests ?? [];
$count    = count($requests);
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
    $s = lang('AccessControl.accessReqView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.accessReqView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        .grant { font-size:1.02rem; font-weight:700; }


        .when { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }


        .reason { color:#cbd5e1; font-size:.9rem; margin:0; }


        .acts input[type=text] { font:inherit; color:#e2e8f0; background:#0b1424; border:1px solid #334155;
            border-radius:7px; padding:6px 10px; font-size:.8rem; min-width:180px; }


        .btn.view { color:#fdba74; border-color:#9a3412; margin-inline-start:auto; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('AccessControl.accessReqView.heading')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.accessReqView.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.accessReqView.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.accessReqView.countOne' : 'AccessControl.accessReqView.count', (string) $count)) ?></p>
            <?php foreach ($requests as $r): ?>
                <?php
                $type    = strtolower((string) ($r['grant_type'] ?? ''));
                $grant   = $type === 'permission'
                    ? (string) ($r['permission_code'] ?? '')
                    : (string) ($r['role_id'] ?? '');
                $flagged = strtolower((string) ($r['conflict_state'] ?? 'none')) === 'flagged';
                $rid     = rawurlencode((string) ($r['id'] ?? ''));
                ?>
                <article class="req<?= $flagged ? ' flagged' : '' ?>">
                    <div class="top">
                        <span class="grant"><?= esc($vocab('grantType', $type)) ?></span>
                        <span class="code"><?= esc($grant !== '' ? $grant : '—') ?></span>
                        <span class="when"><?= esc(lang('AccessControl.accessReqView.colRequested')) ?>: <?= esc((string) ($r['created_at'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag"><?= esc(lang('AccessControl.accessReqView.colSubject')) ?>: <span class="mono"><?= esc((string) ($r['subject_id'] ?? '—')) ?></span></span>
                        <span class="tag"><?= esc(lang('AccessControl.accessReqView.colRequestedBy')) ?>: <span class="mono"><?= esc((string) ($r['requested_by'] ?? '—')) ?></span></span>
                        <?php if (! empty($r['duration_days'])): ?>
                            <span class="tag"><?= esc(lang('AccessControl.accessReqView.colDuration')) ?>: <?= esc((string) $r['duration_days']) ?></span>
                        <?php endif; ?>
                        <span class="tag <?= $flagged ? 'flag' : 'ok' ?>">
                            <?= esc(lang('AccessControl.accessReqView.colConflict')) ?>: <?= esc($vocab('conflict', strtolower((string) ($r['conflict_state'] ?? 'none')))) ?>
                        </span>
                    </div>
                    <?php if (! empty($r['reason'])): ?>
                        <p class="reason"><span class="lbl"><?= esc(lang('AccessControl.accessReqView.colReason')) ?></span><?= esc((string) $r['reason']) ?></p>
                    <?php endif; ?>
                    <?php if ($rid !== ''): ?>
                    <div class="acts">
                        <form method="post" action="<?= esc($url('access-requests/' . $rid . '/approve'), 'attr') ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="text" name="note" maxlength="500" placeholder="<?= esc(lang('AccessControl.accessReqView.notePh'), 'attr') ?>">
                            <button type="submit" class="btn approve"><?= esc(lang('AccessControl.accessReqView.approve')) ?></button>
                        </form>
                        <form method="post" action="<?= esc($url('access-requests/' . $rid . '/reject'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.accessReqView.rejectConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <button type="submit" class="btn reject"><?= esc(lang('AccessControl.accessReqView.reject')) ?></button>
                        </form>
                        <a class="btn view" href="<?= esc($url('access-requests/' . $rid), 'attr') ?>"><?= esc(lang('AccessControl.accessReqView.view')) ?></a>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
