<?php
/**
 * ROLE detail (GET /access-control/roles/{id}) — the browser face of
 * RoleController::show, which otherwise rendered the generic admin console. Shows
 * one role's code, name and creation time, plus the full list of permission codes
 * granted through it. When the role is not found (Result::notFound) the same page
 * renders a localized not-found panel instead of a raw JSON error.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.roleShowView.*') with English fallback; the {0} permission
 * count is interpolated in PHP via $li() with singular/plural chosen in PHP. The
 * role code/name and permission codes are server data shown verbatim & escaped.
 *
 * @var array<string,mixed>|null $role  role row (+ permissions[]), or null if not found
 */
$role        = $role ?? null;
$found       = is_array($role);
$permissions = $found ? (array) ($role['permissions'] ?? []) : [];
$permCount   = count($permissions);

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.roleShowView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }


        h2 { font-size:1rem; color:#93c5fd; margin:0 0 4px; }


        .perms { display:flex; flex-wrap:wrap; gap:6px; }


        .perm { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.76rem; color:#bfdbfe;
            border:1px solid #1e3a8a; background:#0b1424; border-radius:6px; padding:3px 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1><?= esc(lang('AccessControl.roleShowView.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('AccessControl.roleShowView.notFound')) ?></p>
        <?php else: ?>
            <h1><?= esc((string) ($role['name'] ?? '')) ?> <span class="code"><?= esc((string) ($role['code'] ?? '')) ?></span></h1>
            <div class="card">
                <div class="row"><span class="k"><?= esc(lang('AccessControl.roleShowView.colCode')) ?></span><span class="v code"><?= esc((string) ($role['code'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.roleShowView.colName')) ?></span><span class="v"><?= esc((string) ($role['name'] ?? '—')) ?></span></div>
                <div class="row"><span class="k"><?= esc(lang('AccessControl.roleShowView.colCreated')) ?></span><span class="v"><?= esc((string) ($role['created_at'] ?? '—')) ?></span></div>
            </div>
            <div class="card">
                <h2><?= esc(lang('AccessControl.roleShowView.permsHeading')) ?></h2>
                <?php if ($permCount === 0): ?>
                    <p class="empty"><?= esc(lang('AccessControl.roleShowView.permsEmpty')) ?></p>
                <?php else: ?>
                    <p class="count"><?= esc($li($permCount === 1 ? 'AccessControl.roleShowView.permsCountOne' : 'AccessControl.roleShowView.permsCount', (string) $permCount)) ?></p>
                    <div class="perms">
                        <?php foreach ($permissions as $p): ?>
                            <span class="perm"><?= esc((string) $p) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
