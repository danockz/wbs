<?php
/**
 * Membership-journey STAGE LADDER admin (journey/stages) — the browser face of
 * JourneyController::listStages, which otherwise only spoke JSON. The configurable
 * discipleship ladder (Option B): stages ordered by sort_order, each carrying a
 * phase (win|build|send) so the journey and the activity model share one
 * vocabulary. Now with inline create / edit / deactivate controls posting to the
 * webcsrf-guarded /journey/stages route.
 *
 * defineStage() is an UPSERT keyed on (code, group): the "New stage" form and each
 * row's "Edit" form both POST to /journey/stages — creating when the code is new,
 * updating in place otherwise. Stages are DEACTIVATED (status flip via the same
 * upsert) rather than deleted so journey history stays intact.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Journey.admin.stages.*') with English fallback; the phase vocabulary is
 * localized with a raw-value fallback. Progressive-enhancement: create/edit panels
 * are plain <details>, fully usable with no JavaScript.
 *
 * @var ?string                   $group_id
 * @var list<array<string,mixed>> $stages journey_stages rows (effective ladder)
 * @var string                    $csrf   webcsrf double-submit token
 */
$group_id = $group_id ?? null;
$stages   = is_array($stages ?? null) ? $stages : [];
$csrf     = $csrf ?? '';
$count    = count($stages);

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$PHASES     = ['win', 'build', 'send', 'general'];
$phaseColor = static fn (string $p): string => match ($p) {
    'win'   => '#22d3ee',
    'build' => '#a78bfa',
    'send'  => '#f59e0b',
    default => '#64748b',
};
$phaseLbl = static function (string $p): string {
    if ($p === '') { return ''; }
    $v = lang('Journey.phase.' . $p);
    return $v === 'Journey.phase.' . $p ? ucfirst($p) : $v;
};
// The group context is carried on every form so an org-wide vs group-scoped
// ladder edit posts to the right context (empty = org-wide primary ladder).
$ctx = $group_id === null ? '' : (string) $group_id;
?>

<?php ob_start(); ?>
<?= esc(lang('Journey.admin.stages.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        details.panel { border:1px solid #1e293b; border-radius:11px; margin:0 0 16px; background:#0f172aee; }


        details.panel > summary { cursor:pointer; padding:12px 15px; font-weight:700; color:#c4b5fd; list-style:none; }


        .form label { font-size:.64rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }


        .form input, .form select { padding:8px 10px; border-radius:7px; border:1px solid #334155; background:#0b1120; color:#e2e8f0; font-size:.85rem; font-family:inherit; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }


        .name { font-size:1.05rem; font-weight:700; }


        .meta { color:#94a3b8; font-size:.82rem; margin-top:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Journey.admin.stages.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Journey.admin.stages.sub')) ?></p>

        <span class="scope">
            <?= $group_id === null || $group_id === ''
                ? esc(lang('Journey.orgWide'))
                : esc($li('Journey.groupScoped', (string) $group_id)) ?>
        </span>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php
        // Reusable define form. $s is the row (empty for create); $isNew toggles the
        // code field (code is the upsert identity key → locked on edit).
        $stageForm = static function (array $s, bool $isNew) use ($csrf, $ctx, $PHASES, $phaseLbl): void {
            $code = (string) ($s['code'] ?? '');
            $uid  = $isNew ? 'new' : $code;
            ?>
            <form method="post" action="/journey/stages" class="form">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>">
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.codeLabel')) ?></label>
                    <?php if ($isNew): ?>
                        <input name="code" required placeholder="<?= esc(lang('Journey.admin.stages.codePh'), 'attr') ?>">
                    <?php else: ?>
                        <input value="<?= esc($code, 'attr') ?>" readonly title="<?= esc(lang('Journey.admin.stages.codeLocked'), 'attr') ?>">
                        <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                    <?php endif; ?>
                </div>
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.nameLabel')) ?></label>
                    <input name="name" required value="<?= esc((string) ($s['name'] ?? ''), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.phaseLabel')) ?></label>
                    <select name="phase">
                        <?php foreach ($PHASES as $popt): ?>
                            <option value="<?= esc($popt, 'attr') ?>" <?= (string) ($s['phase'] ?? 'build') === $popt ? 'selected' : '' ?>><?= esc($phaseLbl($popt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.orderLabel')) ?></label>
                    <input name="sort_order" type="number" value="<?= esc((string) (int) ($s['sort_order'] ?? 0), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.iconLabel')) ?></label>
                    <input name="icon" value="<?= esc((string) ($s['icon'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Journey.admin.stages.iconPh'), 'attr') ?>">
                </div>
                <div class="fld">
                    <label><?= esc(lang('Journey.admin.stages.colorLabel')) ?></label>
                    <input name="color" value="<?= esc((string) ($s['color'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Journey.admin.stages.colorPh'), 'attr') ?>">
                </div>
                <div class="fld wide">
                    <label><?= esc(lang('Journey.admin.stages.descriptionLabel')) ?></label>
                    <input name="description" value="<?= esc((string) ($s['description'] ?? ''), 'attr') ?>">
                </div>
                <div class="fld chk">
                    <input type="checkbox" id="entry-<?= esc($uid, 'attr') ?>" name="is_entry" value="1" <?= ! empty($s['is_entry']) ? 'checked' : '' ?>>
                    <label for="entry-<?= esc($uid, 'attr') ?>" style="text-transform:none;letter-spacing:0;"><?= esc(lang('Journey.admin.stages.isEntryLabel')) ?></label>
                </div>
                <div class="fld chk">
                    <input type="checkbox" id="term-<?= esc($uid, 'attr') ?>" name="is_terminal" value="1" <?= ! empty($s['is_terminal']) ? 'checked' : '' ?>>
                    <label for="term-<?= esc($uid, 'attr') ?>" style="text-transform:none;letter-spacing:0;"><?= esc(lang('Journey.admin.stages.isTerminalLabel')) ?></label>
                </div>
                <div class="actions">
                    <button type="submit" class="save"><?= esc($isNew ? lang('Journey.admin.stages.saveNew') : lang('Journey.admin.stages.saveEdit')) ?></button>
                </div>
            </form>
            <?php
        };
        ?>

        <details class="panel">
            <summary>+ <?= esc(lang('Journey.admin.stages.newStage')) ?></summary>
            <?php $stageForm([], true); ?>
        </details>

        <p class="sub"><?= esc($li($count === 1 ? 'Journey.admin.stages.countOne' : 'Journey.admin.stages.count', (string) $count)) ?></p>

        <?php if ($stages === []): ?>
            <p class="empty"><?= esc(lang('Journey.admin.stages.empty')) ?></p>
        <?php else: ?>
            <?php foreach ($stages as $s): ?>
                <?php
                $code    = (string) ($s['code'] ?? '');
                $name    = (string) ($s['name'] ?? $code);
                $phase   = (string) ($s['phase'] ?? '');
                $color   = $phaseColor($phase);
                $status  = (string) ($s['status'] ?? 'active');
                $active  = $status !== 'inactive';
                ?>
                <div class="card<?= $active ? '' : ' off' ?>">
                    <div class="row">
                        <span class="name" style="<?= ! empty($s['color']) ? 'color:' . esc((string) $s['color'], 'attr') . ';' : '' ?>">
                            <?php if (! empty($s['icon'])): ?><?= esc((string) $s['icon']) ?> <?php endif; ?><?= esc($name) ?>
                        </span>
                        <span class="chip" style="color:<?= esc($color, 'attr') ?>;border-color:<?= esc($color, 'attr') ?>55"><?= esc($phaseLbl($phase)) ?></span>
                        <?php if (! empty($s['is_entry'])): ?><span class="badge"><?= esc(lang('Journey.admin.stages.entryBadge')) ?></span><?php endif; ?>
                        <?php if (! empty($s['is_terminal'])): ?><span class="badge"><?= esc(lang('Journey.admin.stages.terminalBadge')) ?></span><?php endif; ?>
                    </div>
                    <div class="meta">
                        <span class="stagecode"><?= esc($code !== '' ? $code : '—') ?></span>
                        · <?= esc($li('Journey.admin.stages.orderIs', (string) (int) ($s['sort_order'] ?? 0))) ?>
                        <?php if (! $active): ?> · <?= esc($status) ?><?php endif; ?>
                        <?php if (! empty($s['description'])): ?><br><?= esc((string) $s['description']) ?><?php endif; ?>
                    </div>
                    <?php if ($code !== ''): ?>
                    <div class="acts">
                        <?php if ($active): ?>
                        <form method="post" action="/journey/stages"
                              onsubmit="return confirm('<?= esc(lang('Journey.admin.stages.deactivateConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>">
                            <input type="hidden" name="code" value="<?= esc($code, 'attr') ?>">
                            <input type="hidden" name="name" value="<?= esc($name, 'attr') ?>">
                            <input type="hidden" name="phase" value="<?= esc($phase !== '' ? $phase : 'build', 'attr') ?>">
                            <input type="hidden" name="sort_order" value="<?= esc((string) (int) ($s['sort_order'] ?? 0), 'attr') ?>">
                            <input type="hidden" name="status" value="inactive">
                            <button type="submit" class="act warn"><?= esc(lang('Journey.admin.stages.deactivate')) ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <details class="inline">
                        <summary><?= esc(lang('Journey.admin.stages.edit')) ?></summary>
                        <?php $stageForm($s, false); ?>
                    </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
