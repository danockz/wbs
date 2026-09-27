<?= $this->extend('layouts/app') ?>

<?php
/**
 * CAUSE DIRECTORY (GET /causes) — the browser face of CauseController::index,
 * which otherwise only spoke JSON. A paginated list of the organization's
 * fundraising causes with target, visibility and status, plus per-cause CRUD
 * controls (New / Edit / activate-close / Delete). Deletion is SAFE: a cause
 * with contributions is CLOSED, never hard-deleted (decided in CauseService).
 *
 * Extends layouts/app (locale-aware <html lang dir>). Money is stored in MINOR
 * units and formatted by the shared _money.php include. Copy via
 * lang('Contributions.causes.*') with English fallback.
 *
 * @var list<array<string,mixed>> $causes
 * @var int    $total
 * @var string $csrf
 * @var string $title
 */
include __DIR__ . '/_money.php';
$causes = is_array($causes ?? null) ? $causes : [];
$csrf   = $csrf ?? '';
$count  = count($causes);
$title  = $title ?? lang('Contributions.causes.title');

$cf = static fn (string $k): string => 'Contributions.causes.' . $k;

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$statusLbl = static function (string $s) use ($cf): string {
    if ($s === '') {
        return '—';
    }
    $v = lang(($cf)('status.' . $s));

    return str_contains($v, 'Contributions.') ? ucfirst($s) : $v;
};
$visLbl = static function (string $s) use ($cf): string {
    if ($s === '') {
        return '';
    }
    $v = lang(($cf)('visibility.' . $s));

    return str_contains($v, 'Contributions.') ? ucfirst($s) : $v;
};
?>

<?= $this->section('content') ?>
    

    <div class="cz-head">
        <div class="cz-txt">
            <h1><?= esc(lang(($cf)('title'))) ?></h1>
            <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang(($cf)('countOne')) : lang(($cf)('count')))) ?></div>
        </div>
        <a class="cz-btn" href="/causes/new">+ <?= esc(lang(($cf)('form.newCause'))) ?></a>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cz-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cz-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($causes === []): ?>
        <div class="empty"><?= esc(lang(($cf)('empty'))) ?></div>
        <p style="margin-top:14px"><a class="cz-btn" href="/causes/new">+ <?= esc(lang(($cf)('form.newCause'))) ?></a></p>
    <?php else: ?>
        <?php foreach ($causes as $c): ?>
            <?php
            $cid      = (string) ($c['id'] ?? '');
            $ce       = rawurlencode($cid);
            $status   = (string) ($c['status'] ?? '');
            $vis      = (string) ($c['visibility'] ?? '');
            $currency = (string) ($c['currency'] ?? '');
            $target   = isset($c['target_minor']) && $c['target_minor'] !== null ? (int) $c['target_minor'] : null;
            $chip     = in_array($status, ['active', 'draft', 'closed'], true) ? $status : '';
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc((string) ($c['name'] ?? '—')) ?></span>
                    <span class="cz-chip <?= esc($chip, 'attr') ?>"><?= esc($statusLbl($status)) ?></span>
                </div>
                <?php if (! empty($c['purpose'])): ?><div class="counts"><?= esc((string) $c['purpose']) ?></div><?php endif; ?>
                <div class="cz-target">
                    <?php if ($vis !== ''): ?><?= esc(lang(($cf)('visibilityLabel'))) ?>: <?= esc($visLbl($vis)) ?> · <?php endif; ?>
                    <?php if ($target !== null): ?><?= esc(lang(($cf)('targetLabel'))) ?>: <?= esc($money($target, $currency)) ?><?php else: ?><?= esc(lang(($cf)('noTarget'))) ?><?php endif; ?>
                </div>
                <?php if ($cid !== ''): ?>
                <div class="cz-acts">
                    <a class="cz-act edit" href="/causes/<?= esc($ce, 'attr') ?>/edit"><?= esc(lang(($cf)('form.edit'))) ?></a>
                    <a class="cz-act" href="/causes/<?= esc($ce, 'attr') ?>"><?= esc(lang(($cf)('form.view'))) ?></a>
                    <?php if ($status !== 'active'): ?>
                    <form method="post" action="/causes/<?= esc($ce, 'attr') ?>/status">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="status" value="active">
                        <button type="submit" class="cz-act"><?= esc(lang(($cf)('form.activate'))) ?></button>
                    </form>
                    <?php endif; ?>
                    <?php if ($status !== 'closed'): ?>
                    <form method="post" action="/causes/<?= esc($ce, 'attr') ?>/status">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="status" value="closed">
                        <button type="submit" class="cz-act"><?= esc(lang(($cf)('form.close'))) ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="/causes/<?= esc($ce, 'attr') ?>/delete"
                          onsubmit="return confirm('<?= esc(lang(($cf)('form.deleteConfirm')), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cz-act warn"><?= esc(lang(($cf)('form.delete'))) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
