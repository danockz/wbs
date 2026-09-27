<?php
/**
 * Rank-tier ladder (SRS FR-GAM-*). Server-rendered ladder of rank definitions
 * ordered by the points required to reach each tier. JSON when negotiated.
 *
 * @var list<array<string,mixed>> $result  rank definitions [{code,name,min_points,icon,color}]
 * @var string                    $title
 */
$tiers = is_array($result) ? $result : [];
$count = count($tiers);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.ranksTitle')) ?></h1>
    <div class="sub"><?= $count ?> <?= esc($count === 1 ? lang('Gamification.tier') : lang('Gamification.tiers')) ?></div>

    <?php if ($tiers === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noRanksDef')) ?></div>
    <?php else: ?>
        <?php foreach ($tiers as $t): ?>
            <div class="card">
                <div class="row">
                    <span class="author" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color']) . ';' : '' ?>">
                        <?php if (! empty($t['icon'])): ?><?= esc($t['icon']) ?> <?php endif; ?>
                        <?= esc($t['name'] ?? ($t['code'] ?? lang('Gamification.rankFallback'))) ?>
                    </span>
                    <span class="time"><?= esc(str_replace('{0}', (string) (int) ($t['min_points'] ?? 0), lang('Gamification.minPointsSuffix'))) ?></span>
                </div>
                <?php if (! empty($t['description'])): ?>
                    <div class="counts"><?= esc($t['description']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
