<?php
/**
 * HIERARCHY nodes a cross-cut group spans (GET /groups/{id}/crosscut-nodes) — the
 * browser face of GroupCrosscutController::forCrosscut, which otherwise rendered
 * the generic admin console. Lists the hierarchy nodes one cross-cut group is
 * attached to, each showing name, type, depth and path.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.crosscutNodes.*') with
 * English fallback; the {0} count is interpolated via $li(). Ids/names/paths are
 * server data shown verbatim & escaped.
 *
 * MANAGEMENT CONSOLE: each attached node carries a per-row unlink button — a
 * no-JS PRG form posting to the node's webcsrf-guarded unlink route with this
 * cross-cut id as the payload and a return_to back to this page; confirm()-gated.
 * A flashed success/error banner from the previous PRG round-trip shows at top.
 *
 * @var list<array<string,mixed>> $nodes      hierarchy nodes the cross-cut spans
 * @var string                    $crosscutId the cross-cut group id
 * @var string                    $csrf       webcsrf token for the inline forms
 */
$nodes      = $nodes ?? [];
$crosscutId = $crosscutId ?? '';
$csrf       = $csrf ?? '';
$count      = count($nodes);
$ccAttr     = $crosscutId !== '' ? rawurlencode($crosscutId) : '';
$retTo      = $crosscutId !== '' ? '/groups/' . $ccAttr . '/crosscut-nodes' : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.crosscutNodes.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        .cc { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#5eead4;
            border:1px solid #115e59; border-radius:6px; padding:2px 8px; }


        .name { font-weight:600; }


        .depth { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }


        .unlink-form { margin-inline-start:auto; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.crosscutNodes.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.crosscutNodes.crosscutLabel')) ?>: <span class="cc"><?= esc($crosscutId !== '' ? $crosscutId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.crosscutNodes.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.crosscutNodes.countOne' : 'Groups.crosscutNodes.count', (string) $count)) ?></p>
            <?php foreach ($nodes as $nd): ?>
                <?php $ndId = (string) ($nd['hierarchy_group_id'] ?? ''); ?>
                <article class="item">
                    <div class="top">
                        <span class="name"><?= esc((string) ($nd['name'] ?? '—')) ?></span>
                        <span class="type"><?= esc((string) ($nd['type'] ?? '—')) ?></span>
                        <span class="depth"><?= esc(lang('Groups.crosscutNodes.depth')) ?>: <?= esc((string) ($nd['depth'] ?? '—')) ?></span>
                        <?php if ($crosscutId !== '' && $ndId !== ''): ?>
                            <form class="unlink-form" method="post" action="/groups/<?= esc(rawurlencode($ndId), 'attr') ?>/crosscuts/unlink" onsubmit="return confirm('<?= esc(lang('Groups.crosscutNodes.unlinkConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="return_to" value="<?= esc($retTo, 'attr') ?>">
                                <input type="hidden" name="crosscut_group_id" value="<?= esc($crosscutId, 'attr') ?>">
                                <button type="submit" class="unlink"><?= esc(lang('Groups.crosscutNodes.unlinkBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if (! empty($nd['path'])): ?>
                        <div class="path"><?= esc((string) $nd['path']) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
