<?php
/**
 * INTEGRATION CATALOG page (GET /integrations/catalog) — the browser face of
 * CatalogController::index, which otherwise rendered the generic admin console.
 * Lists active provider adapters grouped by category, each showing its family,
 * version, and honestly-declared capabilities (FR-INT-011: the UI never claims
 * operations an adapter does not declare).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.*') with English fallback; the {0} count is interpolated in
 * PHP via $li() and singular/plural chosen in PHP. Adapter `category` is
 * localized with a raw-value fallback; code/display_name/family and capability
 * tokens are server data shown verbatim (capabilities humanized in-view).
 *
 * @var list<array<string,mixed>> $adapters
 */
$adapters = $adapters ?? [];
$count    = count($adapters);

include __DIR__ . '/_locale.php';

$categoryLbl = static function (string $c): string {
    if ($c === '') {
        return '';
    }
    $v = lang('Integrations.category.' . $c);

    return $v === 'Integrations.category.' . $c ? ucwords(str_replace('_', ' ', $c)) : $v;
};
$labelize = static fn (string $s): string => ucwords(str_replace(['_', '.'], ' ', $s));
// Capabilities may arrive as a JSON string or an array.
$capsOf = static function ($caps): array {
    if (is_string($caps)) {
        $caps = json_decode($caps, true);
    }

    return is_array($caps) ? array_values(array_filter($caps, static fn ($c) => (string) $c !== '')) : [];
};

// Group adapters by category, preserving the service's category/code ordering.
$groups = [];
foreach ($adapters as $a) {
    $groups[(string) ($a['category'] ?? '')][] = $a;
}
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; }


        .card .top { display:flex; align-items:baseline; gap:8px; margin-bottom:4px; }


        .cap { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.7rem; color:#cbd5e1;
            background:#0b1424; border:1px solid #334155; border-radius:6px; padding:2px 7px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Integrations.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.sub')) ?></p>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Integrations.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Integrations.countOne' : 'Integrations.count', (string) $count)) ?></p>
            <?php foreach ($groups as $cat => $items): ?>
                <div class="cat"><?= esc($categoryLbl((string) $cat)) ?></div>
                <div class="grid">
                    <?php foreach ($items as $a): ?>
                        <?php
                        $code = (string) ($a['code'] ?? '');
                        $dn   = (string) ($a['display_name'] ?? $code);
                        $fam  = (string) ($a['family'] ?? '');
                        $ver  = (string) ($a['version'] ?? '');
                        $caps = $capsOf($a['capabilities'] ?? null);
                        ?>
                        <article class="card">
                            <div class="top">
                                <span class="dn"><?= esc($dn) ?></span>
                                <?php if ($ver !== ''): ?><span class="ver"><?= esc($li('Integrations.version', $ver)) ?></span><?php endif; ?>
                            </div>
                            <div class="code"><?= esc($code) ?></div>
                            <?php if ($fam !== ''): ?>
                                <div class="fam"><?= esc(lang('Integrations.family')) ?>: <?= esc($labelize($fam)) ?></div>
                            <?php endif; ?>
                            <p class="caplbl"><?= esc(lang('Integrations.capabilities')) ?></p>
                            <div class="caps">
                                <?php if ($caps === []): ?>
                                    <span class="cap none"><?= esc(lang('Integrations.noCaps')) ?></span>
                                <?php else: ?>
                                    <?php foreach ($caps as $c): ?>
                                        <span class="cap"><?= esc((string) $c) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
