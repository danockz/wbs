<?php
/**
 * Notification templates console (GET /notifications/templates).
 *
 * @var list<array<string,mixed>> $templates
 * @var ?string $group_id
 * @var list<array<string,mixed>> $groups
 * @var list<string> $keys
 * @var string $csrf
 */
$templates = $templates ?? [];
$group_id  = $group_id ?? null;
$groups    = $groups ?? [];
$keys      = $keys ?? [];
$csrf      = $csrf ?? '';
$session   = function_exists('session') ? session() : null;
$flashOk   = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr  = $session ? (string) ($session->getFlashdata('error') ?? '') : '';
include __DIR__ . '/_locale.php';
?>

<?php ob_start(); ?>
<?= esc(lang('Notifications.templates.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        .wrap { max-width:960px; margin:0 auto; padding:5vh 20px 60px; }


        button { margin-top:12px; border:0; border-radius:8px; padding:8px 16px; font-weight:600; background:#0e7666; color:#fff; cursor:pointer; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
    <h1><?= esc(lang('Notifications.templates.heading')) ?></h1>
    <p class="sub"><?= esc(lang('Notifications.templates.sub')) ?></p>
    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <form method="get" action="/notifications/templates">
        <label for="g"><?= esc(lang('Notifications.templates.fGroup')) ?></label>
        <select id="g" name="group">
            <option value=""><?= esc(lang('Notifications.templates.orgLevel')) ?></option>
            <?php foreach ($groups as $g): ?>
                <option value="<?= esc((string) ($g['id'] ?? ''), 'attr') ?>"<?= ((string) ($g['id'] ?? '') === (string) $group_id) ? ' selected' : '' ?>>
                    <?= esc((string) ($g['name'] ?? $g['id'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= esc(lang('Notifications.templates.switchGroup')) ?></button>
    </form>

    <?php if ($templates === []): ?>
        <p class="empty"><?= esc(lang('Notifications.templates.empty')) ?></p>
    <?php else: ?>
        <table>
            <thead><tr>
                <th><?= esc(lang('Notifications.templates.colKey')) ?></th>
                <th><?= esc(lang('Notifications.templates.colChannel')) ?></th>
                <th><?= esc(lang('Notifications.templates.colLocale')) ?></th>
                <th><?= esc(lang('Notifications.templates.colAudience')) ?></th>
                <th><?= esc(lang('Notifications.templates.colVersion')) ?></th>
                <th><?= esc(lang('Notifications.templates.colStatus')) ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($templates as $t): ?>
                <?php $tid = (string) ($t['id'] ?? ''); $kind = (string) ($t['audience_kind'] ?? ''); $role = (string) ($t['audience_role'] ?? ''); ?>
                <tr>
                    <td><?= esc((string) ($t['key_name'] ?? '')) ?></td>
                    <td><?= esc((string) ($t['channel'] ?? '')) ?></td>
                    <td><?= esc((string) ($t['locale'] ?? '')) ?></td>
                    <td><?= esc($kind === '' ? lang('Notifications.templates.audienceBase') : ($kind . ':' . $role)) ?></td>
                    <td><?= esc((string) ($t['version'] ?? 1)) ?></td>
                    <td><span class="pill"><?= esc((string) ($t['status'] ?? '')) ?></span></td>
                    <td>
                        <a href="/notifications/templates/<?= esc($tid, 'attr') ?>/edit"><?= esc(lang('Notifications.templates.edit')) ?></a>
                        <?php if ((string) ($t['status'] ?? '') !== 'retired'): ?>
                            <form method="post" action="/notifications/templates/<?= esc($tid, 'attr') ?>/retire" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button type="submit"><?= esc(lang('Notifications.templates.retireBtn')) ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form class="card" method="post" action="/notifications/templates">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <h2><?= esc(lang('Notifications.templates.addHeading')) ?></h2>
        <div class="grid">
            <div><label for="k"><?= esc(lang('Notifications.templates.colKey')) ?></label>
                <input id="k" name="key_name" list="known-keys" required maxlength="80">
                <datalist id="known-keys"><?php foreach ($keys as $k): ?><option value="<?= esc($k, 'attr') ?>"><?php endforeach; ?></datalist>
            </div>
            <div><label for="ch"><?= esc(lang('Notifications.templates.colChannel')) ?></label>
                <select id="ch" name="channel">
                    <option value="email">email</option>
                    <option value="sms">sms</option>
                    <option value="inapp">inapp</option>
                    <option value="push">push</option>
                </select>
            </div>
            <div><label for="loc"><?= esc(lang('Notifications.templates.colLocale')) ?></label>
                <select id="loc" name="locale">
                    <?php foreach (['en','fr','es','pt','zh','ar'] as $loc): ?>
                        <option value="<?= $loc ?>"><?= $loc ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label for="ak"><?= esc(lang('Notifications.templates.fAudienceKind')) ?></label>
                <select id="ak" name="audience_kind">
                    <option value=""><?= esc(lang('Notifications.templates.audienceBase')) ?></option>
                    <option value="membership"><?= esc(lang('Notifications.templates.kindMembership')) ?></option>
                    <option value="platform"><?= esc(lang('Notifications.templates.kindPlatform')) ?></option>
                </select>
            </div>
            <div><label for="ar"><?= esc(lang('Notifications.templates.fAudienceRole')) ?></label>
                <input id="ar" name="audience_role" maxlength="40" placeholder="leader">
            </div>
            <div><label for="gid"><?= esc(lang('Notifications.templates.fGroup')) ?></label>
                <input id="gid" name="group_id" maxlength="36" value="<?= esc((string) ($group_id ?? ''), 'attr') ?>">
            </div>
            <div class="full"><label for="sub"><?= esc(lang('Notifications.templates.fSubject')) ?></label>
                <input id="sub" name="subject" maxlength="255"></div>
            <div class="full"><label for="body"><?= esc(lang('Notifications.templates.fBody')) ?></label>
                <textarea id="body" name="body" required></textarea>
                <div class="hint" style="color:#64748b;font-size:.72rem;margin-top:4px"><?= esc(lang('Notifications.templates.bodyHint')) ?></div>
            </div>
        </div>
        <button type="submit"><?= esc(lang('Notifications.templates.addBtn')) ?></button>
    </form>
</main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
