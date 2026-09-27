<?php
/**
 * A subject's unlocked achievements (SRS FR-GAM-*), with optional in-progress
 * trackers when ?progress=1 was requested. Server-rendered; JSON when negotiated.
 *
 * The service returns either a plain list of unlocked rows, or, with progress,
 * {unlocked:[...], progress:[...]}. This view normalises both shapes.
 *
 * @var array<string,mixed> $result  raw AchievementService::getUserAchievements payload
 * @var string              $title
 * @var string              $subjectId
 */
$data     = $result ?? [];
$unlocked = array_key_exists('unlocked', $data) ? ($data['unlocked'] ?? []) : $data;
$progress = $data['progress'] ?? [];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.achievementsTitle')) ?></h1>
    <div class="sub">
        <?= esc($subjectId ?? '') ?> · <?= esc(str_replace('{0}', (string) count($unlocked), lang('Gamification.unlockedCount'))) ?>
    </div>

    <?php if ($unlocked === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noneUnlocked')) ?></div>
    <?php else: ?>
        <?php foreach ($unlocked as $u): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($u['achievement_code'] ?? ($u['code'] ?? lang('Gamification.achievementFallback'))) ?></span>
                    <span class="time"><?= esc($u['unlocked_at'] ?? '') ?></span>
                </div>
                <?php if (isset($u['xp']) || isset($u['bonus_points'])): ?>
                    <div class="counts">
                        <?php if (isset($u['xp'])): ?><?= esc(str_replace('{0}', (string) (int) $u['xp'], lang('Gamification.xpSuffix'))) ?><?php endif; ?>
                        <?php if (! empty($u['bonus_points'])): ?> · <?= esc(str_replace('{0}', (string) (int) $u['bonus_points'], lang('Gamification.ptsSuffix'))) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($progress !== []): ?>
        <h2><?= esc(lang('Gamification.inProgress')) ?></h2>
        <?php foreach ($progress as $p): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($p['achievement_code'] ?? ($p['code'] ?? '')) ?></span>
                    <span class="time"><?= (int) ($p['current'] ?? 0) ?> / <?= (int) ($p['threshold'] ?? 0) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
