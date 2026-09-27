<?php
/**
 * CROSS-CUT links for a hierarchy node (GET /groups/{id}/crosscuts) — the browser
 * face of GroupCrosscutController::forNode, which otherwise rendered the generic
 * admin console. Lists the cross-cut groups (worship team, youth network, choir)
 * attached to one hierarchy node, each showing name, type and lifecycle status.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.crosscutNode.*') with
 * English fallback; the {0} count is interpolated via $li(); the group-status
 * vocabulary is localized with a raw-value fallback. Ids/names are server data
 * shown verbatim & escaped.
 *
 * MANAGEMENT CONSOLE: a link form (attach a cross-cut group id to this node) and
 * a per-row unlink button, each a no-JS PRG form posting to a webcsrf-guarded
 * route with a return_to back to this page; unlink is confirm()-gated. A flashed
 * success/error banner from the previous PRG round-trip shows at the top.
 *
 * @var list<array<string,mixed>> $links   cross-cut groups attached to the node
 * @var string                    $nodeId  the hierarchy node id
 * @var string                    $csrf    webcsrf token for the inline forms
 */
$links  = $links ?? [];
$nodeId = $nodeId ?? '';
$csrf   = $csrf ?? '';
$count  = count($links);
$groups = is_array($groups ?? null) ? $groups : [];
// Ids already linked to this node (or the node itself) must not be offered again.
$ccExclude = [$nodeId => true];
foreach ($links as $l) {
    $lid = (string) ($l['crosscut_group_id'] ?? ($l['id'] ?? ''));
    if ($lid !== '') {
        $ccExclude[$lid] = true;
    }
}
$sidAttr = $nodeId !== '' ? rawurlencode($nodeId) : '';
$retTo   = $nodeId !== '' ? '/groups/' . $sidAttr . '/crosscuts' : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.crosscutNode.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.crosscutNode.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 860px; margin: 0 auto; padding: 5vh 20px 60px; }


        .node { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#5eead4;
            border:1px solid #115e59; border-radius:6px; padding:2px 8px; }


        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:13px 16px; margin-bottom:10px; display:flex; flex-wrap:wrap; gap:8px 12px; align-items:baseline; }


        .name { font-weight:600; }


        .id { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.72rem; color:#94a3b8; }


        .unlink-form { margin-inline-start:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.crosscutNode.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.crosscutNode.nodeLabel')) ?>: <span class="node"><?= esc($nodeId !== '' ? $nodeId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($nodeId !== ''): ?>
            <form class="linker" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/crosscuts">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <input type="hidden" name="return_to" value="<?= esc($retTo, 'attr') ?>">
                <h2><?= esc(lang('Groups.crosscutNode.linkHeading')) ?></h2>
                <p class="hint"><?= esc(lang('Groups.crosscutNode.linkHint')) ?></p>
                <div class="linkrow">
                    <label for="cc-id" style="position:absolute;left:-9999px;"><?= esc(lang('Groups.crosscutNode.linkLabel')) ?></label>
                    <?php
                    $ccCandidates = [];
                    foreach ($groups as $g) {
                        $gid = (string) ($g['id'] ?? '');
                        if ($gid !== '' && ! isset($ccExclude[$gid])) {
                            $ccCandidates[] = $g;
                        }
                    }
                    ?>
                    <?php if ($ccCandidates !== []): ?>
                        <select id="cc-id" name="crosscut_group_id" required>
                            <option value=""><?= esc(lang('Groups.crosscutNode.linkNone')) ?></option>
                            <?php foreach ($ccCandidates as $g): ?>
                                <?php
                                $gid    = (string) $g['id'];
                                $gname  = trim((string) ($g['name'] ?? ''));
                                $glabel = $gname !== '' ? $gname : $gid;
                                $depth  = max(0, (int) ($g['depth'] ?? 0));
                                $prefix = str_repeat("\u{00A0}\u{00A0}", $depth);
                                ?>
                                <option value="<?= esc($gid, 'attr') ?>"><?= $prefix . esc($glabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="cc-id" name="crosscut_group_id" required maxlength="64" placeholder="<?= esc(lang('Groups.crosscutNode.linkPh'), 'attr') ?>">
                    <?php endif; ?>
                    <button type="submit"><?= esc(lang('Groups.crosscutNode.linkBtn')) ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.crosscutNode.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.crosscutNode.countOne' : 'Groups.crosscutNode.count', (string) $count)) ?></p>
            <?php foreach ($links as $l): ?>
                <?php $status = strtolower((string) ($l['status'] ?? '')); ?>
                <?php $ccId = (string) ($l['crosscut_group_id'] ?? ''); ?>
                <article class="item">
                    <span class="name"><?= esc((string) ($l['name'] ?? '—')) ?></span>
                    <span class="type"><?= esc((string) ($l['type'] ?? '—')) ?></span>
                    <span class="id"><?= esc($ccId) ?></span>
                    <span class="st <?= esc($status, 'attr') ?>"><?= esc($vstatus($status)) ?></span>
                    <?php if ($nodeId !== '' && $ccId !== ''): ?>
                        <form class="unlink-form" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/crosscuts/unlink" onsubmit="return confirm('<?= esc(lang('Groups.crosscutNode.unlinkConfirm'), 'attr') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="return_to" value="<?= esc($retTo, 'attr') ?>">
                            <input type="hidden" name="crosscut_group_id" value="<?= esc($ccId, 'attr') ?>">
                            <button type="submit" class="unlink"><?= esc(lang('Groups.crosscutNode.unlinkBtn')) ?></button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
