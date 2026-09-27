<?php
/**
 * ACCESS-REQUEST detail (GET /access-control/access-requests/{id}) — the browser
 * face of AccessRequestController::show, which otherwise rendered the generic
 * admin console. Shows one maker-checker request in full: the grant (role or
 * permission) and its code, the subject and requester, scope, requested duration,
 * status, separation-of-duties conflict, justification, decision metadata, and the
 * chronological review trail. When not found ($request is null) the same page
 * renders a localized not-found panel.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.accessReqShowView.*') with English fallback; fixed
 * vocabularies (grant type, status, conflict, review action) are localized with a
 * raw-value fallback (lowercased). Ids/codes are server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null $request access-request row (+ reviews[]), or null
 */
$request = $request ?? null;
$found   = is_array($request);
$reviews = $found ? (array) ($request['reviews'] ?? []) : [];
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
    $s = lang('AccessControl.accessReqShowView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.accessReqShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        .badge.pending { color:#fdba74; border-color:#9a3412; }

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }

        h2 { font-size:1rem; color:#fdba74; margin:0 0 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <p><a class="back" href="<?= esc($url('access-requests/pending'), 'attr') ?>">&larr; <?= esc(lang('AccessControl.accessReqShowView.backToQueue')) ?></a></p>
        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
        <?php if (! $found): ?>
            <h1><?= esc(lang('AccessControl.accessReqShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.accessReqShowView.notFound')) ?></p>
        <?php else: ?>
            <?php
            $type     = strtolower((string) ($request['grant_type'] ?? ''));
            $grant    = $type === 'permission' ? (string) ($request['permission_code'] ?? '') : (string) ($request['role_id'] ?? '');
            $status   = strtolower((string) ($request['status'] ?? ''));
            $conflict = strtolower((string) ($request['conflict_state'] ?? 'none'));
            $group    = (string) ($request['scope_group_id'] ?? '');
            $incDesc  = ! empty($request['include_descendants']);
            ?>
            <h1><?= esc($vocab('grantType', $type)) ?> <span class="code"><?= esc($grant !== '' ? $grant : '—') ?></span></h1>
            <div class="badges">
                <span class="badge <?= $status ?>"><?= esc($vocab('status', $status)) ?></span>
                <span class="badge <?= $conflict ?>"><?= esc(lang('AccessControl.accessReqShowView.colConflict')) ?>: <?= esc($vocab('conflict', $conflict)) ?></span>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.accessReqShowView.requestHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colSubject')) ?></span><span class="v mono"><?= esc((string) ($request['subject_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colRequestedBy')) ?></span><span class="v mono"><?= esc((string) ($request['requested_by'] ?? '—')) ?></span></div>
                <div class="row">
                    <span class="k"><?= esc(lang('AccessControl.accessReqShowView.colScope')) ?></span>
                    <span class="v">
                        <?php if ($group === ''): ?>
                            <?= esc(lang('AccessControl.accessReqShowView.orgWide')) ?>
                        <?php else: ?>
                            <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.accessReqShowView.withDescendants')) : '' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colDuration')) ?></span><span class="v"><?= esc((string) ($request['duration_days'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colApprover')) ?></span><span class="v mono"><?= esc((string) ($request['approver_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colReason')) ?></span><span class="v"><?= esc((string) ($request['reason'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colRequested')) ?></span><span class="v"><?= esc((string) ($request['created_at'] ?? '—')) ?></span></div>
            </div>

            <?php
            $rid       = rawurlencode((string) ($request['id'] ?? ''));
            $isPending = $status === 'pending';
            $isActive  = $status === 'approved';
            if ($rid !== '' && ($isPending || $isActive)):
            ?>
            <div class="card">
                <h2><?= esc(lang('AccessControl.accessReqShowView.actionsHeading')) ?></h2>
                <div class="actwrap">
                    <?php if ($isPending): ?>
                    <form method="post" action="<?= esc($url('access-requests/' . $rid . '/approve'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="text" name="note" placeholder="<?= esc(lang('AccessControl.accessReqShowView.notePh'), 'attr') ?>">
                        <button type="submit" class="btn approve"><?= esc(lang('AccessControl.accessReqShowView.approve')) ?></button>
                    </form>
                    <form method="post" action="<?= esc($url('access-requests/' . $rid . '/reject'), 'attr') ?>"
                          onsubmit="return confirm('<?= esc(lang('AccessControl.accessReqShowView.rejectConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="text" name="note" placeholder="<?= esc(lang('AccessControl.accessReqShowView.notePh'), 'attr') ?>">
                        <button type="submit" class="btn reject"><?= esc(lang('AccessControl.accessReqShowView.reject')) ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($isActive): ?>
                    <form method="post" action="<?= esc($url('access-requests/' . $rid . '/renew'), 'attr') ?>">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="number" name="extra_days" min="1" value="30">
                        <button type="submit" class="btn renew"><?= esc(lang('AccessControl.accessReqShowView.renew')) ?></button>
                    </form>
                    <form method="post" action="<?= esc($url('access-requests/' . $rid . '/revoke'), 'attr') ?>"
                          onsubmit="return confirm('<?= esc(lang('AccessControl.accessReqShowView.revokeConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="text" name="reason" required placeholder="<?= esc(lang('AccessControl.accessReqShowView.reasonPh'), 'attr') ?>">
                        <button type="submit" class="btn revoke"><?= esc(lang('AccessControl.accessReqShowView.revoke')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (! empty($request['decided_at']) || ! empty($request['decided_by'])): ?>
            <div class="card">
                <h2><?= esc(lang('AccessControl.accessReqShowView.decisionHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colDecidedBy')) ?></span><span class="v mono"><?= esc((string) ($request['decided_by'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.accessReqShowView.colDecidedAt')) ?></span><span class="v"><?= esc((string) ($request['decided_at'] ?? '—')) ?></span></div>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><?= esc(lang('AccessControl.accessReqShowView.reviewsHeading')) ?></h2>
                <?php if ($reviews === []): ?>
                    <p class="empty"><?= esc(lang('AccessControl.accessReqShowView.reviewsEmpty')) ?></p>
                <?php else: ?>
                    <?php foreach ($reviews as $rv): ?>
                        <div class="review">
                            <div><span class="act"><?= esc($vocab('reviewAction', strtolower((string) ($rv['action'] ?? '')))) ?></span>
                                <span class="who">— <?= esc((string) ($rv['actor_id'] ?? '—')) ?> · <?= esc((string) ($rv['created_at'] ?? '—')) ?></span></div>
                            <?php if (! empty($rv['note'])): ?><div class="note"><?= esc((string) $rv['note']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
