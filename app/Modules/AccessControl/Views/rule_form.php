<?php
/**
 * RULE create/edit form (GET /rules/new and GET /rules/{id}/edit) — the browser
 * face of RuleController::createForm / editForm, and the write side of the rule
 * catalogue's "New rule" / "Edit" links (previously the catalogue was read-only,
 * with the create/update endpoints reachable only via the JSON API).
 *
 * Posts to POST /rules (create) or POST /rules/{id} (update), both webcsrf-guarded.
 * On success the controller redirects (PRG) to the rule detail; on failure it
 * re-renders this form with $error and the submitted $rule values so nothing the
 * leader typed is lost. Progressive enhancement: a plain HTML form that works
 * with no JavaScript.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.ruleForm.*') with English fallback. The double-submit CSRF
 * token is issued by the controller (renderForm) and echoed into hidden _csrf.
 *
 * Condition and effect params are authored as JSON in <textarea>s (the service
 * validates the condition tree at write time with the SAME evaluator the engine
 * runs, so a malformed rule is rejected on save — the view stays declarative).
 *
 * @var string               $csrf   double-submit token (from renderForm)
 * @var string               $mode   'create' | 'edit'
 * @var array<string,mixed>  $rule   current/submitted rule values (empty for create)
 * @var list<string>         $facets allowed facet codes
 * @var string               $error  validation/service error message, if any
 * @var string|null          $facet  preselected facet (create, from ?facet=)
 */
$csrf   = $csrf ?? '';
$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$rule   = $rule ?? [];
$facets = $facets ?? ['access', 'membership', 'gamification', 'notification', 'events', 'contributions'];
$error  = $error ?? '';
$facet  = $facet ?? null;

$isEdit = $mode === 'edit';
$id     = (string) ($rule['id'] ?? $rule['rule_id'] ?? '');
$groups = is_array($groups ?? null) ? $groups : [];

include __DIR__ . '/_locale.php';

// Test-safe URL builder (see rules.php): framework helper when booted, else a
// plain root-relative path so this self-contained view also renders headlessly.
$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// Escaped current value for an input, tolerating both raw and array/JSON columns.
$ov = static function (string $k, string $default = '') use ($rule): string {
    $v = $rule[$k] ?? $default;
    if (is_array($v)) {
        $v = (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return htmlspecialchars((string) $v, ENT_QUOTES);
};
$selected = static fn (string $k, string $val): string => ((string) ($rule[$k] ?? '') === $val) ? ' selected' : '';

$effects    = ['deny', 'allow', 'flag', 'adjust', 'require_review'];
$scopeModes = ['self', 'self_and_descendants', 'descendants_only', 'groups'];

// Fixed vocab -> localized label with raw-value fallback.
$vocab = static function (string $group, string $value): string {
    $s = lang('AccessControl.ruleForm.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};

$curFacet = (string) ($rule['facet'] ?? $facet ?? '');
$action   = $isEdit ? $url('rules/' . rawurlencode($id)) : $url('rules');
?>

<?php ob_start(); ?>
<?= esc(lang($isEdit ? 'AccessControl.ruleForm.metaTitleEdit' : 'AccessControl.ruleForm.metaTitleNew')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 760px; margin: 0 auto; padding: 5vh 20px 60px; }

        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }

        button { padding:12px 20px; border:0; border-radius:9px; cursor:pointer;
            background:#6366f1; color:#fff; font-size:1rem; font-weight:700; }

        button:hover { background:#4f46e5; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="<?= esc($url('rules'), 'attr') ?>">&larr; <?= esc(lang('AccessControl.ruleForm.backToList')) ?></a>
        <h1><?= esc(lang($isEdit ? 'AccessControl.ruleForm.headingEdit' : 'AccessControl.ruleForm.headingNew')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.ruleForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc($action, 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <div class="grid">
                <div class="row1">
                    <label for="facet"><?= esc(lang('AccessControl.ruleForm.facetLabel')) ?> <span class="req">*</span></label>
                    <select id="facet" name="facet"<?= $isEdit ? ' disabled' : '' ?>>
                        <option value=""><?= esc(lang('AccessControl.ruleForm.facetNone')) ?></option>
                        <?php foreach ($facets as $f): ?>
                            <option value="<?= esc($f, 'attr') ?>"<?= $curFacet === $f ? ' selected' : '' ?>><?= esc($vocab('facet', $f)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="facet" value="<?= esc($curFacet, 'attr') ?>">
                        <p class="hint"><?= esc(lang('AccessControl.ruleForm.facetLocked')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="row1">
                    <label for="code"><?= esc(lang('AccessControl.ruleForm.codeLabel')) ?> <span class="req">*</span></label>
                    <input id="code" name="code" required value="<?= $ov('code') ?>"<?= $isEdit ? ' readonly' : '' ?>
                        placeholder="<?= esc(lang('AccessControl.ruleForm.codePh'), 'attr') ?>">
                    <?php if ($isEdit): ?><p class="hint"><?= esc(lang('AccessControl.ruleForm.codeLocked')) ?></p><?php endif; ?>
                </div>

                <div class="full">
                    <label for="name"><?= esc(lang('AccessControl.ruleForm.nameLabel')) ?> <span class="req">*</span></label>
                    <input id="name" name="name" required value="<?= $ov('name') ?>" placeholder="<?= esc(lang('AccessControl.ruleForm.namePh'), 'attr') ?>">
                </div>

                <div class="full">
                    <label for="description"><?= esc(lang('AccessControl.ruleForm.descLabel')) ?></label>
                    <input id="description" name="description" value="<?= $ov('description') ?>" placeholder="<?= esc(lang('AccessControl.ruleForm.descPh'), 'attr') ?>">
                </div>

                <div>
                    <label for="effect"><?= esc(lang('AccessControl.ruleForm.effectLabel')) ?></label>
                    <select id="effect" name="effect">
                        <?php foreach ($effects as $e): ?>
                            <option value="<?= esc($e, 'attr') ?>"<?= (string) ($rule['effect'] ?? 'deny') === $e ? ' selected' : '' ?>><?= esc($vocab('effect', $e)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="priority"><?= esc(lang('AccessControl.ruleForm.priorityLabel')) ?></label>
                    <input id="priority" name="priority" type="number" inputmode="numeric" value="<?= $ov('priority', '50') ?>">
                    <p class="hint"><?= esc(lang('AccessControl.ruleForm.priorityHint')) ?></p>
                </div>

                <div>
                    <label for="action_pattern"><?= esc(lang('AccessControl.ruleForm.actionLabel')) ?></label>
                    <input id="action_pattern" name="action_pattern" value="<?= $ov('action_pattern', '*') ?>" placeholder="*">
                    <p class="hint"><?= esc(lang('AccessControl.ruleForm.actionHint')) ?></p>
                </div>
                <div>
                    <label for="scope_mode"><?= esc(lang('AccessControl.ruleForm.scopeLabel')) ?></label>
                    <select id="scope_mode" name="scope_mode">
                        <?php foreach ($scopeModes as $s): ?>
                            <option value="<?= esc($s, 'attr') ?>"<?= (string) ($rule['scope_mode'] ?? 'self') === $s ? ' selected' : '' ?>><?= esc($vocab('scope', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full">
                    <label for="scope_group_id"><?= esc(lang('AccessControl.ruleForm.scopeGroupLabel')) ?></label>
                    <?php $curScope = (string) ($rule['scope_group_id'] ?? ''); ?>
                    <?php if ($groups !== []): ?>
                        <select id="scope_group_id" name="scope_group_id">
                            <option value=""><?= esc(lang('AccessControl.ruleForm.scopeGroupNone')) ?></option>
                            <?php
                            $seenScope = false;
                            foreach ($groups as $g):
                                $gid = (string) ($g['id'] ?? '');
                                if ($gid === '') {
                                    continue;
                                }
                                $gname  = trim((string) ($g['name'] ?? ''));
                                $glabel = $gname !== '' ? $gname : $gid;
                                $depth  = max(0, (int) ($g['depth'] ?? 0));
                                $prefix = str_repeat("\u{00A0}\u{00A0}", $depth);
                                $sel    = $gid === $curScope;
                                $seenScope = $seenScope || $sel;
                                ?>
                                <option value="<?= esc($gid, 'attr') ?>"<?= $sel ? ' selected' : '' ?>><?= $prefix . esc($glabel) ?></option>
                            <?php endforeach; ?>
                            <?php if ($curScope !== '' && ! $seenScope): ?>
                                <option value="<?= esc($curScope, 'attr') ?>" selected><?= esc($curScope) ?></option>
                            <?php endif; ?>
                        </select>
                    <?php else: ?>
                        <input id="scope_group_id" name="scope_group_id" maxlength="64" value="<?= $ov('scope_group_id') ?>" placeholder="<?= esc(lang('AccessControl.ruleForm.scopeGroupPh'), 'attr') ?>">
                    <?php endif; ?>
                    <p class="hint"><?= esc(lang('AccessControl.ruleForm.scopeGroupHint')) ?></p>
                </div>

                <div class="full">
                    <label for="condition"><?= esc(lang('AccessControl.ruleForm.conditionLabel')) ?></label>
                    <textarea id="condition" name="condition" spellcheck="false" placeholder='{"all":[]}'><?= $ov('condition') ?></textarea>
                    <p class="hint"><?= esc(lang('AccessControl.ruleForm.conditionHint')) ?></p>
                </div>

                <div class="full">
                    <label for="effect_params"><?= esc(lang('AccessControl.ruleForm.paramsLabel')) ?></label>
                    <textarea id="effect_params" name="effect_params" spellcheck="false" placeholder='{}'><?= $ov('effect_params') ?></textarea>
                    <p class="hint"><?= esc(lang('AccessControl.ruleForm.paramsHint')) ?></p>
                </div>

                <div class="full chk">
                    <input id="enabled" name="enabled" type="checkbox" value="1"<?= (! array_key_exists('enabled', $rule) || ! empty($rule['enabled'])) ? ' checked' : '' ?>>
                    <label for="enabled"><?= esc(lang('AccessControl.ruleForm.enabledLabel')) ?></label>
                </div>
            </div>

            <div class="actions">
                <button type="submit"><?= esc(lang($isEdit ? 'AccessControl.ruleForm.saveEdit' : 'AccessControl.ruleForm.saveNew')) ?></button>
                <a class="cancel" href="<?= esc($isEdit && $id !== '' ? $url('rules/' . rawurlencode($id)) : $url('rules'), 'attr') ?>"><?= esc(lang('AccessControl.ruleForm.cancel')) ?></a>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
