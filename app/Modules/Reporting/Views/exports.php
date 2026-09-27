<?php
/**
 * EXPORT HISTORY page (GET /reports/export) — the browser face of
 * DashboardController::exports, and the landing page for the Reports → Exports
 * menu item (previously a 404). Lists requested report exports with their format,
 * status, row count and expiry; ready exports link to the existing download
 * route (which re-checks authorization in the service).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Reporting.exports.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with PHP singular/plural. `status` is a fixed
 * vocabulary localized with a raw-value fallback; report key/format/rows are
 * server data shown verbatim. NO artifact/storage keys are present in the payload.
 *
 * The page also carries the WRITE form: request a new export (report + format).
 * It POSTs to the webcsrf-guarded /reports/exports route; the controller PRG-
 * redirects back here with a localized flash. No-JS friendly.
 *
 * @var list<array<string,mixed>> $exports
 * @var string                    $csrf
 */
$exports = $exports ?? [];
$count   = count($exports);
$csrf    = $csrf ?? '';

include __DIR__ . '/_locale.php';

$reportKeys = ['wbs_funnel'];
$formats    = ['csv', 'xlsx'];
$flashOk    = function_exists('session') ? session('success') : null;
$flashErr   = function_exists('session') ? session('error') : null;
$reportLbl  = static function (string $k): string {
    if ($k === '') {
        return '';
    }
    $v = lang('Reporting.exports.report.' . $k);

    return $v === 'Reporting.exports.report.' . $k ? ucwords(str_replace('_', ' ', $k)) : $v;
};

$statusColor = static fn (string $s): string => match ($s) {
    'ready'   => '#22c55e',
    'running' => '#38bdf8',
    'queued'  => '#f59e0b',
    'failed'  => '#ef4444',
    'expired' => '#94a3b8',
    default   => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Reporting.exports.status.' . $s);

    return $v === 'Reporting.exports.status.' . $s ? ucfirst($s) : $v;
};
$fmtDate = static function (mixed $raw): string {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i', $ts);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Reporting.exports.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        select:focus { outline:none; border-color:#fbbf24; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Reporting.exports.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Reporting.exports.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <!-- Request an export -->
        <details class="card">
            <summary><?= esc(lang('Reporting.exports.requestHeading')) ?></summary>
            <form class="form" method="post" action="/reports/exports">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label><?= esc(lang('Reporting.exports.reportLabel')) ?></label>
                    <select name="report_key" required>
                        <?php foreach ($reportKeys as $rk): ?>
                            <option value="<?= esc($rk, 'attr') ?>"><?= esc($reportLbl($rk)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Reporting.exports.formatLabel')) ?></label>
                    <select name="format" required>
                        <?php foreach ($formats as $fmt): ?>
                            <option value="<?= esc($fmt, 'attr') ?>"><?= esc(strtoupper($fmt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="full">
                    <button class="btn" type="submit"><?= esc(lang('Reporting.exports.requestBtn')) ?></button>
                </div>
            </form>
        </details>

        <?php if ($exports === []): ?>
            <p class="empty"><?= esc(lang('Reporting.exports.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Reporting.exports.countOne' : 'Reporting.exports.count', (string) $count)) ?></p>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Reporting.exports.colReport')) ?></th>
                    <th><?= esc(lang('Reporting.exports.colStatus')) ?></th>
                    <th class="num"><?= esc(lang('Reporting.exports.colRows')) ?></th>
                    <th><?= esc(lang('Reporting.exports.colRequested')) ?></th>
                    <th><?= esc(lang('Reporting.exports.colExpires')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($exports as $e): ?>
                        <?php
                        $id      = (string) ($e['id'] ?? '');
                        $report  = (string) ($e['report_key'] ?? '');
                        $format  = strtoupper((string) ($e['format'] ?? ''));
                        $status  = (string) ($e['status'] ?? '');
                        $rows    = $e['row_count'] ?? null;
                        $req     = $fmtDate($e['created_at'] ?? '');
                        $exp     = $fmtDate($e['expires_at'] ?? '');
                        $sColor  = $statusColor($status);
                        ?>
                        <tr>
                            <td>
                                <span class="report"><?= esc($report) ?></span>
                                <?php if ($format !== ''): ?> <span class="fmt"><?= esc($format) ?></span><?php endif; ?>
                                <?php if ($status === 'ready' && $id !== ''): ?>
                                    <div><a class="dl" href="<?= esc(base_url('reports/exports/' . $id . '/download'), 'attr') ?>"><?= esc(lang('Reporting.exports.download')) ?></a></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span></td>
                            <td class="num"><?= $rows === null ? '<span class="muted">' . esc(lang('Reporting.exports.noRows')) . '</span>' : esc((string) $rows) ?></td>
                            <td class="when"><?= $req === '' ? '<span class="muted">—</span>' : esc($req) ?></td>
                            <td class="when"><?= $exp === '' ? '<span class="muted">' . esc(lang('Reporting.exports.noExpiry')) . '</span>' : esc($exp) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
