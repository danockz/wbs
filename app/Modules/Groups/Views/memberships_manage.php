<?php
/**
 * GROUP ROSTER management console (GET /groups/{id}/memberships) — the browser
 * face of GroupMembershipController::listForGroup, which otherwise rendered the
 * generic admin console. Lists a group's memberships (default: active) and turns
 * the page into a management console: an add-member form at the top, and per-row
 * change-role + leave (remove) controls.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.roster.*') with English
 * fallback; the {0} count is interpolated via $li(). Ids/roles/types are server
 * data shown verbatim & escaped.
 *
 * Writes are no-JS PRG forms:
 *   - add    → POST /groups/{id}/memberships          (nested; webcsrf)
 *   - role   → POST /memberships/{mid}/role           (by id; webcsrf; return=)
 *   - leave  → POST /memberships/{mid}/leave          (by id; webcsrf; return=; confirm)
 * The CSRF field is `_csrf` (matches WebCsrfFilter); leave is confirm()-gated.
 *
 * @var list<array<string,mixed>> $memberships membership rows (with display_name)
 * @var string                    $groupId     the group whose roster this is
 * @var string                    $status      the status filter in effect
 * @var list<string>              $types       allowed membership types
 * @var string                    $csrf        webcsrf token for the inline forms
 */
$memberships = $memberships ?? [];
$groupId     = $groupId ?? '';
$status      = $status ?? 'active';
$types       = $types ?? ['member', 'leader', 'activity', 'department', 'team', 'guest'];
$csrf        = $csrf ?? '';
$count       = count($memberships);
$roster      = is_array($roster ?? null) ? $roster : [];
// Users already on this roster shouldn't be offered again by the add-member picker.
$memberIds = [];
foreach ($memberships as $m) {
    $uid = (string) ($m['user_id'] ?? '');
    if ($uid !== '') {
        $memberIds[$uid] = true;
    }
}
$sidAttr     = $groupId !== '' ? rawurlencode($groupId) : '';
$returnPath  = $groupId !== '' ? '/groups/' . $sidAttr . '/memberships' : '/groups';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.roster.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 940px; margin: 0 auto; padding: 5vh 20px 60px; }

        .group { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#67e8f9;
            border:1px solid #0e7490; border-radius:6px; padding:2px 8px; }

        .who { font-weight:600; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.roster.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.roster.groupLabel')) ?>: <span class="group"><?= esc($groupId !== '' ? $groupId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($groupId !== ''): ?>
            <form class="adder" method="post" action="/groups/<?= esc($sidAttr, 'attr') ?>/memberships">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h2><?= esc(lang('Groups.roster.addHeading')) ?></h2>
                <div class="grid">
                    <div>
                        <label for="a-user"><?= esc(lang('Groups.roster.fUser')) ?></label>
                        <?php
                        $userCandidates = [];
                        foreach ($roster as $u) {
                            $uid = (string) ($u['id'] ?? '');
                            if ($uid !== '' && ! isset($memberIds[$uid])) {
                                $userCandidates[] = $u;
                            }
                        }
                        ?>
                        <?php if ($userCandidates !== []): ?>
                            <select id="a-user" name="user_id" required>
                                <option value=""><?= esc(lang('Groups.roster.fUserNone')) ?></option>
                                <?php foreach ($userCandidates as $u): ?>
                                    <?php
                                    $uid    = (string) $u['id'];
                                    $uname  = trim((string) ($u['display_name'] ?? ''));
                                    $ulabel = $uname !== '' ? $uname : $uid;
                                    ?>
                                    <option value="<?= esc($uid, 'attr') ?>"><?= esc($ulabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" id="a-user" name="user_id" required maxlength="64" placeholder="<?= esc(lang('Groups.roster.fUserPh'), 'attr') ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="a-type"><?= esc(lang('Groups.roster.fType')) ?></label>
                        <select id="a-type" name="membership_type">
                            <?php foreach ($types as $t): ?>
                                <option value="<?= esc($t, 'attr') ?>"><?= esc($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="a-role"><?= esc(lang('Groups.roster.fRole')) ?></label>
                        <input type="text" id="a-role" name="role" value="member" maxlength="64">
                    </div>
                    <div class="chk">
                        <input type="checkbox" id="a-appr" name="requires_approval" value="1">
                        <label for="a-appr" style="margin:0"><?= esc(lang('Groups.roster.fRequiresApproval')) ?></label>
                    </div>
                </div>
                <button type="submit"><?= esc(lang('Groups.roster.addBtn')) ?></button>
            </form>
        <?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.roster.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.roster.countOne' : 'Groups.roster.count', (string) $count)) ?></p>
            <?php foreach ($memberships as $m): ?>
                <?php
                $mid    = (string) ($m['id'] ?? '');
                $midAttr = rawurlencode($mid);
                $mstatus = strtolower((string) ($m['status'] ?? ''));
                $name    = (string) ($m['display_name'] ?? '');
                ?>
                <article class="item">
                    <div class="itemtop">
                        <span class="who"><?= esc($name !== '' ? $name : lang('Groups.roster.unnamed')) ?></span>
                        <span class="uid"><?= esc((string) ($m['user_id'] ?? '')) ?></span>
                        <span class="tag"><?= esc(lang('Groups.roster.colType')) ?>: <?= esc((string) ($m['membership_type'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('Groups.roster.colRole')) ?>: <?= esc((string) ($m['role'] ?? '—')) ?></span>
                        <span class="st <?= esc($mstatus, 'attr') ?>"><?= esc($mstatus !== '' ? $mstatus : '—') ?></span>
                    </div>
                    <?php if ($mid !== '' && $mstatus === 'active'): ?>
                        <div class="acts">
                            <form method="post" action="<?= esc($url('memberships/' . $midAttr . '/role'), 'attr') ?>">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                                <div class="fld">
                                    <label for="nr-<?= esc($midAttr, 'attr') ?>"><?= esc(lang('Groups.roster.newRoleLabel')) ?></label>
                                    <input id="nr-<?= esc($midAttr, 'attr') ?>" name="role" required maxlength="64" placeholder="<?= esc((string) ($m['role'] ?? ''), 'attr') ?>">
                                </div>
                                <button type="submit" class="btn role"><?= esc(lang('Groups.roster.changeRoleBtn')) ?></button>
                            </form>
                            <form method="post" action="<?= esc($url('memberships/' . $midAttr . '/leave'), 'attr') ?>"
                                  onsubmit="return confirm('<?= esc(lang('Groups.roster.leaveConfirm'), 'js') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                                <div class="fld">
                                    <label for="lr-<?= esc($midAttr, 'attr') ?>"><?= esc(lang('Groups.roster.leaveReasonLabel')) ?></label>
                                    <input id="lr-<?= esc($midAttr, 'attr') ?>" name="reason">
                                </div>
                                <button type="submit" class="btn leave"><?= esc(lang('Groups.roster.leaveBtn')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
