<?php
/**
 * RULE CATALOGUE page (GET /access-control/rules[?facet=…]) — the browser face of
 * RuleController::index, which otherwise rendered the generic admin console. Lists
 * the org's RuBAC rules in evaluation order (facet, then priority), showing each
 * rule's effect, action pattern, scope mode and enabled state.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.rulesView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. Fixed
 * vocabularies (effect, scope_mode) are localized with a raw-value fallback so an
 * unknown value still renders. Rule code/name/action are server data shown
 * verbatim & escaped.
 *
 * WRITE CONTROLS: a "New rule" action, and per-rule Edit / Enable-Disable /
 * Delete controls wired to the RuleController write endpoints. The toggle and
 * delete are real webcsrf-guarded POST <form>s (progressive enhancement — they
 * work with no JavaScript); delete asks for confirm() when JS is available. The
 * $csrf token is the one the global webcsrfissue filter minted for this request.
 *
 * @var list<array<string,mixed>> $rules  rule rows (id,facet,code,name,effect,action_pattern,scope_mode,priority,enabled)
 * @var string|null               $facet  optional facet filter in effect
 * @var string                    $csrf   double-submit CSRF token for inline forms
 */
$rules = $rules ?? [];
$facet = $facet ?? null;
$count = count($rules);
$csrf  = $csrf ?? '';

include __DIR__ . '/_locale.php';

// Test-safe URL builder: use the framework helper when booted, else a plain
// root-relative path (so this self-contained view also renders in the view
// test harness where base_url() is not defined). Mirrors _locale.php's guards.
$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// Flash messages set by the controller after a redirect (PRG).
$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Fixed vocab -> localized label with raw-value fallback.
$vocab = static function (string $group, string $value): string {
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.rulesView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.rulesView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1100px; margin: 0 auto; padding: 5vh 20px 60px; }


        .rule { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }


        .name { font-size:1.05rem; font-weight:700; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; }


        .desc { color:#94a3b8; font-size:.88rem; margin:8px 0 0; }


        .btn.primary { background:#6366f1; color:#fff; }


        .btn.primary:hover { background:#4f46e5; }


        .actions form { display:inline; margin:0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <div class="head">
            <div class="txt">
                <h1><?= esc(lang('AccessControl.rulesView.heading')) ?></h1>
                <p class="sub"><?= esc(lang('AccessControl.rulesView.sub')) ?></p>
            </div>
            <a class="btn primary" href="<?= esc($url('rules/new' . ($facet !== null && $facet !== '' ? '?facet=' . rawurlencode($facet) : '')), 'attr') ?>">
                + <?= esc(lang('AccessControl.rulesView.newRule')) ?>
            </a>
        </div>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.rulesView.empty')) ?></p>
            <p style="text-align:center"><a class="btn primary" href="<?= esc($url('rules/new'), 'attr') ?>">+ <?= esc(lang('AccessControl.rulesView.newRule')) ?></a></p>
        <?php else: ?>
            <p class="count">
                <?= esc($li($count === 1 ? 'AccessControl.rulesView.countOne' : 'AccessControl.rulesView.count', (string) $count)) ?>
                <?php if ($facet !== null && $facet !== ''): ?>
                    <span class="facet"><?= esc(lang('AccessControl.rulesView.facetLabel')) ?>: <?= esc($facet) ?></span>
                <?php endif; ?>
            </p>
            <?php foreach ($rules as $r): ?>
                <?php
                $enabled = ! empty($r['enabled']);
                $effect  = strtolower((string) ($r['effect'] ?? ''));
                ?>
                <article class="rule<?= $enabled ? '' : ' off' ?>">
                    <div class="top">
                        <span class="name"><?= esc((string) ($r['name'] ?? $r['code'] ?? '')) ?></span>
                        <span class="code"><?= esc((string) ($r['code'] ?? '')) ?></span>
                        <span class="prio"><?= esc(lang('AccessControl.rulesView.colPriority')) ?>: <?= esc((string) ($r['priority'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag <?= $effect === 'allow' ? 'allow' : ($effect === 'deny' ? 'deny' : '') ?>">
                            <?= esc(lang('AccessControl.rulesView.colEffect')) ?>: <?= esc($vocab('effect', $effect)) ?>
                        </span>
                        <span class="tag"><?= esc(lang('AccessControl.rulesView.colFacet')) ?>: <?= esc((string) ($r['facet'] ?? '—')) ?></span>
                        <span class="tag mono"><?= esc(lang('AccessControl.rulesView.colAction')) ?>: <?= esc((string) ($r['action_pattern'] ?? '*')) ?></span>
                        <span class="tag"><?= esc(lang('AccessControl.rulesView.colScope')) ?>: <?= esc($vocab('scope', (string) ($r['scope_mode'] ?? ''))) ?></span>
                        <span class="tag <?= $enabled ? 'on' : 'offb' ?>">
                            <?= esc($enabled ? lang('AccessControl.rulesView.enabled') : lang('AccessControl.rulesView.disabled')) ?>
                        </span>
                    </div>
                    <?php if (! empty($r['description'])): ?>
                        <p class="desc"><?= esc((string) $r['description']) ?></p>
                    <?php endif; ?>
                    <?php $rid = rawurlencode((string) ($r['id'] ?? '')); ?>
                    <?php if ($rid !== ''): ?>
                    <div class="actions">
                        <a class="act edit" href="<?= esc($url('rules/' . $rid . '/edit'), 'attr') ?>"><?= esc(lang('AccessControl.rulesView.edit')) ?></a>
                        <form method="post" action="<?= esc($url('rules/' . $rid . '/enabled'), 'attr') ?>">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                            <button type="submit" class="act"><?= esc($enabled ? lang('AccessControl.rulesView.disable') : lang('AccessControl.rulesView.enable')) ?></button>
                        </form>
                        <form method="post" action="<?= esc($url('rules/' . $rid . '/delete'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.rulesView.deleteConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <button type="submit" class="act danger"><?= esc(lang('AccessControl.rulesView.delete')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
