<?php
/**
 * ROLE CATALOGUE page (GET /access-control/roles) — the browser face of
 * RoleController::index, which otherwise rendered the generic admin console.
 * Lists every role in the organization with its permission grants.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.rolesView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() and singular/plural chosen in PHP. Role
 * code/name and permission codes are server data shown verbatim & escaped.
 *
 * @var list<array{id?:string,code?:string,name?:string,permissions?:list<string>}> $roles
 */
$roles = $roles ?? [];
$count = count($roles);
$MAXP  = 8; // permission chips shown before collapsing into a "+N more".
$csrf  = $csrf ?? '';

include __DIR__ . '/_locale.php';

// Test-safe URL builder (framework helper when booted, else root-relative path).
$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// Flash messages set by the controller after a redirect (PRG).
$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.rolesView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        .role { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }


        .perms { display:flex; flex-wrap:wrap; gap:6px; }


        .perm { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.74rem; color:#cbd5e1;
            background:#0b1424; border:1px solid #334155; border-radius:6px; padding:3px 8px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <div class="head">
            <div class="txt">
                <h1><?= esc(lang('AccessControl.rolesView.heading')) ?></h1>
                <p class="sub"><?= esc(lang('AccessControl.rolesView.sub')) ?></p>
            </div>
            <a class="btn" href="<?= esc($url('roles/new'), 'attr') ?>">+ <?= esc(lang('AccessControl.rolesView.newRole')) ?></a>
        </div>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.rolesView.empty')) ?></p>
            <p style="text-align:center"><a class="btn" href="<?= esc($url('roles/new'), 'attr') ?>">+ <?= esc(lang('AccessControl.rolesView.newRole')) ?></a></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.rolesView.countOne' : 'AccessControl.rolesView.count', (string) $count)) ?></p>
            <?php foreach ($roles as $r): ?>
                <?php
                $code  = (string) ($r['code'] ?? '');
                $name  = (string) ($r['name'] ?? $code);
                $rid   = rawurlencode((string) ($r['id'] ?? ''));
                $isSystem = ! empty($r['is_system']);
                $perms = array_values(array_filter((array) ($r['permissions'] ?? []), static fn ($p) => (string) $p !== ''));
                $pcount = count($perms);
                $shown  = array_slice($perms, 0, $MAXP);
                $hidden = $pcount - count($shown);
                ?>
                <article class="role">
                    <div class="top">
                        <span class="name"><?= esc($name) ?></span>
                        <span class="code"><?= esc($code) ?></span>
                        <?php if ($isSystem): ?><span class="sys"><?= esc(lang('AccessControl.rolesView.system')) ?></span><?php endif; ?>
                        <span class="pc"><?= esc(lang('AccessControl.rolesView.colPermCount')) ?>: <?= esc((string) $pcount) ?></span>
                    </div>
                    <div class="perms">
                        <?php if ($pcount === 0): ?>
                            <span class="perm none"><?= esc(lang('AccessControl.rolesView.noPerms')) ?></span>
                        <?php else: ?>
                            <?php foreach ($shown as $p): ?>
                                <span class="perm"><?= esc((string) $p) ?></span>
                            <?php endforeach; ?>
                            <?php if ($hidden > 0): ?>
                                <span class="perm more"><?= esc($li('AccessControl.rolesView.permsMore', (string) $hidden)) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($rid !== ''): ?>
                    <div class="actions">
                        <a class="act edit" href="<?= esc($url('roles/' . $rid . '/edit'), 'attr') ?>"><?= esc(lang('AccessControl.rolesView.edit')) ?></a>
                        <?php if (! $isSystem): ?>
                        <form method="post" action="<?= esc($url('roles/' . $rid . '/delete'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.rolesView.deleteConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <button type="submit" class="act danger"><?= esc(lang('AccessControl.rolesView.delete')) ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
