<?php
/**
 * Reusable GROUP leaderboard body (SRS FR-GAM-*, design doc Part B.5). Ranks
 * groups by their rolled-up subtree total in the chosen measure. Shared by the
 * groups board, the cross-cutting dimension board, and the membership board.
 *
 * @var array<string,mixed> $result  {season_id, dimension?, measure?, entries:[{position,group_id,name,measure_value,members?}]}
 * @var string              $title
 */
$entries = $result['entries'] ?? [];
$measure = (string) ($result['measure'] ?? 'points');
$season  = $result['season_id'] ?? null;
// Measure name is localized but falls back to the raw stored value when a
// measure has no translation (so custom measures never break the page).
$measureLbl = static function (string $m): string {
    $t = lang('Gamification.measure.' . $m);

    return $t === 'Gamification.measure.' . $m ? $m : $t;
};
// Each axis bit is "<label> <value>" via a localized {0} template.
$axis = static fn (string $key, string $val): string => str_replace('{0}', $val, lang('Gamification.axis.' . $key));
$axisBits = array_filter([
    ! empty($result['category']) ? $axis('category', (string) $result['category']) : null,
    ! empty($result['project']) ? $axis('project', (string) $result['project']) : null,
    ! empty($result['phase']) ? $axis('phase', (string) $result['phase']) : null,
    ! empty($result['membership_type']) ? $axis('type', (string) $result['membership_type']) : null,
    ! empty($result['parent']) ? $axis('under', (string) $result['parent']) : null,
]);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc($title) ?></h1>
    <div class="sub">
        <?php if ($season === null): ?>
            <span class="warn"><?= esc(lang('Gamification.noActiveSeason')) ?></span>
        <?php else: ?>
            <?= esc(str_replace(['{0}', '{1}'], [(string) $season, $measureLbl($measure)], lang('Gamification.seasonRankedBy'))) ?>
            <?php foreach ($axisBits as $b): ?> · <?= esc($b) ?><?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($entries === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noRankedGroups')) ?></div>
    <?php else: ?>
        <?php foreach ($entries as $e): ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <span class="pill">#<?= (int) ($e['position'] ?? 0) ?></span>
                        <?= esc($e['name'] ?? ($e['group_id'] ?? lang('Gamification.groupFallback'))) ?>
                    </span>
                    <span class="v" style="font-size:1.15rem;font-weight:800;"><?= (int) ($e['measure_value'] ?? 0) ?></span>
                </div>
                <div class="counts">
                    <?php if (isset($e['depth']) && $e['depth'] !== null): ?><?= esc(str_replace('{0}', (string) (int) $e['depth'], lang('Gamification.depth'))) ?><?php endif; ?>
                    <?php if (isset($e['members'])): ?> · <?= (int) $e['members'] ?> <?= esc((int) $e['members'] === 1 ? lang('Gamification.member') : lang('Gamification.members')) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
