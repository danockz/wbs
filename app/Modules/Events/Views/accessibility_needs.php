<?php
/**
 * ACCESSIBILITY needs (GET /events/{id}/logistics/needs) — the browser face of
 * LogisticsController::needs, which otherwise rendered the generic admin console.
 * SPECIALLY CLASSIFIED individual dietary/accessibility needs; the route is gated
 * by authorize:event.logistics.manage, so only authorized logistics personnel
 * ever render this page. Shows one row per need (type, detail, classification,
 * subject), newest first, behind a standing confidentiality banner.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.needs.*') with English
 * fallback; the {0} count is interpolated via $li(); the need-type vocabulary is
 * localized with a raw-value fallback. All fields are server data shown verbatim
 * & escaped.
 *
 * @var list<array<string,mixed>> $needs accessibility-need rows
 */
$needs = $needs ?? [];
$count = count($needs);

include __DIR__ . '/_locale.php';

$vtype = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Events.needs.type.' . $value);
    return (is_string($s) && ! str_contains($s, 'Events.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.needs.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 960px; margin: 0 auto; padding: 5vh 20px 60px; }


        .banner { background:#3f1d1d; border:1px solid #7f1d1d; color:#fecaca; border-radius:10px; padding:10px 14px; font-size:.85rem; margin-bottom:16px; }


        .type { font-weight:700; color:#fecaca; }


        .who { margin-inline-start:auto; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.76rem; color:#94a3b8; }


        .detail { color:#cbd5e1; font-size:.9rem; margin:0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.needs.heading')) ?></h1>
        <p class="banner"><?= esc(lang('Events.needs.confidential')) ?></p>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Events.needs.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Events.needs.countOne' : 'Events.needs.count', (string) $count)) ?></p>
            <?php foreach ($needs as $nd): ?>
                <article class="need">
                    <div class="top">
                        <span class="type"><?= esc($vtype((string) ($nd['need_type'] ?? ''))) ?></span>
                        <span class="cls"><?= esc((string) ($nd['classification'] ?? '—')) ?></span>
                        <span class="who"><?= esc(lang('Events.needs.colSubject')) ?>: <?= esc((string) ($nd['user_id'] ?? '—')) ?></span>
                    </div>
                    <?php if (! empty($nd['detail'])): ?>
                        <p class="detail"><?= esc((string) $nd['detail']) ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
