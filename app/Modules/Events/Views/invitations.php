<?php
/**
 * INVITATIONS console (GET /events/{id}/invitations) — the browser face of the
 * invite-only allow-list (gap G5). Two mechanisms, both no-JS PRG forms:
 *
 *  A) DIRECT invitations to a named identity (member / email / phone):
 *       - issue  → POST /events/{id}/invitations
 *       - revoke → POST /events/{id}/invitations/{iid}/revoke   (confirm() first)
 *     An email/phone invite is also delivered over Notifications carrying the
 *     shareable link.
 *
 *  B) The ONE shareable, cloaked per-event LINK — broadcast on social media and
 *     enclosed in direct invites. Reusable, bounded by a configurable mode
 *     (expiry | max_redemptions | capacity):
 *       - generate/reconfigure → POST /events/{id}/invitations/link
 *       - enable/disable       → POST /events/{id}/invitations/link/toggle
 *     The cloaked URL is broadcast, so it is unguessable but NOT secret; it is
 *     shown here in full so the organizer can copy/post it.
 *
 * Every form carries the `_csrf` field (WebCsrfFilter). SELF-CONTAINED page:
 * includes _locale.php for a locale-aware <html lang dir> (RTL for Arabic); copy
 * via lang('Events.invite.*').
 *
 * @var array<string,mixed>       $event       the event row
 * @var string                    $event_id    event id (write routes)
 * @var list<array<string,mixed>> $invitations event_invitations rows
 * @var array<string,mixed>|null  $link        shareable-link public view (or null)
 * @var string                    $csrf        webcsrf token
 */
$event       = $event ?? [];
$eventId     = $event_id ?? '';
$invitations = $invitations ?? [];
$link        = $link ?? null;
$csrf        = $csrf ?? '';
$sidAttr     = $eventId !== '' ? rawurlencode($eventId) : '';

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';
$linkMode = (string) ($link['mode'] ?? 'expiry');
?>

<?php ob_start(); ?>
<?= esc(lang('Events.invite.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 980px; margin: 0 auto; padding: 5vh 20px 60px; }


        .event { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#93c5fd; border:1px solid #2563eb; border-radius:6px; padding:2px 8px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .pill.pending { background:#3f3410; color:#fde68a; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .card h3 { margin:0 0 12px; font-size:.98rem; }


        input:focus, select:focus { outline:2px solid #2563eb; border-color:#2563eb; }


        button { background:#2563eb; border:0; border-radius:8px; color:#fff; padding:9px 16px; font-size:.86rem; cursor:pointer; }


        .meta { color:#94a3b8; font-size:.8rem; margin:6px 0 0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
    <h1><?= esc(lang('Events.invite.heading')) ?>
        <span class="event"><?= esc((string) ($event['title'] ?? $eventId)) ?></span></h1>
    <p class="sub"><?= esc(lang('Events.invite.sub')) ?></p>
    <p><a class="back" href="/events/<?= esc($sidAttr, 'attr') ?>">&larr; <?= esc((string) ($event['title'] ?? lang('Events.eventFallback'))) ?></a></p>

    <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <!-- ============ B) Shareable, cloaked per-event invite link ============ -->
    <h2><?= esc(lang('Events.invite.linkHeading')) ?></h2>
    <div class="card">
        <p class="sub" style="margin:0 0 10px;"><?= esc(lang('Events.invite.linkSub')) ?></p>
        <?php if ($link !== null): ?>
            <p class="meta">
                <span class="pill <?= ($link['active'] ?? true) ? 'accepted' : 'revoked' ?>">
                    <?= esc(lang('Events.invite.' . (($link['active'] ?? true) ? 'linkStatusActive' : 'linkStatusDisabled'))) ?>
                </span>
                &nbsp;·&nbsp; <?= esc(lang('Events.invite.linkRedeemed')) ?>: <strong><?= esc((string) ($link['redeemed_count'] ?? 0)) ?></strong>
                <?php if ($linkMode === 'max_redemptions' && ($link['max_redemptions'] ?? null) !== null): ?>
                    / <?= esc((string) $link['max_redemptions']) ?>
                <?php elseif ($linkMode === 'expiry' && ($link['expires_at'] ?? null)): ?>
                    &nbsp;·&nbsp; <?= esc((string) $link['expires_at']) ?>
                <?php endif; ?>
            </p>
            <?php if ((string) ($link['url'] ?? '') !== ''): ?>
                <label for="shareurl"><?= esc(lang('Events.invite.linkUrlLabel')) ?></label>
                <input id="shareurl" class="urlbox" type="text" readonly value="<?= esc((string) $link['url'], 'attr') ?>"
                       onclick="this.select()">
            <?php endif; ?>
            <form class="inline" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/invitations/link/toggle" style="margin-top:12px;">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <input type="hidden" name="active" value="<?= ($link['active'] ?? true) ? '0' : '1' ?>">
                <button class="ghost" type="submit"><?= esc(lang('Events.invite.' . (($link['active'] ?? true) ? 'linkDisableBtn' : 'linkEnableBtn'))) ?></button>
            </form>
        <?php else: ?>
            <p class="empty"><?= esc(lang('Events.invite.linkNone')) ?></p>
        <?php endif; ?>

        <form method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/invitations/link" style="margin-top:14px;">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="row">
                <div>
                    <label for="mode"><?= esc(lang('Events.invite.linkHeading')) ?></label>
                    <select id="mode" name="mode">
                        <option value="expiry" <?= $linkMode === 'expiry' ? 'selected' : '' ?>><?= esc(lang('Events.invite.linkModeExpiry')) ?></option>
                        <option value="max_redemptions" <?= $linkMode === 'max_redemptions' ? 'selected' : '' ?>><?= esc(lang('Events.invite.linkModeMax')) ?></option>
                        <option value="capacity" <?= $linkMode === 'capacity' ? 'selected' : '' ?>><?= esc(lang('Events.invite.linkModeCapacity')) ?></option>
                    </select>
                </div>
                <div>
                    <label for="expires_at"><?= esc(lang('Events.invite.linkExpiresLabel')) ?></label>
                    <input id="expires_at" name="expires_at" type="datetime-local">
                </div>
                <div>
                    <label for="max_redemptions"><?= esc(lang('Events.invite.linkMaxLabel')) ?></label>
                    <input id="max_redemptions" name="max_redemptions" type="number" min="1" inputmode="numeric">
                </div>
                <div>
                    <button type="submit"><?= esc(lang('Events.invite.' . ($link !== null ? 'linkRotateBtn' : 'linkGenerateBtn'))) ?></button>
                </div>
                <?php if ($link !== null): ?>
                    <input type="hidden" name="rotate" value="1">
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ============ A) Direct invitations to a named identity ============ -->
    <form class="card" method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/invitations">
        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
        <h3><?= esc(lang('Events.invite.issueHeading')) ?></h3>
        <div class="row">
            <div>
                <label for="channel"><?= esc(lang('Events.invite.colChannel')) ?></label>
                <select id="channel" name="channel">
                    <option value="user"><?= esc(lang('Events.invite.channelUser')) ?></option>
                    <option value="email"><?= esc(lang('Events.invite.channelEmail')) ?></option>
                    <option value="phone"><?= esc(lang('Events.invite.channelPhone')) ?></option>
                </select>
            </div>
            <div>
                <label for="invitee_user_id"><?= esc(lang('Events.invite.channelUser')) ?></label>
                <input id="invitee_user_id" name="invitee_user_id" type="text" autocomplete="off">
            </div>
            <div>
                <label for="invitee_email"><?= esc(lang('Events.invite.channelEmail')) ?></label>
                <input id="invitee_email" name="invitee_email" type="email" autocomplete="off">
            </div>
            <div>
                <label for="invitee_phone"><?= esc(lang('Events.invite.channelPhone')) ?></label>
                <input id="invitee_phone" name="invitee_phone" type="tel" autocomplete="off">
            </div>
            <div>
                <button type="submit"><?= esc(lang('Events.invite.issueBtn')) ?></button>
            </div>
        </div>
    </form>

    <h2><?= esc(lang('Events.invite.colInvitee')) ?></h2>
    <?php if ($invitations === []): ?>
        <p class="empty"><?= esc(lang('Events.invite.noInvites')) ?></p>
    <?php else: ?>
        <table>
            <thead><tr>
                <th><?= esc(lang('Events.invite.colChannel')) ?></th>
                <th><?= esc(lang('Events.invite.colInvitee')) ?></th>
                <th><?= esc(lang('Events.invite.colStatus')) ?></th>
                <th><?= esc(lang('Events.invite.colNotified')) ?></th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($invitations as $iv):
                $ch     = (string) ($iv['channel'] ?? 'user');
                $chLbl  = (string) lang('Events.invite.channel' . ucfirst($ch));
                $who    = (string) ($iv['invitee_user_id'] ?? $iv['invitee_email'] ?? $iv['invitee_phone'] ?? '—');
                $status = (string) ($iv['status'] ?? 'pending');
                $iid    = (string) ($iv['id'] ?? '');
                $notif  = (string) ($iv['notified_at'] ?? '');
            ?>
                <tr>
                    <td><?= esc($chLbl) ?></td>
                    <td><?= esc($who) ?></td>
                    <td><span class="pill <?= esc($status, 'attr') ?>"><?= esc(ucfirst($status)) ?></span></td>
                    <td><?= $notif !== '' ? esc($notif) : '<span class="hint">&mdash;</span>' ?></td>
                    <td>
                        <?php if ($status === 'pending'): ?>
                        <form method="post" action="/events/<?= esc($sidAttr, 'attr') ?>/invitations/<?= esc(rawurlencode($iid), 'attr') ?>/revoke"
                              onsubmit="return confirm('<?= esc(lang('Events.invite.revokeBtn'), 'attr') ?>?');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <button class="danger" type="submit"><?= esc(lang('Events.invite.revokeBtn')) ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
