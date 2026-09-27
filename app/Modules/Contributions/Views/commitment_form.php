<?= $this->extend('layouts/app') ?>

<?php
/**
 * Giving-commitment (pledge) CAPTURE form (GET /vbcs/commitments/new) — the
 * browser face of VbcsController::createCommitmentForm, and the write side of
 * the commitments list's "New commitment" button (previously pledges could only
 * be created via the JSON API).
 *
 * Posts to POST /vbcs/commitments (webcsrf-guarded). On success the controller
 * PRG-redirects to the subject's commitments list with a localized flash; on
 * failure it re-renders here with $error + the submitted values. Commitments are
 * REMINDER-ONLY — nothing is ever auto-charged — so no payment method is
 * captured; the member completes payment through the normal contribution flow.
 *
 * The cause is chosen from the org's ACTIVE causes (a <select>, not free text).
 * Frequency is a fixed-vocab <select> (monthly/quarterly/annual/one_time). Amount
 * is captured in integer MINOR units to stay consistent with the ledger (a hint
 * says so). The subject id is carried as a hidden field (defaults to the acting
 * user; a leader pledging for a member passes ?subject_id=).
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.commitmentForm.*') with English fallback.
 *
 * @var string                    $csrf
 * @var string                    $subjectId
 * @var list<array<string,mixed>> $causes       active causes for the picker
 * @var list<string>              $frequencies  fixed frequency vocabulary
 * @var array<string,mixed>       $commitment   submitted values (on re-render)
 * @var string                    $error
 * @var string                    $title
 */
$csrf        = $csrf ?? '';
$subjectId   = (string) ($subjectId ?? '');
$causes      = is_array($causes ?? null) ? $causes : [];
$frequencies = is_array($frequencies ?? null) ? $frequencies : ['monthly', 'quarterly', 'annual', 'one_time'];
$commitment  = is_array($commitment ?? null) ? $commitment : [];
$error       = (string) ($error ?? '');

$cf    = static fn (string $k): string => 'Contributions.commitmentForm.' . $k;
$title = $title ?? lang(($cf)('metaTitle'));

$ov = static function (string $k, string $default = '') use ($commitment): string {
    $v = $commitment[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

$curCause = (string) ($commitment['cause_id'] ?? '');
$curFreq  = (string) ($commitment['frequency'] ?? 'monthly');

// Localize a frequency token; fall back to a humanized value for unknown tokens.
$freqLabel = static function (string $f): string {
    if ($f === '') {
        return '';
    }
    $t = lang('Contributions.frequency.' . $f);

    return str_contains($t, 'Contributions.') ? ucfirst(str_replace('_', ' ', $f)) : $t;
};

$listHref = '/vbcs/subjects/' . rawurlencode($subjectId) . '/commitments';
?>

<?= $this->section('content') ?>
    

    <p><a href="<?= esc($listHref, 'attr') ?>">&larr; <?= esc(lang(($cf)('backToList'))) ?></a></p>
    <h1><?= esc(lang(($cf)('heading'))) ?></h1>
    <div class="sub"><?= esc(lang(($cf)('sub'))) ?></div>

    <div class="cf-note"><?= esc(lang(($cf)('reminderNote'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <?php if ($causes === []): ?>
        <div class="empty"><?= esc(lang(($cf)('noActiveCauses'))) ?></div>
    <?php else: ?>
    <form class="cf-form" method="post" action="/vbcs/commitments">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <?php if ($subjectId !== ''): ?><input type="hidden" name="subject_id" value="<?= esc($subjectId, 'attr') ?>"><?php endif; ?>

        <div class="cf-field">
            <label for="cause_id"><?= esc(lang(($cf)('causeLabel'))) ?></label>
            <select id="cause_id" name="cause_id" required>
                <option value="" disabled<?= $curCause === '' ? ' selected' : '' ?>><?= esc(lang(($cf)('causeNone'))) ?></option>
                <?php foreach ($causes as $c): ?>
                    <?php $cid = (string) ($c['id'] ?? ''); ?>
                    <option value="<?= esc($cid, 'attr') ?>"<?= $curCause === $cid ? ' selected' : '' ?>><?= esc((string) ($c['name'] ?? $cid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="cf-grid">
            <div class="cf-field">
                <label for="amount_minor"><?= esc(lang(($cf)('amountLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('amountHint'))) ?></span></label>
                <input type="number" id="amount_minor" name="amount_minor" min="1" step="1" inputmode="numeric"
                       required value="<?= $ov('amount_minor') ?>">
            </div>
            <div class="cf-field">
                <label for="currency"><?= esc(lang(($cf)('currencyLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('currencyHint'))) ?></span></label>
                <input type="text" id="currency" name="currency" maxlength="3" minlength="3"
                       placeholder="GHS" value="<?= $ov('currency', 'GHS') ?>" style="text-transform:uppercase">
            </div>
        </div>

        <div class="cf-field">
            <label for="frequency"><?= esc(lang(($cf)('frequencyLabel'))) ?></label>
            <select id="frequency" name="frequency">
                <?php foreach ($frequencies as $f): ?>
                    <option value="<?= esc($f, 'attr') ?>"<?= $curFreq === $f ? ' selected' : '' ?>><?= esc($freqLabel($f)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="cf-actions">
            <button type="submit" class="cf-btn primary"><?= esc(lang(($cf)('save'))) ?></button>
            <a class="cf-btn ghost" href="<?= esc($listHref, 'attr') ?>"><?= esc(lang(($cf)('cancel'))) ?></a>
        </div>
    </form>
    <?php endif; ?>
<?= $this->endSection() ?>
