<?php
/**
 * Point rules — admin list (GET /gamification/rules) — the browser face of
 * ConfigController::listRules (was the generic admin console). Point-scoring
 * rules ordered by code then version, each showing code, current version, points,
 * event type, review requirement and status — now with per-rule CRUD controls
 * (New / Edit / Disable). Editing is IMMUTABLE (an edit supersedes the active
 * version), and rules are DISABLED rather than deleted so award history is kept.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.rules.*') with English fallback.
 *
 * @var list<array<string,mixed>> $rules gamification_rules rows
 * @var string                    $csrf  webcsrf double-submit token
 * @var string                    $title
 */
$rules = is_array($rules ?? null) ? $rules : [];
$csrf  = $csrf ?? '';
$count = count($rules);
$title = $title ?? lang('Gamification.admin.rules.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <div class="ra-head">
        <div class="ra-txt">
            <h1><?= esc(lang('Gamification.admin.rules.title')) ?></h1>
            <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.rules.countOne') : lang('Gamification.admin.rules.count'))) ?></div>
        </div>
        <a class="ra-btn" href="/gamification/rules/new">+ <?= esc(lang('Gamification.admin.rules.form.newRule')) ?></a>
    </div>

    <?php if ($flashOk !== ''): ?><div class="ra-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="ra-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($rules === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.rules.empty')) ?></div>
        <p style="margin-top:14px"><a class="ra-btn" href="/gamification/rules/new">+ <?= esc(lang('Gamification.admin.rules.form.newRule')) ?></a></p>
    <?php else: ?>
        <?php foreach ($rules as $r): ?>
            <?php
            $rcode    = (string) ($r['code'] ?? '');
            $rc       = rawurlencode($rcode);
            $status   = (string) ($r['status'] ?? '');
            $isActive = $status === 'active';
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($rcode !== '' ? $rcode : '—') ?> <span class="muted">v<?= esc((string) (int) ($r['version'] ?? 1)) ?></span></span>
                    <span class="pill"><?= esc(str_replace('{0}', (string) (int) ($r['points'] ?? 0), lang('Gamification.admin.rules.points'))) ?></span>
                </div>
                <?php if (! empty($r['explanation'])): ?><div class="counts"><?= esc((string) $r['explanation']) ?></div><?php endif; ?>
                <div class="meta">
                    <?php if (! empty($r['event_type'])): ?><?= esc(lang('Gamification.admin.rules.eventType')) ?>: <?= esc((string) $r['event_type']) ?> · <?php endif; ?>
                    <?php if (! empty($r['requires_review'])): ?><span class="warn"><?= esc(lang('Gamification.admin.rules.requiresReview')) ?></span> · <?php endif; ?>
                    <?= esc($status !== '' ? $status : '—') ?>
                </div>
                <?php if ($rcode !== ''): ?>
                <div class="ra-acts">
                    <a class="ra-act edit" href="/gamification/rules/<?= esc($rc, 'attr') ?>/edit"><?= esc(lang('Gamification.admin.rules.form.edit')) ?></a>
                    <a class="ra-act" href="/gamification/rules/<?= esc($rc, 'attr') ?>"><?= esc(lang('Gamification.admin.rules.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/rules/<?= esc($rc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.rules.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="ra-act warn"><?= esc(lang('Gamification.admin.rules.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
