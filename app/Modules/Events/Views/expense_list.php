<?php
/**
 * EVENT expense list (GET /events/{id}/expenses/list) — the browser face of
 * ExpenseController::list, which otherwise rendered the generic admin console.
 * Lists every expense line for one event (optionally filtered by status), newest
 * first, showing description, category, allocation, requested/approved amounts and
 * status.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.expenseList.*') with
 * English fallback; the {0} count is interpolated via $li() with singular/plural
 * chosen in PHP; the status vocabulary is localized with a raw-value fallback.
 * Money is formatted from minor units; all figures are server data shown verbatim
 * & escaped.
 *
 * @var list<array<string,mixed>> $expenses expense rows for the event
 */
$expenses = $expenses ?? [];
$count    = count($expenses);

include __DIR__ . '/_locale.php';

$money = static function (array $r, string $key): string {
    $minor = $r[$key] ?? null;
    if ($minor === null || $minor === '') {
        return '—';
    }
    return number_format(((int) $minor) / 100, 2) . ' ' . (string) ($r['currency'] ?? '');
};
$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Events.expenseList.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Events.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.expenseList.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>

<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.expenseList.heading')) ?></h1>
        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Events.expenseList.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Events.expenseList.countOne' : 'Events.expenseList.count', (string) $count)) ?></p>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.expenseList.colDesc')) ?></th>
                    <th><?= esc(lang('Events.expenseList.colCategory')) ?></th>
                    <th><?= esc(lang('Events.expenseList.colAllocation')) ?></th>
                    <th class="num"><?= esc(lang('Events.expenseList.colRequested')) ?></th>
                    <th class="num"><?= esc(lang('Events.expenseList.colApproved')) ?></th>
                    <th><?= esc(lang('Events.expenseList.colStatus')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($expenses as $r): ?>
                        <?php $status = strtolower((string) ($r['status'] ?? '')); ?>
                        <tr>
                            <td><?= esc((string) ($r['description'] ?? '—')) ?></td>
                            <td><?= esc((string) ($r['category'] ?? '—')) ?></td>
                            <td><?= esc((string) ($r['allocation'] ?? '—')) ?></td>
                            <td class="num"><?= esc($money($r, 'amount_minor')) ?></td>
                            <td class="num"><?= esc($money($r, 'approved_amount_minor')) ?></td>
                            <td><span class="st <?= esc($status, 'attr') ?>"><?= esc($vstatus($status)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
