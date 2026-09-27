<?= $this->extend('layouts/app') ?>

<?php
/**
 * Courses index / catalogue (SRS FR-CRS-001). Server-rendered; JSON when
 * negotiated. Each course links to its overview page.
 *
 * @var array<string,mixed> $result  {courses:[...]}
 */
$courses = $result['courses'] ?? [];
$col     = static fn (string $s): string => $s === 'published' ? '#4ade80' : ($s === 'archived' ? '#f87171' : '#a5b4fc');
// Status LABEL localized (falls back to raw value for unknown statuses); COLOR
// stays code-driven so translation never affects styling.
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Courses.status.' . $s);

    return $t === 'Courses.status.' . $s ? $s : $t;
};
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Courses.title')) ?></h1>
    <div class="sub"><?= count($courses) ?> <?= esc(count($courses) === 1 ? lang('Courses.course') : lang('Courses.courses')) ?></div>

    <?php if ($courses === []): ?>
        <div class="empty"><?= esc(lang('Courses.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($courses as $c): ?>
            <a class="card" style="display:block;" href="/courses/<?= esc($c['id'] ?? '') ?>">
                <div class="row">
                    <span class="author"><?= esc($c['title'] ?? lang('Courses.courseFallback')) ?></span>
                    <span class="pill" style="color:<?= $col((string) ($c['status'] ?? '')) ?>;"><?= esc($statusLbl((string) ($c['status'] ?? ''))) ?></span>
                </div>
                <div class="counts">
                    <?= (int) ($c['lesson_count'] ?? 0) ?> <?= esc((int) ($c['lesson_count'] ?? 0) === 1 ? lang('Courses.lesson') : lang('Courses.lessons')) ?>
                    <?php if (! empty($c['category'])): ?> · <?= esc($c['category']) ?><?php endif; ?>
                    <?php if (! empty($c['delivery_mode'])): ?> · <?= esc($c['delivery_mode']) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
