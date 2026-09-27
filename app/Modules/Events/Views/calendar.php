<?php
/**
 * Events CALENDAR (GET /events/calendar?month=YYYY-MM) — a month grid of the
 * org's events, complementing the flat events index. Days are laid out
 * Monday-first; each event chip links to its overview page. Prev/next/today are
 * plain GET links (no JS). ?group_id= narrows to one organizing group.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic) and $li() for {0} interpolation. Month + weekday names and
 * all copy come from lang('Events.calendar.*') with English fallback. The grid
 * is computed entirely in PHP (no ICU runtime dep). CSP-safe: no <script>, no
 * inline on* handlers, single style block.
 *
 * Event start times are stored UTC; the calendar buckets by the UTC date (the
 * same basis the rest of the module presents), keeping it dependency-free.
 *
 * @var ?string $group_id
 * @var int     $year
 * @var int     $month  1..12
 * @var list<array<string,mixed>> $events  events whose start falls in this month
 * @var list<array<string,mixed>> $birthdays windowed overlay chips (not event rows)
 */
$group_id  = $group_id ?? null;
$year      = (int) ($year ?? (int) gmdate('Y'));
$month     = (int) ($month ?? (int) gmdate('n'));
$events    = $events ?? [];
$birthdays = is_array($birthdays ?? null) ? $birthdays : [];

include __DIR__ . '/_locale.php';

// Localized month + weekday names (comma-joined lists, English fallback).
$monthNames = explode(',', (string) lang('Events.calendar.months'));
if (count($monthNames) !== 12) {
    $monthNames = explode(',', 'January,February,March,April,May,June,July,August,September,October,November,December');
}
$weekdays = explode(',', (string) lang('Events.calendar.weekdays'));
if (count($weekdays) !== 7) {
    $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
}
$monthLabel = ($monthNames[$month - 1] ?? '') . ' ' . $year;

// Bucket events by day-of-month (UTC date of starts_at).
$byDay = [];
foreach ($events as $e) {
    $starts = (string) ($e['starts_at'] ?? '');
    if ($starts === '') {
        continue;
    }
    $day = (int) substr($starts, 8, 2); // YYYY-MM-DD -> DD
    if ($day >= 1 && $day <= 31) {
        $byDay[$day][] = $e;
    }
}

$bdayByDay = [];
foreach ($birthdays as $b) {
    $day = (int) ($b['day'] ?? 0);
    if ($day >= 1 && $day <= 31) {
        $bdayByDay[$day][] = $b;
    }
}

// Grid maths (UTC, Monday-first). w=0 (Sun)..6 -> Monday-index 0..6.
$daysInMonth = (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
$firstW      = (int) gmdate('w', gmmktime(0, 0, 0, $month, 1, $year));
$firstMon    = ($firstW + 6) % 7; // days of leading blanks before day 1
$todayYmd    = gmdate('Y-m-d');

// Prev / next month for the nav links.
$prevY = $month === 1 ? $year - 1 : $year;
$prevM = $month === 1 ? 12 : $month - 1;
$nextY = $month === 12 ? $year + 1 : $year;
$nextM = $month === 12 ? 1 : $month + 1;
$gq    = static function (array $extra) use ($group_id): string {
    $qs = $extra;
    if ($group_id !== null && $group_id !== '') {
        $qs['group_id'] = (string) $group_id;
    }

    return $qs === [] ? '' : '?' . http_build_query($qs);
};
$statusColor = static fn (string $s): string => match ($s) {
    'published'               => '#4ade80',
    'cancelled'               => '#f87171',
    'completed'               => '#38bdf8',
    'completed_no_attendance' => '#fbbf24',
    'draft'                   => '#a5b4fc',
    default                   => '#94a3b8',
};
$MAX_CHIPS = 3;
?>

<?php ob_start(); ?>
<?= esc(lang('Events.calendar.title')) ?> — <?= esc($monthLabel) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: #818cf8">
        <h1><?= esc(lang('Events.calendar.title')) ?></h1>
        <p class="sub"><?= esc((string) ($settings['display_label'] ?? '') !== '' ? (string) $settings['display_label'] : lang('Events.calendar.sub')) ?></p>
        <div>
            <span class="scope"><?= $group_id === null || $group_id === '' ? esc(lang('Events.calendar.allMyGroups')) : esc($li('Events.analytics.groupScoped', (string) $group_id)) ?></span>
            <a class="scope" href="/events/analytics"><?= esc(lang('Events.analytics.title')) ?></a>
            <a class="scope" href="/events"><?= esc(lang('Events.calendar.listView')) ?></a>
            <a class="scope" href="/events/calendar.ics<?= esc($gq([]), 'attr') ?>"><?= esc(lang('Events.calendar.subscribe')) ?></a>
            <?php if ($can_configure && $group_id): ?>
                <a class="scope" href="/events/calendar/settings?group_id=<?= esc((string) $group_id, 'attr') ?>"><?= esc(lang('Events.calendar.settingsLink')) ?></a>
            <?php endif; ?>
        </div>
        <?php if ($picker !== []): ?>
        <form method="get" action="/events/calendar" class="navbar" style="gap:8px">
            <?php if (isset($year, $month)): ?>
                <input type="hidden" name="month" value="<?= esc(sprintf('%04d-%02d', $year, $month), 'attr') ?>">
            <?php endif; ?>
            <label for="cal-g" class="scope"><?= esc(lang('Events.calendar.fGroup')) ?></label>
            <select id="cal-g" name="group_id">
                <option value=""><?= esc(lang('Events.calendar.allMyGroups')) ?></option>
                <?php foreach ($picker as $g): ?>
                    <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>"<?= ((string) ($g['id'] ?? '') === (string) $group_id) ? ' selected' : '' ?>>
                        <?= esc((string) ($g['name'] ?? $g['id'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit"><?= esc(lang('Events.calendar.switchGroup')) ?></button>
        </form>
        <?php endif; ?>

        <div class="navbar">
            <span class="month"><?= esc($monthLabel) ?></span>
            <a class="btn" href="/events/calendar<?= esc($gq(['month' => sprintf('%04d-%02d', $prevY, $prevM)]), 'attr') ?>">&#8592; <?= esc(lang('Events.calendar.prev')) ?></a>
            <a class="btn" href="/events/calendar<?= esc($gq([]), 'attr') ?>"><?= esc(lang('Events.calendar.today')) ?></a>
            <a class="btn" href="/events/calendar<?= esc($gq(['month' => sprintf('%04d-%02d', $nextY, $nextM)]), 'attr') ?>"><?= esc(lang('Events.calendar.next')) ?> &#8594;</a>
        </div>

        <?php if ($events === [] && $birthdays === []): ?>
            <p class="empty"><?= esc(lang('Events.calendar.empty')) ?></p>
        <?php endif; ?>

        <div class="cal">
            <?php foreach ($weekdays as $wd): ?>
                <div class="dow"><?= esc($wd) ?></div>
            <?php endforeach; ?>

            <?php for ($b = 0; $b < $firstMon; $b++): ?>
                <div class="cell blank"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                <?php
                $cellYmd = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $isToday = $cellYmd === $todayYmd;
                $dayEvents = $byDay[$d] ?? [];
                ?>
                <div class="cell<?= $isToday ? ' today' : '' ?>">
                    <div class="daynum">
                        <span><?= esc((string) $d) ?></span>
                        <?php if ($isToday): ?><span class="td"><?= esc(lang('Events.calendar.today')) ?></span><?php endif; ?>
                    </div>
                    <?php foreach (array_slice($dayEvents, 0, $MAX_CHIPS) as $e): ?>
                        <?php $col = $statusColor((string) ($e['status'] ?? '')); $hm = substr((string) ($e['starts_at'] ?? ''), 11, 5); ?>
                        <a class="chip" style="border-inline-start-color:<?= esc($col, 'attr') ?>" href="/events/<?= esc((string) $e['id'], 'attr') ?>" title="<?= esc((string) $e['title'], 'attr') ?>">
                            <?php if ($hm !== ''): ?><span class="t"><?= esc($hm) ?></span> <?php endif; ?><?= esc((string) $e['title']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php foreach (($bdayByDay[$d] ?? []) as $b): ?>
                        <a class="chip" style="border-inline-start-color:#f9a8d4" href="/me/birthdays" title="<?= esc((string) ($b['display_name'] ?? ''), 'attr') ?>">
                            <?= esc((string) ($b['display_name'] ?? '')) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($dayEvents) > $MAX_CHIPS): ?>
                        <span class="more"><?= esc($li('Events.calendar.more', (string) (count($dayEvents) - $MAX_CHIPS))) ?></span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
