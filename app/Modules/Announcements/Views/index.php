<?php
/**
 * Announcements console — compose, submit, approve (SoD), cancel.
 *
 * @var list<array<string,mixed>> $announcements
 * @var list<string> $modes
 * @var string $csrf
 */
$rows  = $announcements ?? [];
$modes = $modes ?? ['self'];
$csrf  = $csrf ?? '';
$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Announcements.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width:960px; margin:0 auto; padding:5vh 20px 60px; }


        button { margin-top:8px; border:0; border-radius:8px; padding:8px 14px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Announcements.heading')) ?></h1>
    <p class="sub"><?= esc(lang('Announcements.sub')) ?></p>
    <p><a href="/announcements/inbox"><?= esc(lang('Announcements.inboxLink')) ?></a></p>
    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty"><?= esc(lang('Announcements.empty')) ?></p>
    <?php else: ?>
        <table>
            <thead><tr>
                <th><?= esc(lang('Announcements.colTitle')) ?></th>
                <th><?= esc(lang('Announcements.colStatus')) ?></th>
                <th><?= esc(lang('Announcements.colAudience')) ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $a): ?>
                <?php $id = (string) ($a['id'] ?? ''); $st = (string) ($a['status'] ?? ''); ?>
                <tr>
                    <td><?= esc((string) ($a['title'] ?? '')) ?></td>
                    <td><span class="pill"><?= esc($st) ?></span></td>
                    <td><?= esc((string) ($a['audience_count'] ?? '—')) ?></td>
                    <td>
                        <?php if ($st === 'draft'): ?>
                            <form class="inline" method="post" action="/announcements/<?= esc($id, 'attr') ?>/submit">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button type="submit"><?= esc(lang('Announcements.submitBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($st === 'pending_approval'): ?>
                            <form class="inline" method="post" action="/announcements/<?= esc($id, 'attr') ?>/approve">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button type="submit"><?= esc(lang('Announcements.approveBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($st !== 'cancelled'): ?>
                            <form class="inline" method="post" action="/announcements/<?= esc($id, 'attr') ?>/cancel">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button class="ghost" type="submit"><?= esc(lang('Announcements.cancelBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form class="card" method="post" action="/announcements">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <h2><?= esc(lang('Announcements.composeHeading')) ?></h2>
        <p class="hint"><?= esc(lang('Announcements.targetingHint')) ?></p>
        <div class="grid">
            <div class="full"><label for="t"><?= esc(lang('Announcements.fTitle')) ?></label>
                <input id="t" name="title" required maxlength="200"></div>
            <div class="full"><label for="b"><?= esc(lang('Announcements.fBody')) ?></label>
                <textarea id="b" name="body" required></textarea></div>
            <div><label for="g"><?= esc(lang('Announcements.fGroup')) ?></label>
                <input id="g" name="group_id" maxlength="36"></div>
            <div><label for="m"><?= esc(lang('Announcements.fScope')) ?></label>
                <select id="m" name="scope_mode">
                    <?php foreach ($modes as $mode): ?>
                        <option value="<?= esc($mode, 'attr') ?>"><?= esc($mode) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label for="k"><?= esc(lang('Announcements.fGroupKind')) ?></label>
                <input id="k" name="group_kind" maxlength="50" placeholder="cell"></div>
            <div><label for="mr"><?= esc(lang('Announcements.fMemRoles')) ?></label>
                <input id="mr" name="membership_roles" placeholder="leader, treasurer"></div>
            <div><label for="pr"><?= esc(lang('Announcements.fPlatRoles')) ?></label>
                <input id="pr" name="platform_roles" placeholder="elder"></div>
            <div><label for="u"><?= esc(lang('Announcements.fUsers')) ?></label>
                <input id="u" name="user_ids" placeholder="<?= esc(lang('Announcements.usersHint'), 'attr') ?>"></div>
            <div><label for="e"><?= esc(lang('Announcements.fEnds')) ?></label>
                <input id="e" name="ends_at" placeholder="2026-12-31"></div>
            <div><label for="n"><?= esc(lang('Announcements.fNotify')) ?></label>
                <select id="n" name="notify"><option value="0"><?= esc(lang('Announcements.notifyNo')) ?></option>
                    <option value="1"><?= esc(lang('Announcements.notifyYes')) ?></option></select></div>
            <div><label for="tk"><?= esc(lang('Announcements.fTemplate')) ?></label>
                <input id="tk" name="template_key" value="announcement_published"></div>
            <div><label for="ch"><?= esc(lang('Announcements.fChannel')) ?></label>
                <select id="ch" name="channel"><option value="email">email</option><option value="sms">sms</option><option value="inapp">inapp</option></select></div>
        </div>
        <button type="submit"><?= esc(lang('Announcements.createBtn')) ?></button>
    </form>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
