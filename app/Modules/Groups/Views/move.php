<?php
/**
 * MOVE / RESTRUCTURE launcher (GET /groups/move) — the browser face of
 * GroupController::moveForm and the landing page for the Groups → Move / restructure
 * menu item (previously a 404 because the real work is the id-scoped POST
 * groups/{id}/move). The operator picks the group to move and its new parent; the
 * form POSTs to /groups/move (webcsrf-guarded), which dispatches to
 * GroupService::move (cycle / self-parent / max-depth guards intact). Success
 * redirects to the moved group (PRG); failure re-renders here with $error.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.move.*') with English
 * fallback. The CSRF token issued by the controller is echoed into hidden _csrf.
 * The two dropdowns are indented by materialized `depth` so the hierarchy reads
 * as a tree.
 *
 * @var string                     $csrf   CSRF token (also an HttpOnly cookie)
 * @var list<array<string,mixed>>  $groups active groups (id,name,depth,path,parent_id)
 * @var string                     $error  optional error from a failed submit
 * @var array<string,mixed>        $old    optional previously-submitted values
 */
$csrf   = $csrf ?? '';
$error  = $error ?? '';
$old    = $old ?? [];
$groups = $groups ?? [];

include __DIR__ . '/_locale.php';

$indent = static function (array $g): string {
    $d = max(0, (int) ($g['depth'] ?? 1) - 1);
    return str_repeat('— ', $d);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.move.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 640px; margin: 0 auto; padding: 5vh 20px 60px; }


        form { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:22px; }


        button { margin-top:22px; width:100%; padding:12px; border:0; border-radius:9px; cursor:pointer;
            background:#9333ea; color:#fff; font-size:1rem; font-weight:700; }


        button:hover { background:#7e22ce; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.move.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.move.sub')) ?></p>

        <?php if ($error !== ''): ?><div class="err"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($groups === []): ?>
            <div class="empty"><?= esc(lang('Groups.move.empty')) ?></div>
        <?php else: ?>
        <form method="post" action="<?= esc(base_url('groups/move'), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">

            <label for="group_id"><?= esc(lang('Groups.move.groupLabel')) ?> <span class="req">*</span></label>
            <select id="group_id" name="group_id" required>
                <option value="" disabled<?= ($old['group_id'] ?? '') === '' ? ' selected' : '' ?>><?= esc(lang('Groups.move.groupPh')) ?></option>
                <?php foreach ($groups as $g): $gid = (string) ($g['id'] ?? ''); ?>
                    <option value="<?= esc($gid, 'attr') ?>"<?= ($old['group_id'] ?? '') === $gid ? ' selected' : '' ?>><?= esc($indent($g) . (string) ($g['name'] ?? $gid)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="new_parent_id"><?= esc(lang('Groups.move.parentLabel')) ?></label>
            <select id="new_parent_id" name="new_parent_id">
                <option value=""<?= ($old['new_parent_id'] ?? '') === '' ? ' selected' : '' ?>><?= esc(lang('Groups.move.parentPh')) ?></option>
                <?php foreach ($groups as $g): $gid = (string) ($g['id'] ?? ''); ?>
                    <option value="<?= esc($gid, 'attr') ?>"<?= ($old['new_parent_id'] ?? '') === $gid ? ' selected' : '' ?>><?= esc($indent($g) . (string) ($g['name'] ?? $gid)) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit"><?= esc(lang('Groups.move.submit')) ?></button>
        </form>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
