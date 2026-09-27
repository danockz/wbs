<?php
/**
 * CREATE COURSE form (GET /courses/create) — the browser face of
 * CourseController::createForm, and the landing page for the Learning → Create
 * course menu item (previously a 404). Posts back to POST /courses
 * (webcsrf-guarded); on success the controller redirects to the new course (PRG),
 * on failure it re-renders this form with $error and the submitted $old values.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Courses.createForm.*') with English fallback. The double-submit CSRF token
 * is issued by the controller (renderForm) and echoed into the hidden _csrf field.
 *
 * @var string              $csrf
 * @var string              $error
 * @var array<string,mixed> $old
 */
$csrf  = $csrf ?? '';
$error = $error ?? '';
$old   = $old ?? [];
$ov    = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);

include __DIR__ . '/_locale.php';

$modes = ['self_paced', 'cohort', 'blended'];
?>

<?php ob_start(); ?>
<?= esc(lang('Courses.createForm.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#0d9488; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#0f766e; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Courses.createForm.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Courses.createForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc(base_url('courses'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="title"><?= esc(lang('Courses.createForm.titleLabel')) ?> <span class="req">*</span></label>
            <input id="title" name="title" required value="<?= $ov('title') ?>" placeholder="<?= esc(lang('Courses.createForm.titlePh'), 'attr') ?>">

            <div class="row2">
                <div>
                    <label for="category"><?= esc(lang('Courses.createForm.categoryLabel')) ?></label>
                    <input id="category" name="category" value="<?= $ov('category') ?>">
                </div>
                <div>
                    <label for="delivery_mode"><?= esc(lang('Courses.createForm.deliveryLabel')) ?></label>
                    <select id="delivery_mode" name="delivery_mode">
                        <?php foreach ($modes as $m): ?>
                            <option value="<?= esc($m, 'attr') ?>"<?= ($old['delivery_mode'] ?? 'self_paced') === $m ? ' selected' : '' ?>><?= esc(lang('Courses.createForm.delivery.' . $m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label for="description"><?= esc(lang('Courses.createForm.descLabel')) ?></label>
            <textarea id="description" name="description"><?= $ov('description') ?></textarea>

            <button type="submit"><?= esc(lang('Courses.createForm.submit')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
