<?php
/**
 * LOGISTICS plan console (GET /events/{id}/logistics) — the browser face of
 * LogisticsController::planConsole, which otherwise left every logistics write as
 * a JSON-only endpoint. Shows the plan header plus its resources, seating areas,
 * staff roster and suppliers, and turns each section into a management console
 * with a no-JS PRG form for the matching write action:
 *   - create plan        → POST .../logistics/plan
 *   - refresh projection → POST .../logistics/refresh-projection
 *   - add resource       → POST .../logistics/resources
 *   - add seating area   → POST .../logistics/seating
 *   - assign staff       → POST .../logistics/staff
 *   - add supplier       → POST .../logistics/suppliers
 * Each form carries the `_csrf` field (matches WebCsrfFilter). NO specially-
 * classified accessibility detail is shown here.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.logistics.*') with English
 * fallback. Ids/names/quantities are server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null  $plan      plan header row, or null if none yet
 * @var list<array<string,mixed>> $resources event_resources rows
 * @var list<array<string,mixed>> $seating   event_seating_areas rows
 * @var list<array<string,mixed>> $staff     event_staff_roster rows
 * @var list<array<string,mixed>> $suppliers event_suppliers rows
 * @var string                    $eventId   the event id (for the write routes)
 * @var string                    $csrf      webcsrf token for the inline forms
 */
$plan      = $plan ?? null;
$resources = $resources ?? [];
$seating   = $seating ?? [];
$staff     = $staff ?? [];
$suppliers = $suppliers ?? [];
$eventId   = $eventId ?? '';
$csrf      = $csrf ?? '';
$sidAttr   = $eventId !== '' ? rawurlencode($eventId) : '';
$hasPlan   = is_array($plan);
$roster    = is_array($roster ?? null) ? $roster : [];
// Users already assigned to this event's staff roster shouldn't be offered again.
$staffIds = [];
foreach ($staff as $st) {
    $uid = (string) ($st['user_id'] ?? '');
    if ($uid !== '') {
        $staffIds[$uid] = true;
    }
}

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$kinds = ['material', 'equipment', 'security', 'catering'];
?>

<?php ob_start(); ?>
<?= esc(lang('Events.logistics.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .pill.draft { border-color:#a16207; color:#fcd34d; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        input:focus, select:focus { outline:2px solid #0d9488; border-color:#0d9488; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#0d9488; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.logistics.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.logistics.eventLabel')) ?>: <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if (! $hasPlan): ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/plan">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.logistics.noPlanHeading')) ?></h3>
                <p class="muted" style="margin:0 0 4px"><?= esc(lang('Events.logistics.noPlanHint')) ?></p>
                <button type="submit"><?= esc(lang('Events.logistics.createPlanBtn')) ?></button>
            </form>
        <?php else: ?>
            <div class="planhead">
                <span class="pill <?= esc((string) ($plan['status'] ?? ''), 'attr') ?>"><?= esc((string) ($plan['status'] ?? '—')) ?></span>
                <span><?= esc(lang('Events.logistics.projectionLabel')) ?>: <strong><?= esc((string) ($plan['projection_expected'] ?? 0)) ?></strong></span>
                <form method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/refresh-projection" style="margin-inline-start:auto;display:flex;gap:8px;align-items:flex-end">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div>
                        <label for="show-rate"><?= esc(lang('Events.logistics.showRateLabel')) ?></label>
                        <input type="number" step="0.05" min="0" max="1" id="show-rate" name="show_rate" value="0.6" style="width:90px">
                    </div>
                    <button type="submit" class="ghost"><?= esc(lang('Events.logistics.refreshBtn')) ?></button>
                </form>
            </div>

            <!-- Resources -->
            <h2><?= esc(lang('Events.logistics.resourcesHeading')) ?></h2>
            <?php if ($resources === []): ?>
                <p class="empty"><?= esc(lang('Events.logistics.noResources')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.logistics.colName')) ?></th>
                        <th><?= esc(lang('Events.logistics.colKind')) ?></th>
                        <th class="num"><?= esc(lang('Events.logistics.colPlanned')) ?></th>
                        <th class="num"><?= esc(lang('Events.logistics.colOrdered')) ?></th>
                        <th><?= esc(lang('Events.logistics.colStatus')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($resources as $r): ?>
                            <tr>
                                <td><?= esc((string) ($r['name'] ?? '—')) ?></td>
                                <td><?= esc((string) ($r['kind'] ?? '—')) ?></td>
                                <td class="num"><?= esc((string) ($r['quantity_planned'] ?? 0)) ?></td>
                                <td class="num"><?= esc((string) ($r['quantity_ordered'] ?? 0)) ?></td>
                                <td><?= esc((string) ($r['status'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/resources">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.logistics.addResourceHeading')) ?></h3>
                <div class="grid">
                    <div><label for="r-name"><?= esc(lang('Events.logistics.colName')) ?></label><input id="r-name" name="name" required maxlength="120"></div>
                    <div><label for="r-kind"><?= esc(lang('Events.logistics.colKind')) ?></label><select id="r-kind" name="kind"><?php foreach ($kinds as $k): ?><option value="<?= esc($k, 'attr') ?>"><?= esc($k) ?></option><?php endforeach; ?></select></div>
                    <div><label for="r-unit"><?= esc(lang('Events.logistics.fUnit')) ?></label><input id="r-unit" name="unit" maxlength="40"></div>
                    <div><label for="r-planned"><?= esc(lang('Events.logistics.colPlanned')) ?></label><input type="number" min="0" id="r-planned" name="quantity_planned" value="0"></div>
                    <div><label for="r-ratio"><?= esc(lang('Events.logistics.fPerAttendee')) ?></label><input type="number" step="0.01" min="0" id="r-ratio" name="per_attendee"></div>
                </div>
                <button type="submit"><?= esc(lang('Events.logistics.addResourceBtn')) ?></button>
            </form>

            <!-- Seating -->
            <h2><?= esc(lang('Events.logistics.seatingHeading')) ?></h2>
            <?php if ($seating === []): ?>
                <p class="empty"><?= esc(lang('Events.logistics.noSeating')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr><th><?= esc(lang('Events.logistics.colName')) ?></th><th class="num"><?= esc(lang('Events.logistics.colCapacity')) ?></th></tr></thead>
                    <tbody><?php foreach ($seating as $s): ?><tr><td><?= esc((string) ($s['name'] ?? '—')) ?></td><td class="num"><?= esc((string) ($s['capacity'] ?? 0)) ?></td></tr><?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/seating">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.logistics.addSeatingHeading')) ?></h3>
                <div class="grid">
                    <div><label for="s-name"><?= esc(lang('Events.logistics.colName')) ?></label><input id="s-name" name="name" required maxlength="120"></div>
                    <div><label for="s-cap"><?= esc(lang('Events.logistics.colCapacity')) ?></label><input type="number" min="0" id="s-cap" name="capacity" value="0"></div>
                </div>
                <button type="submit"><?= esc(lang('Events.logistics.addSeatingBtn')) ?></button>
            </form>

            <!-- Staff -->
            <h2><?= esc(lang('Events.logistics.staffHeading')) ?></h2>
            <?php if ($staff === []): ?>
                <p class="empty"><?= esc(lang('Events.logistics.noStaff')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr><th><?= esc(lang('Events.logistics.colUser')) ?></th><th><?= esc(lang('Events.logistics.colRole')) ?></th><th><?= esc(lang('Events.logistics.colStatus')) ?></th></tr></thead>
                    <tbody><?php foreach ($staff as $st): ?><tr><td class="muted"><?= esc((string) ($st['user_id'] ?? '—')) ?></td><td><?= esc((string) ($st['role'] ?? '—')) ?></td><td><?= esc((string) ($st['status'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/staff">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.logistics.assignStaffHeading')) ?></h3>
                <div class="grid">
                    <div><label for="st-user"><?= esc(lang('Events.logistics.colUser')) ?></label><?php
                        $staffCandidates = [];
                        foreach ($roster as $u) {
                            $uid = (string) ($u['id'] ?? '');
                            if ($uid !== '' && ! isset($staffIds[$uid])) {
                                $staffCandidates[] = $u;
                            }
                        }
                        if ($staffCandidates !== []): ?><select id="st-user" name="user_id" required><option value=""><?= esc(lang('Events.logistics.userNone')) ?></option><?php foreach ($staffCandidates as $u): $uid = (string) $u['id']; $uname = trim((string) ($u['display_name'] ?? '')); ?><option value="<?= esc($uid, 'attr') ?>"><?= esc($uname !== '' ? $uname : $uid) ?></option><?php endforeach; ?></select><?php else: ?><input id="st-user" name="user_id" required maxlength="64"><?php endif; ?></div>
                    <div><label for="st-role"><?= esc(lang('Events.logistics.colRole')) ?></label><input id="st-role" name="role" required maxlength="64"></div>
                    <div class="full"><label for="st-assign"><?= esc(lang('Events.logistics.fAssignment')) ?></label><input id="st-assign" name="assignment" maxlength="255"></div>
                </div>
                <button type="submit"><?= esc(lang('Events.logistics.assignStaffBtn')) ?></button>
            </form>

            <!-- Suppliers -->
            <h2><?= esc(lang('Events.logistics.suppliersHeading')) ?></h2>
            <?php if ($suppliers === []): ?>
                <p class="empty"><?= esc(lang('Events.logistics.noSuppliers')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr><th><?= esc(lang('Events.logistics.colName')) ?></th><th><?= esc(lang('Events.logistics.colCategory')) ?></th><th><?= esc(lang('Events.logistics.colContact')) ?></th></tr></thead>
                    <tbody><?php foreach ($suppliers as $sp): ?><tr><td><?= esc((string) ($sp['name'] ?? '—')) ?></td><td><?= esc((string) ($sp['category'] ?? '—')) ?></td><td class="muted"><?= esc((string) ($sp['contact'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
            <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/logistics/suppliers">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.logistics.addSupplierHeading')) ?></h3>
                <div class="grid">
                    <div><label for="sp-name"><?= esc(lang('Events.logistics.colName')) ?></label><input id="sp-name" name="name" required maxlength="120"></div>
                    <div><label for="sp-cat"><?= esc(lang('Events.logistics.colCategory')) ?></label><input id="sp-cat" name="category" maxlength="80"></div>
                    <div><label for="sp-contact"><?= esc(lang('Events.logistics.colContact')) ?></label><input id="sp-contact" name="contact" maxlength="120"></div>
                </div>
                <button type="submit"><?= esc(lang('Events.logistics.addSupplierBtn')) ?></button>
            </form>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
