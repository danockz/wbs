<?php
/**
 * Catalogue of achievement definitions (SRS FR-GAM-*). Server-rendered; the same
 * controller returns JSON when negotiated. Secret achievements are only present
 * when the caller asked for them (the service filters them out by default).
 *
 * @var array<string,mixed> $result  {achievements:[{code,name,description,category,phase,xp,icon,secret}]}
 * @var string              $title
 */
$items = $result['achievements'] ?? [];
$count = count($items);
// Phase COLOR stays code-driven; the LABEL is localized (falls back to raw).
$phaseColor = static fn (string $p): string => match ($p) {
    'win'   => '#38bdf8',
    'build' => '#a5b4fc',
    'send'  => '#4ade80',
    default => '#94a3b8',
};
$phaseLbl = static function (string $p): string {
    $t = lang('Gamification.phase.' . $p);

    return $t === 'Gamification.phase.' . $p ? $p : $t;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.achievementsTitle')) ?></h1>
    <div class="sub"><?= $count ?> <?= esc($count === 1 ? lang('Gamification.achievement') : lang('Gamification.achievements')) ?></div>

    <?php if ($items === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noAchievementsDef')) ?></div>
    <?php else: ?>
        <?php foreach ($items as $a): ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <?php if (! empty($a['icon'])): ?><?= esc($a['icon']) ?> <?php endif; ?>
                        <?= esc($a['name'] ?? ($a['code'] ?? lang('Gamification.achievementFallback'))) ?>
                        <?php if (! empty($a['secret'])): ?><span class="pill" style="color:#fbbf24;"><?= esc(lang('Gamification.secret')) ?></span><?php endif; ?>
                    </span>
                    <?php if (! empty($a['phase']) && $a['phase'] !== 'general'): ?>
                        <span class="pill" style="color:<?= $phaseColor((string) $a['phase']) ?>;"><?= esc($phaseLbl((string) $a['phase'])) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (! empty($a['description'])): ?>
                    <div class="counts"><?= esc($a['description']) ?></div>
                <?php endif; ?>
                <div class="counts">
                    <?php if (! empty($a['category'])): ?><?= esc($a['category']) ?> · <?php endif; ?>
                    <?= esc(str_replace('{0}', (string) (int) ($a['xp'] ?? 0), lang('Gamification.xpSuffix'))) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
