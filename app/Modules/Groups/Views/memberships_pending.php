<?php
/**
 * PENDING memberships (GET /groups/{id}/memberships/pending) — the browser face of
 * GroupMembershipController::pending, which otherwise rendered the generic admin
 * console. The reviewer queue for one group: membership requests awaiting a
 * decision, oldest first, each showing the person, requested membership type/role,
 * source and when they joined the queue. Approve/reject actions live on their own
 * webcsrf-guarded POST routes.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Groups.pending.*') with English
 * fallback; the {0} count is interpolated via $li(); the source vocabulary is
 * localized with a raw-value fallback. Ids/types are server data shown verbatim
 * & escaped.
 *
 * @var list<array<string,mixed>> $pending pending membership rows
 * @var string                    $groupId the group whose queue this is
 */
$pending = $pending ?? [];
$groupId = $groupId ?? '';
$count   = count($pending);
$csrf    = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// The relative path PRG returns to after an inline decision from this page.
$returnPath = '/groups/' . rawurlencode($groupId) . '/memberships/pending';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Presentation helpers: time_ago (queue age) + redact_id (user id) are procedural
// view helpers registered by BaseController::$helpers. Guard-load so this
// SELF-CONTAINED page still renders headless in the test harness.
if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$vsource = static function (string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('Groups.pending.source.' . $value);
    return (is_string($s) && ! str_contains($s, 'Groups.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Groups.pending.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        
        .group { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#fbbf24;
            border:1px solid #a16207; border-radius:6px; padding:2px 8px; }

        .who { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-weight:600; color:#e2e8f0; }

        .when { margin-inline-start:auto; font-size:.72rem; color:#94a3b8; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Groups.pending.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Groups.pending.groupLabel')) ?>: <span class="group"><?= esc($groupId !== '' ? $groupId : '—') ?></span></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Groups.pending.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Groups.pending.countOne' : 'Groups.pending.count', (string) $count)) ?></p>
            <?php foreach ($pending as $m): ?>
                <?php
                $mid = rawurlencode((string) ($m['id'] ?? ''));
                $age = time_ago($m['joined_at'] ?? null, '');
                ?>
                <article class="item">
                    <div class="itemtop">
                        <span class="who"><?= esc(redact_id((string) ($m['user_id'] ?? ''))) ?></span>
                        <span class="tag"><?= esc(lang('Groups.pending.colType')) ?>: <?= esc((string) ($m['membership_type'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('Groups.pending.colRole')) ?>: <?= esc((string) ($m['role'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('Groups.pending.colSource')) ?>: <?= esc($vsource((string) ($m['source'] ?? ''))) ?></span>
                        <span class="when"><?= esc(lang('Groups.pending.colJoined')) ?>: <?php if ($age !== ''): ?><span title="<?= esc((string) ($m['joined_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php else: ?><?= esc((string) ($m['joined_at'] ?? '—')) ?><?php endif; ?></span>
                    </div>
                    <?php if ($mid !== ''): ?>
                    <div class="acts">
                        <form method="post" action="<?= esc($url('memberships/' . $mid . '/approve'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('Groups.pending.approveConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                            <div class="fld">
                                <label for="an-<?= esc($mid, 'attr') ?>"><?= esc(lang('Groups.pending.noteLabel')) ?></label>
                                <input id="an-<?= esc($mid, 'attr') ?>" name="note">
                            </div>
                            <button type="submit" class="btn approve"><?= esc(lang('Groups.pending.approve')) ?></button>
                        </form>
                        <form method="post" action="<?= esc($url('memberships/' . $mid . '/reject'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('Groups.pending.rejectConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                            <div class="fld">
                                <label for="rn-<?= esc($mid, 'attr') ?>"><?= esc(lang('Groups.pending.reasonLabel')) ?></label>
                                <input id="rn-<?= esc($mid, 'attr') ?>" name="reason">
                            </div>
                            <button type="submit" class="btn reject"><?= esc(lang('Groups.pending.reject')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
