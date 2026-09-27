<?= $this->extend('layouts/app') ?>

<?php
/**
 * Leader's group giving report (GET /contributions/groups/{id}/report[?days=N]) —
 * the browser face of VbcsController::groupReport, which otherwise rendered the
 * generic admin console. Shows a group's giving over a recent window: headline
 * totals (verified / pending / manual amounts + gift count), a top-causes
 * breakdown and a month-by-month trend. All amounts are minor units, formatted
 * via the shared _money helper.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.groupReport.*') with English fallback; the {0} day count
 * is interpolated. Cause names are org data and shown verbatim.
 *
 * @var array<string,mixed> $result groupGivingReport payload
 * @var string              $title
 */
include __DIR__ . '/_money.php';
$r       = is_array($result) ? $result : [];
$totals  = is_array($r['totals'] ?? null) ? $r['totals'] : [];
$byCause = is_array($r['by_cause'] ?? null) ? $r['by_cause'] : [];
$monthly = is_array($r['monthly'] ?? null) ? $r['monthly'] : [];
$days    = (int) ($r['days'] ?? 0);
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.groupReport.title')) ?></h1>
    <div class="sub"><?= esc((string) ($r['group_id'] ?? '')) ?> · <?= esc(str_replace('{0}', (string) $days, lang('Contributions.groupReport.window'))) ?></div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupReport.verified')) ?></div><div class="v"><?= $money((int) ($totals['verified_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupReport.pending')) ?></div><div class="v"><?= $money((int) ($totals['pending_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupReport.manual')) ?></div><div class="v"><?= $money((int) ($totals['manual_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupReport.gifts')) ?></div><div class="v"><?= esc((string) (int) ($totals['count'] ?? 0)) ?></div></div>
    </div>

    <h2><?= esc(lang('Contributions.groupReport.topCauses')) ?></h2>
    <?php if ($byCause === []): ?>
        <div class="empty"><?= esc(lang('Contributions.groupReport.noCauses')) ?></div>
    <?php else: ?>
        <?php foreach ($byCause as $c): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc((string) ($c['cause_name'] ?? '—')) ?></span>
                    <span class="v" style="font-size:1.05rem;font-weight:800;"><?= $money((int) ($c['total_minor'] ?? 0)) ?></span>
                </div>
                <div class="counts"><?= esc((string) (int) ($c['count'] ?? 0)) ?> <?= esc(lang('Contributions.groupReport.giftsLabel')) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2><?= esc(lang('Contributions.groupReport.monthlyTrend')) ?></h2>
    <?php if ($monthly === []): ?>
        <div class="empty"><?= esc(lang('Contributions.groupReport.noMonthly')) ?></div>
    <?php else: ?>
        <?php foreach ($monthly as $m): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc((string) ($m['month'] ?? '—')) ?></span>
                    <span class="v" style="font-weight:800;"><?= $money((int) ($m['total_minor'] ?? 0)) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
