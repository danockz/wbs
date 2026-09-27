<?php
/**
 * PUBLIC global→local drill-down map (/g/map): Country ▸ State/Region ▸ City ▸
 * Venue ▸ the groups that meet there. Rendered as a nested <details>/<summary>
 * disclosure tree so it works with NO JavaScript (CSP-safe) and stays keyboard-
 * and screen-reader-navigable. Driven entirely by the denormalized geo columns
 * LocationSyncService keeps current; a group with no resolved country lands in
 * the pinned "Other locations" bucket, a group with no venue on a "(No venue)"
 * node — nothing is dropped.
 *
 * Self-contained + sandbox-safe: inline CSS only, no external assets. Copy via
 * lang('Groups.geoDirectory.*') with English fallback; RTL-aware.
 *
 * @var list<array<string,mixed>> $tree country nodes:
 *     { key,label,level,count, children:[ state {..., children:[ city {...,
 *       children:[ venue {..., groups[] } ] } ] } ] }
 */
$tree = $tree ?? [];
$total = array_sum(array_map(static fn ($c) => (int) ($c['count'] ?? 0), $tree));
include __DIR__ . '/_locale.php';

$labelFor = static function (array $node): string {
    $label = trim((string) ($node['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    return match ($node['level'] ?? '') {
        'venue' => (string) lang('Groups.geoDirectory.noVenue'),
        default => (string) lang('Groups.geoDirectory.virtual'),
    };
};
$plural = static fn (int $n, string $one, string $many): string => (string) lang($n === 1 ? $one : $many);

$renderGroups = static function (array $groups): void {
    if ($groups === []) {
        return;
    }
    echo '<div class="grid">';
    foreach ($groups as $g) {
        echo '<div class="card">';
        if (! empty($g['type'])) {
            echo '<div class="type">' . esc((string) $g['type']) . '</div>';
        }
        echo '<h5>' . esc((string) ($g['name'] ?? lang('Groups.geoDirectory.groupFallback'))) . '</h5>';
        if (! empty($g['tagline'])) {
            echo '<div class="tag">' . esc((string) $g['tagline']) . '</div>';
        }
        echo '<div class="meta">';
        if (! empty($g['location_text'])) {
            echo '<span>📍 ' . esc((string) $g['location_text']) . '</span>';
        }
        if (! empty($g['contact_phone'])) {
            echo '<span>📞 ' . esc((string) $g['contact_phone']) . '</span>';
        }
        if (! empty($g['contact_email'])) {
            echo '<a href="mailto:' . esc((string) $g['contact_email'], 'attr') . '">✉️ ' . esc((string) $g['contact_email']) . '</a>';
        }
        echo '</div>';
        if (! empty($g['slug'])) {
            echo '<a class="join" href="/g/' . esc((string) $g['slug'], 'attr') . '/join">' . esc(lang('Groups.geoDirectory.join')) . ' →</a>';
        }
        echo '</div>';
    }
    echo '</div>';
};

$renderNode = static function (array $node, int $depth) use (&$renderNode, $renderGroups, $labelFor, $plural): void {
    $level = (string) ($node['level'] ?? 'country');
    $cls   = in_array($level, ['region', 'subregion', 'country', 'state', 'city', 'town', 'venue', 'virtual'], true)
        ? $level : 'country';
    $open  = $depth === 0 ? ' open' : ' open';
    echo '<details class="' . esc($cls, 'attr') . '"' . $open . '>';
    echo '<summary>' . esc($labelFor($node));
    if ($level === 'venue' && ! empty($node['venue_type'])) {
        echo '<span class="vtype">' . esc((string) $node['venue_type']) . '</span>';
    }
    if ($level === 'venue' && ! empty($node['venue_capacity'])) {
        echo '<span class="cap">' . esc(str_replace('{0}', (string) (int) $node['venue_capacity'], lang('Groups.geoDirectory.capacity'))) . '</span>';
    }
    echo ' <span class="count">(' . (int) ($node['count'] ?? 0) . ')</span></summary>';
    if ($level === 'venue' && ! empty($node['venue_address'])) {
        echo '<div class="addr">' . esc((string) $node['venue_address']) . '</div>';
    }
    $renderGroups($node['groups'] ?? []);
    foreach ($node['children'] ?? [] as $ch) {
        $renderNode($ch, $depth + 1);
    }
    echo '</details>';
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.geoDirectory.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 960px; margin: 0 auto; padding: 6vh 20px 60px; }


        details { border-radius:12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?= view('WBS\Groups\Views\_public_nav', ['viewer' => $viewer ?? null, 'csrf' => $csrf ?? null]) ?>
    <div class="wrap">
        <h1><?= esc(lang('Groups.geoDirectory.heading')) ?></h1>
        <?php
            $gWord = $plural($total, 'Groups.geoDirectory.group', 'Groups.geoDirectory.groups');
            $cWord = $plural(count($tree), 'Groups.geoDirectory.country', 'Groups.geoDirectory.countries');
        ?>
        <div class="sub"><?= esc(str_replace(['{0}', '{1}', '{2}', '{3}'], [(string) (int) $total, $gWord, (string) count($tree), $cWord], lang('Groups.geoDirectory.summary'))) ?></div>
        <p class="intro"><?= esc(lang('Groups.geoDirectory.intro')) ?></p>

        <?php if ($tree === []): ?>
            <p class="empty"><?= esc(lang('Groups.geoDirectory.none')) ?></p>
        <?php else: ?>
            <?php foreach ($tree as $node): ?>
                <?php $renderNode($node, 0); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
