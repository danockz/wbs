<?php
/**
 * Per-group calendar settings (GET /events/calendar/settings?group_id=).
 *
 * @var string $group_id
 * @var list<array<string,mixed>> $picker
 * @var array<string,mixed>|null $own
 * @var array<string,mixed> $resolved
 * @var string $csrf
 */
$group_id = (string) ($group_id ?? '');
$picker   = $picker ?? [];
$own      = $own ?? null;
$resolved = $resolved ?? [];
$csrf     = $csrf ?? '';
$kinds    = $own['visible_kinds'] ?? ($resolved['visible_kinds'] ?? []);
if (is_array($kinds)) {
    $kindsStr = implode(', ', $kinds);
} else {
    $kindsStr = (string) $kinds;
}

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.calendar.settingsMetaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        button { margin-top:14px; border:0; border-radius:8px; padding:9px 18px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Events.calendar.settingsHeading')) ?></h1>
    <p class="sub"><?= esc(lang('Events.calendar.settingsSub')) ?></p>
    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <p><a href="/events/calendar?group_id=<?= esc($group_id, 'attr') ?>"><?= esc(lang('Events.calendar.title')) ?></a></p>

    <form method="get" action="/events/calendar/settings" style="margin-bottom:16px">
        <label for="g"><?= esc(lang('Events.calendar.fGroup')) ?></label>
        <select id="g" name="group_id">
            <?php foreach ($picker as $g): ?>
                <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>"<?= ((string) ($g['id'] ?? '') === $group_id) ? ' selected' : '' ?>>
                    <?= esc((string) ($g['name'] ?? $g['id'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= esc(lang('Events.calendar.switchGroup')) ?></button>
    </form>

    <form class="card" method="post" action="/events/calendar/settings">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <input type="hidden" name="group_id" value="<?= esc($group_id, 'attr') ?>">
        <label for="lab"><?= esc(lang('Events.calendar.fLabel')) ?></label>
        <input id="lab" name="display_label" maxlength="200" value="<?= esc((string) ($own['display_label'] ?? $resolved['display_label'] ?? ''), 'attr') ?>">
        <label for="tz"><?= esc(lang('Events.calendar.fTimezone')) ?></label>
        <input id="tz" name="timezone" maxlength="64" placeholder="Africa/Accra" value="<?= esc((string) ($own['timezone'] ?? $resolved['timezone'] ?? ''), 'attr') ?>">
        <div class="hint"><?= esc(lang('Events.calendar.timezoneHint')) ?></div>
        <label for="kinds"><?= esc(lang('Events.calendar.fKinds')) ?></label>
        <input id="kinds" name="visible_kinds" value="<?= esc($kindsStr, 'attr') ?>">
        <div class="hint"><?= esc(lang('Events.calendar.kindsHint')) ?></div>
        <button type="submit"><?= esc(lang('Events.calendar.saveSettings')) ?></button>
    </form>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
