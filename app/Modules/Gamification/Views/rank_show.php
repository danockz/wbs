<?php
/**
 * Rank-tier detail (GET /gamification/ranks/{code}) — the browser face of
 * AwardsController::showRank (was the generic admin console). Shows one rank
 * tier in full: code, name, min-points threshold, group scope, sort order and
 * status. Not-found panel when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.rankShow.*') with English fallback.
 *
 * @var array<string,mixed>|null $tier  rank_definitions row, or null if not found
 * @var string                   $title
 */
$tier  = is_array($tier ?? null) ? $tier : null;
$title = $title ?? lang('Gamification.admin.rankShow.title');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.rankShow.title')) ?></h1>
    <?php if ($tier === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.rankShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub" style="<?= ! empty($tier['color']) ? 'color:' . esc($tier['color'], 'attr') . ';' : '' ?>">
            <?php if (! empty($tier['icon'])): ?><?= esc((string) $tier['icon']) ?> <?php endif; ?>
            <?= esc((string) ($tier['name'] ?? ($tier['code'] ?? '—'))) ?>
        </div>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.rankShow.code')) ?></div><div class="v"><?= esc((string) ($tier['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.rankShow.minPoints')) ?></div><div class="v"><?= esc((string) (int) ($tier['min_points'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.rankShow.sortOrder')) ?></div><div class="v"><?= esc((string) ($tier['sort_order'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.rankShow.status')) ?></div><div class="v"><?= esc((string) ($tier['status'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.rankShow.group')) ?></div><div class="v"><?= esc((string) ($tier['group_id'] ?? lang('Gamification.admin.rankShow.orgWide'))) ?></div></div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
