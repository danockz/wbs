<?= $this->extend('layouts/app') ?>

<?php
/**
 * A cause's fundraising progress (SRS FR-VBCS-*): amount raised vs target and
 * donor count. Server-rendered; JSON when negotiated. Money in minor units.
 *
 * @var array<string,mixed> $result  {cause_id, currency, raised_minor, target_minor, percent, donor_count, target_count, status}
 * @var string              $title
 */
include __DIR__ . '/_money.php';
$r   = $result ?? [];
$pct = $r['percent'] ?? null;
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.causeProgress')) ?></h1>
    <div class="sub"><?= esc($r['cause_id'] ?? '') ?>
        <?php if (! empty($r['status'])): ?> · <span class="pill"><?= esc($r['status']) ?></span><?php endif; ?>
    </div>

    <?php if ($pct !== null): ?>
        <div class="card" style="padding:12px 14px;">
            <div style="height:14px;background:#101a2e;border-radius:999px;overflow:hidden;">
                <div style="height:100%;width:<?= (int) $pct ?>%;background:linear-gradient(90deg,#22d3ee,#4ade80);"></div>
            </div>
            <div class="counts" style="margin-top:8px;"><?= esc(str_replace('{0}', (string) (int) $pct, lang('Contributions.percentOfGoal'))) ?></div>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Contributions.raised')) ?></div><div class="v"><?= $money((int) ($r['raised_minor'] ?? 0), $r['currency'] ?? null) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.target')) ?></div><div class="v"><?= $r['target_minor'] !== null ? $money((int) $r['target_minor'], $r['currency'] ?? null) : '—' ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.donorsStat')) ?></div><div class="v"><?= (int) ($r['donor_count'] ?? 0) ?><?= $r['target_count'] !== null ? ' / ' . (int) $r['target_count'] : '' ?></div></div>
    </div>
<?= $this->endSection() ?>
