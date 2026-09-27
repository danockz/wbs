<?= $this->extend('layouts/app') ?>

<?php
/**
 * Course overview / authoring view (SRS FR-CRS-001). Server-rendered; the same
 * controller returns JSON when negotiated. Shows the course header and its
 * lessons with drip configuration. It reports whether a lesson has attached
 * content but never prints the private content_ref — that value is not sent to
 * the browser by the service.
 *
 * Authoring write controls (publish the course / append a lesson) appear only for
 * browsers holding a webcsrf token — passed as $csrf by the controller, or minted
 * globally by WebCsrfIssueFilter on this safe navigation ($request->wbsCsrf). The
 * routes are additionally gated by auth + authorize:course.create, so a member
 * without the right simply can't POST even if the markup is present. Writes PRG
 * back to this page with a localized success/error flash.
 *
 * @var array<string,mixed> $result  payload from CourseService::overview
 */
$c       = $result ?? [];
$lessons = $c['lessons'] ?? [];
$status  = (string) ($c['status'] ?? 'draft');
$courseId = (string) ($c['course_id'] ?? '');

// Authoring-token resolution (see header): explicit $csrf else the request's
// globally-minted token. Null when neither exists (e.g. JSON/API render path).
$authCsrf = $csrf ?? null;
if ($authCsrf === null && function_exists('service')) {
    $req      = function_exists('service') ? service('request') : null;
    $authCsrf = ($req !== null && isset($req->wbsCsrf)) ? (string) $req->wbsCsrf : null;
}
$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
$col     = $status === 'published' ? '#4ade80' : ($status === 'archived' ? '#f87171' : '#a5b4fc');
// Status/drip LABELS localized with raw-value fallback; colors stay code-driven.
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Courses.status.' . $s);

    return $t === 'Courses.status.' . $s ? $s : $t;
};
$dripLbl = static function (string $d): string {
    if ($d === '') {
        return '';
    }
    $t = lang('Courses.drip.' . $d);

    return $t === 'Courses.drip.' . $d ? $d : $t;
};
?>

<?= $this->section('content') ?>
    <h1><?= esc($c['title'] ?? lang('Courses.courseFallback')) ?></h1>
    <div class="sub">
        <span class="pill" style="color:<?= $col ?>;"><?= esc($statusLbl($status)) ?></span>
        <?php if (! empty($c['category'])): ?> · <?= esc($c['category']) ?><?php endif; ?>
        <?php if (! empty($c['delivery_mode'])): ?> · <?= esc($c['delivery_mode']) ?><?php endif; ?>
    </div>

    <?php if ($flashOk !== ''): ?>
        <div class="card" style="border-color:#166534;color:#86efac;margin-top:12px;"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
        <div class="card" style="border-color:#7f1d1d;color:#fca5a5;margin-top:12px;"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($authCsrf !== null && $courseId !== ''): ?>
        <div class="card" style="margin-top:14px;">
            <div class="row">
                <span class="author"><?= esc(lang('Courses.authoring.heading')) ?></span>
            </div>
            <?php if ($status !== 'published'): ?>
                <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px;">
                    <form method="post" action="/courses/<?= esc(rawurlencode($courseId), 'attr') ?>/publish" style="margin:0;"
                          onsubmit="return confirm('<?= esc(str_replace("'", '', (string) lang('Courses.authoring.publishConfirm')), 'attr') ?>')">
                        <input type="hidden" name="_csrf" value="<?= esc($authCsrf, 'attr') ?>">
                        <button type="submit" style="border:0;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#166534;color:#dcfce7;"><?= esc(lang('Courses.authoring.publishBtn')) ?></button>
                    </form>
                    <span class="counts" style="margin:0;"><?= esc(lang('Courses.authoring.publishHint')) ?></span>
                </div>
            <?php else: ?>
                <div class="counts" style="margin-top:8px;color:#4ade80;"><?= esc(lang('Courses.authoring.publishedNote')) ?></div>
            <?php endif; ?>

            <form method="post" action="/courses/<?= esc(rawurlencode($courseId), 'attr') ?>/lessons" style="margin:14px 0 0;border-top:1px solid #1e293b;padding-top:12px;">
                <input type="hidden" name="_csrf" value="<?= esc($authCsrf, 'attr') ?>">
                <div style="font-weight:600;margin-bottom:8px;"><?= esc(lang('Courses.authoring.lessonHeading')) ?></div>
                <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:4px;"><?= esc(lang('Courses.authoring.lessonTitleLabel')) ?></label>
                <input type="text" name="title" required maxlength="200" placeholder="<?= esc(lang('Courses.authoring.lessonTitlePh'), 'attr') ?>"
                       style="width:100%;padding:9px 11px;border-radius:8px;border:1px solid #334155;background:#0b1120;color:#e2e8f0;font:inherit;">
                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:10px;">
                    <div style="flex:1;min-width:120px;">
                        <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:4px;"><?= esc(lang('Courses.authoring.lessonPositionLabel')) ?></label>
                        <input type="number" name="position" min="0" max="9999" value="<?= (int) ($c['lesson_count'] ?? 0) + 1 ?>"
                               style="width:100%;padding:9px 11px;border-radius:8px;border:1px solid #334155;background:#0b1120;color:#e2e8f0;font:inherit;">
                    </div>
                    <div style="flex:2;min-width:200px;">
                        <label style="display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:4px;"><?= esc(lang('Courses.authoring.lessonContentLabel')) ?></label>
                        <input type="text" name="content_ref" maxlength="500" placeholder="<?= esc(lang('Courses.authoring.lessonContentPh'), 'attr') ?>"
                               style="width:100%;padding:9px 11px;border-radius:8px;border:1px solid #334155;background:#0b1120;color:#e2e8f0;font:inherit;">
                    </div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;color:#cbd5e1;font-size:.9rem;">
                    <input type="checkbox" name="required" value="1" checked> <?= esc(lang('Courses.authoring.lessonRequiredLabel')) ?>
                </label>
                <button type="submit" style="margin-top:14px;border:0;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;background:#0d9488;color:#fff;"><?= esc(lang('Courses.authoring.lessonAddBtn')) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Courses.lessonsLbl')) ?></div><div class="v"><?= (int) ($c['lesson_count'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Courses.required')) ?></div><div class="v"><?= (int) ($c['required_count'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Courses.statusLbl')) ?></div><div class="v" style="font-size:1rem;color:<?= $col ?>;"><?= esc($statusLbl($status)) ?></div></div>
    </div>

    <?php if (! empty($c['description'])): ?>
        <h2><?= esc(lang('Courses.description')) ?></h2>
        <div class="card" style="line-height:1.55;"><?= nl2br(esc($c['description'])) ?></div>
    <?php endif; ?>

    <h2><?= esc(lang('Courses.lessonsHeading')) ?></h2>
    <?php if ($lessons === []): ?>
        <div class="empty"><?= esc(lang('Courses.noLessons')) ?></div>
    <?php else: ?>
        <?php foreach ($lessons as $l): ?>
            <div class="card">
                <div class="row">
                    <span class="author">
                        <?= (int) ($l['position'] ?? 0) ?>. <?= esc($l['title'] ?? lang('Courses.lessonFallback')) ?>
                        <?php if (! empty($l['required'])): ?><span class="pill"><?= esc(lang('Courses.requiredTag')) ?></span><?php endif; ?>
                    </span>
                    <span class="time"><?= esc($dripLbl((string) ($l['drip'] ?? 'immediate'))) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($l['has_content'])): ?>
                        <span style="color:#4ade80;"><?= esc(lang('Courses.contentAttached')) ?></span>
                    <?php else: ?>
                        <span class="warn"><?= esc(lang('Courses.noContent')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="meta">
        <?= esc(str_replace('{0}', (string) ($c['course_id'] ?? ''), lang('Courses.courseMeta'))) ?> ·
        <a href="/courses/<?= esc($c['course_id'] ?? '') ?>/syllabus"><?= esc(lang('Courses.viewSyllabus')) ?></a>
    </div>
<?= $this->endSection() ?>
