<?php
/**
 * "MY EVENTS" attendee hub (GET /events/mine) — the signed-in member's own
 * events across the module: registrations, attendance, issued certificates and
 * paid tickets, split upcoming vs past. Complements the organizer surfaces
 * (analytics, calendar) with a member-self view. Always scoped to the session
 * user server-side (never a body param).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic) and $li() for {0} interpolation. All copy via
 * lang('Events.myEvents.*') with English fallback; enum labels reuse
 * Events.status.* / Events.mode.* with raw-value fallback. CSP-safe: no
 * <script>, no inline on* handlers, single style block.
 *
 * @var string $user_id
 * @var array<string,int> $counts
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $past
 */
$user_id  = $user_id ?? '';
$counts   = $counts ?? [];
$upcoming = $upcoming ?? [];
$past     = $past ?? [];
$feed_url = $feed_url ?? '';

include __DIR__ . '/_locale.php';

$num  = static fn ($n): string => number_format((int) $n);
$enum = static function (string $group, string $value): string {
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

$renderRow = static function (array $e) use ($enum, $statusColor, $num, $li): string {
    ob_start();
    $col      = $statusColor((string) ($e['status'] ?? ''));
    $attended = (bool) ($e['attended'] ?? false);
    $reg      = (string) ($e['my_registration'] ?? '');
    $cert     = $e['certificate'] ?? null;
    $tickets  = (int) ($e['tickets'] ?? 0);
    ?>
    <article class="card">
        <div class="cardhead">
            <a class="etitle" href="/events/<?= esc((string) $e['id'], 'attr') ?>"><?= esc((string) ($e['title'] ?? '')) ?></a>
            <span class="pill" style="background:<?= esc($statusColor((string) ($e['status'] ?? '')), 'attr') ?>22;color:<?= esc($col, 'attr') ?>"><?= esc($enum('status', (string) ($e['status'] ?? ''))) ?></span>
        </div>
        <div class="meta">
            <?php if (($e['starts_at'] ?? '') !== ''): ?><span class="m">&#128197; <?= esc((string) $e['starts_at']) ?></span><?php endif; ?>
            <?php if (($e['mode'] ?? '') !== ''): ?><span class="m"><?= esc($enum('mode', (string) $e['mode'])) ?></span><?php endif; ?>
        </div>
        <div class="tags">
            <?php if ($attended): ?>
                <span class="tag good">&#10003; <?= esc(lang('Events.myEvents.attended')) ?></span>
            <?php elseif ($reg === 'waitlisted'): ?>
                <span class="tag warn"><?= esc(lang('Events.myEvents.waitlisted')) ?></span>
            <?php elseif ($reg !== ''): ?>
                <span class="tag"><?= esc(lang('Events.myEvents.registered')) ?></span>
            <?php endif; ?>
            <?php if ($tickets > 0): ?>
                <span class="tag">&#127903; <?= esc($li('Events.myEvents.ticketsHeld', (string) $tickets)) ?></span>
            <?php endif; ?>
            <?php if (is_array($cert) && (string) ($cert['status'] ?? '') === 'issued'): ?>
                <?php $vid = (string) ($cert['verification_id'] ?? ''); ?>
                <?php if ($vid !== ''): ?>
                    <a class="tag cert" href="/certificates/verify/<?= esc($vid, 'attr') ?>">&#127894; <?= esc(lang('Events.myEvents.viewCertificate')) ?></a>
                <?php else: ?>
                    <span class="tag cert">&#127894; <?= esc(lang('Events.myEvents.certificate')) ?></span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.myEvents.title')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size: .82rem; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8; margin:30px 0 12px; }


        .links a { display:inline-block; font-size:.72rem; text-transform:uppercase; letter-spacing:.06em;
            color:#86efac; text-decoration:none; border:1px solid #22c55e55; border-radius:999px; padding:4px 12px; margin-inline-end:6px; }


        .links a:hover { color:#dcfce7; border-color:#4ade80; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:10px; }


        .meta { display:flex; flex-wrap:wrap; gap:12px; color:#94a3b8; font-size:.82rem; margin:8px 0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.myEvents.title')) ?></h1>
        <p class="sub"><?= esc(lang('Events.myEvents.sub')) ?></p>
        <div class="links">
            <a href="/events"><?= esc(lang('Events.title')) ?></a>
            <a href="/events/calendar"><?= esc(lang('Events.calendar.title')) ?></a>
            <?php if ($feed_url !== ''): ?>
                <a href="<?= esc($feed_url, 'attr') ?>"><?= esc(lang('Events.myEvents.subscribe')) ?></a>
            <?php endif; ?>
        </div>

        <div class="grid">
            <div class="kpi"><div class="n"><?= esc($num($counts['upcoming'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.myEvents.kUpcoming')) ?></div></div>
            <div class="kpi good"><div class="n"><?= esc($num($counts['attended'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.myEvents.kAttended')) ?></div></div>
            <div class="kpi"><div class="n"><?= esc($num($counts['certificates'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.myEvents.kCertificates')) ?></div></div>
            <div class="kpi"><div class="n"><?= esc($num($counts['tickets'] ?? 0)) ?></div><div class="k"><?= esc(lang('Events.myEvents.kTickets')) ?></div></div>
        </div>

        <h2><?= esc(lang('Events.myEvents.secUpcoming')) ?></h2>
        <?php if ($upcoming === []): ?>
            <p class="empty">
                <?= esc(lang('Events.myEvents.emptyUpcoming')) ?><br>
                <a class="browse" href="/events"><?= esc(lang('Events.myEvents.browse')) ?> &#8594;</a>
            </p>
        <?php else: ?>
            <?php foreach ($upcoming as $e): ?>
                <?= $renderRow($e) ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2><?= esc(lang('Events.myEvents.secPast')) ?></h2>
        <?php if ($past === []): ?>
            <p class="empty"><?= esc(lang('Events.myEvents.emptyPast')) ?></p>
        <?php else: ?>
            <?php foreach ($past as $e): ?>
                <?= $renderRow($e) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
