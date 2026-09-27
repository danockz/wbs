<?php
/**
 * Follow-up record detail (GET /gamification/follow-ups/{id}) — the browser face
 * of FollowUpsController::showFollowUp (was the generic admin console). Shows one
 * recorded follow-up: subject and follower, type/method, status, when performed,
 * summary, outcome, spiritual-health note, any recorded needs, and the scheduled
 * next follow-up. Not-found panel when the id is unknown.
 *
 * WRITE CONTROLS (new): unless the record is already cancelled, the page carries
 *   - an inline EDIT form that POSTs to /gamification/follow-ups/{id}/update
 *     (a browser alias for the API's PATCH; webcsrf-guarded). type_code,
 *     method_code, subject and follower are IMMUTABLE on edit — re-record instead
 *     — so only the mutable fields (status, summary, outcome, spiritual_health,
 *     needs, next follow-up) are editable here; status/health render as pickers.
 *   - a CANCEL form that POSTs to /gamification/follow-ups/{id}/cancel.
 * A cancelled record shows a banner and no write controls.
 *
 * SPECIALLY CLASSIFIED pastoral content — this page is gated by the same
 * follow-up read authorization as the JSON endpoint. Extends layouts/app
 * (locale-aware <html lang dir>). Copy via lang('Gamification.admin.followup.*')
 * and lang('Gamification.admin.followupRecord.*') with English fallback.
 *
 * @var array<string,mixed>|null $followup follow_ups row (needs decoded) or null
 * @var bool                     $canceled whether the record is cancelled
 * @var list<string>             $statuses record status vocabulary
 * @var list<string>             $health   spiritual-health vocabulary
 * @var string                   $csrf
 * @var string                   $title
 */
$f        = is_array($followup ?? null) ? $followup : null;
$title    = $title ?? lang('Gamification.admin.followup.title');
$needs    = is_array($f['needs'] ?? null) ? $f['needs'] : [];
$canceled = (bool) ($canceled ?? false);
$statuses = is_array($statuses ?? null) ? $statuses : [];
$health   = is_array($health ?? null) ? $health : [];
$csrf     = $csrf ?? '';

$fl = static fn (string $key): string => 'Gamification.admin.followup.' . $key;
$fr = static fn (string $key): string => 'Gamification.admin.followupRecord.' . $key;

$vocab = static function (string $group, string $value) use ($fr): string {
    if ($value === '') {
        return '';
    }
    $s = lang(($fr)($group . '.' . $value));
    if (is_string($s) && ! str_contains($s, 'Gamification.')) {
        return $s;
    }

    return ucfirst(str_replace('_', ' ', $value));
};

// A recorded "needs" list may have been decoded to an array, or kept as a plain
// string; normalise it back to a comma-joined string for the edit input.
$needsStr = '';
if (is_array($needs) && $needs !== []) {
    $flat = [];
    foreach ($needs as $n) {
        $flat[] = is_scalar($n) ? (string) $n : (string) json_encode($n, JSON_UNESCAPED_UNICODE);
    }
    $needsStr = implode(', ', $flat);
} elseif (is_string($f['needs'] ?? null)) {
    $needsStr = (string) $f['needs'];
}

$id        = (string) ($f['id'] ?? '');
$curStatus = (string) ($f['status'] ?? '');
$curHealth = (string) ($f['spiritual_health'] ?? '');
$ov        = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <p><a href="/gamification/follow-ups/due">&larr; <?= esc(lang(($fr)('backToDue'))) ?></a></p>
    <h1><?= esc(lang(($fl)('title'))) ?></h1>
    <?php if ($f === null): ?>
        <div class="empty"><?= esc(lang(($fl)('notFound'))) ?></div>
    <?php else: ?>
        <div class="sub">
            <?= esc(lang(($fl)('subject'))) ?>: <?= esc((string) ($f['subject_user_id'] ?? '—')) ?>
            · <?= esc(lang(($fl)('follower'))) ?>: <?= esc((string) ($f['follower_user_id'] ?? '—')) ?>
        </div>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang(($fl)('type'))) ?></div><div class="v"><?= esc((string) ($f['type_code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($fl)('method'))) ?></div><div class="v"><?= esc((string) ($f['method_code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($fl)('status'))) ?></div><div class="v"><?= esc((string) ($f['status'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang(($fl)('performedAt'))) ?></div><div class="v"><?= esc((string) ($f['performed_at'] ?? '—')) ?></div></div>
        </div>
        <?php if (! empty($f['summary'])): ?><h2><?= esc(lang(($fl)('summary'))) ?></h2><div class="card"><?= esc((string) $f['summary']) ?></div><?php endif; ?>
        <?php if (! empty($f['outcome'])): ?><h2><?= esc(lang(($fl)('outcome'))) ?></h2><div class="card"><?= esc((string) $f['outcome']) ?></div><?php endif; ?>
        <?php if (! empty($f['spiritual_health'])): ?><h2><?= esc(lang(($fl)('spiritualHealth'))) ?></h2><div class="card"><?= esc((string) $f['spiritual_health']) ?></div><?php endif; ?>
        <?php if ($needs !== []): ?>
            <h2><?= esc(lang(($fl)('needs'))) ?></h2>
            <div class="card"><?php foreach ($needs as $n): ?><span class="pill"><?= esc(is_scalar($n) ? (string) $n : json_encode($n, JSON_UNESCAPED_UNICODE)) ?></span> <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if (! empty($f['next_follow_up_at'])): ?>
            <div class="meta"><?= esc(lang(($fl)('nextFollowUp'))) ?>: <?= esc((string) $f['next_follow_up_at']) ?>
            <?php if (! empty($f['next_notes'])): ?> — <?= esc((string) $f['next_notes']) ?><?php endif; ?></div>
        <?php endif; ?>

        <?php if ($canceled): ?>
            <div class="fs-banner"><?= esc(lang(($fr)('canceledBanner'))) ?></div>
        <?php else: ?>
            <div class="fs-controls">
                <h2><?= esc(lang(($fr)('editHeading'))) ?></h2>
                <div class="sub"><?= esc(lang(($fr)('editSub'))) ?></div>

                <form class="fs-form" method="post" action="/gamification/follow-ups/<?= esc(rawurlencode($id), 'attr') ?>/update">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

                    <div class="fs-grid">
                        <div class="fs-field">
                            <label for="status"><?= esc(lang(($fr)('statusLabel'))) ?></label>
                            <select id="status" name="status">
                                <?php foreach ($statuses as $st): ?>
                                    <option value="<?= esc($st, 'attr') ?>"<?= $curStatus === $st ? ' selected' : '' ?>><?= esc($vocab('status', $st)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fs-field">
                            <label for="spiritual_health"><?= esc(lang(($fr)('spiritualHealthLabel'))) ?></label>
                            <select id="spiritual_health" name="spiritual_health">
                                <option value=""><?= esc(lang(($fr)('spiritualHealthNone'))) ?></option>
                                <?php foreach ($health as $h): ?>
                                    <option value="<?= esc($h, 'attr') ?>"<?= $curHealth === $h ? ' selected' : '' ?>><?= esc($vocab('health', $h)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="fs-field full">
                        <label for="summary"><?= esc(lang(($fr)('summaryLabel'))) ?></label>
                        <textarea id="summary" name="summary" maxlength="1000"><?= $ov((string) ($f['summary'] ?? '')) ?></textarea>
                    </div>

                    <div class="fs-field full">
                        <label for="outcome"><?= esc(lang(($fr)('outcomeLabel'))) ?></label>
                        <textarea id="outcome" name="outcome" maxlength="1000"><?= $ov((string) ($f['outcome'] ?? '')) ?></textarea>
                    </div>

                    <div class="fs-field full">
                        <label for="needs"><?= esc(lang(($fr)('needsLabel'))) ?>
                            <span class="hint"><?= esc(lang(($fr)('needsHint'))) ?></span></label>
                        <input type="text" id="needs" name="needs" maxlength="500" value="<?= $ov($needsStr) ?>">
                    </div>

                    <div class="fs-grid">
                        <div class="fs-field">
                            <label for="next_follow_up_at"><?= esc(lang(($fr)('nextFollowUpLabel'))) ?></label>
                            <input type="datetime-local" id="next_follow_up_at" name="next_follow_up_at" value="<?= $ov((string) ($f['next_follow_up_at'] ?? '')) ?>">
                        </div>
                        <div class="fs-field">
                            <label for="next_notes"><?= esc(lang(($fr)('nextNotesLabel'))) ?></label>
                            <input type="text" id="next_notes" name="next_notes" maxlength="500" value="<?= $ov((string) ($f['next_notes'] ?? '')) ?>">
                        </div>
                    </div>

                    <div class="fs-actions">
                        <button type="submit" class="fs-btn primary"><?= esc(lang(($fr)('saveEdit'))) ?></button>
                    </div>
                </form>

                <form class="fs-form" method="post" action="/gamification/follow-ups/<?= esc(rawurlencode($id), 'attr') ?>/cancel"
                      onsubmit="return confirm('<?= esc(lang(($fr)('cancelConfirm')), 'attr') ?>');">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="fs-actions">
                        <button type="submit" class="fs-btn danger"><?= esc(lang(($fr)('cancelRecord'))) ?></button>
                        <span class="hint"><?= esc(lang(($fr)('cancelHint'))) ?></span>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
