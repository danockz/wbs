<?php
/**
 * VENUE DIRECTORY page (GET /venues) — the browser face of VenueController::index,
 * which otherwise only spoke JSON. A paginated list of the organization's
 * facilities with type, status, capacity, discoverability, and coordinates.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Geo.*') with English fallback; the "{0} of {1}" count is interpolated in
 * PHP via $li(). Venue `status` is localized with a raw-value fallback;
 * venue_type is server data humanized in-view; names/coords shown verbatim.
 *
 * @var list<array<string,mixed>> $venues
 * @var int    $total
 * @var int    $limit
 * @var int    $offset
 */
$venues = $venues ?? [];
$total  = (int) ($total ?? count($venues));
$shown  = count($venues);
$csrf   = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$statusColor = static fn (string $s): string => match ($s) {
    'active'      => '#22c55e',
    'maintenance' => '#f59e0b',
    'closed'      => '#ef4444',
    default       => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Geo.status.' . $s);

    return $v === 'Geo.status.' . $s ? ucfirst($s) : $v;
};
$labelize = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
$fmtCoord = static function ($lat, $lng): ?string {
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return null;
    }

    return number_format((float) $lat, 4) . ', ' . number_format((float) $lng, 4);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Geo.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        td.acts { white-space:nowrap; text-align:end; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <div class="head">
            <div class="txt">
                <h1><?= esc(lang('Geo.heading')) ?></h1>
                <p class="sub"><?= esc(lang('Geo.sub')) ?></p>
            </div>
            <a class="btn" href="<?= esc($url('venues/new'), 'attr') ?>">+ <?= esc(lang('Geo.venueView.newVenue')) ?></a>
        </div>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($venues === []): ?>
            <p class="empty"><?= esc(lang('Geo.empty')) ?></p>
            <p style="text-align:center;margin-top:14px"><a class="btn" href="<?= esc($url('venues/new'), 'attr') ?>">+ <?= esc(lang('Geo.venueView.newVenue')) ?></a></p>
        <?php else: ?>
            <p class="count"><?= esc($li('Geo.count', (string) $shown, (string) $total)) ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?= esc(lang('Geo.colName')) ?></th>
                        <th><?= esc(lang('Geo.colType')) ?></th>
                        <th><?= esc(lang('Geo.colStatus')) ?></th>
                        <th class="num"><?= esc(lang('Geo.colCapacity')) ?></th>
                        <th><?= esc(lang('Geo.colCoords')) ?></th>
                        <th class="acts"><?= esc(lang('Geo.venueView.colActions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($venues as $v): ?>
                        <?php
                        $name    = (string) ($v['name'] ?? '');
                        $type    = (string) ($v['venue_type'] ?? '');
                        $status  = (string) ($v['status'] ?? '');
                        $cap     = $v['capacity'] ?? null;
                        $disc    = (string) ($v['discovery_status'] ?? '');
                        $coords  = $fmtCoord($v['latitude'] ?? null, $v['longitude'] ?? null);
                        $color   = $statusColor($status);
                        $isDisc  = ($disc !== '' && $disc !== 'private');
                        $vid     = rawurlencode((string) ($v['id'] ?? ''));
                        ?>
                        <tr>
                            <td>
                                <span class="name"><?= esc($name) ?></span>
                                <span class="disc"><?= $isDisc ? esc(lang('Geo.discoverable')) : esc(lang('Geo.private')) ?></span>
                            </td>
                            <td><?= $type === '' ? '<span class="muted">—</span>' : esc($labelize($type)) ?></td>
                            <td><span class="chip" style="color:<?= esc($color, 'attr') ?>;border-color:<?= esc($color, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span></td>
                            <td class="num"><?= $cap === null ? '<span class="muted">—</span>' : esc((string) $cap) ?></td>
                            <td class="coords"><?= $coords === null ? '<span class="muted">' . esc(lang('Geo.noCoords')) . '</span>' : esc($coords) ?></td>
                            <td class="acts">
                                <?php if ($vid !== ''): ?>
                                <a class="act edit" href="<?= esc($url('venues/' . $vid . '/edit'), 'attr') ?>"><?= esc(lang('Geo.venueView.edit')) ?></a>
                                <form method="post" action="<?= esc($url('venues/' . $vid . '/delete'), 'attr') ?>"
                                      onsubmit="return confirm('<?= esc(lang('Geo.venueView.deleteConfirm'), 'js') ?>');">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button type="submit" class="act danger"><?= esc(lang('Geo.venueView.delete')) ?></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
