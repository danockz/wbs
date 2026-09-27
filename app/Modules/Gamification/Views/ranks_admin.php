<?php
/**
 * Rank-tier definitions — admin list + in-page CRUD (GET /gamification/rank-definitions)
 * — the browser face of AwardsController::listRankDefinitions. Tiers ordered by the
 * points needed to reach each, showing code, name, min-points threshold, group scope
 * and status, now with inline create / edit / disable controls posting to the
 * webcsrf-guarded catalog routes.
 *
 * define() is an UPSERT keyed on (code, group): the "New tier" form and each row's
 * "Edit" form both POST to /gamification/ranks — creating when the code is new,
 * updating in place otherwise. Tiers are DISABLED (status flip) rather than deleted.
 * Progressive-enhancement: edit panels are plain <details>, usable with no JS.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.ranks.*') with English fallback.
 *
 * @var list<array<string,mixed>> $tiers rank_definitions rows
 * @var string                    $csrf  webcsrf double-submit token
 * @var string                    $title
 */
$tiers = is_array($tiers ?? null) ? $tiers : [];
$csrf  = $csrf ?? '';
$count = count($tiers);
$title = $title ?? lang('Gamification.admin.ranks.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <div class="cf-head">
        <div>
            <h1><?= esc(lang('Gamification.admin.ranks.title')) ?></h1>
            <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.ranks.countOne') : lang('Gamification.admin.ranks.count'))) ?></div>
        </div>
    </div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $t, bool $isNew) use ($csrf): void {
        $code = (string) ($t['code'] ?? '');
        ?>
        <form method="post" action="/gamification/ranks" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.ranks.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.ranks.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($t['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.minPointsLabel')) ?></label>
                <input name="min_points" type="number" min="0" value="<?= esc((string) (int) ($t['min_points'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($t['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.iconLabel')) ?></label>
                <input name="icon" value="<?= esc((string) ($t['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.ranks.form.iconPh'), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.ranks.form.colorLabel')) ?></label>
                <input name="color" value="<?= esc((string) ($t['color'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.ranks.form.colorPh'), 'attr') ?>">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.ranks.form.saveNew') : lang('Gamification.admin.ranks.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.ranks.form.newTier')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($tiers === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.ranks.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($tiers as $t): ?>
            <?php
            $tcode    = (string) ($t['code'] ?? '');
            $tc       = rawurlencode($tcode);
            $status   = (string) ($t['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' tier-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($t['name'] ?? ($tcode !== '' ? $tcode : '—'))) ?>
                    </span>
                    <span class="pill"><?= esc(str_replace('{0}', (string) (int) ($t['min_points'] ?? 0), lang('Gamification.admin.ranks.minPoints'))) ?></span>
                </div>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.ranks.code')) ?>: <?= esc($tcode !== '' ? $tcode : '—') ?>
                    <?php if (! empty($t['group_id'])): ?> · <?= esc(lang('Gamification.admin.ranks.group')) ?>: <?= esc((string) $t['group_id']) ?><?php endif; ?>
                    <?php if (! empty($t['status'])): ?> · <?= esc((string) $t['status']) ?><?php endif; ?>
                </div>
                <?php if ($tcode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/ranks/<?= esc($tc, 'attr') ?>"><?= esc(lang('Gamification.admin.ranks.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/ranks/<?= esc($tc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.ranks.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.ranks.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.ranks.form.edit')) ?></summary>
                    <?php $defineForm($t, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
