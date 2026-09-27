<?php
/**
 * CATERING aggregates (GET /events/{id}/logistics/catering) — the browser face of
 * LogisticsController::cateringAggregates, which otherwise rendered the generic
 * admin console. Non-sensitive dietary headcounts safe for dashboards: one row
 * per diet label with its headcount, plus a total.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.catering.*') with English
 * fallback; diet labels and counts are server data shown verbatim & escaped.
 *
 * @var list<array<string,mixed>> $catering diet_label/headcount rows
 */
$catering = $catering ?? [];
$count    = count($catering);
$total    = 0;
foreach ($catering as $r) {
    $total += (int) ($r['headcount'] ?? 0);
}

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Events.catering.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        tfoot td { font-weight:700; color:#fde68a; border-bottom:0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.catering.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.catering.sub')) ?></p>
        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Events.catering.empty')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.catering.colDiet')) ?></th>
                    <th class="num"><?= esc(lang('Events.catering.colHeadcount')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($catering as $r): ?>
                        <tr>
                            <td><?= esc((string) ($r['diet_label'] ?? '—')) ?></td>
                            <td class="num"><?= esc(number_format((int) ($r['headcount'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td><?= esc(lang('Events.catering.total')) ?></td>
                    <td class="num"><?= esc(number_format($total)) ?></td>
                </tr></tfoot>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
