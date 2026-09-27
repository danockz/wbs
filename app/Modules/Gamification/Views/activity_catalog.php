<?php
/**
 * Activity catalog (GET /gamification/activities/catalog) — the browser face of
 * ConfigController::activityCatalog (was the generic admin console). The full
 * Win-Build-Send scoring catalog grouped by phase → category → activity, each
 * activity showing its points (or point mode/formula) and event type.
 *
 * The service returns phases keyed by win|build|send|general, each
 * {phase, categories:{catKey:{category:{code,name}, activities:[{code,name,
 * points,point_mode,event_type,...}]}}}. Extends layouts/app (locale-aware
 * <html lang dir>). Copy via lang('Gamification.admin.catalog.*') + localized
 * phase.* labels (raw-value fallback).
 *
 * @var array<string,mixed> $catalog phases map
 * @var string              $title
 */
$catalog = is_array($catalog ?? null) ? $catalog : [];
$title   = $title ?? lang('Gamification.admin.catalog.title');
$phaseLabel = static function (string $p): string {
    $s = lang('Gamification.admin.catalog.phase.' . $p);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $p;
};
$total = 0;
foreach ($catalog as $ph) {
    foreach (($ph['categories'] ?? []) as $cat) {
        $total += count($cat['activities'] ?? []);
    }
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.catalog.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $total, $total === 1 ? lang('Gamification.admin.catalog.countOne') : lang('Gamification.admin.catalog.count'))) ?></div>

    <?php if ($total === 0): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.catalog.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($catalog as $phaseKey => $ph): ?>
            <?php $cats = $ph['categories'] ?? []; if ($cats === []) { continue; } ?>
            <h2><?= esc($phaseLabel((string) ($ph['phase'] ?? $phaseKey))) ?></h2>
            <?php foreach ($cats as $cat): ?>
                <div class="card">
                    <div class="row">
                        <span class="author"><?= esc((string) ($cat['category']['name'] ?? lang('Gamification.admin.catalog.uncategorised'))) ?></span>
                        <span class="muted"><?= esc(str_replace('{0}', (string) count($cat['activities'] ?? []), lang('Gamification.admin.catalog.activities'))) ?></span>
                    </div>
                    <?php foreach (($cat['activities'] ?? []) as $act): ?>
                        <div class="row" style="margin-top:8px">
                            <span><?= esc((string) ($act['name'] ?? ($act['code'] ?? '—'))) ?> <span class="muted">(<?= esc((string) ($act['code'] ?? '')) ?>)</span></span>
                            <span class="pill">
                                <?php if (($act['point_mode'] ?? 'fixed') === 'fixed'): ?>
                                    <?= esc(str_replace('{0}', (string) (int) ($act['points'] ?? 0), lang('Gamification.admin.catalog.points'))) ?>
                                <?php else: ?>
                                    <?= esc((string) ($act['point_mode'] ?? '')) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
