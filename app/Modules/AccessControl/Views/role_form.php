<?php
/**
 * ROLE create/edit form (GET /roles/new and GET /roles/{id}/edit) — the browser
 * face of RoleController::createForm / editForm, and the write side of the role
 * catalogue's "New role" / "Edit" links (previously the catalogue was read-only,
 * with create/update/setPermissions reachable only via the JSON API).
 *
 * Posts to POST /roles (create) or POST /roles/{id} (update), both webcsrf-
 * guarded. The permission set is submitted as permissions[] checkboxes drawn
 * from the central catalogue; the controller applies them via setPermissions.
 * On success the controller redirects (PRG) to the role detail; on failure it
 * re-renders this form with $error and the submitted values.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.roleForm.*') with English fallback.
 *
 * @var string                                        $csrf
 * @var string                                        $mode    'create' | 'edit'
 * @var array<string,mixed>                           $role    current/submitted values
 * @var list<array{code:string,description:string}>   $catalog full permission catalogue
 * @var string                                        $error
 */
$csrf    = $csrf ?? '';
$mode    = ($mode ?? 'create') === 'edit' ? 'edit' : 'create';
$role    = $role ?? [];
$catalog = $catalog ?? [];
$error   = $error ?? '';

$isEdit = $mode === 'edit';
$id     = (string) ($role['id'] ?? $role['role_id'] ?? '');
$isSystem = ! empty($role['is_system']);

// Currently-selected permission codes (submitted values win over stored).
$selected = array_flip(array_map('strval', (array) ($role['permissions'] ?? [])));

include __DIR__ . '/_locale.php';

// Test-safe URL builder (framework helper when booted, else root-relative path).
$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$ov = static function (string $k) use ($role): string {
    return htmlspecialchars((string) ($role[$k] ?? ''), ENT_QUOTES);
};

$action = $isEdit ? $url('roles/' . rawurlencode($id)) : $url('roles');
?>

<?php ob_start(); ?>
<?= esc(lang($isEdit ? 'AccessControl.roleForm.metaTitleEdit' : 'AccessControl.roleForm.metaTitleNew')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }

        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }

        input[type=text], input:not([type]) { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #334155;
            background:#0b1120; color:#e2e8f0; font-size:.95rem; font-family:inherit; }

        .perms { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:6px 14px;
            max-height:340px; overflow:auto; border:1px solid #1e293b; border-radius:10px; padding:14px; background:#0b1424; }

        .perm { display:flex; gap:9px; align-items:flex-start; font-size:.82rem; }

        .warn { background:#78350f55; border:1px solid #d97706; color:#fed7aa; border-radius:9px; padding:11px 14px; margin-bottom:18px; font-size:.85rem; }

        button { padding:12px 20px; border:0; border-radius:9px; cursor:pointer;
            background:#0d9488; color:#fff; font-size:1rem; font-weight:700; }

        button:hover { background:#0f766e; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="<?= esc($url('roles'), 'attr') ?>">&larr; <?= esc(lang('AccessControl.roleForm.backToList')) ?></a>
        <h1><?= esc(lang($isEdit ? 'AccessControl.roleForm.headingEdit' : 'AccessControl.roleForm.headingNew')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.roleForm.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>
        <?php if ($isSystem): ?><div class="warn"><?= esc(lang('AccessControl.roleForm.systemWarn')) ?></div><?php endif; ?>

        <form method="post" action="<?= esc($action, 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label class="first" for="code"><?= esc(lang('AccessControl.roleForm.codeLabel')) ?> <span class="req">*</span></label>
            <input id="code" name="code" required value="<?= $ov('code') ?>"<?= $isEdit ? ' readonly' : '' ?>
                placeholder="<?= esc(lang('AccessControl.roleForm.codePh'), 'attr') ?>">
            <?php if ($isEdit): ?><p class="hint"><?= esc(lang('AccessControl.roleForm.codeLocked')) ?></p><?php endif; ?>

            <label for="name"><?= esc(lang('AccessControl.roleForm.nameLabel')) ?> <span class="req">*</span></label>
            <input id="name" name="name" required value="<?= $ov('name') ?>" placeholder="<?= esc(lang('AccessControl.roleForm.namePh'), 'attr') ?>">

            <label for="description"><?= esc(lang('AccessControl.roleForm.descLabel')) ?></label>
            <input id="description" name="description" value="<?= $ov('description') ?>" placeholder="<?= esc(lang('AccessControl.roleForm.descPh'), 'attr') ?>">

            <label><?= esc(lang('AccessControl.roleForm.permsLabel')) ?></label>
            <p class="hint"><?= esc(lang('AccessControl.roleForm.permsHint')) ?></p>
            <?php if ($catalog === []): ?>
                <p class="hint"><?= esc(lang('AccessControl.roleForm.permsEmpty')) ?></p>
            <?php else: ?>
                <div class="tools">
                    <a onclick="for(var e of document.querySelectorAll('.perm input'))e.checked=true;return false;" href="#"><?= esc(lang('AccessControl.roleForm.selectAll')) ?></a>
                    <a onclick="for(var e of document.querySelectorAll('.perm input'))e.checked=false;return false;" href="#"><?= esc(lang('AccessControl.roleForm.selectNone')) ?></a>
                </div>
                <div class="perms">
                    <?php foreach ($catalog as $p): ?>
                        <?php $pc = (string) ($p['code'] ?? ''); if ($pc === '') { continue; } ?>
                        <label class="perm">
                            <input type="checkbox" name="permissions[]" value="<?= esc($pc, 'attr') ?>"<?= isset($selected[$pc]) ? ' checked' : '' ?>>
                            <span>
                                <code><?= esc($pc) ?></code>
                                <?php if (($p['description'] ?? '') !== ''): ?><span class="d"><?= esc((string) $p['description']) ?></span><?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="actions">
                <button type="submit"><?= esc(lang($isEdit ? 'AccessControl.roleForm.saveEdit' : 'AccessControl.roleForm.saveNew')) ?></button>
                <a class="cancel" href="<?= esc($isEdit && $id !== '' ? $url('roles/' . rawurlencode($id)) : $url('roles'), 'attr') ?>"><?= esc(lang('AccessControl.roleForm.cancel')) ?></a>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
