<?php
/**
 * A subject's streaks (SRS FR-GAM-*). Server-rendered; JSON when negotiated.
 *
 * @var list<array<string,mixed>> $result  [{streak_code,current_count,best_count,last_event_date,freeze_until}]
 * @var string                    $title
 * @var string                    $subjectId
 */
$streaks = is_array($result) ? $result : [];
$count   = count($streaks);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.streaksTitle')) ?></h1>
    <div class="sub"><?= esc($subjectId ?? '') ?> · <?= $count ?> <?= esc($count === 1 ? lang('Gamification.streak') : lang('Gamification.streaks')) ?></div>

    <?php if ($streaks === []): ?>
        <div class="empty"><?= esc(lang('Gamification.noStreaks')) ?></div>
    <?php else: ?>
        <?php foreach ($streaks as $s): ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($s['streak_code'] ?? lang('Gamification.streakFallback')) ?></span>
                    <span class="v" style="font-size:1.15rem;font-weight:800;"><?= (int) ($s['current_count'] ?? 0) ?></span>
                </div>
                <div class="counts">
                    <?= esc(str_replace('{0}', (string) (int) ($s['best_count'] ?? 0), lang('Gamification.best'))) ?>
                    <?php if (! empty($s['last_event_date'])): ?> · <?= esc(str_replace('{0}', (string) $s['last_event_date'], lang('Gamification.lastInline'))) ?><?php endif; ?>
                    <?php if (! empty($s['freeze_until'])): ?> · <span class="pill"><?= esc(str_replace('{0}', (string) $s['freeze_until'], lang('Gamification.frozenUntil'))) ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
