<?php
/**
 * RULE detail (GET /access-control/rules/{id}) — the browser face of
 * RuleController::show, which otherwise rendered the generic admin console. Shows
 * one RuBAC rule in full: identity (facet, code, name, description), the decision
 * (effect + action pattern), the condition and effect params, and the scope
 * (mode + group set), plus priority and enabled state. When the rule is not found
 * (Result::notFound) the same page renders a localized not-found panel.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.ruleShowView.*') with English fallback; fixed vocabularies
 * (effect, scope mode) are localized with a raw-value fallback (value lowercased
 * before lookup); condition/params JSON and codes are server data shown verbatim
 * & escaped.
 *
 * @var array<string,mixed>|null $rule  rule row (+ scope_groups[]), or null if not found
 */
$rule   = $rule ?? null;
$found  = is_array($rule);
$groups = $found ? (array) ($rule['scope_groups'] ?? []) : [];

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.ruleShowView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
$scalar = static function (mixed $v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    return is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.ruleShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }


        h2 { font-size:1rem; color:#67e8f9; margin:0 0 8px; }


        pre { margin:0; background:#0b1424; border:1px solid #16233a; border-radius:8px; padding:10px 12px;
            font-size:.78rem; color:#a5f3fc; overflow:auto; white-space:pre-wrap; word-break:break-word; }


        .groups { display:flex; flex-wrap:wrap; gap:6px; }


        .grp { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.76rem; color:#a5f3fc;
            border:1px solid #155e75; background:#0b1424; border-radius:6px; padding:3px 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1><?= esc(lang('AccessControl.ruleShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.ruleShowView.notFound')) ?></p>
        <?php else: ?>
            <?php
            $effect  = strtolower((string) ($rule['effect'] ?? ''));
            $enabled = ! empty($rule['enabled']);
            ?>
            <h1><?= esc((string) ($rule['name'] ?? $rule['code'] ?? '')) ?></h1>
            <div class="badges">
                <span class="badge <?= in_array($effect, ['allow','deny','flag','adjust'], true) ? $effect : '' ?>"><?= esc($vocab('effect', $effect)) ?></span>
                <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= esc($enabled ? lang('AccessControl.ruleShowView.enabled') : lang('AccessControl.ruleShowView.disabled')) ?></span>
                <span class="badge"><?= esc(lang('AccessControl.ruleShowView.colPriority')) ?>: <?= esc((string) ($rule['priority'] ?? '—')) ?></span>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.ruleShowView.identityHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colFacet')) ?></span><span class="v"><?= esc((string) ($rule['facet'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colCode')) ?></span><span class="v mono"><?= esc((string) ($rule['code'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colName')) ?></span><span class="v"><?= esc((string) ($rule['name'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colDescription')) ?></span><span class="v"><?= esc($scalar($rule['description'] ?? null)) ?></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.ruleShowView.decisionHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colEffect')) ?></span><span class="v"><?= esc($vocab('effect', $effect)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colAction')) ?></span><span class="v mono"><?= esc((string) ($rule['action_pattern'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colCondition')) ?></span><span class="v"><pre><?= esc($scalar($rule['condition'] ?? null)) ?></pre></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colEffectParams')) ?></span><span class="v"><pre><?= esc($scalar($rule['effect_params'] ?? null)) ?></pre></span></div>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.ruleShowView.scopeHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colScopeMode')) ?></span><span class="v"><?= esc($vocab('scope', (string) ($rule['scope_mode'] ?? ''))) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.ruleShowView.colScopeGroup')) ?></span><span class="v mono"><?= esc($scalar($rule['scope_group_id'] ?? null)) ?></span></div>
                <div class="row">
                    <span class="k"><?= esc(lang('AccessControl.ruleShowView.colScopeGroups')) ?></span>
                    <span class="v">
                        <?php if ($groups === []): ?>
                            <span class="empty"><?= esc(lang('AccessControl.ruleShowView.noScopeGroups')) ?></span>
                        <?php else: ?>
                            <span class="groups"><?php foreach ($groups as $g): ?><span class="grp"><?= esc((string) $g) ?></span><?php endforeach; ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
