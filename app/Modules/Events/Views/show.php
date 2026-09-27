<?= $this->extend('layouts/app') ?>

<?php
/**
 * Event overview (SRS FR-EVT-001). Server-rendered; the same controller returns
 * JSON when negotiated. A read-only summary of a single event with a link to the
 * live expected-attendance report — it never invents attendance figures itself.
 *
 * Localized via lang('Events.*'); enum labels fall back to the raw stored value.
 *
 * A self-service RSVP panel (register / change intent / cancel) appears for a
 * signed-in attendee when the event is published and open — no-JS, CSP-safe,
 * webcsrf-guarded POSTs that PRG back here with a flash.
 *
 * @var array<string,mixed>       $result       the event row from EventService::find
 * @var array<string,mixed>|null  $registration the viewer's own registration, or null
 * @var string                    $user_id      current member id ('' when anonymous)
 * @var string                    $csrf         webcsrf double-submit token
 */
$e         = $result ?? [];
$eventId   = (string) ($e['id'] ?? '');
$myReg     = is_array($registration ?? null) ? $registration : null;
$viewerId  = (string) ($user_id ?? '');
$capacity  = $e['capacity'] ?? null;
$status    = (string) ($e['status'] ?? 'draft');
// L4 — archive state (soft flag, orthogonal to status). Archivable = a settled
// event (draft or terminal); a live published event must be cancelled/completed
// first.
$isArchived   = ($e['archived_at'] ?? null) !== null && (string) $e['archived_at'] !== '';
$isArchivable = in_array($status, ['draft', 'cancelled', 'completed', 'completed_no_attendance'], true);
$statusCol = match ($status) {
    'published'                 => '#4ade80',
    'cancelled'                 => '#f87171',
    'completed'                 => '#38bdf8',
    'completed_no_attendance'   => '#fbbf24',
    default                     => '#a5b4fc',
};
$enum = static function (string $group, string $value): string {
    if ($value === '') {
        return '';
    }
    $label = lang('Events.' . $group . '.' . $value);

    return (is_string($label) && $label !== 'Events.' . $group . '.' . $value) ? $label : $value;
};
$interp = static fn (string $key, string $v): string => str_replace('{0}', $v, lang($key));

// Lifecycle write controls (publish/complete) appear only for browsers with a
// webcsrf token. The token is either passed as $csrf or minted globally by
// WebCsrfIssueFilter on this safe navigation ($request->wbsCsrf).
$lcCsrf = $csrf ?? null;
if ($lcCsrf === null && function_exists('service')) {
    $req    = service('request');
    $lcCsrf = ($req !== null && isset($req->wbsCsrf)) ? (string) $req->wbsCsrf : null;
}
$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
?>

<?= $this->section('content') ?>
    <h1><?= esc($e['title'] ?? lang('Events.eventFallback')) ?></h1>
    <div class="sub">
        <span class="pill" style="color:<?= $statusCol ?>;"><?= esc($enum('status', $status)) ?></span>
        <?php if (! empty($e['starts_at'])): ?>
            · <?= esc($interp('Events.startsAtUtc', (string) $e['starts_at'])) ?>
        <?php endif; ?>
        <?php if (! empty($e['ends_at'])): ?>
            · <?= esc($interp('Events.endsAtUtc', (string) $e['ends_at'])) ?>
        <?php endif; ?>
    </div>

    <?php if ($flashOk !== ''): ?>
        <div class="card" style="border-color:#166534;color:#86efac;margin-top:12px;"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
        <div class="card" style="border-color:#7f1d1d;color:#fca5a5;margin-top:12px;"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <?php
    // ---- Publish-readiness checklist (G6) — draft only ---------------------
    $rd = is_array($readiness ?? null) ? $readiness : null;
    if ($lcCsrf !== null && $eventId !== '' && $status === 'draft' && $rd !== null
        && ((! empty($rd['blockers'])) || (! empty($rd['warnings'])))):
        $rdLbl = static fn (string $key): string => (string) (($t = lang($key)) !== $key ? $t : $key);
    ?>
        <div class="card" style="margin-top:14px;">
            <span class="author"><?= esc(lang('Events.readiness.heading')) ?></span>
            <?php if (! empty($rd['blockers'])): ?>
                <div class="counts" style="margin-top:8px;color:#fca5a5;"><?= esc(lang('Events.readiness.blockersLead')) ?></div>
                <ul style="margin:6px 0 0;padding-inline-start:20px;">
                    <?php foreach ($rd['blockers'] as $b): ?>
                        <li style="color:#fca5a5;"><?= esc($rdLbl((string) $b)) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (! empty($rd['warnings'])): ?>
                <div class="counts" style="margin-top:8px;color:#fcd34d;"><?= esc(lang('Events.readiness.warningsLead')) ?></div>
                <ul style="margin:6px 0 0;padding-inline-start:20px;">
                    <?php foreach ($rd['warnings'] as $w): ?>
                        <li style="color:#fcd34d;"><?= esc($rdLbl((string) $w)) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($lcCsrf !== null && $eventId !== '' && ($status === 'draft' || $status === 'published')): ?>
        <div class="card" style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <span class="author"><?= esc(lang('Events.lifecycle.heading')) ?></span>
            <a class="pill" href="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/edit"><?= esc(lang('Events.editForm.editLink')) ?></a>
            <?php if ($status === 'draft'): ?>
                <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/publish" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                    <button type="submit" style="border:0;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#166534;color:#dcfce7;"><?= esc(lang('Events.lifecycle.publishBtn')) ?></button>
                </form>
                <span class="counts" style="margin:0;"><?= esc(lang('Events.lifecycle.publishHint')) ?></span>
            <?php elseif ($status === 'published'): ?>
                <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/complete" style="margin:0;"
                      onsubmit="return confirm('<?= esc(str_replace("'", '', (string) lang('Events.lifecycle.completeConfirm')), 'attr') ?>')">
                    <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                    <button type="submit" style="border:0;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#0369a1;color:#e0f2fe;"><?= esc(lang('Events.lifecycle.completeBtn')) ?></button>
                </form>
                <span class="counts" style="margin:0;"><?= esc(lang('Events.lifecycle.completeHint')) ?></span>
            <?php endif; ?>
        </div>

        <?php // ---- Cancel event (G7): guarded, reason required -------------- ?>
        <div class="card" style="margin-top:12px;border-color:#7f1d1d;">
            <span class="author" style="color:#fca5a5;"><?= esc(lang('Events.lifecycle.cancelHeading')) ?></span>
            <div class="counts" style="margin:4px 0 8px;"><?= esc(lang('Events.lifecycle.cancelHint')) ?></div>
            <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/cancel"
                  style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0;"
                  onsubmit="return confirm('<?= esc(str_replace("'", '', (string) lang('Events.lifecycle.cancelConfirm')), 'attr') ?>')">
                <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                <input type="text" name="reason" required maxlength="500"
                       placeholder="<?= esc(lang('Events.lifecycle.cancelReasonPh'), 'attr') ?>"
                       style="flex:1;min-width:200px;padding:8px 10px;border-radius:8px;border:1px solid #7f1d1d;background:#0b1120;color:#e2e8f0;font:inherit;">
                <button type="submit" style="border:1px solid #7f1d1d;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#3a0d12;color:#fca5a5;"><?= esc(lang('Events.lifecycle.cancelBtn')) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <?php // ---- Archive / restore (L4): organizer-only, settled events ------ ?>
    <?php if ($lcCsrf !== null && $eventId !== '' && ($isArchivable || $isArchived)): ?>
        <div class="card" style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?php if ($isArchived): ?>
                <span class="author"><?= esc(lang('Events.archive.archivedHeading')) ?></span>
                <div class="counts" style="margin:0;flex-basis:100%;"><?= esc(lang('Events.archive.archivedHint')) ?></div>
                <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/unarchive" style="margin:0;">
                    <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                    <button type="submit" style="border:0;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#3730a3;color:#e0e7ff;"><?= esc(lang('Events.archive.unarchiveBtn')) ?></button>
                </form>
            <?php else: ?>
                <span class="author"><?= esc(lang('Events.archive.heading')) ?></span>
                <div class="counts" style="margin:0;flex-basis:100%;"><?= esc(lang('Events.archive.hint')) ?></div>
                <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/archive" style="margin:0;"
                      onsubmit="return confirm('<?= esc(str_replace("'", '', (string) lang('Events.archive.confirm')), 'attr') ?>')">
                    <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                    <button type="submit" style="border:1px solid #475569;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer;background:#1e293b;color:#cbd5e1;"><?= esc(lang('Events.archive.archiveBtn')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // ---- Self-service RSVP (attendee) --------------------------------------
    $rsvpStates = ['yes', 'maybe', 'no'];
    $rsvpLbl    = static fn (string $st): string => match ($st) {
        'yes'   => lang('Events.rsvp.rsvpYes'),
        'maybe' => lang('Events.rsvp.rsvpMaybe'),
        'no'    => lang('Events.rsvp.rsvpNo'),
        default => $st,
    };
    $regOpen = $status === 'published' && (string) ($e['registration_policy'] ?? 'open') !== 'closed';
    if ($eventId !== '' && ($status === 'published')):
    ?>
        <h2><?= esc(lang('Events.rsvp.heading')) ?></h2>
        <div class="card" style="margin-top:6px;">
            <?php if (! $regOpen): ?>
                <div class="counts"><?= esc(lang('Events.rsvp.closedNote')) ?></div>
            <?php elseif ($viewerId === '' || $lcCsrf === null): ?>
                <div class="counts"><?= esc(lang('Events.rsvp.signInToRsvp')) ?></div>
            <?php else: ?>
                <?php if ($myReg !== null): ?>
                    <div class="row" style="margin-bottom:8px;">
                        <span class="author">
                            <?php $st = (string) ($myReg['status'] ?? 'registered'); ?>
                            <span class="pill" style="color:<?= $st === 'waitlisted' ? '#fbbf24' : '#4ade80' ?>;">
                                <?= esc($st === 'waitlisted' ? lang('Events.rsvp.statusWaitlisted') : lang('Events.rsvp.statusRegistered')) ?>
                            </span>
                            · <?= esc(str_replace('{0}', $rsvpLbl((string) ($myReg['rsvp_state'] ?? 'yes')), lang('Events.rsvp.youRsvped'))) ?>
                        </span>
                    </div>
                    <?php if ($st === 'waitlisted'): ?>
                        <div class="counts" style="margin-bottom:8px;"><?= esc(lang('Events.rsvp.waitlistNote')) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="counts" style="margin-bottom:8px;"><?= esc(lang('Events.rsvp.promptOpen')) ?></div>
                <?php endif; ?>

                <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/register"
                      style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                    <label style="font-size:.82rem;color:#94a3b8;">
                        <select name="rsvp_state" style="padding:8px 10px;border-radius:8px;border:1px solid #334155;background:#0b1120;color:#e2e8f0;font:inherit;">
                            <?php foreach ($rsvpStates as $st): ?>
                                <option value="<?= esc($st, 'attr') ?>"<?= ($myReg !== null && (string) ($myReg['rsvp_state'] ?? 'yes') === $st) ? ' selected' : '' ?>><?= esc($rsvpLbl($st)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" style="border:0;border-radius:8px;padding:9px 18px;font-weight:700;cursor:pointer;background:#0d9488;color:#fff;">
                        <?= esc($myReg !== null ? lang('Events.rsvp.updateBtn') : lang('Events.rsvp.registerBtn')) ?>
                    </button>
                </form>

                <?php if ($myReg !== null): ?>
                    <form method="post" action="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/register/cancel" style="margin-top:10px;">
                        <input type="hidden" name="_csrf" value="<?= esc($lcCsrf, 'attr') ?>">
                        <button type="submit" style="border:1px solid #7f1d1d;border-radius:8px;padding:7px 14px;font-weight:600;cursor:pointer;background:#3a0d12;color:#fca5a5;font-size:.85rem;">
                            <?= esc(lang('Events.rsvp.cancelBtn')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2><?= esc(lang('Events.atAGlance')) ?></h2>
    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Events.capacityLbl')) ?></div><div class="v"><?= $capacity !== null ? (int) $capacity : esc(lang('Events.unlimited')) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.modeLbl')) ?></div><div class="v" style="font-size:1rem;"><?= esc($enum('mode', (string) ($e['mode'] ?? 'physical'))) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.registration')) ?></div><div class="v" style="font-size:1rem;"><?= esc($enum('regPolicy', (string) ($e['registration_policy'] ?? 'open'))) ?></div></div>
    </div>

    <?php if (! empty($e['description'])): ?>
        <h2><?= esc(lang('Events.description')) ?></h2>
        <div class="card" style="line-height:1.55;"><?= nl2br(esc($e['description'])) ?></div>
    <?php endif; ?>

    <h2><?= esc(lang('Events.attendance')) ?></h2>
    <div class="card">
        <div class="row">
            <span class="author"><?= esc(lang('Events.expectedReport')) ?></span>
            <a class="pill" href="/events/<?= esc($eventId) ?>/attendance"><?= esc(lang('Events.openReport')) ?></a>
        </div>
        <div class="counts"><?= esc(lang('Events.funnelBlurb')) ?></div>
    </div>

    <?php if ($status === 'published'): ?>
        <div class="card" style="margin-top:12px;">
            <div class="row">
                <span class="author"><?= esc(lang('Events.addToCalendar')) ?></span>
                <a class="pill" href="/events/<?= esc(rawurlencode($eventId), 'attr') ?>/event.ics"><?= esc(lang('Events.downloadIcs')) ?></a>
            </div>
            <div class="counts"><?= esc(lang('Events.addToCalendarBlurb')) ?></div>
        </div>
    <?php endif; ?>

    <div class="meta">
        <?= esc($interp('Events.eventMeta', $eventId)) ?>
        <?php if (! empty($e['created_at'])): ?> · <?= esc($interp('Events.created', (string) $e['created_at'])) ?><?php endif; ?>
    </div>
<?= $this->endSection() ?>
