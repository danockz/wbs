<?= $this->extend('layouts/app') ?>

<?php
/**
 * A subject's giving metrics (SRS FR-VBCS-*): personal giving volume (PGV),
 * group giving volume (GGV, downline × multiplier) and downline reach.
 * Server-rendered; the same controller returns JSON when negotiated. Money is
 * stored in minor units.
 *
 * @var array<string,mixed> $result  {subject_id, pgv_minor, ggv_minor, ggv_raw_minor, direct_recruits, downline_size, multiplier_bps, computed_at}
 * @var string              $title
 */
include __DIR__ . '/_money.php';
$r    = $result ?? [];
$mult = (int) ($r['multiplier_bps'] ?? 10000) / 10000;
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.givingMetrics')) ?></h1>
    <div class="sub"><?= esc($r['subject_id'] ?? '') ?>
        <?php if (! empty($r['computed_at'])): ?> · <?= esc(str_replace('{0}', (string) $r['computed_at'], lang('Contributions.asOf'))) ?><?php endif; ?>
    </div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Contributions.personalPgv')) ?></div><div class="v"><?= $money((int) ($r['pgv_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupGgv')) ?></div><div class="v"><?= $money((int) ($r['ggv_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.ggvRaw')) ?></div><div class="v"><?= $money((int) ($r['ggv_raw_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.multiplier')) ?></div><div class="v"><?= number_format($mult, 2) ?>×</div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.directRecruits')) ?></div><div class="v"><?= (int) ($r['direct_recruits'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.downlineSize')) ?></div><div class="v"><?= (int) ($r['downline_size'] ?? 0) ?></div></div>
    </div>
<?= $this->endSection() ?>
