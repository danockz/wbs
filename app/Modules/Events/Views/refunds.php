<?php
/**
 * REFUNDS maker-checker console (GET /event-refunds/pending) — gap L2.
 *
 * Lists ticket-order refunds awaiting action and turns approve / reject / execute
 * into no-JS PRG forms:
 *   - approve → POST /event-refunds/{id}/approve
 *   - reject  → POST /event-refunds/{id}/reject
 *   - execute → POST /event-refunds/{id}/execute
 *
 * The maker-checker is enforced server-side (approver ≠ requester), so this view
 * simply surfaces the actions; the service rejects a self-approval. Every form
 * carries the `_csrf` field (WebCsrfFilter). SELF-CONTAINED page: includes
 * _locale.php for a locale-aware <html lang dir> (RTL for Arabic); copy via
 * lang('Events.refund.*').
 *
 * @var list<array<string,mixed>> $refunds event_order_refunds rows
 * @var string                    $csrf     webcsrf token
 */
$refunds = $refunds ?? [];
$csrf    = $csrf ?? '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$money = static function (int $minor, string $ccy): string {
    return $ccy . ' ' . number_format($minor / 100, 2);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.refund.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .pill.approved { background:#12275b; color:#bfdbfe; }


        button { background:#2563eb; border:0; border-radius:8px; color:#fff; padding:7px 13px; font-size:.82rem; cursor:pointer; }


        button.ok { background:#0f3d34; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
    <h1><?= esc(lang('Events.refund.heading')) ?></h1>
    <p class="sub"><?= esc(lang('Events.refund.sub')) ?></p>

    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($refunds === []): ?>
        <p class="empty"><?= esc(lang('Events.refund.noRefunds')) ?></p>
    <?php else: ?>
        <table>
            <thead><tr>
                <th><?= esc(lang('Events.refund.colOrder')) ?></th>
                <th class="num"><?= esc(lang('Events.refund.colAmount')) ?></th>
                <th><?= esc(lang('Events.refund.colStatus')) ?></th>
                <th><?= esc(lang('Events.refund.colSource')) ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($refunds as $r):
                $rid    = (string) ($r['id'] ?? '');
                $status = (string) ($r['status'] ?? 'requested');
                $src    = (string) ($r['source'] ?? 'manual');
                $srcLbl = (string) lang('Events.refund.' . ($src === 'event_cancel' ? 'sourceEventCancel' : 'sourceManual'));
            ?>
                <tr>
                    <td><span class="mono"><?= esc(substr((string) ($r['order_id'] ?? ''), 0, 8)) ?></span></td>
                    <td class="num"><?= esc($money((int) ($r['amount_minor'] ?? 0), (string) ($r['currency'] ?? ''))) ?></td>
                    <td><span class="pill <?= esc($status, 'attr') ?>"><?= esc(ucfirst($status)) ?></span></td>
                    <td><?= esc($srcLbl) ?></td>
                    <td>
                        <div class="actions">
                        <?php if ($status === 'requested'): ?>
                            <form class="inline" method="post" action="/event-refunds/<?= esc(rawurlencode($rid), 'attr') ?>/approve">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button class="ok" type="submit"><?= esc(lang('Events.refund.approveBtn')) ?></button>
                            </form>
                            <form class="inline" method="post" action="/event-refunds/<?= esc(rawurlencode($rid), 'attr') ?>/reject"
                                  onsubmit="return confirm('<?= esc(lang('Events.refund.rejectBtn'), 'attr') ?>?');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button class="danger" type="submit"><?= esc(lang('Events.refund.rejectBtn')) ?></button>
                            </form>
                        <?php elseif ($status === 'approved' || $status === 'failed'): ?>
                            <form class="inline" method="post" action="/event-refunds/<?= esc(rawurlencode($rid), 'attr') ?>/execute"
                                  onsubmit="return confirm('<?= esc(lang('Events.refund.executeBtn'), 'attr') ?>?');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button type="submit"><?= esc(lang('Events.refund.executeBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
