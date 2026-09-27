<?php
/**
 * Activity categories — admin list + in-page CRUD (GET /gamification/activity-categories)
 * — the browser face of ConfigController::listActivityCategories. Categories ordered
 * by phase then sort order, each showing code, name, phase, group scope and status,
 * now with inline create / edit / disable controls posting to the webcsrf-guarded
 * catalog routes.
 *
 * define() is an UPSERT keyed on (code, group): the "New category" form and each row's
 * "Edit" form both POST to /gamification/activity-categories — creating when the code
 * is new, updating in place otherwise. Categories are DISABLED (status flip) rather
 * than deleted. Progressive-enhancement: edit panels are plain <details>, no JS.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.categories.*') with English fallback; phase vocabulary
 * localized with a raw-value fallback.
 *
 * @var list<array<string,mixed>> $categories activity_categories rows
 * @var string                    $csrf       webcsrf double-submit token
 * @var string                    $title
 */
$categories = is_array($categories ?? null) ? $categories : [];
$csrf       = $csrf ?? '';
$count      = count($categories);
$title      = $title ?? lang('Gamification.admin.categories.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$PHASES     = ['win', 'build', 'send', 'general'];
$phaseLabel = static function (string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '—'; }
    $s = lang('Gamification.admin.categories.phase.' . $p);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $p;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.categories.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.categories.countOne') : lang('Gamification.admin.categories.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $c, bool $isNew) use ($csrf, $PHASES, $phaseLabel): void {
        $code = (string) ($c['code'] ?? '');
        ?>
        <form method="post" action="/gamification/activity-categories" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.categories.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.categories.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($c['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.phaseLabel')) ?></label>
                <select name="phase">
                    <?php foreach ($PHASES as $popt): ?>
                        <option value="<?= esc($popt, 'attr') ?>" <?= (string) ($c['phase'] ?? 'general') === $popt ? 'selected' : '' ?>><?= esc($phaseLabel($popt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($c['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.iconLabel')) ?></label>
                <input name="icon" value="<?= esc((string) ($c['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.categories.form.iconPh'), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.categories.form.colorLabel')) ?></label>
                <input name="color" value="<?= esc((string) ($c['color'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.categories.form.colorPh'), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang('Gamification.admin.categories.form.descriptionLabel')) ?></label>
                <input name="description" value="<?= esc((string) ($c['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.categories.form.saveNew') : lang('Gamification.admin.categories.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.categories.form.newCategory')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($categories === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.categories.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($categories as $c): ?>
            <?php
            $ccode    = (string) ($c['code'] ?? '');
            $cc       = rawurlencode($ccode);
            $status   = (string) ($c['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' cat-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($c['color']) ? 'color:' . esc($c['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($c['icon'])): ?><?= esc((string) $c['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($c['name'] ?? ($ccode !== '' ? $ccode : '—'))) ?>
                    </span>
                    <span class="pill"><?= esc($phaseLabel((string) ($c['phase'] ?? ''))) ?></span>
                </div>
                <?php if (! empty($c['description'])): ?><div class="counts"><?= esc((string) $c['description']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.categories.code')) ?>: <?= esc($ccode !== '' ? $ccode : '—') ?>
                    <?php if (! empty($c['group_id'])): ?> · <?= esc(lang('Gamification.admin.categories.group')) ?>: <?= esc((string) $c['group_id']) ?><?php endif; ?>
                    <?php if (! empty($c['status'])): ?> · <?= esc((string) $c['status']) ?><?php endif; ?>
                </div>
                <?php if ($ccode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/activity-categories/<?= esc($cc, 'attr') ?>"><?= esc(lang('Gamification.admin.categories.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/activity-categories/<?= esc($cc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.categories.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.categories.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.categories.form.edit')) ?></summary>
                    <?php $defineForm($c, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
