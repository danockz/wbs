<?php
/**
 * Activity category detail (GET /gamification/activities/categories/{code}) — the
 * browser face of ConfigController::showActivityCategory (was the generic admin
 * console). Shows one category: code, name, description, phase, group scope,
 * status and the count of active activities that reference it. Not-found panel
 * when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.categoryShow.*'); phase vocabulary raw-value fallback.
 *
 * @var array<string,mixed>|null $category activity_categories row (+activity_count) or null
 * @var string                   $title
 */
$c     = is_array($category ?? null) ? $category : null;
$title = $title ?? lang('Gamification.admin.categoryShow.title');
$phaseLabel = static function (string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '—'; }
    $s = lang('Gamification.admin.categoryShow.phase.' . $p);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $p;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.categoryShow.title')) ?></h1>
    <?php if ($c === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.categoryShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub" style="<?= ! empty($c['color']) ? 'color:' . esc($c['color'], 'attr') . ';' : '' ?>">
            <?php if (! empty($c['icon'])): ?><?= esc((string) $c['icon']) ?> <?php endif; ?>
            <?= esc((string) ($c['name'] ?? ($c['code'] ?? '—'))) ?>
        </div>
        <?php if (! empty($c['description'])): ?><div class="card"><?= esc((string) $c['description']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.categoryShow.code')) ?></div><div class="v"><?= esc((string) ($c['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.categoryShow.phaseLabel')) ?></div><div class="v"><?= esc($phaseLabel((string) ($c['phase'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.categoryShow.activityCount')) ?></div><div class="v"><?= esc((string) (int) ($c['activity_count'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.categoryShow.status')) ?></div><div class="v"><?= esc((string) ($c['status'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.categoryShow.group')) ?></div><div class="v"><?= esc((string) ($c['group_id'] ?? lang('Gamification.admin.categoryShow.orgWide'))) ?></div></div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
