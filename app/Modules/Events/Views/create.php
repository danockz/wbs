<?php
/**
 * CREATE EVENT form (GET /events/create) — the browser face of
 * EventController::createForm, and the landing page for the Events → Create event
 * menu item (previously a 404). Posts back to POST /events (webcsrf-guarded); on
 * success the controller redirects to the new event (PRG), on failure it
 * re-renders this form with $error and the submitted $old values.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Events.createForm.*') with English fallback. The double-submit CSRF token
 * is issued by the controller (renderForm) and echoed into the hidden _csrf field.
 *
 * @var string                    $csrf   CSRF token (also set as an HttpOnly cookie)
 * @var string                    $error  optional error message from a failed submit
 * @var array<string,mixed>       $old    optional previously-submitted values
 */
$csrf  = $csrf ?? '';
$error = $error ?? '';
$old   = $old ?? [];
$ov    = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);

include __DIR__ . '/_locale.php';

$modes = ['physical', 'online', 'hybrid'];
?>

<?php ob_start(); ?>
<?= esc(lang('Events.createForm.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#6366f1; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#4f46e5; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.createForm.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.createForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc(base_url('events'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="title"><?= esc(lang('Events.createForm.titleLabel')) ?> <span class="req">*</span></label>
            <input id="title" name="title" required value="<?= $ov('title') ?>" placeholder="<?= esc(lang('Events.createForm.titlePh'), 'attr') ?>">

            <div class="row2">
                <div>
                    <label for="starts_at"><?= esc(lang('Events.createForm.startsLabel')) ?> <span class="req">*</span></label>
                    <input id="starts_at" name="starts_at" type="datetime-local" required value="<?= $ov('starts_at') ?>">
                </div>
                <div>
                    <label for="ends_at"><?= esc(lang('Events.createForm.endsLabel')) ?></label>
                    <input id="ends_at" name="ends_at" type="datetime-local" value="<?= $ov('ends_at') ?>">
                </div>
            </div>

            <div class="row2">
                <div>
                    <label for="mode"><?= esc(lang('Events.createForm.modeLabel')) ?></label>
                    <select id="mode" name="mode">
                        <?php foreach ($modes as $m): ?>
                            <option value="<?= esc($m, 'attr') ?>"<?= ($old['mode'] ?? 'physical') === $m ? ' selected' : '' ?>><?= esc(lang('Events.createForm.mode.' . $m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="capacity"><?= esc(lang('Events.createForm.capacityLabel')) ?></label>
                    <input id="capacity" name="capacity" type="number" min="0" value="<?= $ov('capacity') ?>">
                </div>
            </div>

            <label for="description"><?= esc(lang('Events.createForm.descLabel')) ?></label>
            <textarea id="description" name="description"><?= $ov('description') ?></textarea>

            <button type="submit"><?= esc(lang('Events.createForm.submit')) ?></button>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
