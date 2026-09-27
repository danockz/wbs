<?php
/**
 * EDIT EVENT form (GET /events/{id}/edit) — the browser face of
 * EventController::editForm/update, closing the pre-event gap where an event
 * could be created but never curated. Posts back to POST /events/{id}/edit
 * (webcsrf-guarded); on success the controller redirects to the event (PRG), on
 * failure it re-renders this form with $error and the submitted $old values.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Events.editForm.*') / lang('Events.createForm.*') (shared field labels)
 * with English fallback. The double-submit CSRF token is issued by the controller
 * (renderForm) and echoed into the hidden _csrf field. No-JS and CSP-safe: a
 * fixed form action + hidden fields, no inline on* handlers and no <script>.
 *
 * Field values are sticky: a re-render after a failed submit prefers $old (the
 * just-submitted values), otherwise the stored $event row.
 *
 * @var string                    $csrf     CSRF token (also set as an HttpOnly cookie)
 * @var string                    $event_id the event id (form action target)
 * @var array<string,mixed>       $event    the stored event row
 * @var string                    $error    optional error message from a failed submit
 * @var array<string,mixed>       $old      optional previously-submitted values
 */
$csrf    = $csrf ?? '';
$error   = $error ?? '';
$old     = $old ?? [];
$event   = $event ?? [];
$eventId = (string) ($event_id ?? ($event['id'] ?? ''));

// Sticky value: submitted $old wins, else the stored row.
$val = static function (string $k) use ($old, $event): string {
    $v = array_key_exists($k, $old) ? $old[$k] : ($event[$k] ?? '');

    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
};
$sel = static function (string $k, string $opt, string $default) use ($old, $event): string {
    $cur = (string) (array_key_exists($k, $old) ? $old[$k] : ($event[$k] ?? $default));

    return $cur === $opt ? ' selected' : '';
};

include __DIR__ . '/_locale.php';

$modes     = ['physical', 'online', 'hybrid'];
$regPols   = ['open', 'invite', 'closed'];
$attPols   = ['checkin', 'streaming', 'manual'];
?>

<?php ob_start(); ?>
<?= esc(lang('Events.editForm.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#6366f1; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#4f46e5; }


        .cancel { display:inline-block; margin-top:14px; color:#94a3b8; text-decoration:none; font-size:.85rem; }


        .cancel:hover { color:#e2e8f0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.editForm.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.editForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc(base_url('events/' . rawurlencode($eventId) . '/edit'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="title"><?= esc(lang('Events.createForm.titleLabel')) ?> <span class="req">*</span></label>
            <input id="title" name="title" required value="<?= $val('title') ?>" placeholder="<?= esc(lang('Events.createForm.titlePh'), 'attr') ?>">

            <div class="row2">
                <div>
                    <label for="starts_at"><?= esc(lang('Events.createForm.startsLabel')) ?> <span class="req">*</span></label>
                    <input id="starts_at" name="starts_at" type="datetime-local" required value="<?= $val('starts_at') ?>">
                </div>
                <div>
                    <label for="ends_at"><?= esc(lang('Events.createForm.endsLabel')) ?></label>
                    <input id="ends_at" name="ends_at" type="datetime-local" value="<?= $val('ends_at') ?>">
                </div>
            </div>

            <div class="row2">
                <div>
                    <label for="mode"><?= esc(lang('Events.createForm.modeLabel')) ?></label>
                    <select id="mode" name="mode">
                        <?php foreach ($modes as $m): ?>
                            <option value="<?= esc($m, 'attr') ?>"<?= $sel('mode', $m, 'physical') ?>><?= esc(lang('Events.createForm.mode.' . $m)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="capacity"><?= esc(lang('Events.createForm.capacityLabel')) ?></label>
                    <input id="capacity" name="capacity" type="number" min="0" value="<?= $val('capacity') ?>">
                </div>
            </div>

            <label for="access_url"><?= esc(lang('Events.editForm.accessUrlLabel')) ?></label>
            <input id="access_url" name="access_url" type="url" value="<?= $val('access_url') ?>" placeholder="<?= esc(lang('Events.editForm.accessUrlPh'), 'attr') ?>">
            <div class="hint"><?= esc(lang('Events.editForm.accessUrlHint')) ?></div>

            <div class="row2">
                <div>
                    <label for="registration_policy"><?= esc(lang('Events.editForm.regPolicyLabel')) ?></label>
                    <select id="registration_policy" name="registration_policy">
                        <?php foreach ($regPols as $p): ?>
                            <option value="<?= esc($p, 'attr') ?>"<?= $sel('registration_policy', $p, 'open') ?>><?= esc(lang('Events.editForm.regPolicy.' . $p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="attendance_policy"><?= esc(lang('Events.editForm.attPolicyLabel')) ?></label>
                    <select id="attendance_policy" name="attendance_policy">
                        <?php foreach ($attPols as $p): ?>
                            <option value="<?= esc($p, 'attr') ?>"<?= $sel('attendance_policy', $p, 'checkin') ?>><?= esc(lang('Events.editForm.attPolicy.' . $p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label for="timezone"><?= esc(lang('Events.editForm.timezoneLabel')) ?></label>
            <input id="timezone" name="timezone" value="<?= $val('timezone') ?>" placeholder="UTC">
            <div class="hint"><?= esc(lang('Events.editForm.timezoneHint')) ?></div>

            <label for="description"><?= esc(lang('Events.createForm.descLabel')) ?></label>
            <textarea id="description" name="description"><?= $val('description') ?></textarea>

            <button type="submit"><?= esc(lang('Events.editForm.submit')) ?></button>
        </form>
        <a class="cancel" href="<?= esc(base_url('events/' . rawurlencode($eventId)), 'attr') ?>">← <?= esc(lang('Events.editForm.cancel')) ?></a>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
