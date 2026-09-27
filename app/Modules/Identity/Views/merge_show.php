<?php
/**
 * Account merge detail (GET /identity/merges/{id}) — the browser face of
 * AccountController::showMerge, which otherwise rendered the generic admin
 * console. Shows one merge request: the primary and duplicate accounts, status,
 * requester, decision (who/when), justification, any detected conflict detail,
 * and the review trail. Not-found panel when the id is unknown.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy via lang('Identity.mergeShow.*') with English fallback;
 * the status vocabulary is localized with a raw-value fallback. Ids/reasons are
 * server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null $merge merge request (+reviews[]) or null if not found
 */
$merge = $merge ?? null;
$found = is_array($merge);
$reviews = $found && is_array($merge['reviews'] ?? null) ? $merge['reviews'] : [];
$csrf  = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Identity.mergeShow.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Identity.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.mergeShow.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 760px; margin: 0 auto; padding: 5vh 20px 60px; }

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }

        .primary { color:#86efac; }

        h2 { font-size:.78rem; text-transform:uppercase; letter-spacing:.08em; color:#818cf8; margin:26px 0 12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <p><a class="back" href="<?= esc($url('identity/merges/pending'), 'attr') ?>">&larr; <?= esc(lang('Identity.mergeShow.backToQueue')) ?></a></p>
        <h1><?= esc(lang('Identity.mergeShow.heading')) ?></h1>
        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>
        <?php if (! $found): ?>
            <p class="notfound"><?= esc(lang('Identity.mergeShow.notFound')) ?></p>
        <?php else: ?>
            <div class="card">
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colPrimary')) ?></span><span class="v primary mono"><?= esc((string) ($merge['primary_user_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colDuplicate')) ?></span><span class="v dup mono"><?= esc((string) ($merge['duplicate_user_id'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colStatus')) ?></span><span class="v"><span class="st"><?= esc($vstatus((string) ($merge['status'] ?? ''))) ?></span></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colReason')) ?></span><span class="v"><?= esc((string) ($merge['reason'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colRequestedBy')) ?></span><span class="v mono"><?= esc((string) ($merge['requested_by'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colDecidedBy')) ?></span><span class="v mono"><?= esc((string) ($merge['decided_by'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colDecidedAt')) ?></span><span class="v"><?= esc((string) ($merge['decided_at'] ?? '—')) ?></span></div>
                <?php if (! empty($merge['conflict_detail'])): ?>
                    <div class="row"><span class="k"><?= esc(lang('Identity.mergeShow.colConflict')) ?></span><span class="v"><?= esc(is_string($merge['conflict_detail']) ? $merge['conflict_detail'] : (string) json_encode($merge['conflict_detail'], JSON_UNESCAPED_UNICODE)) ?></span></div>
                <?php endif; ?>
            </div>

            <h2><?= esc(lang('Identity.mergeShow.reviews')) ?></h2>
            <?php if ($reviews === []): ?>
                <p class="v" style="color:#64748b"><?= esc(lang('Identity.mergeShow.noReviews')) ?></p>
            <?php else: ?>
                <?php foreach ($reviews as $r): ?>
                    <div class="rev">
                        <span class="st"><?= esc((string) ($r['action'] ?? '—')) ?></span>
                        <span class="mono"><?= esc((string) ($r['actor_id'] ?? '')) ?></span>
                        · <?= esc((string) ($r['created_at'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php
            $mid = rawurlencode((string) ($merge['id'] ?? ''));
            $status = strtolower((string) ($merge['status'] ?? ''));
            if ($mid !== '' && $status === 'pending'):
            ?>
            <h2><?= esc(lang('Identity.mergeShow.actionsHeading')) ?></h2>
            <div class="acts">
                <form method="post" action="<?= esc($url('identity/merges/' . $mid . '/approve'), 'attr') ?>"
                      onsubmit="return confirm('<?= esc(lang('Identity.mergeShow.approveConfirm'), 'js') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="fld">
                        <label for="an"><?= esc(lang('Identity.mergeShow.noteLabel')) ?></label>
                        <input id="an" name="note">
                    </div>
                    <button type="submit" class="btn approve"><?= esc(lang('Identity.mergeShow.approve')) ?></button>
                </form>
                <form method="post" action="<?= esc($url('identity/merges/' . $mid . '/reject'), 'attr') ?>"
                      onsubmit="return confirm('<?= esc(lang('Identity.mergeShow.rejectConfirm'), 'js') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="fld">
                        <label for="rn"><?= esc(lang('Identity.mergeShow.noteLabel')) ?></label>
                        <input id="rn" name="note">
                    </div>
                    <button type="submit" class="btn reject"><?= esc(lang('Identity.mergeShow.reject')) ?></button>
                </form>
                <form method="post" action="<?= esc($url('identity/merges/' . $mid . '/cancel'), 'attr') ?>"
                      onsubmit="return confirm('<?= esc(lang('Identity.mergeShow.cancelConfirm'), 'js') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <button type="submit" class="btn cancel"><?= esc(lang('Identity.mergeShow.cancel')) ?></button>
                </form>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
