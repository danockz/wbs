<?= $this->extend('layouts/app') ?>

<?php
/**
 * Cause create/edit form (GET /causes/new and /causes/{id}/edit) — the browser
 * face of CauseController::createForm / editForm, and the write side of the
 * cause directory's "New cause" / "Edit" links (previously the module was
 * create-only via the JSON API).
 *
 * Posts to POST /causes (create) or POST /causes/{id} (update), both
 * webcsrf-guarded. On success the controller redirects (PRG) to the cause
 * detail; on failure it re-renders with $error + the submitted values. Money
 * targets are captured in MAJOR units here and converted to integer MINOR units
 * before submit is NOT done client-side — the field posts minor units directly
 * (see hint) to stay consistent with the ledger.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.causes.form.*') with English fallback.
 *
 * @var string               $csrf
 * @var string               $mode  'create' | 'edit'
 * @var array<string,mixed>  $cause current/submitted values
 * @var string               $error
 * @var string               $title
 */
$csrf  = $csrf ?? '';
$mode  = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$cause = is_array($cause ?? null) ? $cause : [];
$error = $error ?? '';

$isEdit = $mode === 'edit';
$id     = (string) ($cause['id'] ?? '');
$cf     = static fn (string $k): string => 'Contributions.causes.form.' . $k;
$title  = $title ?? lang($isEdit ? ($cf)('metaTitleEdit') : ($cf)('metaTitleNew'));

$ov = static function (string $k, string $default = '') use ($cause): string {
    $v = $cause[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

$visibilities = ['group', 'public', 'private'];
$vocab = static function (string $group, string $value) use ($cf): string {
    if ($value === '') {
        return '';
    }
    $s = lang('Contributions.causes.' . $group . '.' . $value);

    return str_contains($s, 'Contributions.') ? ucfirst($value) : $s;
};

$curVis = (string) ($cause['visibility'] ?? 'group');
$action = $isEdit ? '/causes/' . rawurlencode($id) : '/causes';
?>

<?= $this->section('content') ?>
    

    <p><a href="/causes">&larr; <?= esc(lang(($cf)('backToList'))) ?></a></p>
    <h1><?= esc(lang($isEdit ? ($cf)('headingEdit') : ($cf)('headingNew'))) ?></h1>
    <div class="sub"><?= esc(lang(($cf)('sub'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <form class="cf-form" method="post" action="<?= esc($action, 'attr') ?>">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

        <div class="cf-field full">
            <label for="name"><?= esc(lang(($cf)('nameLabel'))) ?></label>
            <input type="text" id="name" name="name" required maxlength="200"
                   placeholder="<?= esc(lang(($cf)('namePh')), 'attr') ?>" value="<?= $ov('name') ?>">
        </div>

        <div class="cf-field full">
            <label for="purpose"><?= esc(lang(($cf)('purposeLabel'))) ?></label>
            <textarea id="purpose" name="purpose" maxlength="2000"><?= $ov('purpose') ?></textarea>
        </div>

        <div class="cf-grid">
            <div class="cf-field">
                <label for="visibility"><?= esc(lang(($cf)('visibilityLabel'))) ?></label>
                <select id="visibility" name="visibility">
                    <?php foreach ($visibilities as $v): ?>
                        <option value="<?= esc($v, 'attr') ?>"<?= $curVis === $v ? ' selected' : '' ?>><?= esc($vocab('visibility', $v)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-field">
                <label for="currency"><?= esc(lang(($cf)('currencyLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('currencyHint'))) ?></span></label>
                <input type="text" id="currency" name="currency" maxlength="3" minlength="3"
                       placeholder="GHS" value="<?= $ov('currency', 'GHS') ?>" style="text-transform:uppercase">
            </div>
        </div>

        <div class="cf-grid">
            <div class="cf-field">
                <label for="target_minor"><?= esc(lang(($cf)('targetMinorLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('targetMinorHint'))) ?></span></label>
                <input type="number" id="target_minor" name="target_minor" min="0" inputmode="numeric" value="<?= $ov('target_minor') ?>">
            </div>
            <div class="cf-field">
                <label for="target_count"><?= esc(lang(($cf)('targetCountLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('targetCountHint'))) ?></span></label>
                <input type="number" id="target_count" name="target_count" min="0" inputmode="numeric" value="<?= $ov('target_count') ?>">
            </div>
        </div>
        <?php $curShow = (string) ($ov('show_target', '1') === '' ? '1' : $ov('show_target', '1')); ?>
        <div class="cf-grid">
            <div class="cf-field">
                <label for="show_target"><?= esc(lang(($cf)('showTargetLabel'))) ?>
                    <span class="hint"><?= esc(lang(($cf)('showTargetHint'))) ?></span></label>
                <select id="show_target" name="show_target">
                    <option value="1"<?= $curShow !== '0' ? ' selected' : '' ?>><?= esc(lang(($cf)('showTargetYes'))) ?></option>
                    <option value="0"<?= $curShow === '0' ? ' selected' : '' ?>><?= esc(lang(($cf)('showTargetNo'))) ?></option>
                </select>
            </div>
        </div>

        <div class="cf-grid">
            <div class="cf-field">
                <label for="starts_at"><?= esc(lang(($cf)('startsAtLabel'))) ?></label>
                <input type="datetime-local" id="starts_at" name="starts_at" value="<?= $ov('starts_at') ?>">
            </div>
            <div class="cf-field">
                <label for="ends_at"><?= esc(lang(($cf)('endsAtLabel'))) ?></label>
                <input type="datetime-local" id="ends_at" name="ends_at" value="<?= $ov('ends_at') ?>">
            </div>
        </div>

        <div class="cf-actions">
            <button type="submit" class="cf-btn primary"><?= esc(lang($isEdit ? ($cf)('saveEdit') : ($cf)('saveNew'))) ?></button>
            <a class="cf-btn ghost" href="/causes"><?= esc(lang(($cf)('cancel'))) ?></a>
        </div>
    </form>
<?= $this->endSection() ?>
