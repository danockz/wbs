<?php
/**
 * Follow-up methods — admin list + in-page CRUD (GET /gamification/follow-up-methods)
 * — the browser face of FollowUpsController::listFollowUpMethods. The contact channels
 * (visit, call, message, …) in sort order, each showing code, name, its point-multiplier
 * key and status, now with inline create / edit / disable controls posting to the
 * webcsrf-guarded catalog routes.
 *
 * defineMethod() is an UPSERT keyed on code: the "New method" form and each row's "Edit"
 * form both POST to /gamification/follow-up-methods — creating when the code is new,
 * updating in place otherwise. Methods are DISABLED (status flip) rather than deleted.
 * Progressive-enhancement: edit panels are plain <details>, no JS.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.followupMethods.*') with English fallback.
 *
 * @var list<array<string,mixed>> $methods follow_up_methods rows
 * @var string                    $csrf    webcsrf double-submit token
 * @var string                    $title
 */
$methods = is_array($methods ?? null) ? $methods : [];
$csrf    = $csrf ?? '';
$count   = count($methods);
$title   = $title ?? lang('Gamification.admin.followupMethods.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.followupMethods.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.followupMethods.countOne') : lang('Gamification.admin.followupMethods.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $m, bool $isNew) use ($csrf): void {
        $code = (string) ($m['code'] ?? '');
        ?>
        <form method="post" action="/gamification/follow-up-methods" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupMethods.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.followupMethods.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.followupMethods.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupMethods.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($m['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupMethods.form.multiplierLabel')) ?></label>
                <input name="multiplier_key" value="<?= esc((string) ($m['multiplier_key'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.followupMethods.form.multiplierPh'), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupMethods.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($m['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.followupMethods.form.saveNew') : lang('Gamification.admin.followupMethods.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.followupMethods.form.newMethod')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($methods === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.followupMethods.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($methods as $m): ?>
            <?php
            $mcode    = (string) ($m['code'] ?? '');
            $mc       = rawurlencode($mcode);
            $status   = (string) ($m['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' fum-disabled' ?>">
                <div class="row">
                    <span class="author"><?= esc((string) ($m['name'] ?? ($mcode !== '' ? $mcode : '—'))) ?></span>
                    <?php if (! empty($m['multiplier_key'])): ?><span class="pill"><?= esc((string) $m['multiplier_key']) ?></span><?php endif; ?>
                </div>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.followupMethods.code')) ?>: <?= esc($mcode !== '' ? $mcode : '—') ?>
                    <?php if (! empty($m['status'])): ?> · <?= esc((string) $m['status']) ?><?php endif; ?>
                </div>
                <?php if ($mcode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/follow-up-methods/<?= esc($mc, 'attr') ?>"><?= esc(lang('Gamification.admin.followupMethods.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/follow-up-methods/<?= esc($mc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.followupMethods.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.followupMethods.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.followupMethods.form.edit')) ?></summary>
                    <?php $defineForm($m, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
