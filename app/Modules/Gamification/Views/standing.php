<?php
/**
 * A subject's own standing (SRS FR-GAM-*): season points, position and current
 * rank. Server-rendered; JSON when negotiated.
 *
 * @var array<string,mixed> $result  {season_id, points, position, rank}
 * @var string              $title
 * @var string              $subjectId
 */
$r = $result ?? [];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.standingTitle')) ?></h1>
    <div class="sub">
        <?= esc($subjectId ?? '') ?>
        <?php if (($r['season_id'] ?? null) === null): ?> · <span class="warn"><?= esc(lang('Gamification.noActiveSeasonInline')) ?></span>
        <?php else: ?> · <?= esc(str_replace('{0}', (string) $r['season_id'], lang('Gamification.seasonInline'))) ?><?php endif; ?>
    </div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Gamification.points')) ?></div><div class="v"><?= (int) ($r['points'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Gamification.position')) ?></div><div class="v"><?= $r['position'] !== null ? '#' . (int) $r['position'] : '—' ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Gamification.rank')) ?></div><div class="v" style="font-size:1rem;"><?= esc($r['rank'] ?? '—') ?></div></div>
    </div>
<?= $this->endSection() ?>
