<?= $this->extend('layouts/app') ?>

<?php
/**
 * Cause detail (GET /causes/{id}) — the browser face of CauseController::show.
 * Shows a single cause's core fields with links to edit it or return to the
 * directory. Not-found panel when the id is unknown for the org.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Money is stored in MINOR
 * units and formatted by the shared _money.php include. Copy via
 * lang('Contributions.causes.*').
 *
 * @var array<string,mixed>|null $cause causes row or null
 * @var string $title
 */
include __DIR__ . '/_money.php';
$cause = is_array($cause ?? null) ? $cause : null;
$cf    = static fn (string $k): string => 'Contributions.causes.' . $k;
$title = $title ?? lang(($cf)('detailTitle'));

$statusLbl = static function (string $s) use ($cf): string {
    if ($s === '') {
        return '—';
    }
    $v = lang(($cf)('status.' . $s));

    return str_contains($v, 'Contributions.') ? ucfirst($s) : $v;
};
$visLbl = static function (string $s) use ($cf): string {
    if ($s === '') {
        return '—';
    }
    $v = lang(($cf)('visibility.' . $s));

    return str_contains($v, 'Contributions.') ? ucfirst($s) : $v;
};
?>

<?= $this->section('content') ?>
    <p><a href="/causes">&larr; <?= esc(lang(($cf)('form.backToList'))) ?></a></p>
    <h1><?= esc(lang(($cf)('detailTitle'))) ?></h1>

    <?php if ($cause === null): ?>
        <div class="empty"><?= esc(lang(($cf)('notFound'))) ?></div>
    <?php else: ?>
        <?php
        $cid      = (string) ($cause['id'] ?? '');
        $currency = (string) ($cause['currency'] ?? '');
        $target   = isset($cause['target_minor']) && $cause['target_minor'] !== null ? (int) $cause['target_minor'] : null;
        ?>
        <div class="sub"><?= esc((string) ($cause['name'] ?? '—')) ?>
            · <span class="pill"><?= esc($statusLbl((string) ($cause['status'] ?? ''))) ?></span>
        </div>

        <?php if (! empty($cause['purpose'])): ?>
            <div class="card"><?= esc((string) $cause['purpose']) ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang(($cf)('visibilityLabel'))) ?></div><div class="v"><?= esc($visLbl((string) ($cause['visibility'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($cf)('currencyLabel'))) ?></div><div class="v"><?= esc($currency !== '' ? $currency : '—') ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($cf)('targetLabel'))) ?></div><div class="v"><?= $target !== null ? esc($money($target, $currency)) : esc(lang(($cf)('noTarget'))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($cf)('targetCountLabel'))) ?></div><div class="v"><?= isset($cause['target_count']) && $cause['target_count'] !== null ? esc((string) (int) $cause['target_count']) : '—' ?></div></div>
        </div>

        <div class="meta">
            <?php if (! empty($cause['starts_at'])): ?><?= esc(lang(($cf)('startsAtLabel'))) ?>: <?= esc((string) $cause['starts_at']) ?><?php endif; ?>
            <?php if (! empty($cause['ends_at'])): ?> · <?= esc(lang(($cf)('endsAtLabel'))) ?>: <?= esc((string) $cause['ends_at']) ?><?php endif; ?>
        </div>

        <?php if ($cid !== ''): ?>
            <p style="margin-top:18px"><a href="/causes/<?= esc(rawurlencode($cid), 'attr') ?>/edit"><?= esc(lang(($cf)('form.edit'))) ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
