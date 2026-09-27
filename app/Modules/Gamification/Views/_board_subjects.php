<?php
/**
 * Reusable INDIVIDUAL leaderboard body (SRS FR-GAM-*). Server-rendered ranking
 * of subjects by points/measure. PII-free: subject_id + display name + points
 * only. Shared by the org leaderboard and the individuals-within-group board.
 *
 * @var array<string,mixed> $result  {season_id, subject_type?, within_group?, measure?, entries:[...]}
 * @var string              $title
 */
$entries = $result['entries'] ?? [];
$measure = (string) ($result['measure'] ?? 'points');
$season  = $result['season_id'] ?? null;
$measureLbl = static function (string $m): string {
    $t = lang('Gamification.measure.' . $m);

    return $t === 'Gamification.measure.' . $m ? $m : $t;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc($title) ?></h1>
    <div class="sub">
        <?php if ($season === null): ?>
            <span class="warn"><?= esc(lang('Gamification.noActiveSeason')) ?></span>
        <?php else: ?>
            <?= esc(str_replace(['{0}', '{1}'], [(string) $season, $measureLbl($measure)], lang('Gamification.seasonRankedBy'))) ?>
            <?php if (! empty($result['within_group'])): ?> · <?= esc(str_replace('{0}', (string) $result['within_group'], lang('Gamification.withinGroup'))) ?><?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($entries === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noRankedSubjects')) ?></div>
    <?php else: ?>
        <?php foreach ($entries as $e): ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <span class="pill">#<?= (int) ($e['position'] ?? 0) ?></span>
                        <?= esc($e['display_name'] ?? ($e['subject_id'] ?? lang('Gamification.anonymous'))) ?>
                    </span>
                    <span class="v" style="font-size:1.15rem;font-weight:800;"><?= (int) ($e['points'] ?? 0) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($e['rank'])): ?><span class="pill"><?= esc($e['rank']) ?></span> · <?php endif; ?>
                    <?= (int) ($e['achievement_count'] ?? 0) ?> <?= esc((int) ($e['achievement_count'] ?? 0) === 1 ? lang('Gamification.achievement') : lang('Gamification.achievements')) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
