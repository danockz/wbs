<?php
/**
 * MEMBERS DIRECTORY page (GET /members) — the browser face of
 * AccountController::index, and the landing page for the People → Members menu
 * item (previously a 404). Lists everyone registered in the organization with a
 * self-contained avatar, email, account status, and join date.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Identity.members.*') with English fallback; the {0} count is interpolated
 * in PHP via $li() with PHP singular/plural. `status` is a fixed vocabulary
 * localized with a raw-value fallback; name/email are server data shown verbatim.
 *
 * Avatars come from \WBS\Shared\Support\Avatar::resolveUrl — a member's photo URL
 * when set, otherwise a deterministic inline-SVG initials data-URI, so the page
 * renders fully even with no network (in-app preview safe).
 *
 * ADMIN LIFECYCLE (M8): each row carries a CSP-safe, no-JS <details> panel with
 * the lifecycle actions LEGAL from that member's current status (suspend /
 * lock / reactivate / deactivate / anonymize), each a POST form with a required
 * reason + the CSRF token, targeting the existing
 * identity/accounts/{id}/{action} routes (authorize:identity.manage + webcsrf).
 * The legal-action map here mirrors AccountLifecycleService::TRANSITIONS — but the
 * SERVICE is the real guard; this only avoids showing impossible actions.
 *
 * @var list<array<string,mixed>> $members
 * @var string $csrf
 */
$members = $members ?? [];
$count   = count($members);
$csrf    = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// Admin-actionable lifecycle transitions per current status (mirrors
// AccountLifecycleService::TRANSITIONS, minus 'merged' which has its own console
// and 'active'/'pending' targets that are not one-click reasoned actions).
$actionsFor = static function (string $s): array {
    $map = [
        'active'               => ['suspend', 'lock', 'deactivate', 'anonymize'],
        'pending_verification' => ['lock', 'deactivate'],
        'prospect'             => ['deactivate'],
        'suspended'            => ['reactivate', 'lock', 'deactivate', 'anonymize'],
        'locked'               => ['reactivate', 'suspend', 'deactivate'],
        'deactivated'          => ['reactivate', 'anonymize'],
    ];

    return $map[$s] ?? [];
};
// Actions that irreversibly destroy data — flagged with a confirm hint.
$irreversible = ['anonymize' => true];

$statusColor = static fn (string $s): string => match ($s) {
    'active'      => '#22c55e',
    'pending'     => '#f59e0b',
    'suspended'   => '#ef4444',
    'locked'      => '#ef4444',
    'deactivated' => '#94a3b8',
    'anonymized'  => '#64748b',
    default       => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Identity.members.status.' . $s);

    return $v === 'Identity.members.status.' . $s ? ucfirst($s) : $v;
};
$fmtDate = static function (mixed $raw): string {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d', $ts);
};
$avatar = static fn (array $m): string => \WBS\Shared\Support\Avatar::resolveUrl($m, 72);
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.members.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        .who { display:flex; align-items:center; gap:11px; }


        .btn-reactivate { color:#22c55e; border-color:#22c55e55; }


        .warn { font-size:.68rem; color:#ef4444; width:100%; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Identity.members.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.members.sub')) ?></p>

        <?php if ($members === []): ?>
            <p class="empty"><?= esc(lang('Identity.members.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Identity.members.countOne' : 'Identity.members.count', (string) $count)) ?></p>
            <table>
                <thead>
                    <tr>
                        <th><?= esc(lang('Identity.members.colMember')) ?></th>
                        <th><?= esc(lang('Identity.members.colEmail')) ?></th>
                        <th><?= esc(lang('Identity.members.colStatus')) ?></th>
                        <th><?= esc(lang('Identity.members.colJoined')) ?></th>
                        <th><?= esc(lang('Identity.members.colActions')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php
                        $name   = trim((string) ($m['display_name'] ?? ''));
                        $email  = trim((string) ($m['email'] ?? ''));
                        $status = (string) ($m['status'] ?? '');
                        $joined = $fmtDate($m['created_at'] ?? '');
                        $sColor = $statusColor($status);
                        $uid    = (string) ($m['id'] ?? '');
                        $acts   = $uid === '' ? [] : $actionsFor($status);
                        ?>
                        <tr>
                            <td>
                                <span class="who">
                                    <img src="<?= esc($avatar($m), 'attr') ?>" alt="" width="36" height="36">
                                    <span class="name"><?= $name === '' ? '<span class="muted">' . esc(lang('Identity.members.noName')) . '</span>' : esc($name) ?></span>
                                </span>
                            </td>
                            <td><?= $email === '' ? '<span class="muted">' . esc(lang('Identity.members.noEmail')) . '</span>' : esc($email) ?></td>
                            <td><span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span></td>
                            <td class="when"><?= $joined === '' ? '<span class="muted">—</span>' : esc($joined) ?></td>
                            <td class="acts">
                                <?php if ($acts === []): ?>
                                    <span class="muted"><?= esc(lang('Identity.members.noActions')) ?></span>
                                <?php else: ?>
                                    <details class="manage">
                                        <summary><?= esc(lang('Identity.members.manage')) ?></summary>
                                        <div class="panel">
                                            <?php foreach ($acts as $a): ?>
                                                <form method="post"
                                                      action="<?= esc($url('identity/accounts/' . $uid . '/' . $a), 'attr') ?>"
                                                      class="act-form">
                                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                    <input type="text" name="reason" required
                                                           maxlength="240"
                                                           placeholder="<?= esc(lang('Identity.members.reasonPlaceholder'), 'attr') ?>"
                                                           aria-label="<?= esc(lang('Identity.members.reasonLabel'), 'attr') ?>">
                                                    <button type="submit" class="btn btn-<?= esc($a, 'attr') ?>"><?= esc(lang('Identity.members.act.' . $a)) ?></button>
                                                    <?php if (isset($irreversible[$a])): ?>
                                                        <span class="warn"><?= esc(lang('Identity.members.confirmIrreversible')) ?></span>
                                                    <?php endif; ?>
                                                </form>
                                            <?php endforeach; ?>
                                            <a class="hist" href="<?= esc($url('identity/accounts/' . $uid . '/transitions'), 'attr') ?>"><?= esc(lang('Identity.members.history')) ?></a>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
