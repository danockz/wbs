<?php
/**
 * DELEGATIONS received (GET /access-control/delegations/received/{subjectId}) — the
 * browser face of DelegationController::received, which otherwise rendered the
 * generic admin console. Lists the delegations currently held BY one subject
 * (what they received), newest first, each showing the delegated permission, who
 * delegated it, scope (group + descendants), status, depth and effective window.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.delegationsRecvView.*') with English fallback; the {0} count
 * is interpolated in PHP via $li() with singular/plural chosen in PHP; the status
 * vocabulary is localized with a raw-value fallback. Ids/codes are server data
 * shown verbatim & escaped.
 *
 * @var list<array<string,mixed>> $delegations delegations held by the subject
 * @var string                    $subjectId   the subject these belong to
 */
$delegations = $delegations ?? [];
$subjectId   = $subjectId ?? '';
$count       = count($delegations);
$csrf        = $csrf ?? '';
$delegates   = is_array($delegates ?? null) ? $delegates : [];
$groups      = is_array($groups ?? null) ? $groups : [];

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// The relative path PRG returns to after an inline write from this page.
$returnPath = '/delegations/received/' . rawurlencode($subjectId);

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.delegationsRecvView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.delegationsRecvView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 1000px; margin: 0 auto; padding: 5vh 20px 60px; }

        .subject { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#5eead4;
            border:1px solid #115e59; border-radius:6px; padding:2px 8px; }

        .perm { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.84rem; font-weight:700; color:#99f6e4; }

        .meta { display:flex; flex-wrap:wrap; gap:6px; }

        .fld label { font-size:.72rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em; }

        .fld input, .fld select { font:inherit; color:#e2e8f0; background:#0b1120; border:1px solid #334155;
            border-radius:7px; padding:8px 10px; font-size:.85rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('AccessControl.delegationsRecvView.heading')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.delegationsRecvView.subjectLabel')) ?>: <span class="subject"><?= esc($subjectId !== '' ? $subjectId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($subjectId !== ''): ?>
        <section class="redeleg">
            <h2><?= esc(lang('AccessControl.delegationsRecvView.redelegHeading')) ?></h2>
            <p class="hint"><?= esc(lang('AccessControl.delegationsRecvView.redelegHint')) ?></p>
            <form method="post" action="<?= esc($url('delegations'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="fld">
                    <label for="rd_delegate"><?= esc(lang('AccessControl.delegationsRecvView.fDelegate')) ?></label>
                    <?php if ($delegates !== []): ?>
                        <select id="rd_delegate" name="delegate_id" required>
                            <option value=""><?= esc(lang('AccessControl.delegationsRecvView.fDelegateNone')) ?></option>
                            <?php foreach ($delegates as $u): ?>
                                <?php
                                $uid = (string) ($u['id'] ?? '');
                                if ($uid === '' || $uid === $subjectId) {
                                    continue; // cannot delegate to the subject themselves
                                }
                                $uname  = trim((string) ($u['display_name'] ?? ''));
                                $ulabel = $uname !== '' ? $uname : $uid;
                                ?>
                                <option value="<?= esc($uid, 'attr') ?>"><?= esc($ulabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="rd_delegate" name="delegate_id" required maxlength="64"
                               placeholder="<?= esc(lang('AccessControl.delegationsRecvView.fDelegatePh'), 'attr') ?>">
                    <?php endif; ?>
                </div>
                <div class="fld">
                    <label for="rd_perm"><?= esc(lang('AccessControl.delegationsRecvView.fPermission')) ?></label>
                    <input type="text" id="rd_perm" name="permission_code" required
                           placeholder="<?= esc(lang('AccessControl.delegationsRecvView.fPermissionPh'), 'attr') ?>">
                </div>
                <div class="fld">
                    <label for="rd_scope"><?= esc(lang('AccessControl.delegationsRecvView.fScopeMode')) ?></label>
                    <select id="rd_scope" name="scope_mode">
                        <option value="self"><?= esc(lang('AccessControl.delegationsRecvView.scopeSelf')) ?></option>
                        <option value="self_and_descendants"><?= esc(lang('AccessControl.delegationsRecvView.scopeSelfDesc')) ?></option>
                        <option value="descendants_only"><?= esc(lang('AccessControl.delegationsRecvView.scopeDescOnly')) ?></option>
                    </select>
                </div>
                <div class="fld">
                    <label for="rd_group"><?= esc(lang('AccessControl.delegationsRecvView.fScopeGroup')) ?></label>
                    <?php if ($groups !== []): ?>
                        <select id="rd_group" name="scope_group_id">
                            <option value=""><?= esc(lang('AccessControl.delegationsRecvView.fScopeGroupNone')) ?></option>
                            <?php foreach ($groups as $g): ?>
                                <?php
                                $gid = (string) ($g['id'] ?? '');
                                if ($gid === '') {
                                    continue;
                                }
                                $gname  = trim((string) ($g['name'] ?? ''));
                                $glabel = $gname !== '' ? $gname : $gid;
                                $depth  = max(0, (int) ($g['depth'] ?? 0));
                                $prefix = str_repeat("\u{00A0}\u{00A0}", $depth);
                                ?>
                                <option value="<?= esc($gid, 'attr') ?>"><?= $prefix . esc($glabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" id="rd_group" name="scope_group_id" maxlength="64"
                               placeholder="<?= esc(lang('AccessControl.delegationsRecvView.fScopeGroupPh'), 'attr') ?>">
                    <?php endif; ?>
                </div>
                <div class="fld">
                    <label for="rd_days"><?= esc(lang('AccessControl.delegationsRecvView.fDurationDays')) ?></label>
                    <input type="number" id="rd_days" name="duration_days" min="1" value="30" required>
                </div>
                <div class="fld full">
                    <label for="rd_purpose"><?= esc(lang('AccessControl.delegationsRecvView.fPurpose')) ?></label>
                    <input type="text" id="rd_purpose" name="purpose" required
                           placeholder="<?= esc(lang('AccessControl.delegationsRecvView.fPurposePh'), 'attr') ?>">
                </div>
                <div class="submit">
                    <button type="submit" class="btn deleg"><?= esc(lang('AccessControl.delegationsRecvView.redelegSubmit')) ?></button>
                </div>
            </form>
        </section>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.delegationsRecvView.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.delegationsRecvView.countOne' : 'AccessControl.delegationsRecvView.count', (string) $count)) ?></p>
            <?php foreach ($delegations as $d): ?>
                <?php
                $status  = strtolower((string) ($d['status'] ?? ''));
                $group   = (string) ($d['scope_group_id'] ?? '');
                $incDesc = ! empty($d['include_descendants']);
                ?>
                <article class="dl<?= in_array($status, ['revoked','expired'], true) ? ' revoked' : '' ?>">
                    <div class="top">
                        <span class="perm"><?= esc((string) ($d['permission_code'] ?? '')) ?></span>
                        <span class="st <?= $status ?>"><?= esc($vocab('status', $status)) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag"><?= esc(lang('AccessControl.delegationsRecvView.colFromWhom')) ?>: <span class="mono"><?= esc((string) ($d['delegator_id'] ?? '—')) ?></span></span>
                        <span class="tag">
                            <?= esc(lang('AccessControl.delegationsRecvView.colScope')) ?>:
                            <?php if ($group === ''): ?>
                                <?= esc(lang('AccessControl.delegationsRecvView.orgWide')) ?>
                            <?php else: ?>
                                <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.delegationsRecvView.withDescendants')) : '' ?>
                            <?php endif; ?>
                        </span>
                        <span class="tag"><?= esc(lang('AccessControl.delegationsRecvView.colDepth')) ?>: <?= esc((string) ($d['depth'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('AccessControl.delegationsRecvView.colTo')) ?>: <?= esc((string) ($d['effective_to'] ?? '—')) ?></span>
                    </div>
                    <?php $did = rawurlencode((string) ($d['id'] ?? '')); ?>
                    <?php if ($did !== '' && $status === 'active'): ?>
                    <div class="acts">
                        <a class="btn deleg" href="<?= esc($url('delegations/' . $did . '/chain'), 'attr') ?>"><?= esc(lang('AccessControl.delegationsRecvView.viewChain')) ?></a>
                        <form method="post" action="<?= esc($url('delegations/' . $did . '/revoke'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.delegationsRecvView.revokeConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                            <input type="text" name="reason" required
                                   placeholder="<?= esc(lang('AccessControl.delegationsRecvView.reasonPh'), 'attr') ?>">
                            <button type="submit" class="btn revoke"><?= esc(lang('AccessControl.delegationsRecvView.revoke')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
