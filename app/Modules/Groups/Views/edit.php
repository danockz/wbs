<?php
/**
 * EDIT GROUP core fields (GET /groups/{id}/edit) — the browser face of
 * GroupController::editForm. Edits name / slug / type / classification (kind).
 * Placement (parent/depth) is NOT edited here — re-parenting is the "move" flow.
 * Posts back to POST /groups/{id}/edit (webcsrf-guarded); on success the
 * controller redirects to the group detail page (PRG), on failure it re-renders
 * with $error and the submitted $old values.
 *
 * SELF-CONTAINED page (renderForm renders it without a layout): it emits its own
 * <html> and includes _locale.php for a locale-aware <html lang dir> (RTL for
 * Arabic). UI copy is localized via lang('Groups.editCore.*') with English
 * fallback. The type dropdown mirrors the standing hierarchy chain. The CSRF
 * token is issued by the controller and echoed into the hidden _csrf field.
 *
 * @var string              $csrf
 * @var string              $error
 * @var array<string,mixed> $group    the current row
 * @var string              $group_id
 * @var list<array<string,mixed>> $kinds active group-kind rows
 * @var array<string,mixed> $old      submitted values on a failed save
 */
$csrf    = $csrf ?? '';
$error   = $error ?? '';
$group   = is_array($group ?? null) ? $group : [];
$groupId = (string) ($group_id ?? ($group['id'] ?? ''));
$kinds   = is_array($kinds ?? null) ? $kinds : [];
$old     = is_array($old ?? null) ? $old : [];

// Prefer a resubmitted value, else the stored row value.
$cur = static fn (string $k): string => htmlspecialchars(
    (string) ($old[$k] ?? ($group[$k] ?? '')),
    ENT_QUOTES,
);

include __DIR__ . '/_locale.php';

$types = ['national', 'region', 'area', 'local_assembly', 'fellowship', 'senior_cell', 'cell'];
$curType = (string) ($old['type'] ?? ($group['type'] ?? ''));
$curKind = (string) ($old['kind_code'] ?? ($group['kind_code'] ?? ''));
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.editCore.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#d97706; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#b45309; }


        .back { color:#94a3b8; text-decoration:none; font-size:.9rem; }


        .back:hover { color:#e2e8f0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.editCore.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.editCore.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <form method="post" action="<?= esc(base_url('groups/' . rawurlencode($groupId) . '/edit'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="name"><?= esc(lang('Groups.editCore.nameLabel')) ?> <span class="req">*</span></label>
            <input id="name" name="name" required value="<?= $cur('name') ?>">

            <label for="slug"><?= esc(lang('Groups.editCore.slugLabel')) ?></label>
            <input id="slug" name="slug" value="<?= $cur('slug') ?>">

            <label for="type"><?= esc(lang('Groups.editCore.typeLabel')) ?></label>
            <select id="type" name="type">
                <option value=""><?= esc(lang('Groups.editCore.typeNone')) ?></option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= esc($t, 'attr') ?>"<?= $curType === $t ? ' selected' : '' ?>><?= esc(lang('Groups.createForm.type.' . $t)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="kind_code"><?= esc(lang('Groups.editCore.kindLabel')) ?></label>
            <select id="kind_code" name="kind_code">
                <option value=""><?= esc(lang('Groups.editCore.kindNone')) ?></option>
                <?php foreach ($kinds as $k): ?>
                    <?php $kc = (string) ($k['code'] ?? ''); ?>
                    <option value="<?= esc($kc, 'attr') ?>"<?= $curKind === $kc ? ' selected' : '' ?>><?= esc((string) ($k['name'] ?? $kc)) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit"><?= esc(lang('Groups.editCore.submit')) ?></button>
        </form>

        <p style="margin-top:16px;">
            <a class="back" href="<?= esc(base_url('groups/' . rawurlencode($groupId)), 'attr') ?>">← <?= esc(lang('Groups.editCore.back')) ?></a>
        </p>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
