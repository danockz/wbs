<?php
/**
 * BROADCAST CAMPAIGNS page (GET /notifications/campaigns) — the browser face of
 * CampaignController::index. Lists the organization's message campaigns with
 * their channel, status in the approval lifecycle, priority, and frozen audience
 * count.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Notifications.*') with English fallback; the {0} count is interpolated
 * in PHP via $li() with PHP singular/plural. `status` and `priority` are
 * localized with a raw-value fallback; name/template/channel/audience are server
 * data shown verbatim (channel humanized in-view).
 *
 * The dashboard also carries the leader WRITE forms: draft a campaign, submit a
 * draft for approval (freezing an audience count), and approve a pending campaign.
 * Each posts to a webcsrf-guarded route; the controller PRG-redirects back here
 * with a localized flash. The requester/approver come from the authenticated
 * session (SoD by identity), so no id field is exposed. No-JS friendly.
 *
 * @var list<array<string,mixed>> $campaigns
 * @var string                    $csrf
 */
$campaigns = $campaigns ?? [];
$count     = count($campaigns);
$csrf      = $csrf ?? '';

include __DIR__ . '/_locale.php';

$channels   = ['email', 'sms', 'push', 'whatsapp', 'in_app'];
$priorities = ['essential', 'high', 'normal', 'low'];
$flashOk    = function_exists('session') ? session('success') : null;
$flashErr   = function_exists('session') ? session('error') : null;

$statusColor = static fn (string $s): string => match ($s) {
    'sent'             => '#22c55e',
    'approved'         => '#38bdf8',
    'queued'           => '#a78bfa',
    'pending_approval' => '#f59e0b',
    'cancelled'        => '#ef4444',
    default            => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Notifications.status.' . $s);

    return $v === 'Notifications.status.' . $s ? ucwords(str_replace('_', ' ', $s)) : $v;
};
$priorityLbl = static function (string $p): string {
    if ($p === '') {
        return '';
    }
    $v = lang('Notifications.priority.' . $p);

    return $v === 'Notifications.priority.' . $p ? ucfirst($p) : $v;
};
$labelize = static fn (string $s): string => ucwords(str_replace('_', ' ', $s));
?>

<?php ob_start(); ?>
<?= esc(lang('Notifications.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        input:focus, select:focus { outline:none; border-color:#f472b6; }


        .rowform input { width:6.5rem; padding:5px 7px; font-size:.76rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Notifications.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Notifications.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <!-- Draft a campaign -->
        <details class="card">
            <summary><?= esc(lang('Notifications.admin.draftHeading')) ?></summary>
            <form class="form" method="post" action="/notifications/campaigns">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="full">
                    <label><?= esc(lang('Notifications.admin.nameLabel')) ?></label>
                    <input name="name" required maxlength="160" placeholder="<?= esc(lang('Notifications.admin.namePh'), 'attr') ?>">
                </div>
                <div class="full">
                    <label><?= esc(lang('Notifications.admin.templateLabel')) ?></label>
                    <input name="template_key" placeholder="<?= esc(lang('Notifications.admin.templatePh'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Notifications.admin.channelLabel')) ?></label>
                    <select name="channel">
                        <?php foreach ($channels as $ch): ?>
                            <option value="<?= esc($ch, 'attr') ?>"><?= esc($labelize($ch)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Notifications.admin.priorityLabel')) ?></label>
                    <select name="priority">
                        <?php foreach ($priorities as $pr): ?>
                            <option value="<?= esc($pr, 'attr') ?>" <?= $pr === 'normal' ? 'selected' : '' ?>><?= esc($priorityLbl($pr)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="full">
                    <button class="btn" type="submit"><?= esc(lang('Notifications.admin.draftBtn')) ?></button>
                    <div class="hint"><?= esc(lang('Notifications.admin.draftHint')) ?></div>
                </div>
            </form>
        </details>

        <?php if ($campaigns === []): ?>
            <p class="empty"><?= esc(lang('Notifications.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Notifications.countOne' : 'Notifications.count', (string) $count)) ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?= esc(lang('Notifications.colName')) ?></th>
                        <th><?= esc(lang('Notifications.colChannel')) ?></th>
                        <th><?= esc(lang('Notifications.colStatus')) ?></th>
                        <th><?= esc(lang('Notifications.colPriority')) ?></th>
                        <th class="num"><?= esc(lang('Notifications.colAudience')) ?></th>
                        <th><?= esc(lang('Notifications.admin.colActions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                        <?php
                        $name     = (string) ($c['name'] ?? '');
                        $tpl      = (string) ($c['template_key'] ?? '');
                        $channel  = (string) ($c['channel'] ?? '');
                        $status   = (string) ($c['status'] ?? '');
                        $priority = (string) ($c['priority'] ?? '');
                        $aud      = $c['audience_count'] ?? null;
                        $cId      = (string) ($c['id'] ?? '');
                        $sColor   = $statusColor($status);
                        ?>
                        <tr>
                            <td>
                                <span class="name"><?= esc($name) ?></span>
                                <?php if ($tpl !== ''): ?><span class="tpl"><?= esc(lang('Notifications.templateKey')) ?>: <?= esc($tpl) ?></span><?php endif; ?>
                            </td>
                            <td><?= $channel === '' ? '<span class="muted">—</span>' : esc($labelize($channel)) ?></td>
                            <td><span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span></td>
                            <td><?= $priority === '' ? '<span class="muted">—</span>' : esc($priorityLbl($priority)) ?></td>
                            <td class="num"><?= $aud === null ? '<span class="muted">' . esc(lang('Notifications.noAudience')) . '</span>' : esc((string) $aud) ?></td>
                            <td>
                                <?php if ($cId !== '' && $status === 'draft'): ?>
                                    <form class="rowform" method="post" action="/notifications/campaigns/<?= esc(rawurlencode($cId), 'attr') ?>/submit"
                                          onsubmit="return confirm('<?= esc(lang('Notifications.admin.submitConfirm'), 'attr') ?>');">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="number" name="audience_count" min="0" value="0" aria-label="<?= esc(lang('Notifications.admin.audienceLabel'), 'attr') ?>">
                                        <button class="btn ghost" type="submit"><?= esc(lang('Notifications.admin.submitBtn')) ?></button>
                                    </form>
                                <?php elseif ($cId !== '' && $status === 'pending_approval'): ?>
                                    <form class="rowform" method="post" action="/notifications/campaigns/<?= esc(rawurlencode($cId), 'attr') ?>/approve"
                                          onsubmit="return confirm('<?= esc(lang('Notifications.admin.approveConfirm'), 'attr') ?>');">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="btn" type="submit"><?= esc(lang('Notifications.admin.approveBtn')) ?></button>
                                    </form>
                                    <div class="hint"><?= esc(lang('Notifications.admin.sodHint')) ?></div>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
