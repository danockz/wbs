<?php
/**
 * Streak definitions — admin list + in-page CRUD (GET /gamification/streak-definitions)
 * — the browser face of AwardsController::listStreakDefinitions. Definitions in sort
 * order, each showing code, name, cadence, grace days and status, now with inline
 * create / edit / disable controls posting to the webcsrf-guarded catalog routes.
 *
 * define() is an UPSERT keyed on (code, group): the "New streak" form and each row's
 * "Edit" form both POST to /gamification/streak-definitions — creating when the code
 * is new, updating in place otherwise. Definitions are DISABLED (status flip) rather
 * than deleted. Progressive-enhancement: edit panels are plain <details>, no JS.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.streaks.*') with English fallback; the cadence vocabulary
 * is localized with a raw-value fallback.
 *
 * @var list<array<string,mixed>> $streaks streak_definitions rows
 * @var string                    $csrf    webcsrf double-submit token
 * @var string                    $title
 */
$streaks = is_array($streaks ?? null) ? $streaks : [];
$csrf    = $csrf ?? '';
$count   = count($streaks);
$title   = $title ?? lang('Gamification.admin.streaks.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$CADENCES = ['daily', 'weekly'];
$cadence  = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $s = lang('Gamification.admin.streaks.cadence.' . $v);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $v;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.streaks.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.streaks.countOne') : lang('Gamification.admin.streaks.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $s, bool $isNew) use ($csrf, $CADENCES, $cadence): void {
        $code = (string) ($s['code'] ?? '');
        ?>
        <form method="post" action="/gamification/streak-definitions" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.streaks.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.streaks.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($s['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.cadenceLabel')) ?></label>
                <select name="cadence">
                    <?php foreach ($CADENCES as $copt): ?>
                        <option value="<?= esc($copt, 'attr') ?>" <?= (string) ($s['cadence'] ?? 'daily') === $copt ? 'selected' : '' ?>><?= esc($cadence($copt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.graceLabel')) ?></label>
                <input name="default_grace_days" type="number" min="0" value="<?= esc((string) (int) ($s['default_grace_days'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($s['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.streaks.form.iconLabel')) ?></label>
                <input name="icon" value="<?= esc((string) ($s['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.streaks.form.iconPh'), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang('Gamification.admin.streaks.form.descriptionLabel')) ?></label>
                <input name="description" value="<?= esc((string) ($s['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.streaks.form.saveNew') : lang('Gamification.admin.streaks.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.streaks.form.newStreak')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($streaks === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.streaks.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($streaks as $s): ?>
            <?php
            $scode    = (string) ($s['code'] ?? '');
            $sc       = rawurlencode($scode);
            $status   = (string) ($s['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' streak-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($s['color']) ? 'color:' . esc($s['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($s['icon'])): ?><?= esc((string) $s['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($s['name'] ?? ($scode !== '' ? $scode : '—'))) ?>
                    </span>
                    <span class="pill"><?= esc($cadence((string) ($s['cadence'] ?? ''))) ?></span>
                </div>
                <?php if (! empty($s['description'])): ?><div class="counts"><?= esc((string) $s['description']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.streaks.code')) ?>: <?= esc($scode !== '' ? $scode : '—') ?>
                    · <?= esc(str_replace('{0}', (string) (int) ($s['default_grace_days'] ?? 0), lang('Gamification.admin.streaks.grace'))) ?>
                    <?php if (! empty($s['status'])): ?> · <?= esc((string) $s['status']) ?><?php endif; ?>
                </div>
                <?php if ($scode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/streak-definitions/<?= esc($sc, 'attr') ?>"><?= esc(lang('Gamification.admin.streaks.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/streak-definitions/<?= esc($sc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.streaks.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.streaks.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.streaks.form.edit')) ?></summary>
                    <?php $defineForm($s, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
