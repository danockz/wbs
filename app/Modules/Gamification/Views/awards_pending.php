<?php
/**
 * Pending award-approval queue (GET /gamification/awards/pending) — the browser
 * face of AwardsController::pending (was the generic admin console). Group-scoped
 * in the controller so an approver only sees awards inside their grant. Each row
 * shows the subject, points, the rule/source that generated it, the entry type
 * and when it was posted.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.awardsPending.*') with English fallback. Each row now
 * carries inline approve/reject forms that POST to the webcsrf-guarded
 * /gamification/awards/{id}/{approve|reject} routes (maker-checker + group scope
 * enforced in the controller/service); the controller PRGs back here with a flash.
 *
 * @var list<array<string,mixed>> $awards pending point_ledger rows
 * @var string                    $csrf   webcsrf double-submit token
 * @var string                    $title
 */
$awards = is_array($awards ?? null) ? $awards : [];
$csrf   = $csrf ?? '';
$count  = count($awards);
$title  = $title ?? lang('Gamification.admin.awardsPending.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Presentation helpers: time_ago (posting age) + redact_id (subject id) are
// procedural view helpers registered by BaseController::$helpers. Guard-load so
// this view still renders headless in the test harness.
if (! function_exists('time_ago')) {
    require_once dirname(__DIR__, 3) . '/Helpers/time_helper.php';
}
if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.awardsPending.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.awardsPending.countOne') : lang('Gamification.admin.awardsPending.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="ap-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="ap-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($awards === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.awardsPending.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($awards as $a): ?>
            <?php
            $lid = rawurlencode((string) ($a['id'] ?? ''));
            $age = time_ago($a['created_at'] ?? null, '');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc(redact_id((string) ($a['subject_id'] ?? ''))) ?></span>
                    <span class="pill"><?= esc((string) (int) ($a['points'] ?? 0)) ?> <?= esc(lang('Gamification.admin.awardsPending.points')) ?></span>
                </div>
                <?php if (! empty($a['explanation'])): ?><div class="counts"><?= esc((string) $a['explanation']) ?></div><?php endif; ?>
                <div class="meta">
                    <?php if (! empty($a['entry_type'])): ?><?= esc(lang('Gamification.admin.awardsPending.entryType')) ?>: <?= esc((string) $a['entry_type']) ?> · <?php endif; ?>
                    <?php if (! empty($a['rule_id'])): ?><?= esc(lang('Gamification.admin.awardsPending.rule')) ?>: <?= esc((string) $a['rule_id']) ?> · <?php endif; ?>
                    <?php if (! empty($a['source_ref'])): ?><?= esc(lang('Gamification.admin.awardsPending.source')) ?>: <?= esc((string) $a['source_ref']) ?> · <?php endif; ?>
                    <?php if ($age !== ''): ?><span title="<?= esc((string) ($a['created_at'] ?? ''), 'attr') ?>"><?= esc($age) ?></span><?php else: ?><?= esc((string) ($a['created_at'] ?? '')) ?><?php endif; ?>
                </div>
                <?php if ($lid !== ''): ?>
                <div class="ap-acts">
                    <form method="post" action="/gamification/awards/<?= esc($lid, 'attr') ?>/approve">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="fld">
                            <label for="notes-<?= esc($lid, 'attr') ?>"><?= esc(lang('Gamification.admin.awardsPending.notesLabel')) ?></label>
                            <input id="notes-<?= esc($lid, 'attr') ?>" name="notes">
                        </div>
                        <button type="submit" class="ap-btn approve"><?= esc(lang('Gamification.admin.awardsPending.approve')) ?></button>
                    </form>
                    <form method="post" action="/gamification/awards/<?= esc($lid, 'attr') ?>/reject"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.awardsPending.rejectConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="fld">
                            <label for="reason-<?= esc($lid, 'attr') ?>"><?= esc(lang('Gamification.admin.awardsPending.reasonLabel')) ?></label>
                            <input id="reason-<?= esc($lid, 'attr') ?>" name="reason" required>
                        </div>
                        <button type="submit" class="ap-btn reject"><?= esc(lang('Gamification.admin.awardsPending.reject')) ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
