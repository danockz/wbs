<?= $this->extend('layouts/app') ?>

<?php
/**
 * Refund requests awaiting a decision (GET /contributions/refunds/pending) — the
 * browser face of RefundController::pending, which otherwise rendered the generic
 * admin console. This is the maker-checker queue for the refund workflow
 * (request -> approve -> execute):
 *   - a `requested` row shows an APPROVE control (approver must differ from the
 *     requester — the service enforces SoD and returns 403 on violation);
 *   - an `approved` row shows an EXECUTE control (optional provider + provider
 *     refund id), which posts the compensating ledger entry and refunds.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Contributions.refundPending.*') with English fallback; the {0} count is
 * interpolated and the status vocabulary localizes with a raw-value fallback.
 * Amounts are minor units via the shared _money helper. Requester/donor ids are
 * redacted. Each action form carries the `_csrf` field for the webcsrf check.
 *
 * @var list<array<string,mixed>> $result refund_requests rows (status requested|approved)
 * @var string                    $title
 * @var string                    $csrf   webcsrf token for the inline forms
 */
include __DIR__ . '/_money.php';
$rows  = is_array($result) ? $result : [];
$count = count($rows);
$csrf  = $csrf ?? '';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Contributions.refundPending.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Contributions.')) ? $s : $value;
};
?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Contributions.refundPending.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Contributions.refundPending.countOne') : lang('Contributions.refundPending.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="rf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="rf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="empty"><?= esc(lang('Contributions.refundPending.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($rows as $r): ?>
            <?php
            $rid    = rawurlencode((string) ($r['id'] ?? ''));
            $status = strtolower((string) ($r['status'] ?? ''));
            $age    = time_ago($r['created_at'] ?? null, '');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><span class="rf-pill <?= esc($status, 'attr') ?>"><?= esc($vstatus($status)) ?></span></span>
                    <span class="v" style="font-size:1.05rem;font-weight:800;"><?= $money((int) ($r['amount_minor'] ?? 0), $r['currency'] ?? null) ?></span>
                </div>
                <div class="meta">
                    <?php if (! empty($r['contribution_amount_minor'])): ?><?= esc(lang('Contributions.refundPending.ofContribution')) ?>: <?= $money((int) $r['contribution_amount_minor'], $r['currency'] ?? null) ?><?php endif; ?>
                    <?php if ($age !== ''): ?> · <span title="<?= esc((string) ($r['created_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php endif; ?>
                </div>
                <div class="counts">
                    <?php if (! empty($r['requested_by'])): ?><?= esc(lang('Contributions.refundPending.requestedBy')) ?>: <?= esc(redact_id((string) $r['requested_by'])) ?><?php endif; ?>
                    <?php if (! empty($r['approved_by'])): ?> · <?= esc(lang('Contributions.refundPending.approvedBy')) ?>: <?= esc(redact_id((string) $r['approved_by'])) ?><?php endif; ?>
                    <?php if (! empty($r['donor_id'])): ?> · <?= esc(lang('Contributions.refundPending.donor')) ?>: <?= esc(redact_id((string) $r['donor_id'])) ?><?php endif; ?>
                    <?php if (! empty($r['reason'])): ?> · <?= esc(lang('Contributions.refundPending.reason')) ?>: <?= esc((string) $r['reason']) ?><?php endif; ?>
                </div>
                <?php if ($rid !== ''): ?>
                <div class="rf-acts">
                    <?php if ($status === 'requested'): ?>
                        <form method="post" action="/contributions/refunds/<?= esc($rid, 'attr') ?>/approve"
                              onsubmit="return confirm('<?= esc(lang('Contributions.refundPending.approveConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <button type="submit" class="rf-btn approve"><?= esc(lang('Contributions.refundPending.approve')) ?></button>
                        </form>
                        <span class="counts" style="margin:0;"><?= esc(lang('Contributions.refundPending.sodNote')) ?></span>
                    <?php elseif ($status === 'approved'): ?>
                        <form method="post" action="/contributions/refunds/<?= esc($rid, 'attr') ?>/execute"
                              onsubmit="return confirm('<?= esc(lang('Contributions.refundPending.executeConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div class="fld">
                                <label for="pv-<?= esc($rid, 'attr') ?>"><?= esc(lang('Contributions.refundPending.providerLabel')) ?></label>
                                <input id="pv-<?= esc($rid, 'attr') ?>" name="provider" placeholder="<?= esc(lang('Contributions.refundPending.providerPh'), 'attr') ?>">
                            </div>
                            <div class="fld">
                                <label for="pr-<?= esc($rid, 'attr') ?>"><?= esc(lang('Contributions.refundPending.providerRefLabel')) ?></label>
                                <input id="pr-<?= esc($rid, 'attr') ?>" name="provider_refund_id">
                            </div>
                            <button type="submit" class="rf-btn execute"><?= esc(lang('Contributions.refundPending.execute')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
