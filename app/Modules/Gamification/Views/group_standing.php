<?php
/**
 * A single group's standing (SRS FR-GAM-*, design doc Part B.5): its rolled-up
 * subtree total in the chosen measure and its position among siblings.
 * Server-rendered; JSON when negotiated.
 *
 * @var array<string,mixed> $result  {season_id, group_id, measure, measure_value, position}
 * @var string              $title
 */
$r = $result ?? [];
$measureLbl = static function (string $m): string {
    $t = lang('Gamification.measure.' . $m);

    return $t === 'Gamification.measure.' . $m ? $m : $t;
};
$measure = (string) ($r['measure'] ?? 'points');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.groupStanding')) ?></h1>
    <div class="sub">
        <?= esc($r['group_id'] ?? '') ?>
        <?php if (($r['season_id'] ?? null) === null): ?> · <span class="warn"><?= esc(lang('Gamification.noActiveSeasonInline')) ?></span>
        <?php else: ?> · <?= esc(str_replace(['{0}', '{1}'], [(string) $r['season_id'], $measureLbl($measure)], lang('Gamification.seasonByInline'))) ?><?php endif; ?>
    </div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc($measureLbl($measure)) ?></div><div class="v"><?= (int) ($r['measure_value'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Gamification.position')) ?></div><div class="v"><?= $r['position'] !== null ? '#' . (int) $r['position'] : '—' ?></div></div>
    </div>
<?= $this->endSection() ?>
