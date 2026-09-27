<?= $this->extend('layouts/app') ?>

<?php
/**
 * Manual / in-kind contributions awaiting approval
 * (GET /contributions/manual/pending) — the browser face of
 * VbcsController::pendingManual, which otherwise rendered the generic admin
 * console. This is the maker-checker queue: each submitted record shows its type
 * (cash/cheque/bank_transfer/in_kind), the stated value, currency, valuation
 * method, received date, custodian, evidence reference and who submitted it, so
 * a checker (who must differ from the submitter) can approve or reject.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.manualPending.*') with English fallback; the {0} count is
 * interpolated and the type vocabulary localizes with a raw-value fallback.
 * Amounts are minor units via the shared _money helper.
 *
 * @var list<array<string,mixed>> $result manual_contribution_records rows (status=submitted)
 * @var string                    $title
 */
include __DIR__ . '/_money.php';
$rows  = is_array($result) ? $result : [];
$count = count($rows);
$csrf  = $csrf ?? '';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Presentation helpers: time_ago (submission age) + redact_id (submitter/custodian
// ids) are procedural view helpers registered by BaseController::$helpers.
// Guard-load so this view still renders headless in the test harness.
if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$vtype = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') { return '—'; }
    $s = lang('Contributions.manualPending.type.' . $value);
    return (is_string($s) && ! str_contains($s, 'Contributions.')) ? $s : $value;
};
?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Contributions.manualPending.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Contributions.manualPending.countOne') : lang('Contributions.manualPending.count'))) ?></div>
    <p style="margin:12px 0 18px;"><a href="/vbcs/manual/new" style="display:inline-block;background:#0d9488;color:#fff;border-radius:9px;padding:9px 16px;font-weight:700;font-size:.85rem;text-decoration:none;"><?= esc(lang('Contributions.manualForm.newManual')) ?></a></p>

    <?php if ($flashOk !== ''): ?><div class="mp-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="mp-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="empty"><?= esc(lang('Contributions.manualPending.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($rows as $m): ?>
            <?php
            $rid = rawurlencode((string) ($m['id'] ?? ''));
            $age = time_ago($m['created_at'] ?? ($m['received_date'] ?? null), '');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($vtype((string) ($m['type'] ?? ''))) ?></span>
                    <span class="v" style="font-size:1.05rem;font-weight:800;"><?= $money((int) ($m['stated_value_minor'] ?? 0), $m['currency'] ?? null) ?></span>
                </div>
                <div class="meta">
                    <?php if (! empty($m['valuation_method'])): ?><?= esc(lang('Contributions.manualPending.valuation')) ?>: <?= esc((string) $m['valuation_method']) ?><?php endif; ?>
                    <?php if (! empty($m['received_date'])): ?> · <?= esc(lang('Contributions.manualPending.received')) ?> <?= esc((string) $m['received_date']) ?><?php endif; ?>
                    <?php if ($age !== ''): ?> · <span title="<?= esc((string) ($m['created_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php endif; ?>
                </div>
                <div class="counts">
                    <?php if (! empty($m['custodian_id'])): ?><?= esc(lang('Contributions.manualPending.custodian')) ?>: <?= esc(redact_id((string) $m['custodian_id'])) ?> · <?php endif; ?>
                    <?php if (! empty($m['submitted_by'])): ?><?= esc(lang('Contributions.manualPending.submittedBy')) ?>: <?= esc(redact_id((string) $m['submitted_by'])) ?><?php endif; ?>
                    <?php if (! empty($m['evidence_ref'])): ?> · <?= esc(lang('Contributions.manualPending.evidence')) ?>: <?= esc((string) $m['evidence_ref']) ?><?php endif; ?>
                </div>
                <?php if ($rid !== ''): ?>
                <div class="mp-acts">
                    <form method="post" action="/vbcs/manual/<?= esc($rid, 'attr') ?>/approve">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="fld">
                            <label for="uid-<?= esc($rid, 'attr') ?>"><?= esc(lang('Contributions.manualPending.attributeLabel')) ?></label>
                            <input id="uid-<?= esc($rid, 'attr') ?>" name="user_id" placeholder="<?= esc(lang('Contributions.manualPending.attributePh'), 'attr') ?>">
                        </div>
                        <button type="submit" class="mp-btn approve"><?= esc(lang('Contributions.manualPending.approve')) ?></button>
                    </form>
                    <form method="post" action="/vbcs/manual/<?= esc($rid, 'attr') ?>/reject">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="fld">
                            <label for="rn-<?= esc($rid, 'attr') ?>"><?= esc(lang('Contributions.manualPending.reasonLabel')) ?></label>
                            <input id="rn-<?= esc($rid, 'attr') ?>" name="reason">
                        </div>
                        <button type="submit" class="mp-btn reject"><?= esc(lang('Contributions.manualPending.reject')) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
