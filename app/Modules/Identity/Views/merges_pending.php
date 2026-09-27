<?php
/**
 * Pending account merges (GET /identity/merges/pending) — the browser face of
 * AccountController::pendingMerges, which otherwise rendered the generic admin
 * console. The reviewer queue of merge requests awaiting a decision, oldest
 * first, each showing the primary and duplicate accounts, who requested it, when,
 * and the justification. Approve/reject live on their own webcsrf-guarded POSTs.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy via lang('Identity.mergesPending.*') with English
 * fallback; the {0} count is interpolated via $li(). Ids/reasons are server data
 * shown verbatim & escaped.
 *
 * @var list<array<string,mixed>> $merges identity_merge_requests rows (pending)
 */
$merges = $merges ?? [];
$count  = count($merges);
$csrf   = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Presentation helpers: time_ago (request age) + redact_id (account ids) are
// procedural view helpers registered by BaseController::$helpers. Guard-load so
// this SELF-CONTAINED page still renders headless in the test harness.
if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.mergesPending.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        
        .item { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:10px; }

        .pair { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:6px; }

        .primary { color:#86efac; font-weight:600; }

        .arr { color:#fbbf24; }

        .reason { color:#cbd5e1; font-size:.9rem; margin:4px 0; }

        .meta { font-size:.72rem; color:#94a3b8; display:flex; flex-wrap:wrap; gap:8px; }

        .btn.view { background:#1e293b; color:#cbd5e1; margin-inline-start:auto; align-self:center; }

        .btn.view:hover { background:#334155; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Identity.mergesPending.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.mergesPending.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('Identity.mergesPending.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Identity.mergesPending.countOne' : 'Identity.mergesPending.count', (string) $count)) ?></p>
            <?php foreach ($merges as $m): ?>
                <?php
                $mid = rawurlencode((string) ($m['id'] ?? ''));
                $age = time_ago($m['created_at'] ?? null, '');
                ?>
                <article class="item">
                    <div class="pair">
                        <span class="primary mono"><?= esc(redact_id((string) ($m['primary_user_id'] ?? ''))) ?></span>
                        <span class="arr">←</span>
                        <span class="dup mono"><?= esc(redact_id((string) ($m['duplicate_user_id'] ?? ''))) ?></span>
                    </div>
                    <?php if (! empty($m['reason'])): ?><p class="reason"><?= esc((string) $m['reason']) ?></p><?php endif; ?>
                    <div class="meta">
                        <?php if (! empty($m['requested_by'])): ?><span><?= esc(lang('Identity.mergesPending.colRequestedBy')) ?>: <span class="mono"><?= esc(redact_id((string) $m['requested_by'])) ?></span></span><?php endif; ?>
                        <?php if ($age !== ''): ?><span title="<?= esc((string) ($m['created_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php else: ?><span><?= esc((string) ($m['created_at'] ?? '')) ?></span><?php endif; ?>
                    </div>
                    <?php if ($mid !== ''): ?>
                    <div class="acts">
                        <form method="post" action="<?= esc($url('identity/merges/' . $mid . '/approve'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('Identity.mergesPending.approveConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div class="fld">
                                <label for="an-<?= esc($mid, 'attr') ?>"><?= esc(lang('Identity.mergesPending.noteLabel')) ?></label>
                                <input id="an-<?= esc($mid, 'attr') ?>" name="note">
                            </div>
                            <button type="submit" class="btn approve"><?= esc(lang('Identity.mergesPending.approve')) ?></button>
                        </form>
                        <form method="post" action="<?= esc($url('identity/merges/' . $mid . '/reject'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('Identity.mergesPending.rejectConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <div class="fld">
                                <label for="rn-<?= esc($mid, 'attr') ?>"><?= esc(lang('Identity.mergesPending.noteLabel')) ?></label>
                                <input id="rn-<?= esc($mid, 'attr') ?>" name="note">
                            </div>
                            <button type="submit" class="btn reject"><?= esc(lang('Identity.mergesPending.reject')) ?></button>
                        </form>
                        <a class="btn view" href="<?= esc($url('identity/merges/' . $mid), 'attr') ?>"><?= esc(lang('Identity.mergesPending.view')) ?></a>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
