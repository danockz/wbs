<?php
/**
 * Cross-event ANALYTICS dashboard (GET /events/analytics) — the organizer's
 * birds-eye across the whole events programme, complementing the per-event
 * report. Renders programme totals, attendance & RSVP→check-in conversion,
 * ticket revenue, mode/status splits, and upcoming / filling-up lists.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic) and $li() for {0} interpolation. All copy via
 * lang('Events.analytics.*') with English fallback; every figure is computed
 * server-side (no ICU runtime dep). CSP-safe: no <script>, no inline on*
 * handlers, single style block. Money is minor units / 100.
 *
 * @var ?string $group_id
 * @var string  $now
 * @var array<string,int> $totals
 * @var array<string,mixed> $people
 * @var array{paid_orders:int,gross_minor:int,currency:string} $revenue
 * @var list<array{mode:string,count:int}> $by_mode
 * @var list<array{status:string,count:int}> $by_status
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $filling
 */
$group_id  = $group_id ?? null;
$totals    = $totals ?? [];
$people    = $people ?? [];
$revenue   = $revenue ?? ['paid_orders' => 0, 'gross_minor' => 0, 'currency' => 'GHS'];
$by_mode   = $by_mode ?? [];
$by_status = $by_status ?? [];
$upcoming  = $upcoming ?? [];
$filling   = $filling ?? [];

include __DIR__ . '/_locale.php';

$num   = static fn ($n): string => number_format((int) $n);
$money = static fn ($minor, $ccy): string => number_format(((int) $minor) / 100, 2) . ' ' . strtoupper((string) ($ccy ?: ''));
$enum  = static function (string $group, string $value): string {
    if ($value === '') {
        return '';
    }
    $label = lang('Events.' . $group . '.' . $value);

    return (is_string($label) && $label !== 'Events.' . $group . '.' . $value) ? $label : $value;
};
$statusColor = static fn (string $s): string => match ($s) {
    'published'               => '#4ade80',
    'cancelled'               => '#f87171',
    'completed'               => '#38bdf8',
    'completed_no_attendance' => '#fbbf24',
    'draft'                   => '#a5b4fc',
    default                   => '#94a3b8',
};
$fillColor = static function (?float $pct): string {
    if ($pct === null) {
        return '#475569';
    }
    if ($pct >= 90) {
        return '#f87171';
    }
    if ($pct >= 60) {
        return '#fbbf24';
    }

    return '#4ade80';
};
$modeTotal   = array_sum(array_map(static fn ($r) => (int) $r['count'], $by_mode));
$statusTotal = array_sum(array_map(static fn ($r) => (int) $r['count'], $by_status));
?>

<?php ob_start(); ?>
<?= esc(lang('Events.analytics.title')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1000px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size: .82rem; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin:30px 0 12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: #38bdf8">
        <h1><?= esc(lang('Events.analytics.title')) ?></h1>
        <p class="sub"><?= esc(lang('Events.analytics.sub')) ?></p>
        <div>
            <span class="scope">
                <?= $group_id === null || $group_id === ''
                    ? esc(lang('Events.analytics.orgWide'))
                    : esc($li('Events.analytics.groupScoped', (string) $group_id)) ?>
            </span>
            <a class="scope" href="/events/calendar"><?= esc(lang('Events.calendar.title')) ?></a>
            <a class="scope" href="/events"><?= esc(lang('Events.title')) ?></a>
        </div>

        <?php if ((int) ($totals['events'] ?? 0) === 0): ?>
            <p class="empty" style="margin-top:24px"><?= esc(lang('Events.analytics.empty')) ?></p>
        <?php else: ?>
            <h2><?= esc(lang('Events.analytics.secOverview')) ?></h2>
            <div class="grid">
                <div class="kpi"><div class="n"><?= esc($num($totals['events'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kTotal')) ?></div></div>
                <div class="kpi hl"><div class="n"><?= esc($num($totals['upcoming'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kUpcoming')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($totals['past'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kPast')) ?></div></div>
                <div class="kpi good"><div class="n"><?= esc($num($totals['published'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kPublished')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($totals['draft'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kDraft')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($totals['attended_events'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kAttendedEvents')) ?></div></div>
            </div>

            <h2><?= esc(lang('Events.analytics.secPeople')) ?></h2>
            <div class="grid">
                <div class="kpi"><div class="n"><?= esc($num($people['registrations'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kRegistrations')) ?></div></div>
                <div class="kpi hl"><div class="n"><?= esc($num($people['attendance'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kAttendance')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($people['unique_attendees'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kUnique')) ?></div></div>
                <div class="kpi good"><div class="n"><?= esc((string) ($people['conversion_pct'] ?? 0)) ?>%</div><div class="k"><?= esc(lang('Events.analytics.kConversion')) ?></div></div>
                <div class="kpi warn"><div class="n"><?= esc($num($people['no_show'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kNoShow')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($people['waitlisted'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kWaitlisted')) ?></div></div>
                <div class="kpi"><div class="n"><?= esc($num($people['follow_up_sourced'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kFollowUpSourced')) ?></div></div>
            </div>

            <?php if ((int) ($revenue['paid_orders'] ?? 0) > 0): ?>
                <h2><?= esc(lang('Events.analytics.secRevenue')) ?></h2>
                <div class="grid">
                    <div class="kpi good"><div class="n"><?= esc($money($revenue['gross_minor'] ?? 0, $revenue['currency'] ?? 'GHS')) ?></div><div class="k"><?= esc(lang('Events.analytics.kGross')) ?></div></div>
                    <div class="kpi"><div class="n"><?= esc($num($revenue['paid_orders'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.analytics.kPaidOrders')) ?></div></div>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:8px">
                <div>
                    <h2><?= esc(lang('Events.analytics.secStatus')) ?></h2>
                    <div class="split">
                        <?php foreach ($by_status as $r): $c = (int) $r['count']; $pct = $statusTotal > 0 ? round($c * 100 / $statusTotal) : 0; $col = $statusColor((string) $r['status']); ?>
                            <div class="srow">
                                <span class="lbl"><?= esc($enum('status', (string) $r['status'])) ?></span>
                                <span class="track"><i style="width:<?= esc((string) $pct, 'attr') ?>%;background:<?= esc($col, 'attr') ?>"></i></span>
                                <span class="val"><?= esc($num($c)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h2><?= esc(lang('Events.analytics.secMode')) ?></h2>
                    <div class="split">
                        <?php foreach ($by_mode as $r): $c = (int) $r['count']; $pct = $modeTotal > 0 ? round($c * 100 / $modeTotal) : 0; ?>
                            <div class="srow">
                                <span class="lbl"><?= esc($enum('mode', (string) $r['mode'])) ?></span>
                                <span class="track"><i style="width:<?= esc((string) $pct, 'attr') ?>%;background:#38bdf8"></i></span>
                                <span class="val"><?= esc($num($c)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <h2><?= esc(lang('Events.analytics.secUpcoming')) ?></h2>
            <?php if ($upcoming === []): ?>
                <p class="empty"><?= esc(lang('Events.analytics.emptyUpcoming')) ?></p>
            <?php else: ?>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.analytics.colEvent')) ?></th>
                        <th><?= esc(lang('Events.analytics.colStarts')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colRegistered')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colCapacity')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colFill')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($upcoming as $e): $fp = $e['fill_pct'] ?? null; ?>
                            <tr>
                                <td><a class="evlink" href="/events/<?= esc((string) $e['id'], 'attr') ?>"><?= esc((string) $e['title']) ?></a></td>
                                <td class="muted"><?= esc((string) $e['starts_at']) ?></td>
                                <td class="num"><?= esc($num($e['registered'] ?? 0)) ?></td>
                                <td class="num"><?= $e['capacity'] !== null ? esc($num($e['capacity'])) : '<span class="muted">' . esc(lang('Events.analytics.unlimited')) . '</span>' ?></td>
                                <td class="num"><?php if ($fp !== null): ?><span class="fillbadge" style="background:<?= esc($fillColor((float) $fp), 'attr') ?>22;color:<?= esc($fillColor((float) $fp), 'attr') ?>"><?= esc((string) $fp) ?>%</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($filling !== []): ?>
                <h2><?= esc(lang('Events.analytics.secFilling')) ?></h2>
                <table>
                    <thead><tr>
                        <th><?= esc(lang('Events.analytics.colEvent')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colRegistered')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colCapacity')) ?></th>
                        <th class="num"><?= esc(lang('Events.analytics.colFill')) ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($filling as $e): $fp = (float) ($e['fill_pct'] ?? 0); ?>
                            <tr>
                                <td><a class="evlink" href="/events/<?= esc((string) $e['id'], 'attr') ?>"><?= esc((string) $e['title']) ?></a></td>
                                <td class="num"><?= esc($num($e['registered'] ?? 0)) ?></td>
                                <td class="num"><?= esc($num($e['capacity'] ?? 0)) ?></td>
                                <td class="num"><span class="fillbadge" style="background:<?= esc($fillColor($fp), 'attr') ?>22;color:<?= esc($fillColor($fp), 'attr') ?>"><?= esc((string) $fp) ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
