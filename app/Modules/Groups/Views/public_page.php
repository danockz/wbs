<?php
/**
 * Public group landing page (group-specific, unauthenticated).
 *
 * Self-contained HTML with inline styles so it renders correctly everywhere
 * (including sandboxed previews with no external assets). The look is chosen per
 * group via `hero_theme` (aurora | sunrise | forest | slate).
 *
 * @var array<string,mixed> $data  { group, leader, member_count, upcoming_events, causes, ancestors }
 */
$g        = $data['group'] ?? [];
$leader   = $data['leader'] ?? null;
$members  = (int) ($data['member_count'] ?? 0);
$events   = $data['upcoming_events'] ?? [];
$causes   = $data['causes'] ?? [];
$trail    = $data['ancestors'] ?? [];
include __DIR__ . '/_locale.php';

$theme = (string) ($g['hero_theme'] ?? 'aurora');

/** Theme palette table. Each: [pageBg, heroGradient, accent, accentSoft, text, muted, card, cardBorder]. */
$themes = [
    'aurora' => [
        'pageBg'   => '#0b1120',
        'hero'     => 'radial-gradient(1200px 600px at 15% -10%, #0891b255, transparent), linear-gradient(135deg,#0e7490,#4338ca)',
        'accent'   => '#22d3ee', 'accentSoft' => '#0e7490',
        'text'     => '#e2e8f0', 'muted' => '#94a3b8',
        'card'     => '#0f172a', 'cardBorder' => '#1e293b', 'scheme' => 'dark',
    ],
    'sunrise' => [
        'pageBg'   => '#1a1210',
        'hero'     => 'radial-gradient(1000px 500px at 80% -20%, #f59e0b55, transparent), linear-gradient(135deg,#ea580c,#db2777)',
        'accent'   => '#fb923c', 'accentSoft' => '#c2410c',
        'text'     => '#fdf4e6', 'muted' => '#d6bcb0',
        'card'     => '#211815', 'cardBorder' => '#3b2a24', 'scheme' => 'dark',
    ],
    'forest' => [
        'pageBg'   => '#0a1410',
        'hero'     => 'radial-gradient(1000px 500px at 20% -10%, #10b98155, transparent), linear-gradient(135deg,#047857,#065f46)',
        'accent'   => '#34d399', 'accentSoft' => '#047857',
        'text'     => '#e6f4ee', 'muted' => '#9fbcae',
        'card'     => '#0f1c17', 'cardBorder' => '#1d332a', 'scheme' => 'dark',
    ],
    'slate' => [
        'pageBg'   => '#f8fafc',
        'hero'     => 'radial-gradient(1000px 500px at 80% -20%, #6366f133, transparent), linear-gradient(135deg,#334155,#0f172a)',
        'accent'   => '#4f46e5', 'accentSoft' => '#c7d2fe',
        'text'     => '#0f172a', 'muted' => '#64748b',
        'card'     => '#ffffff', 'cardBorder' => '#e2e8f0', 'scheme' => 'light',
    ],
];
$t = $themes[$theme] ?? $themes['aurora'];

$fmtDate = static function (?string $utc, string $tz): string {
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $d = $d->setTimezone(new DateTimeZone($tz !== '' ? $tz : 'UTC'));

        return $d->format('D, j M Y · H:i') . ' ' . $d->format('T');
    } catch (Exception $e) {
        return $utc;
    }
};
$name = (string) ($g['name'] ?? '') !== '' ? (string) $g['name'] : lang('Groups.page.groupFallback');

// Safe truncation that works even if mbstring is unavailable.
$clip = static function (string $s, int $max = 160): string {
    $s = trim($s);
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $max, '…');
    }

    return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
};
?>

<?php ob_start(); ?>
<?= esc($name) ?> — <?= esc(lang('Groups.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        a { color: <?= $t['accent'] ?>; text-decoration: none; }


        a:hover { text-decoration: underline; }


        .hero { background: <?= $t['hero'] ?>; color:#fff; padding: 30px 20px 46px; }


        .hero-inner, .wrap { max-width: 960px; margin: 0 auto; }


        .meta { display:flex; flex-wrap:wrap; gap:16px; margin-top:20px; font-size:.9rem; }


        .meta .chip { background:#ffffff22; border:1px solid #ffffff33; border-radius:999px; padding:6px 14px; backdrop-filter: blur(4px); }


        .btn-primary { background: <?= $t['accent'] ?>; color:#00121a; }


        .wrap { padding: 30px 20px 60px; }


        .announce { background: <?= $t['accentSoft'] ?>22; border:1px solid <?= $t['accent'] ?>66; border-left-width:4px;
            border-radius:10px; padding:14px 18px; margin: 0 0 26px; }


        .announce .k { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; color: <?= $t['accent'] ?>; font-weight:700; }


        h2 { font-size:.82rem; text-transform:uppercase; letter-spacing:.09em; color: <?= $t['muted'] ?>; margin: 30px 0 12px; }


        .card { background: <?= $t['card'] ?>; border:1px solid <?= $t['cardBorder'] ?>; border-radius:14px; padding:16px 18px; }


        .card h3 { margin:0 0 4px; font-size:1.05rem; }


        .card .when { color: <?= $t['accent'] ?>; font-size:.85rem; font-weight:600; }


        .card .where { color: <?= $t['muted'] ?>; font-size:.83rem; margin-top:2px; }


        .card p { color: <?= $t['muted'] ?>; font-size:.9rem; margin:.5em 0 0; }


        .mode { font-size:.7rem; text-transform:uppercase; letter-spacing:.06em; border:1px solid <?= $t['cardBorder'] ?>;
            border-radius:999px; padding:2px 9px; color: <?= $t['muted'] ?>; }


        .about { color: <?= $t['text'] ?>; font-size:1rem; max-width:70ch; }


        .contact { display:flex; flex-wrap:wrap; gap:10px 26px; font-size:.92rem; color: <?= $t['muted'] ?>; }


        .contact b { color: <?= $t['text'] ?>; font-weight:600; }


        .empty { color: <?= $t['muted'] ?>; font-size:.9rem; font-style:italic; }


        footer { border-top:1px solid <?= $t['cardBorder'] ?>; margin-top:40px; padding:22px 20px; color: <?= $t['muted'] ?>; font-size:.82rem; }


        footer .wrap { padding:0; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<header class="hero">
        <div class="hero-inner">
            <?php if ($trail !== []): ?>
                <nav class="crumbs">
                    <a href="/g"><?= esc(lang('Groups.page.allGroups')) ?></a>
                    <?php foreach ($trail as $a): ?>
                        <span>/</span> <a href="/g/<?= esc($a['slug']) ?>"><?= esc($a['name']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if (! empty($g['type'])): ?><div class="kicker"><?= esc((string) $g['type']) ?></div><?php endif; ?>
            <h1><?= esc($name) ?></h1>
            <?php if (! empty($g['tagline'])): ?><div class="tagline"><?= esc((string) $g['tagline']) ?></div><?php endif; ?>

            <div class="meta">
                <span class="chip"><?= esc(str_replace('{0}', number_format($members), lang('Groups.page.membersChip'))) ?></span>
                <?php if (! empty($g['location_text'])): ?><span class="chip">📍 <?= esc((string) $g['location_text']) ?></span><?php endif; ?>
                <?php if ($leader !== null): ?><span class="chip"><?= esc($li('Groups.page.ledBy', (string) $leader['display_name'])) ?></span><?php endif; ?>
            </div>

            <div class="cta">
                <?php if (! empty($g['public_join'])): ?>
                    <a class="btn btn-primary" href="/g/<?= esc((string) $g['slug']) ?>/join"><?= esc(lang('Groups.page.joinThisGroup')) ?></a>
                <?php endif; ?>
                <?php if (! empty($viewer)): ?>
                    <span class="whoami"><?= str_replace('{0}', '<strong>' . esc((string) $viewer['display_name']) . '</strong>', esc(lang('Groups.page.signedInAs'))) ?></span>
                    <a class="btn btn-ghost" href="/me/dashboard"><?= esc(lang('Groups.page.myDashboard')) ?></a>
                    <form class="logout" method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= esc((string) ($csrf ?? '')) ?>">
                        <button class="btn btn-ghost" type="submit"><?= esc(lang('Groups.page.logOut')) ?></button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-ghost" href="/login?return=/me/dashboard"><?= esc(lang('Groups.page.memberLogin')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="wrap">
        <?php if (! empty($g['announcement'])): ?>
            <div class="announce">
                <div class="k"><?= esc(lang('Groups.page.announcement')) ?></div>
                <div><?= nl2br(esc((string) $g['announcement'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if (! empty($g['description'])): ?>
            <h2><?= esc(lang('Groups.page.about')) ?></h2>
            <div class="about"><?= nl2br(esc((string) $g['description'])) ?></div>
        <?php endif; ?>

        <h2><?= esc(lang('Groups.page.upcomingEvents')) ?></h2>
        <?php if ($events === []): ?>
            <div class="empty"><?= esc(lang('Groups.page.noEvents')) ?></div>
        <?php else: ?>
            <div class="grid two">
                <?php foreach ($events as $e): ?>
                    <article class="card">
                        <div class="when"><?= esc($fmtDate($e['starts_at'] ?? null, (string) ($e['timezone'] ?? 'UTC'))) ?></div>
                        <h3><?= esc((string) ($e['title'] ?? lang('Groups.page.eventFallback'))) ?></h3>
                        <div class="where">
                            <span class="mode"><?= esc((string) ($e['mode'] ?? 'physical')) ?></span>
                            <?php if (! empty($e['venue_name'])): ?> · <?= esc((string) $e['venue_name']) ?><?php endif; ?>
                        </div>
                        <?php if (! empty($e['description'])): ?>
                            <p><?= esc($clip((string) $e["description"], 160)) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($causes !== []): ?>
            <h2><?= esc(lang('Groups.page.activeCauses')) ?></h2>
            <div class="grid two">
                <?php foreach ($causes as $c): ?>
                    <article class="card">
                        <h3><?= esc((string) ($c['name'] ?? lang('Groups.page.causeFallback'))) ?></h3>
                        <?php if (! empty($c['purpose'])): ?>
                            <p><?= esc($clip((string) $c["purpose"], 160)) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($g['contact_email']) || ! empty($g['contact_phone']) || ! empty($g['website_url'])): ?>
            <h2><?= esc(lang('Groups.page.getInTouch')) ?></h2>
            <div class="contact">
                <?php if (! empty($g['contact_email'])): ?><span><b><?= esc(lang('Groups.page.email')) ?></b> <a href="mailto:<?= esc((string) $g['contact_email']) ?>"><?= esc((string) $g['contact_email']) ?></a></span><?php endif; ?>
                <?php if (! empty($g['contact_phone'])): ?><span><b><?= esc(lang('Groups.page.phone')) ?></b> <?= esc((string) $g['contact_phone']) ?></span><?php endif; ?>
                <?php if (! empty($g['website_url'])): ?><span><b><?= esc(lang('Groups.page.web')) ?></b> <a href="<?= esc((string) $g['website_url']) ?>" rel="noopener noreferrer"><?= esc((string) $g['website_url']) ?></a></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <div class="wrap">
            <span><?= esc($name) ?> · <?= esc(lang('Groups.brand')) ?></span>
            <span><a href="/g"><?= esc(lang('Groups.page.browseAll')) ?></a></span>
        </div>
    </footer>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
