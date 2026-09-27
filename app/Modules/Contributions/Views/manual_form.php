<?= $this->extend('layouts/app') ?>

<?php
/**
 * Manual / in-kind giving CAPTURE form (GET /vbcs/manual/new) — the browser face
 * of VbcsController::submitManualForm and the maker side of the manual-giving
 * maker-checker. Previously the maker step could only be posted as JSON; there
 * was no capture form and the POST route was not webcsrf-guarded.
 *
 * Posts to POST /vbcs/manual (webcsrf-guarded). On success the controller PRGs to
 * the pending queue with a localized flash; on failure it re-renders here with
 * $error + the submitted values. What is submitted here MUST be approved by a
 * DIFFERENT staff member (segregation of duties) before it counts.
 *
 * The cause is chosen from the org's ACTIVE causes (a <select>, not free text).
 * Type is a fixed vocabulary (cash/cheque/bank_transfer/in_kind). Because the
 * page is no-JS, the in-kind category + hours fields are always visible with
 * hints explaining they apply only to in-kind gifts; server-side valuation
 * enforces which value/hours are required per type/category.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.manualForm.*') with English fallback. Amounts are integer
 * MINOR units to stay consistent with the ledger.
 *
 * @var string                    $csrf
 * @var list<array<string,mixed>> $causes      active causes for the picker
 * @var list<string>              $types       fixed type vocabulary
 * @var list<string>              $categories  in-kind category vocabulary
 * @var array<string,mixed>       $record      submitted values (on re-render)
 * @var string                    $error
 * @var string                    $title
 */
$csrf       = $csrf ?? '';
$causes     = is_array($causes ?? null) ? $causes : [];
$types      = is_array($types ?? null) ? $types : ['cash', 'cheque', 'bank_transfer', 'in_kind'];
$categories = is_array($categories ?? null) ? $categories : ['goods', 'services', 'time'];
$record     = is_array($record ?? null) ? $record : [];
$error      = (string) ($error ?? '');

$mf    = static fn (string $k): string => 'Contributions.manualForm.' . $k;
$title = $title ?? lang(($mf)('metaTitle'));

$ov = static function (string $k, string $default = '') use ($record): string {
    $v = $record[$k] ?? $default;
    if ($v === null) {
        $v = '';
    }

    return htmlspecialchars((string) $v, ENT_QUOTES);
};

$curCause = (string) ($record['cause_id'] ?? '');
$curType  = (string) ($record['type'] ?? 'cash');
$curCat   = (string) ($record['in_kind_category'] ?? '');

$typeLabel = static function (string $t) use ($mf): string {
    return match ($t) {
        'cash'          => lang(($mf)('typeCash')),
        'cheque'        => lang(($mf)('typeCheque')),
        'bank_transfer' => lang(($mf)('typeBankTransfer')),
        'in_kind'       => lang(($mf)('typeInKind')),
        default         => ucfirst(str_replace('_', ' ', $t)),
    };
};
$catLabel = static function (string $c) use ($mf): string {
    return match ($c) {
        'goods'    => lang(($mf)('catGoods')),
        'services' => lang(($mf)('catServices')),
        'time'     => lang(($mf)('catTime')),
        default    => ucfirst($c),
    };
};

$pendingHref = '/vbcs/manual/pending';
?>

<?= $this->section('content') ?>
    

    <p><a href="<?= esc($pendingHref, 'attr') ?>">&larr; <?= esc(lang(($mf)('backToPending'))) ?></a></p>
    <h1><?= esc(lang(($mf)('heading'))) ?></h1>
    <div class="sub"><?= esc(lang(($mf)('sub'))) ?></div>

    <div class="mf-note"><?= esc(lang(($mf)('reviewNote'))) ?></div>

    <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

    <?php if ($causes === []): ?>
        <div class="empty"><?= esc(lang(($mf)('noActiveCauses'))) ?></div>
    <?php else: ?>
    <form class="mf-form" method="post" action="/vbcs/manual">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

        <div class="mf-field">
            <label for="cause_id"><?= esc(lang(($mf)('causeLabel'))) ?></label>
            <select id="cause_id" name="cause_id" required>
                <option value="" disabled<?= $curCause === '' ? ' selected' : '' ?>><?= esc(lang(($mf)('causeNone'))) ?></option>
                <?php foreach ($causes as $c): ?>
                    <?php $cid = (string) ($c['id'] ?? ''); ?>
                    <option value="<?= esc($cid, 'attr') ?>"<?= $curCause === $cid ? ' selected' : '' ?>><?= esc((string) ($c['name'] ?? $cid)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mf-grid">
            <div class="mf-field">
                <label for="type"><?= esc(lang(($mf)('typeLabel'))) ?></label>
                <select id="type" name="type" required>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= esc($t, 'attr') ?>"<?= $curType === $t ? ' selected' : '' ?>><?= esc($typeLabel($t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mf-field">
                <label for="currency"><?= esc(lang(($mf)('currencyLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('currencyHint'))) ?></span></label>
                <input type="text" id="currency" name="currency" maxlength="3" minlength="3"
                       placeholder="GHS" value="<?= $ov('currency', 'GHS') ?>" style="text-transform:uppercase">
            </div>
        </div>

        <div class="mf-grid">
            <div class="mf-field">
                <label for="stated_value_minor"><?= esc(lang(($mf)('amountLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('amountHint'))) ?></span></label>
                <input type="number" id="stated_value_minor" name="stated_value_minor" min="0" step="1" inputmode="numeric"
                       value="<?= $ov('stated_value_minor') ?>">
            </div>
            <div class="mf-field">
                <label for="received_date"><?= esc(lang(($mf)('receivedLabel'))) ?></label>
                <input type="date" id="received_date" name="received_date" value="<?= $ov('received_date') ?>">
            </div>
        </div>

        <div class="mf-sec"><?= esc(lang(($mf)('typeInKind'))) ?></div>

        <div class="mf-grid">
            <div class="mf-field">
                <label for="in_kind_category"><?= esc(lang(($mf)('categoryLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('categoryHint'))) ?></span></label>
                <select id="in_kind_category" name="in_kind_category">
                    <option value=""<?= $curCat === '' ? ' selected' : '' ?>>—</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= esc($cat, 'attr') ?>"<?= $curCat === $cat ? ' selected' : '' ?>><?= esc($catLabel($cat)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mf-field">
                <label for="in_kind_hours"><?= esc(lang(($mf)('hoursLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('hoursHint'))) ?></span></label>
                <input type="number" id="in_kind_hours" name="in_kind_hours" min="0" step="0.25" inputmode="decimal"
                       value="<?= $ov('in_kind_hours') ?>">
            </div>
        </div>

        <div class="mf-sec"><?= esc(lang(($mf)('valuationLabel'))) ?></div>

        <div class="mf-field">
            <label for="valuation_method"><?= esc(lang(($mf)('valuationLabel'))) ?>
                <span class="hint"><?= esc(lang(($mf)('valuationHint'))) ?></span></label>
            <input type="text" id="valuation_method" name="valuation_method" maxlength="120" value="<?= $ov('valuation_method') ?>">
        </div>

        <div class="mf-grid">
            <div class="mf-field">
                <label for="custodian_id"><?= esc(lang(($mf)('custodianLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('custodianHint'))) ?></span></label>
                <input type="text" id="custodian_id" name="custodian_id" maxlength="64" value="<?= $ov('custodian_id') ?>">
            </div>
            <div class="mf-field">
                <label for="evidence_ref"><?= esc(lang(($mf)('evidenceLabel'))) ?>
                    <span class="hint"><?= esc(lang(($mf)('evidenceHint'))) ?></span></label>
                <input type="text" id="evidence_ref" name="evidence_ref" maxlength="190" value="<?= $ov('evidence_ref') ?>">
            </div>
        </div>

        <div class="mf-actions">
            <button type="submit" class="mf-btn primary"><?= esc(lang(($mf)('save'))) ?></button>
            <a class="mf-btn ghost" href="<?= esc($pendingHref, 'attr') ?>"><?= esc(lang(($mf)('cancel'))) ?></a>
        </div>
    </form>
    <?php endif; ?>
<?= $this->endSection() ?>
