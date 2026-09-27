<?php
/**
 * EXPENSE reconciliation (GET /events/{id}/expenses/reconcile) — the browser face
 * of ExpenseController::reconcile, which otherwise rendered the generic admin
 * console. Shows the budget-vs-committed reconciliation for one event: budget,
 * approved, reimbursed, committed and variance totals, plus a per-status
 * breakdown (count / requested / decided).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.reconcile.*') with English
 * fallback; the status vocabulary is localized with a raw-value fallback. Money is
 * formatted from minor units; all figures are server data shown verbatim & escaped.
 *
 * @var array<string,mixed> $recon reconciliation map from ExpenseService::reconcile
 */
$recon = $recon ?? [];

include __DIR__ . '/_locale.php';

$cur   = (string) ($recon['currency'] ?? 'GHS');
$money = static fn ($minor): string => $minor === null ? '—' : number_format(((int) $minor) / 100, 2);
$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Events.reconcile.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Events.')) ? $s : $value;
};
$byStatus = (array) ($recon['by_status'] ?? []);
$variance = $recon['variance_minor'] ?? null;
?>

<?php ob_start(); ?>
<?= esc(lang('Events.reconcile.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:16px; }


        h2 { font-size:.95rem; color:#86efac; margin:0 0 10px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.reconcile.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.reconcile.sub')) ?></p>

        <div class="card">
            <h2><?= esc(lang('Events.reconcile.totalsHeading')) ?></h2>
            <div class="row"><span class="k"><?= esc(lang('Events.reconcile.budget')) ?></span><span class="v"><?= esc($money($recon['budget_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.reconcile.approved')) ?></span><span class="v"><?= esc($money($recon['approved_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.reconcile.reimbursed')) ?></span><span class="v"><?= esc($money($recon['reimbursed_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.reconcile.committed')) ?></span><span class="v"><?= esc($money($recon['committed_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.reconcile.variance')) ?></span><span class="v <?= $variance === null ? '' : ((int) $variance < 0 ? 'neg' : 'pos') ?>"><?= esc($money($variance)) ?> <?= esc($cur) ?></span></div>
        </div>

        <div class="card">
            <h2><?= esc(lang('Events.reconcile.byStatusHeading')) ?></h2>
            <?php if ($byStatus === []): ?>
                <p class="empty"><?= esc(lang('Events.reconcile.empty')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.reconcile.colStatus')) ?></th>
                        <th class="num"><?= esc(lang('Events.reconcile.colCount')) ?></th>
                        <th class="num"><?= esc(lang('Events.reconcile.colRequested')) ?></th>
                        <th class="num"><?= esc(lang('Events.reconcile.colDecided')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($byStatus as $status => $agg): ?>
                            <tr>
                                <td><?= esc($vstatus((string) $status)) ?></td>
                                <td class="num"><?= esc(number_format((int) ($agg['count'] ?? 0))) ?></td>
                                <td class="num"><?= esc($money($agg['requested_minor'] ?? null)) ?></td>
                                <td class="num"><?= esc($money($agg['decided_minor'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
