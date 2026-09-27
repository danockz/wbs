<?php
/**
 * MEETING SCHEDULE page (GET /meetings) — the browser face of
 * MeetingController::index. Lists the organization's meetings and webinars with
 * their provider, time window, status, access policy, and hosted join link.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Meetings.*') with English fallback; the {0} count is interpolated in PHP
 * via $li() with PHP singular/plural. `status`, `provider`, `mode` and `access`
 * are localized with a raw-value fallback; title/times/join links are server data
 * shown verbatim (times formatted defensively).
 *
 * The dashboard also carries the leader WRITE forms: schedule a meeting, change a
 * meeting's status, and grant a participant a short-lived join token. Each posts
 * to a webcsrf-guarded route and the controller PRG-redirects back here with a
 * localized flash — no-JS friendly, and a browser never sees raw JSON.
 *
 * @var list<array<string,mixed>> $meetings
 * @var string                    $csrf
 */
$meetings = $meetings ?? [];
$count    = count($meetings);
$csrf     = $csrf ?? '';
$roster   = is_array($roster ?? null) ? $roster : [];

include __DIR__ . '/_locale.php';

$statuses  = ['scheduled', 'live', 'ended', 'canceled'];
$providers = ['zoom', 'meet', 'teams', 'jitsi', 'link'];
$roles     = ['host', 'cohost', 'attendee'];
$flashOk   = function_exists('session') ? session('success') : null;
$flashErr  = function_exists('session') ? session('error') : null;

$statusColor = static fn (string $s): string => match ($s) {
    'live'      => '#22c55e',
    'scheduled' => '#38bdf8',
    'ended'     => '#94a3b8',
    'canceled'  => '#ef4444',
    default     => '#94a3b8',
};
$vocab = static function (string $group, string $key): string {
    if ($key === '') {
        return '';
    }
    $v = lang("Meetings.$group.$key");

    return $v === "Meetings.$group.$key" ? ucwords(str_replace('_', ' ', $key)) : $v;
};
$fmtWhen = static function (?string $start, ?string $end): string {
    if ($start === null || $start === '') {
        return '';
    }
    $ts = strtotime($start);
    if ($ts === false) {
        return $start;
    }
    $out = date('Y-m-d H:i', $ts);
    if ($end !== null && $end !== '' && ($te = strtotime($end)) !== false) {
        $out .= ' – ' . (date('Y-m-d', $ts) === date('Y-m-d', $te) ? date('H:i', $te) : date('Y-m-d H:i', $te));
    }

    return $out . ' UTC';
};
?>

<?php ob_start(); ?>
<?= esc(lang('Meetings.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1080px; margin: 0 auto; padding: 5vh 20px 60px; }


        .title { font-weight:700; }


        .mode { display:inline-block; margin-inline-start:8px; font-size:.6rem; text-transform:uppercase; letter-spacing:.05em;
            color:#a78bfa; border:1px solid #a78bfa55; border-radius:999px; padding:1px 7px; vertical-align:middle; }


        input:focus, select:focus { outline:none; border-color:#38bdf8; }


        .rowform { display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:6px; }


        .rowform select, .rowform input { width:auto; padding:5px 7px; font-size:.76rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Meetings.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Meetings.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <!-- Schedule a meeting -->
        <details class="card">
            <summary><?= esc(lang('Meetings.admin.scheduleHeading')) ?></summary>
            <form class="form" method="post" action="/meetings">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="full">
                    <label><?= esc(lang('Meetings.admin.titleLabel')) ?></label>
                    <input name="title" required maxlength="200" placeholder="<?= esc(lang('Meetings.admin.titlePh'), 'attr') ?>">
                </div>
                <div>
                    <label><?= esc(lang('Meetings.admin.providerLabel')) ?></label>
                    <select name="provider">
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= esc($p, 'attr') ?>"><?= esc($vocab('provider', $p)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Meetings.admin.modeLabel')) ?></label>
                    <select name="mode">
                        <option value="meeting"><?= esc($vocab('mode', 'meeting')) ?></option>
                        <option value="webinar"><?= esc($vocab('mode', 'webinar')) ?></option>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Meetings.admin.accessLabel')) ?></label>
                    <select name="access_policy">
                        <option value="restricted"><?= esc($vocab('access', 'restricted')) ?></option>
                        <option value="public"><?= esc($vocab('access', 'public')) ?></option>
                    </select>
                </div>
                <div>
                    <label><?= esc(lang('Meetings.admin.startsLabel')) ?></label>
                    <input type="datetime-local" name="starts_at">
                </div>
                <div>
                    <label><?= esc(lang('Meetings.admin.endsLabel')) ?></label>
                    <input type="datetime-local" name="ends_at">
                </div>
                <div class="full">
                    <label><?= esc(lang('Meetings.admin.joinUrlLabel')) ?></label>
                    <input type="url" name="join_url" placeholder="https://…">
                    <div class="hint"><?= esc(lang('Meetings.admin.joinUrlHint')) ?></div>
                </div>
                <div class="full">
                    <button class="btn" type="submit"><?= esc(lang('Meetings.admin.scheduleBtn')) ?></button>
                </div>
            </form>
        </details>

        <?php if ($meetings === []): ?>
            <p class="empty"><?= esc(lang('Meetings.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Meetings.countOne' : 'Meetings.count', (string) $count)) ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?= esc(lang('Meetings.colTitle')) ?></th>
                        <th><?= esc(lang('Meetings.colWhen')) ?></th>
                        <th><?= esc(lang('Meetings.colProvider')) ?></th>
                        <th><?= esc(lang('Meetings.colStatus')) ?></th>
                        <th><?= esc(lang('Meetings.colAccess')) ?></th>
                        <th><?= esc(lang('Meetings.admin.colActions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $m): ?>
                        <?php
                        $title    = (string) ($m['title'] ?? '');
                        $mode     = (string) ($m['mode'] ?? '');
                        $provider = (string) ($m['provider'] ?? '');
                        $status   = (string) ($m['status'] ?? '');
                        $access   = (string) ($m['access_policy'] ?? '');
                        $joinUrl  = (string) ($m['join_url'] ?? '');
                        $mId      = (string) ($m['id'] ?? '');
                        $when     = $fmtWhen($m['starts_at'] ?? null, $m['ends_at'] ?? null);
                        $sColor   = $statusColor($status);
                        ?>
                        <tr>
                            <td>
                                <span class="title"><?= esc($title) ?></span>
                                <?php if ($mode !== ''): ?><span class="mode"><?= esc($vocab('mode', $mode)) ?></span><?php endif; ?>
                                <?php if ($joinUrl !== ''): ?>
                                    <div><a class="join" href="<?= esc($joinUrl, 'attr') ?>" rel="noopener noreferrer" target="_blank"><?= esc(lang('Meetings.join')) ?></a></div>
                                <?php else: ?>
                                    <div class="muted" style="font-size:.72rem"><?= esc(lang('Meetings.noJoin')) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="when"><?= $when === '' ? '<span class="muted">' . esc(lang('Meetings.noTime')) . '</span>' : esc($when) ?></td>
                            <td><?= $provider === '' ? '<span class="muted">—</span>' : esc($vocab('provider', $provider)) ?></td>
                            <td><span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($vocab('status', $status)) ?></span></td>
                            <td><span class="access"><?= $access === '' ? '<span class="muted">—</span>' : esc($vocab('access', $access)) ?></span></td>
                            <td class="actions-cell">
                                <?php if ($mId !== ''): ?>
                                    <form class="rowform" method="post" action="/meetings/<?= esc(rawurlencode($mId), 'attr') ?>/transition">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <select name="status" aria-label="<?= esc(lang('Meetings.admin.statusLabel'), 'attr') ?>">
                                            <?php foreach ($statuses as $st): ?>
                                                <option value="<?= esc($st, 'attr') ?>" <?= $st === $status ? 'selected' : '' ?>><?= esc($vocab('status', $st)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn ghost" type="submit"><?= esc(lang('Meetings.admin.setStatusBtn')) ?></button>
                                    </form>
                                    <form class="rowform" method="post" action="/meetings/<?= esc(rawurlencode($mId), 'attr') ?>/grant">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <?php if ($roster !== []): ?>
                                            <select name="user_id" required aria-label="<?= esc(lang('Meetings.admin.userIdLabel'), 'attr') ?>">
                                                <option value=""><?= esc(lang('Meetings.admin.userIdNone')) ?></option>
                                                <?php foreach ($roster as $u): ?>
                                                    <?php $uid = (string) ($u['id'] ?? ''); if ($uid === '') { continue; } $un = trim((string) ($u['display_name'] ?? '')); ?>
                                                    <option value="<?= esc($uid, 'attr') ?>"><?= esc($un !== '' ? $un : $uid) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input name="user_id" required maxlength="64" placeholder="<?= esc(lang('Meetings.admin.userIdPh'), 'attr') ?>">
                                        <?php endif; ?>
                                        <select name="role" aria-label="<?= esc(lang('Meetings.admin.roleLabel'), 'attr') ?>">
                                            <?php foreach ($roles as $r): ?>
                                                <option value="<?= esc($r, 'attr') ?>"><?= esc($vocab('role', $r)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn ghost" type="submit"><?= esc(lang('Meetings.admin.grantBtn')) ?></button>
                                    </form>
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
