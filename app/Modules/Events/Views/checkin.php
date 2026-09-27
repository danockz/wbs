<?php
/**
 * MANUAL CHECK-IN launcher (GET /events/checkin) — the browser face of
 * CheckinController::checkinForm and the landing page for the Events → Check-in
 * menu item (previously a 404 because the real work is the id-scoped POST
 * events/{id}/checkin/manual). The operator picks an event and records a manual
 * check-in; the form POSTs to /events/checkin (webcsrf-guarded) which dispatches
 * to CheckinService::checkInManual. Success redirects to the event (PRG); failure
 * re-renders here with $error and sticky $old values.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.checkin.*') with English
 * fallback. The CSRF token issued by the controller is echoed into hidden _csrf.
 *
 * @var string                        $csrf   CSRF token (also an HttpOnly cookie)
 * @var list<array<string,mixed>>     $events events to choose from (id,title,starts_at)
 * @var string                        $error  optional error from a failed submit
 * @var array<string,mixed>           $old    optional previously-submitted values
 */
$csrf   = $csrf ?? '';
$error  = $error ?? '';
$old    = $old ?? [];
$events = $events ?? [];
$roster = is_array($roster ?? null) ? $roster : [];
$ov     = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);

// Render a roster-backed person <select> for an entity-reference field, preserving
// a previously-submitted (possibly off-list) id and falling back to bounded text.
$personSelect = static function (string $id, string $name, bool $required, string $current, array $roster, string $noneLabel, string $placeholder): string {
    if ($roster === []) {
        $req = $required ? ' required' : '';
        return '<input id="' . $id . '" name="' . $name . '"' . $req . ' maxlength="64" value="'
            . htmlspecialchars($current, ENT_QUOTES) . '" placeholder="' . htmlspecialchars($placeholder, ENT_QUOTES) . '">';
    }
    $req  = $required ? ' required' : '';
    $html = '<select id="' . $id . '" name="' . $name . '"' . $req . '>';
    $html .= '<option value="">' . htmlspecialchars($noneLabel, ENT_QUOTES) . '</option>';
    $seen = false;
    foreach ($roster as $u) {
        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $uname  = trim((string) ($u['display_name'] ?? ''));
        $ulabel = $uname !== '' ? $uname : $uid;
        $sel    = $uid === $current;
        $seen   = $seen || $sel;
        $html  .= '<option value="' . htmlspecialchars($uid, ENT_QUOTES) . '"' . ($sel ? ' selected' : '') . '>'
            . htmlspecialchars($ulabel, ENT_QUOTES) . '</option>';
    }
    if ($current !== '' && ! $seen) {
        $html .= '<option value="' . htmlspecialchars($current, ENT_QUOTES) . '" selected>'
            . htmlspecialchars($current, ENT_QUOTES) . '</option>';
    }
    return $html . '</select>';
};

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.checkin.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#0284c7; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#0369a1; }


        button:disabled { background:#334155; cursor:not-allowed; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.checkin.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.checkin.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($events === []): ?>
            <div class="empty"><?= esc(lang('Events.checkin.noEvents')) ?></div>
        <?php else: ?>
        <form method="post" action="<?= esc(base_url('events/checkin'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="event_id"><?= esc(lang('Events.checkin.eventLabel')) ?> <span class="req">*</span></label>
            <select id="event_id" name="event_id" required>
                <option value="" disabled<?= ($old['event_id'] ?? '') === '' ? ' selected' : '' ?>><?= esc(lang('Events.checkin.eventPh')) ?></option>
                <?php foreach ($events as $e): ?>
                    <option value="<?= esc((string) ($e['id'] ?? ''), 'attr') ?>"<?= ($old['event_id'] ?? '') === (string) ($e['id'] ?? '') ? ' selected' : '' ?>>
                        <?= esc((string) ($e['title'] ?? $e['id'] ?? '')) ?><?= isset($e['starts_at']) ? ' — ' . esc((string) $e['starts_at']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="user_id"><?= esc(lang('Events.checkin.userLabel')) ?> <span class="req">*</span></label>
            <?= $personSelect('user_id', 'user_id', true, (string) ($old['user_id'] ?? ''), $roster, lang('Events.checkin.userNone'), lang('Events.checkin.userPh')) ?>

            <label for="staff_id"><?= esc(lang('Events.checkin.staffLabel')) ?></label>
            <?= $personSelect('staff_id', 'staff_id', false, (string) ($old['staff_id'] ?? ''), $roster, lang('Events.checkin.staffNone'), lang('Events.checkin.staffPh')) ?>

            <label for="reason"><?= esc(lang('Events.checkin.reasonLabel')) ?></label>
            <input id="reason" name="reason" value="<?= $ov('reason') ?>" placeholder="<?= esc(lang('Events.checkin.reasonPh'), 'attr') ?>">

            <label for="group_attribution"><?= esc(lang('Events.checkin.attrLabel')) ?></label>
            <input id="group_attribution" name="group_attribution" value="<?= $ov('group_attribution') ?>" placeholder="<?= esc(lang('Events.checkin.attrPh'), 'attr') ?>">
            <p class="hint"><?= esc(lang('Events.checkin.attrHint')) ?></p>

            <button type="submit"><?= esc(lang('Events.checkin.submit')) ?></button>
        </form>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
