<?php
/**
 * PROVIDERS page (GET /admin/providers) — the browser face of
 * AdminController::providers, and the landing page for the Admin → Providers menu
 * item (previously a 404). Lists configured integration connections with their
 * category, approval status, and last-tested time.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Admin.providers.*') with English fallback; the {0} count is interpolated
 * in PHP via $li() with PHP singular/plural. `status` and `category` are fixed
 * vocabularies localized with a raw-value fallback; connection/adapter names are
 * server data shown verbatim. NO secret fields are present in the payload.
 *
 * @var list<array<string,mixed>> $providers
 */
$providers = $providers ?? [];
$count     = count($providers);

include __DIR__ . '/_locale.php';

$statusColor = static fn (string $s): string => match ($s) {
    'active'           => '#22c55e',
    'tested'           => '#38bdf8',
    'pending_approval' => '#f59e0b',
    'draft'            => '#94a3b8',
    'disabled'         => '#94a3b8',
    'revoked', 'failed', 'expired' => '#ef4444',
    default            => '#94a3b8',
};
$vocab = static function (string $group, string $key): string {
    if ($key === '') {
        return '';
    }
    $v = lang("Admin.providers.$group.$key");

    return $v === "Admin.providers.$group.$key" ? ucwords(str_replace('_', ' ', $key)) : $v;
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
<?= esc(lang('Admin.providers.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>

<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Admin.providers.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Admin.providers.sub')) ?></p>

        <?php if ($providers === []): ?>
            <p class="empty"><?= esc(lang('Admin.providers.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Admin.providers.countOne' : 'Admin.providers.count', (string) $count)) ?></p>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Admin.providers.colName')) ?></th>
                    <th><?= esc(lang('Admin.providers.colCategory')) ?></th>
                    <th><?= esc(lang('Admin.providers.colStatus')) ?></th>
                    <th><?= esc(lang('Admin.providers.colTested')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($providers as $p): ?>
                        <?php
                        $name    = trim((string) ($p['display_name'] ?? ''));
                        $adapter = trim((string) ($p['adapter_code'] ?? ''));
                        $ver     = (string) ($p['adapter_version'] ?? '');
                        $cat     = (string) ($p['category'] ?? '');
                        $status  = (string) ($p['status'] ?? '');
                        $tested  = $fmtDate($p['tested_at'] ?? '');
                        $sColor  = $statusColor($status);
                        ?>
                        <tr>
                            <td>
                                <span class="name"><?= $name === '' ? '<span class="muted">' . esc(lang('Admin.providers.noName')) . '</span>' : esc($name) ?></span>
                                <?php if ($adapter !== ''): ?><span class="adapter"><?= esc(lang('Admin.providers.adapter')) ?>: <?= esc($adapter) ?><?= $ver !== '' ? ' v' . esc($ver) : '' ?></span><?php endif; ?>
                            </td>
                            <td><?= $cat === '' ? '<span class="muted">—</span>' : esc($vocab('category', $cat)) ?></td>
                            <td><span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($vocab('status', $status)) ?></span></td>
                            <td class="when"><?= $tested === '' ? '<span class="muted">' . esc(lang('Admin.providers.never')) . '</span>' : esc($tested) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
