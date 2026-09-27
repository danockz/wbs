<?php
/**
 * Public directory of active groups (/g): Region → Subregion → Country → State
 * → City → Town/village → Venue → groups. Physical groups have a venue+country.
 * Groups without a place collect under Virtual (pinned last).
 *
 * @var list<array<string,mixed>> $sections
 */
$sections = $sections ?? [];
$total    = array_sum(array_map(static fn ($s) => (int) ($s['count'] ?? 0), $sections));
include __DIR__ . '/_locale.php';

// Resolve a node's display label, substituting localized copy for the two
// sentinel levels (unresolved country / unspecified sub-level).
$labelFor = static function (array $node): string {
    $label = trim((string) ($node['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return (string) lang('Groups.directory.virtual');
};

$accentFor = static function (string $theme): string {
    return match ($theme) {
        'sunrise' => '#fb923c',
        'forest'  => '#34d399',
        'slate'   => '#4f46e5',
        default   => '#22d3ee',
    };
};

$renderCards = static function (array $groups) use ($accentFor): void {
    if ($groups === []) {
        return;
    }
    echo '<div class="grid">';
    foreach ($groups as $g) {
        $accent = $accentFor((string) ($g['hero_theme'] ?? 'aurora'));
        echo '<a class="card" href="/g/' . esc((string) ($g['slug'] ?? ''), 'attr') . '" style="border-color:' . esc($accent, 'attr') . '33;">';
        echo '<div class="bar" style="background:' . esc($accent, 'attr') . ';"></div>';
        if (! empty($g['type'])) {
            echo '<div class="type">' . esc((string) $g['type']) . '</div>';
        }
        echo '<h5>' . esc((string) ($g['name'] ?? lang('Groups.directory.groupFallback'))) . '</h5>';
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
        echo '</div></a>';
    }
    echo '</div>';
};

$renderDeep = static function (array $node) use (&$renderDeep, $renderCards, $labelFor): void {
    $level = (string) ($node['level'] ?? 'city');
    $cls   = in_array($level, ['state', 'subregion', 'city', 'town', 'venue'], true) ? $level : 'city';
    echo '<div class="' . esc($cls, 'attr') . '">';
    $tag = in_array($level, ['state', 'subregion'], true) ? 'h3' : 'h4';
    echo '<' . $tag . '>' . esc($labelFor($node)) . '</' . $tag . '>';
    $renderCards($node['groups'] ?? []);
    foreach ($node['children'] ?? [] as $ch) {
        $renderDeep($ch);
    }
    echo '</div>';
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.directory.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 1000px; margin: 0 auto; padding: 6vh 20px 60px; }


        .city, .town, .venue { margin:0 0 16px; padding-inline-start:14px; }


        .card { display:block; background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:16px 18px;
            transition: transform .12s ease, border-color .12s ease; }


        .card:hover { transform: translateY(-2px); text-decoration:none; }


        .card .type { font-size:.66rem; text-transform:uppercase; letter-spacing:.08em; color:#64748b; }


        .card .tag { color:#94a3b8; font-size:.88rem; }


        .card .meta { margin-top:10px; display:flex; flex-direction:column; gap:5px; }


        .card .meta span, .card .meta a { color:#94a3b8; font-size:.8rem; }


        .bar { height:4px; border-radius:999px; margin-bottom:12px; width:40px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?= view('WBS\Groups\Views\_public_nav', ['viewer' => $viewer ?? null, 'csrf' => $csrf ?? null]) ?>
    <div class="wrap">
        <h1><?= esc(lang('Groups.directory.heading')) ?></h1>
        <?php
            $gWord = $total === 1 ? lang('Groups.directory.group') : lang('Groups.directory.groups');
            $lWord = count($sections) === 1 ? lang('Groups.directory.location') : lang('Groups.directory.locations');
        ?>
        <div class="sub"><?= esc(str_replace(['{0}', '{1}', '{2}', '{3}'], [(string) (int) $total, $gWord, (string) count($sections), $lWord], lang('Groups.directory.summary'))) ?></div>

        <?php if ($sections === []): ?>
            <p class="empty"><?= esc(lang('Groups.directory.none')) ?></p>
        <?php else: ?>
            <?php if (count($sections) > 1): ?>
                <nav class="toc">
                    <?php foreach ($sections as $c): ?>
                        <a href="#<?= esc((string) $c['key']) ?>"><?= esc($labelFor($c)) ?> (<?= (int) $c['count'] ?>)</a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php foreach ($sections as $c): ?>
                <section class="country" id="<?= esc((string) $c['key']) ?>">
                    <?php $cWord = (int) $c['count'] === 1 ? lang('Groups.directory.group') : lang('Groups.directory.groups'); ?>
                    <h2>🌍 <?= esc($labelFor($c)) ?> <span class="count"><?= esc(str_replace(['{0}', '{1}'], [(string) (int) $c['count'], $cWord], lang('Groups.directory.countGroups'))) ?></span></h2>
                    <div class="rule"></div>
                    <?php $renderCards($c['groups'] ?? []); ?>
                    <?php foreach (($c['children'] ?? []) as $ch): ?>
                        <?php $renderDeep($ch); ?>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
