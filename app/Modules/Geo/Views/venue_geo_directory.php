<?php
/**
 * ADMIN global→local venue map (GET /venues/directory) — the browser face of
 * VenueController::geoDirectory. Country ▸ State ▸ City ▸ Venue nested disclosure
 * tree, each venue showing its facility facts (type/status/discovery/capacity)
 * and the groups assigned to it (primary/secondary/overflow). Unlike the public
 * directory this includes EVERY venue — private/unlisted and any status — clearly
 * flagged. Gated `venue.manage` at the route.
 *
 * SELF-CONTAINED + sandbox-safe: inline CSS only, no JS (native <details>).
 * Copy via lang('Geo.venueDirectory.*') with English fallback; RTL-aware.
 *
 * @var list<array<string,mixed>> $tree country nodes:
 *     { key,label,level,count, children:[ state {..., children:[ city {...,
 *       venues:[ { id,name,venue_type,status,discovery_status,capacity,groups[] } ] } ] } ] }
 */
$tree = $tree ?? [];
$totalVenues = 0;
foreach ($tree as $c) {
    $totalVenues += (int) ($c['count'] ?? 0);
}
include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path) : '/' . ltrim($path, '/');

$labelFor = static function (array $node): string {
    $label = trim((string) ($node['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    return match ($node['level'] ?? '') {
        'country' => (string) lang('Geo.venueDirectory.unlocated'),
        default   => (string) lang('Geo.venueDirectory.unspecified'),
    };
};
$plural = static fn (int $n, string $one, string $many): string => (string) lang($n === 1 ? $one : $many);

$discoveryChip = static function (string $d): array {
    return match ($d) {
        'private'  => [(string) lang('Geo.venueDirectory.private'), '#ef4444'],
        'unlisted' => [(string) lang('Geo.venueDirectory.unlisted'), '#f59e0b'],
        default    => [(string) lang('Geo.venueDirectory.publicLabel'), '#22c55e'],
    };
};
$statusColor = static fn (string $s): string => match ($s) {
    'active'      => '#22c55e',
    'maintenance' => '#f59e0b',
    'closed'      => '#ef4444',
    default       => '#94a3b8',
};
$assignChip = static fn (string $t): string => match ($t) {
    'primary'   => (string) lang('Geo.venueDirectory.primary'),
    'secondary' => (string) lang('Geo.venueDirectory.secondary'),
    'overflow'  => (string) lang('Geo.venueDirectory.overflow'),
    default     => $t,
};
$assignColor = static fn (string $t): string => match ($t) {
    'primary'   => '#22d3ee',
    'secondary' => '#818cf8',
    'overflow'  => '#94a3b8',
    default     => '#64748b',
};
?>

<?php ob_start(); ?>
<?= esc(lang('Geo.venueDirectory.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        details { border-radius:12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
        <h1><?= esc(lang('Geo.venueDirectory.heading')) ?></h1>
        <?php
            $vWord = $plural($totalVenues, 'Geo.venueDirectory.venue', 'Geo.venueDirectory.venues');
            $cWord = $plural(count($tree), 'Geo.venueDirectory.country', 'Geo.venueDirectory.countries');
        ?>
        <div class="sub"><?= esc(str_replace(['{0}', '{1}', '{2}', '{3}'], [(string) $totalVenues, $vWord, (string) count($tree), $cWord], lang('Geo.venueDirectory.summary'))) ?></div>
        <p class="intro"><?= esc(lang('Geo.venueDirectory.intro')) ?></p>

        <?php if ($tree === []): ?>
            <p class="empty"><?= esc(lang('Geo.venueDirectory.none')) ?></p>
        <?php else: ?>
            <?php foreach ($tree as $idx => $country): ?>
                <details class="country"<?= $idx === 0 ? ' open' : '' ?>>
                    <summary>🌍 <?= esc($labelFor($country)) ?> <span class="count">(<?= (int) $country['count'] ?>)</span></summary>
                    <div class="body">
                        <?php foreach (($country['children'] ?? []) as $state): ?>
                            <details class="state" open>
                                <summary>📍 <?= esc($labelFor($state)) ?> <span class="count">(<?= (int) $state['count'] ?>)</span></summary>
                                <?php foreach (($state['children'] ?? []) as $city): ?>
                                    <details class="city" open>
                                        <summary><?= esc($labelFor($city)) ?> (<?= (int) $city['count'] ?>)</summary>
                                        <?php foreach (($city['venues'] ?? []) as $venue): ?>
                                            <?php [$dLbl, $dCol] = $discoveryChip((string) ($venue['discovery_status'] ?? 'public')); ?>
                                            <div class="venue">
                                                <div class="vhead">
                                                    <span class="vname">🏛 <?= esc((string) ($venue['name'] ?? lang('Geo.venueDirectory.venueFallback'))) ?></span>
                                                    <?php if (! empty($venue['venue_type'])): ?><span class="vtype"><?= esc((string) $venue['venue_type']) ?></span><?php endif; ?>
                                                    <span class="chip" style="color:<?= $dCol ?>;border-color:<?= $dCol ?>55;"><?= esc($dLbl) ?></span>
                                                    <?php if (! empty($venue['status'])): ?><span class="chip" style="color:<?= $statusColor((string) $venue['status']) ?>;border-color:<?= $statusColor((string) $venue['status']) ?>55;"><?= esc((string) $venue['status']) ?></span><?php endif; ?>
                                                    <?php if (! empty($venue['capacity'])): ?><span class="cap"><?= esc(str_replace('{0}', (string) (int) $venue['capacity'], lang('Geo.venueDirectory.capacity'))) ?></span><?php endif; ?>
                                                    <a class="editlink" href="<?= esc($url('venues/' . (string) $venue['id'] . '/edit'), 'attr') ?>"><?= esc(lang('Geo.venueDirectory.edit')) ?> →</a>
                                                </div>
                                                <?php if (! empty($venue['groups'])): ?>
                                                    <div class="groups">
                                                        <?php foreach ($venue['groups'] as $g): ?>
                                                            <?php $at = (string) ($g['assignment_type'] ?? 'primary'); ?>
                                                            <div class="grow">
                                                                <span class="chip" style="color:<?= $assignColor($at) ?>;border-color:<?= $assignColor($at) ?>55;"><?= esc($assignChip($at)) ?></span>
                                                                <span class="gname"><?php if (! empty($g['slug'])): ?><a href="/g/<?= esc((string) $g['slug']) ?>"><?= esc((string) $g['name']) ?></a><?php else: ?><?= esc((string) $g['name']) ?><?php endif; ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="nogroups"><?= esc(lang('Geo.venueDirectory.noGroups')) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </details>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
