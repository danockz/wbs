<?php
/**
 * ABAC POLICY create/edit form (GET /abac-policies/new and /{id}/edit) — the
 * browser face of AbacPolicyController::createForm / editForm, and the write side
 * of the policy catalogue's "New policy" / "Edit" links (previously the catalogue
 * was read-only, with the create/update endpoints reachable only via the JSON
 * API).
 *
 * Posts to POST /abac-policies (create) or POST /abac-policies/{id} (update),
 * both webcsrf-guarded. On success the controller redirects (PRG) to the policy
 * detail; on failure it re-renders with $error + the submitted values. The
 * condition is authored as JSON; the service validates it through the PDP's own
 * evaluator on save (an empty condition is normalized to fail-safe FALSE).
 *
 * SELF-CONTAINED page: renders its own <html>, includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy via
 * lang('AccessControl.abacForm.*') with English fallback.
 *
 * @var string               $csrf
 * @var string               $mode   'create' | 'edit'
 * @var array<string,mixed>  $policy current/submitted values
 * @var string               $error
 */
$csrf   = $csrf ?? '';
$mode   = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$policy = $policy ?? [];
$error  = $error ?? '';

$isEdit = $mode === 'edit';
$id     = (string) ($policy['id'] ?? $policy['policy_id'] ?? '');

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$ov = static function (string $k, string $default = '') use ($policy): string {
    $v = $policy[$k] ?? $default;
    if (is_array($v)) {
        $v = (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return htmlspecialchars((string) $v, ENT_QUOTES);
};

$effects = ['allow', 'deny'];
$vocab = static function (string $group, string $value): string {
    $s = lang('AccessControl.abacForm.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};

$action = $isEdit ? $url('abac-policies/' . rawurlencode($id)) : $url('abac-policies');
?>

<?php ob_start(); ?>
<?= esc(lang($isEdit ? 'AccessControl.abacForm.metaTitleEdit' : 'AccessControl.abacForm.metaTitleNew')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 760px; margin: 0 auto; padding: 5vh 20px 60px; }

        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }

        button { padding:12px 20px; border:0; border-radius:9px; cursor:pointer;
            background:#7c3aed; color:#fff; font-size:1rem; font-weight:700; }

        button:hover { background:#6d28d9; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="<?= esc($url('abac-policies'), 'attr') ?>">&larr; <?= esc(lang('AccessControl.abacForm.backToList')) ?></a>
        <h1><?= esc(lang($isEdit ? 'AccessControl.abacForm.headingEdit' : 'AccessControl.abacForm.headingNew')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.abacForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc($action, 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <div class="grid">
                <div class="row1">
                    <label for="code"><?= esc(lang('AccessControl.abacForm.codeLabel')) ?> <span class="req">*</span></label>
                    <input id="code" name="code" required value="<?= $ov('code') ?>"<?= $isEdit ? ' readonly' : '' ?>
                        placeholder="<?= esc(lang('AccessControl.abacForm.codePh'), 'attr') ?>">
                    <?php if ($isEdit): ?><p class="hint"><?= esc(lang('AccessControl.abacForm.codeLocked')) ?></p><?php endif; ?>
                </div>
                <div class="row1">
                    <label for="effect"><?= esc(lang('AccessControl.abacForm.effectLabel')) ?></label>
                    <select id="effect" name="effect">
                        <?php foreach ($effects as $e): ?>
                            <option value="<?= esc($e, 'attr') ?>"<?= (string) ($policy['effect'] ?? 'allow') === $e ? ' selected' : '' ?>><?= esc($vocab('effect', $e)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full">
                    <label for="description"><?= esc(lang('AccessControl.abacForm.descLabel')) ?></label>
                    <input id="description" name="description" value="<?= $ov('description') ?>" placeholder="<?= esc(lang('AccessControl.abacForm.descPh'), 'attr') ?>">
                </div>

                <div>
                    <label for="action_pattern"><?= esc(lang('AccessControl.abacForm.actionLabel')) ?> <span class="req">*</span></label>
                    <input id="action_pattern" name="action_pattern" required value="<?= $ov('action_pattern') ?>" placeholder="<?= esc(lang('AccessControl.abacForm.actionPh'), 'attr') ?>">
                    <p class="hint"><?= esc(lang('AccessControl.abacForm.actionHint')) ?></p>
                </div>
                <div>
                    <label for="priority"><?= esc(lang('AccessControl.abacForm.priorityLabel')) ?></label>
                    <input id="priority" name="priority" type="number" inputmode="numeric" value="<?= $ov('priority', '50') ?>">
                    <p class="hint"><?= esc(lang('AccessControl.abacForm.priorityHint')) ?></p>
                </div>

                <div class="full">
                    <label for="condition"><?= esc(lang('AccessControl.abacForm.conditionLabel')) ?></label>
                    <textarea id="condition" name="condition" spellcheck="false" placeholder='{"all":[]}'><?= $ov('condition') ?></textarea>
                    <p class="hint"><?= esc(lang('AccessControl.abacForm.conditionHint')) ?></p>
                </div>

                <div class="full chk">
                    <input id="enabled" name="enabled" type="checkbox" value="1"<?= (! array_key_exists('enabled', $policy) || ! empty($policy['enabled'])) ? ' checked' : '' ?>>
                    <label for="enabled"><?= esc(lang('AccessControl.abacForm.enabledLabel')) ?></label>
                </div>
            </div>

            <div class="actions">
                <button type="submit"><?= esc(lang($isEdit ? 'AccessControl.abacForm.saveEdit' : 'AccessControl.abacForm.saveNew')) ?></button>
                <a class="cancel" href="<?= esc($isEdit && $id !== '' ? $url('abac-policies/' . rawurlencode($id)) : $url('abac-policies'), 'attr') ?>"><?= esc(lang('AccessControl.abacForm.cancel')) ?></a>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
