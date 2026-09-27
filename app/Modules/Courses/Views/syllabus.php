<?= $this->extend('layouts/app') ?>

<?php
/**
 * Learner syllabus with drip-feed unlock + SELF-SERVICE learner controls
 * (SRS FR-CRS-003/004). Server-rendered; JSON when negotiated. Locked lessons
 * show their next unlock time WITHOUT leaking hidden content — the service
 * already strips content_ref for locked lessons.
 *
 * The member can ENROL themselves (when not yet enrolled) and MARK a lesson
 * complete (for each unlocked, not-yet-done lesson) via no-JS, CSP-safe,
 * webcsrf-guarded POST forms. Both routes are auth + webcsrf gated; the buttons
 * only render for a browser that carries a token AND (for completion) an active
 * enrollment. Writes PRG back here with a localized flash. A progress bar rolls
 * up required-lesson completion.
 *
 * @var array<string,mixed> $result   learnerView payload (+ lessons)
 * @var string              $csrf     webcsrf double-submit token (browser only)
 * @var string              $user_id  current member id ('' when anonymous)
 */
$r         = $result ?? [];
$lessons   = $r['lessons'] ?? [];
$courseId  = (string) ($r['course_id'] ?? '');
$isEnrolled  = ! empty($r['is_enrolled']);
$isCompleted = ! empty($r['is_completed']);
$enrollment  = $r['enrollment'] ?? null;
$enrollmentId = (string) ($enrollment['id'] ?? '');
$progress  = $r['progress'] ?? ['required_total' => 0, 'required_done' => 0, 'total' => 0, 'done' => 0, 'percent' => 0];
$userId    = (string) ($user_id ?? '');

// Browser token: explicit $csrf else the globally-minted request token.
$authCsrf = $csrf ?? null;
if ($authCsrf === null && function_exists('service')) {
    $req      = service('request');
    $authCsrf = ($req !== null && isset($req->wbsCsrf)) ? (string) $req->wbsCsrf : null;
}
$canAct   = $authCsrf !== null && $authCsrf !== '' && $userId !== '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
$lf       = static fn (string $k): string => lang('Courses.learner.' . $k);
$pct      = max(0, min(100, (int) ($progress['percent'] ?? 0)));
?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Courses.syllabusTitle')) ?></h1>
    <div class="sub"><?= count($lessons) ?> <?= esc(count($lessons) === 1 ? lang('Courses.lesson') : lang('Courses.lessons')) ?> · <?= esc(lang('Courses.syllabusSub')) ?></div>

    <?php if ($flashOk !== ''): ?><div class="lv-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="lv-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($isCompleted): ?>
        <div class="lv-enrol"><span class="lv-donetag">✓ <?= esc($lf('courseCompleted')) ?></span></div>
    <?php elseif ($isEnrolled): ?>
        <div class="lv-prog">
            <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
            <div class="lbl"><?= esc(str_replace(['{0}', '{1}', '{2}'], [(string) ($progress['required_done'] ?? 0), (string) ($progress['required_total'] ?? 0), (string) $pct], $lf('progressLabel'))) ?></div>
        </div>
    <?php elseif ($canAct && $courseId !== ''): ?>
        <form class="lv-enrol" method="post" action="/courses/<?= esc(rawurlencode($courseId), 'attr') ?>/enrol">
            <input type="hidden" name="_csrf" value="<?= esc($authCsrf, 'attr') ?>">
            <span class="msg"><?= esc($lf('enrolPrompt')) ?></span>
            <button type="submit" class="lv-btn"><?= esc($lf('enrolBtn')) ?></button>
        </form>
    <?php elseif ($userId === ''): ?>
        <div class="lv-enrol"><span class="msg"><?= esc($lf('signInToEnrol')) ?></span></div>
    <?php endif; ?>

    <?php if ($lessons === []): ?>
        <div class="empty"><?= esc(lang('Courses.nonePublished')) ?></div>
    <?php else: ?>
        <?php foreach ($lessons as $l): ?>
            <?php
            $locked = ! empty($l['locked']);
            $done   = ! empty($l['completed']);
            $lid    = (string) ($l['lesson_id'] ?? '');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <?= (int) ($l['position'] ?? 0) ?>. <?= esc($l['title'] ?? lang('Courses.lessonFallback')) ?>
                        <?php if (! empty($l['required'])): ?><span class="pill"><?= esc(lang('Courses.requiredTag')) ?></span><?php endif; ?>
                    </span>
                    <?php if ($locked): ?>
                        <span class="time"><?= esc(str_replace('{0}', (string) ($l['unlock_at'] ?? lang('Courses.unlockLater')), lang('Courses.unlocks'))) ?></span>
                    <?php else: ?>
                        <span class="time" style="color:#4ade80;"><?= esc(lang('Courses.available')) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (! $locked && ! empty($l['content_ref'])): ?>
                    <div class="counts"><?= esc(str_replace('{0}', (string) $l['content_ref'], lang('Courses.contentLabel'))) ?></div>
                <?php endif; ?>

                <?php if ($done): ?>
                    <div class="lv-lesson-actions"><span class="lv-donetag">✓ <?= esc($lf('lessonDone')) ?></span></div>
                <?php elseif ($locked): ?>
                    <div class="lv-lesson-actions"><span class="lv-lockedtag"><?= esc($lf('lessonLocked')) ?></span></div>
                <?php elseif ($canAct && $isEnrolled && ! $isCompleted && $enrollmentId !== '' && $lid !== ''): ?>
                    <form class="lv-lesson-actions" method="post"
                          action="/enrollments/<?= esc(rawurlencode($enrollmentId), 'attr') ?>/lessons/<?= esc(rawurlencode($lid), 'attr') ?>/complete">
                        <input type="hidden" name="_csrf" value="<?= esc($authCsrf, 'attr') ?>">
                        <input type="hidden" name="course_id" value="<?= esc($courseId, 'attr') ?>">
                        <button type="submit" class="lv-btn done"><?= esc($lf('markCompleteBtn')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
