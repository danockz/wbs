<?php
/**
 * GROUP lifecycle history (GET /groups/{id}/lifecycle/history) — the browser face
 * of GroupLifecycleController::history, which otherwise rendered the generic admin
 * console. Shows the group's lifecycle transitions newest first as a timeline,
 * each entry showing from→to status, reason, actor, any approval reference and
 * survivor (on a merge).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.lifecycle.*') with English
 * fallback; the {0} count is interpolated via $li(); the status vocabulary is
 * localized with a raw-value fallback. Ids/reasons are server data shown verbatim
 * & escaped.
 *
 * The page is also the group's GOVERNANCE CONSOLE: below the timeline it shows
 * the lifecycle controls allowed from the group's current state ($allowed, from
 * the pure transition matrix) — archive/dissolve/merge from active, reactivate/
 * dissolve/merge from archived, none from a terminal state. Each control is a
 * no-JS PRG form posting a stated reason (+ optional approval reference; a
 * survivor group id on merge) to its webcsrf-guarded route; destructive actions
 * (dissolve/merge) ask for confirm(). A flashed success/error banner from the
 * previous PRG round-trip is shown at the top.
 *
 * @var list<array<string,mixed>> $transitions lifecycle transition rows (newest first)
 * @var string                    $groupId     the group these transitions belong to
 * @var string                    $status      the group's current lifecycle status
 * @var string                    $groupName   the group's display name (may be '')
 * @var list<string>              $allowed     states reachable from $status
 * @var string                    $csrf        webcsrf token for the inline forms
 */
$transitions = $transitions ?? [];
$groupId     = $groupId ?? '';
$status      = $status ?? 'active';
$groupName   = $groupName ?? '';
$allowed     = $allowed ?? [];
$csrf        = $csrf ?? '';
$count       = count($transitions);
$groups      = is_array($groups ?? null) ? $groups : [];

$canArchive    = in_array('archived', $allowed, true);
$canReactivate = in_array('active', $allowed, true);
$canDissolve   = in_array('dissolved', $allowed, true);
$canMerge      = in_array('merged', $allowed, true);
$hasControls   = $canArchive || $canReactivate || $canDissolve || $canMerge;
$sidAttr       = $groupId !== '' ? rawurlencode($groupId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$vstatus = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.lifecycle.status.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.lifecycle.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 820px; margin: 0 auto; padding: 5vh 20px 60px; }


        .group { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#93c5fd;
            border:1px solid #1d4ed8; border-radius:6px; padding:2px 8px; }


        .t { background:#0f172aee; border:1px solid #1e293b; border-inline-start:3px solid #1d4ed8; border-radius:0 12px 12px 0; padding:13px 16px; margin-bottom:10px; }


        .flow { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:6px; }


        .arr { color:#60a5fa; }


        .when { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }


        .reason { color:#cbd5e1; font-size:.9rem; margin:0 0 4px; }


        .meta { display:flex; flex-wrap:wrap; gap:6px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:18px; border-top:1px solid #1e293b; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px; }


        .card h3 { margin:0 0 4px; font-size:.98rem; }


        .card label { display:block; font-size:.78rem; color:#cbd5e1; margin:0 0 4px; }


        .card input[type=text] { width:100%; background:#0b1120; border:1px solid #334155; border-radius:8px; color:#e2e8f0; padding:8px 10px; font-size:.88rem; margin:0 0 10px; }


        .card input:focus { outline:2px solid #2563eb; border-color:#2563eb; }


        .card button { width:100%; border:0; border-radius:8px; padding:9px 12px; font-size:.9rem; font-weight:600; cursor:pointer; }


        .btn-reactivate { background:#15803d; color:#fff; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.lifecycle.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.lifecycle.groupLabel')) ?>: <span class="group"><?= esc($groupId !== '' ? $groupId : '—') ?></span><?php if ($groupName !== ''): ?> — <?= esc($groupName) ?><?php endif; ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <div class="now">
            <span class="lbl"><?= esc(lang('Groups.lifecycle.currentLabel')) ?>:</span>
            <span class="st <?= esc($status, 'attr') ?>"><?= esc($vstatus($status)) ?></span>
        </div>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.lifecycle.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.lifecycle.countOne' : 'Groups.lifecycle.count', (string) $count)) ?></p>
            <?php foreach ($transitions as $t): ?>
                <article class="t">
                    <div class="flow">
                        <?php if (! empty($t['from_status'])): ?>
                            <span class="badge"><?= esc($vstatus((string) $t['from_status'])) ?></span>
                            <span class="arr">→</span>
                        <?php endif; ?>
                        <span class="badge"><?= esc($vstatus((string) ($t['to_status'] ?? ''))) ?></span>
                        <span class="when"><?= esc((string) ($t['created_at'] ?? '—')) ?></span>
                    </div>
                    <?php if (! empty($t['reason'])): ?><p class="reason"><?= esc((string) $t['reason']) ?></p><?php endif; ?>
                    <div class="meta">
                        <?php if (! empty($t['actor_id'])): ?><span class="tag"><?= esc(lang('Groups.lifecycle.colActor')) ?>: <span class="mono"><?= esc((string) $t['actor_id']) ?></span></span><?php endif; ?>
                        <?php if (! empty($t['approval_ref'])): ?><span class="tag"><?= esc(lang('Groups.lifecycle.colApproval')) ?>: <span class="mono"><?= esc((string) $t['approval_ref']) ?></span></span><?php endif; ?>
                        <?php if (! empty($t['merged_into_id'])): ?><span class="tag"><?= esc(lang('Groups.lifecycle.colSurvivor')) ?>: <span class="mono"><?= esc((string) $t['merged_into_id']) ?></span></span><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($groupId !== ''): ?>
            <h2><?= esc(lang('Groups.lifecycle.actionsHeading')) ?></h2>
            <?php if (! $hasControls): ?>
                <p class="term"><?= esc(lang('Groups.lifecycle.terminalNote')) ?></p>
            <?php else: ?>
                <div class="cards">
                    <?php if ($canArchive): ?>
                        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/archive">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <h3><?= esc(lang('Groups.lifecycle.archiveTitle')) ?></h3>
                            <p class="hint"><?= esc(lang('Groups.lifecycle.archiveHint')) ?></p>
                            <label for="ar-reason"><?= esc(lang('Groups.lifecycle.reasonLabel')) ?></label>
                            <input type="text" id="ar-reason" name="reason" required maxlength="500" placeholder="<?= esc(lang('Groups.lifecycle.reasonPh'), 'attr') ?>">
                            <label for="ar-appr"><?= esc(lang('Groups.lifecycle.approvalLabel')) ?></label>
                            <input type="text" id="ar-appr" name="approval_ref" maxlength="120" placeholder="<?= esc(lang('Groups.lifecycle.approvalPh'), 'attr') ?>">
                            <button type="submit" class="btn-archive"><?= esc(lang('Groups.lifecycle.archiveBtn')) ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canReactivate): ?>
                        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/reactivate">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <h3><?= esc(lang('Groups.lifecycle.reactivateTitle')) ?></h3>
                            <p class="hint"><?= esc(lang('Groups.lifecycle.reactivateHint')) ?></p>
                            <label for="re-reason"><?= esc(lang('Groups.lifecycle.reasonLabel')) ?></label>
                            <input type="text" id="re-reason" name="reason" required maxlength="500" placeholder="<?= esc(lang('Groups.lifecycle.reasonPh'), 'attr') ?>">
                            <label for="re-appr"><?= esc(lang('Groups.lifecycle.approvalLabel')) ?></label>
                            <input type="text" id="re-appr" name="approval_ref" maxlength="120" placeholder="<?= esc(lang('Groups.lifecycle.approvalPh'), 'attr') ?>">
                            <button type="submit" class="btn-reactivate"><?= esc(lang('Groups.lifecycle.reactivateBtn')) ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canMerge): ?>
                        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/merge" onsubmit="return confirm('<?= esc(lang('Groups.lifecycle.mergeConfirm'), 'attr') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <h3><?= esc(lang('Groups.lifecycle.mergeTitle')) ?></h3>
                            <p class="hint"><?= esc(lang('Groups.lifecycle.mergeHint')) ?></p>
                            <label for="mg-surv"><?= esc(lang('Groups.lifecycle.survivorLabel')) ?></label>
                            <?php
                            $survCandidates = [];
                            foreach ($groups as $g) {
                                $gid = (string) ($g['id'] ?? '');
                                if ($gid !== '' && $gid !== $groupId) {
                                    $survCandidates[] = $g;
                                }
                            }
                            ?>
                            <?php if ($survCandidates !== []): ?>
                                <select id="mg-surv" name="survivor_id" required>
                                    <option value=""><?= esc(lang('Groups.lifecycle.survivorNone')) ?></option>
                                    <?php foreach ($survCandidates as $g): ?>
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
                                <input type="text" id="mg-surv" name="survivor_id" required maxlength="64" placeholder="<?= esc(lang('Groups.lifecycle.survivorPh'), 'attr') ?>">
                            <?php endif; ?>
                            <label for="mg-reason"><?= esc(lang('Groups.lifecycle.reasonLabel')) ?></label>
                            <input type="text" id="mg-reason" name="reason" required maxlength="500" placeholder="<?= esc(lang('Groups.lifecycle.reasonPh'), 'attr') ?>">
                            <label for="mg-appr"><?= esc(lang('Groups.lifecycle.approvalLabel')) ?></label>
                            <input type="text" id="mg-appr" name="approval_ref" maxlength="120" placeholder="<?= esc(lang('Groups.lifecycle.approvalPh'), 'attr') ?>">
                            <button type="submit" class="btn-danger"><?= esc(lang('Groups.lifecycle.mergeBtn')) ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canDissolve): ?>
                        <form class="card" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/dissolve" onsubmit="return confirm('<?= esc(lang('Groups.lifecycle.dissolveConfirm'), 'attr') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <h3><?= esc(lang('Groups.lifecycle.dissolveTitle')) ?></h3>
                            <p class="hint"><?= esc(lang('Groups.lifecycle.dissolveHint')) ?></p>
                            <label for="di-reason"><?= esc(lang('Groups.lifecycle.reasonLabel')) ?></label>
                            <input type="text" id="di-reason" name="reason" required maxlength="500" placeholder="<?= esc(lang('Groups.lifecycle.reasonPh'), 'attr') ?>">
                            <label for="di-appr"><?= esc(lang('Groups.lifecycle.approvalLabel')) ?></label>
                            <input type="text" id="di-appr" name="approval_ref" maxlength="120" placeholder="<?= esc(lang('Groups.lifecycle.approvalPh'), 'attr') ?>">
                            <button type="submit" class="btn-danger"><?= esc(lang('Groups.lifecycle.dissolveBtn')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
