<?php
/**
 * ABAC POLICY detail (GET /access-control/abac-policies/{id}) — the browser face
 * of AbacPolicyController::show, which otherwise rendered the generic admin
 * console. Shows one attribute policy in full: code, decision (effect + action
 * pattern), the condition expression, priority and enabled state. When the policy
 * is not found (Result::notFound) the same page renders a localized not-found
 * panel.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.abacShowView.*') with English fallback; the effect
 * vocabulary is localized with a raw-value fallback (value lowercased before
 * lookup); the code, action pattern and condition JSON are server data shown
 * verbatim & escaped.
 *
 * @var array<string,mixed>|null $policy  policy row, or null if not found
 */
$policy = $policy ?? null;
$found  = is_array($policy);

include __DIR__ . '/_locale.php';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.abacShowView.' . $group . '.' . $value);
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
<?= esc(lang('AccessControl.abacShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }


        h2 { font-size:1rem; color:#c4b5fd; margin:0 0 8px; }


        pre { margin:0; background:#0b1424; border:1px solid #16233a; border-radius:8px; padding:10px 12px;
            font-size:.78rem; color:#ddd6fe; overflow:auto; white-space:pre-wrap; word-break:break-word; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1 style="font-family:inherit"><?= esc(lang('AccessControl.abacShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.abacShowView.notFound')) ?></p>
        <?php else: ?>
            <?php
            $effect  = strtolower((string) ($policy['effect'] ?? ''));
            $enabled = ! empty($policy['enabled']);
            ?>
            <h1><?= esc((string) ($policy['code'] ?? '')) ?></h1>
            <div class="badges">
                <span class="badge <?= in_array($effect, ['allow','deny'], true) ? $effect : '' ?>"><?= esc($vocab('effect', $effect)) ?></span>
                <span class="badge <?= $enabled ? 'on' : 'off' ?>"><?= esc($enabled ? lang('AccessControl.abacShowView.enabled') : lang('AccessControl.abacShowView.disabled')) ?></span>
                <span class="badge"><?= esc(lang('AccessControl.abacShowView.colPriority')) ?>: <?= esc((string) ($policy['priority'] ?? '—')) ?></span>
            </div>

            <div class="card">
                <h2><?= esc(lang('AccessControl.abacShowView.decisionHeading')) ?></h2>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.abacShowView.colCode')) ?></span><span class="v mono"><?= esc((string) ($policy['code'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.abacShowView.colEffect')) ?></span><span class="v"><?= esc($vocab('effect', $effect)) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.abacShowView.colAction')) ?></span><span class="v mono"><?= esc((string) ($policy['action_pattern'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.abacShowView.colCondition')) ?></span><span class="v"><pre><?= esc($scalar($policy['condition'] ?? null)) ?></pre></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.abacShowView.colCreated')) ?></span><span class="v"><?= esc((string) ($policy['created_at'] ?? '—')) ?></span></div>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
