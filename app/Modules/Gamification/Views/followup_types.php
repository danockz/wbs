<?php
/**
 * Follow-up types — admin list + in-page CRUD (GET /gamification/follow-up-types) —
 * the browser face of FollowUpsController::listFollowUpTypes. Types ordered by phase
 * then sort order, each showing code, name, phase, whether an outcome is required,
 * the default next-follow-up interval and status, now with inline create / edit /
 * disable controls posting to the webcsrf-guarded catalog routes.
 *
 * defineType() is an UPSERT keyed on (code, group): the "New type" form and each row's
 * "Edit" form both POST to /gamification/follow-up-types — creating when the code is
 * new, updating in place otherwise. Types are DISABLED (status flip) rather than
 * deleted. Progressive-enhancement: edit panels are plain <details>, no JS.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.followupTypes.*'); phase vocabulary raw-value fallback.
 *
 * @var list<array<string,mixed>> $types follow_up_types rows
 * @var string                    $csrf  webcsrf double-submit token
 * @var string                    $title
 */
$types = is_array($types ?? null) ? $types : [];
$csrf  = $csrf ?? '';
$count = count($types);
$title = $title ?? lang('Gamification.admin.followupTypes.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$PHASES     = ['win', 'build', 'send', 'general'];
$phaseLabel = static function (string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '—'; }
    $s = lang('Gamification.admin.followupTypes.phase.' . $p);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $p;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.followupTypes.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.followupTypes.countOne') : lang('Gamification.admin.followupTypes.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php
    $defineForm = static function (array $t, bool $isNew) use ($csrf, $PHASES, $phaseLabel): void {
        $code = (string) ($t['code'] ?? '');
        $uid  = $isNew ? 'new' : $code;
        ?>
        <form method="post" action="/gamification/follow-up-types" class="cf-form">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.codeLabel')) ?></label>
                <?php if ($isNew): ?>
                    <input name="code" required placeholder="<?= esc(lang('Gamification.admin.followupTypes.form.codePh'), 'attr') ?>">
                <?php else: ?>
                    <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Gamification.admin.followupTypes.form.codeLocked'), 'attr') ?>">
                    <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                <?php endif; ?>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.nameLabel')) ?></label>
                <input name="name" required value="<?= esc((string) ($t['name'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.phaseLabel')) ?></label>
                <select name="phase">
                    <?php foreach ($PHASES as $popt): ?>
                        <option value="<?= esc($popt, 'attr') ?>" <?= (string) ($t['phase'] ?? 'build') === $popt ? 'selected' : '' ?>><?= esc($phaseLabel($popt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.nextDaysLabel')) ?></label>
                <input name="default_next_days" type="number" min="0" value="<?= esc(($t['default_next_days'] ?? '') === null ? '' : (string) ($t['default_next_days'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.sortLabel')) ?></label>
                <input name="sort_order" type="number" value="<?= esc((string) (int) ($t['sort_order'] ?? 0), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.iconLabel')) ?></label>
                <input name="icon" value="<?= esc((string) ($t['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Gamification.admin.followupTypes.form.iconPh'), 'attr') ?>">
            </div>
            <div class="fld wide">
                <label><?= esc(lang('Gamification.admin.followupTypes.form.descriptionLabel')) ?></label>
                <input name="description" value="<?= esc((string) ($t['description'] ?? ''), 'attr') ?>">
            </div>
            <div class="fld chk">
                <input type="checkbox" id="ro-<?= esc($uid, 'attr') ?>" name="requires_outcome" value="1" <?= ! empty($t['requires_outcome']) ? 'checked' : '' ?>>
                <label for="ro-<?= esc($uid, 'attr') ?>" style="text-transform:none;letter-spacing:0;"><?= esc(lang('Gamification.admin.followupTypes.form.requiresOutcomeLabel')) ?></label>
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc($isNew ? lang('Gamification.admin.followupTypes.form.saveNew') : lang('Gamification.admin.followupTypes.form.saveEdit')) ?></button>
            </div>
        </form>
        <?php
    };
    ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.followupTypes.form.newType')) ?></summary>
        <?php $defineForm([], true); ?>
    </details>

    <?php if ($types === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.followupTypes.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($types as $t): ?>
            <?php
            $tcode    = (string) ($t['code'] ?? '');
            $tc       = rawurlencode($tcode);
            $status   = (string) ($t['status'] ?? 'active');
            $isActive = $status !== 'inactive';
            ?>
            <div class="card<?= $isActive ? '' : ' fut-disabled' ?>">
                <div class="row">
                    <span class="author" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
                        <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
                        <?= esc((string) ($t['name'] ?? ($tcode !== '' ? $tcode : '—'))) ?>
                    </span>
                    <span class="pill"><?= esc($phaseLabel((string) ($t['phase'] ?? ''))) ?></span>
                </div>
                <?php if (! empty($t['description'])): ?><div class="counts"><?= esc((string) $t['description']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.followupTypes.code')) ?>: <?= esc($tcode !== '' ? $tcode : '—') ?>
                    <?php if (! empty($t['requires_outcome'])): ?> · <span class="warn"><?= esc(lang('Gamification.admin.followupTypes.requiresOutcome')) ?></span><?php endif; ?>
                    <?php if (($t['default_next_days'] ?? null) !== null): ?> · <?= esc(str_replace('{0}', (string) (int) $t['default_next_days'], lang('Gamification.admin.followupTypes.nextDays'))) ?><?php endif; ?>
                    <?php if (! empty($t['status'])): ?> · <?= esc((string) $t['status']) ?><?php endif; ?>
                </div>
                <?php if ($tcode !== ''): ?>
                <div class="cf-acts">
                    <a class="cf-act" href="/gamification/follow-up-types/<?= esc($tc, 'attr') ?>"><?= esc(lang('Gamification.admin.followupTypes.form.view')) ?></a>
                    <?php if ($isActive): ?>
                    <form method="post" action="/gamification/follow-up-types/<?= esc($tc, 'attr') ?>/disable"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.followupTypes.form.disableConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.followupTypes.form.disable')) ?></button>
                    </form>
                    <?php endif; ?>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.followupTypes.form.edit')) ?></summary>
                    <?php $defineForm($t, false); ?>
                </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
