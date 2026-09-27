<?php
/**
 * EFFECTIVE GROUP CONFIG page (GET /admin/groups/{id}/config/{capability}) — the
 * browser face of AdminController::resolveGroupConfig, which otherwise rendered
 * the generic admin console. Shows how a capability resolves for a group: the
 * effective value, which group it came from, the version, and the decision path
 * through the inheritance model.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Admin.*') with English fallback. `decision` and `inheritance_mode` are
 * localized with a raw-value fallback; capability/group ids and the resolved
 * value are server/config data shown verbatim & escaped.
 *
 * WRITE CONTROL (new): below the resolution card, a CSP-safe (no inline JS),
 * no-JS form POSTs to the webcsrf-guarded POST /admin/groups/{id}/config/{cap}
 * to SET/OVERRIDE this group's own value for the capability. It carries a value
 * TYPE picker (type fidelity) and an inheritance-mode picker; group + capability
 * are fixed from the URL (shown, not re-entered). $csrf is minted by the global
 * webcsrfissue filter.
 *
 * @var string               $capability
 * @var string               $group_id
 * @var array<string,mixed>  $config     resolver payload (value/source_group_id/version/decision/inheritance_mode)
 * @var string               $csrf
 * @var list<string>         $types
 * @var list<string>         $modes
 */
$capability = $capability ?? '';
$group_id   = $group_id ?? '';
$config     = $config ?? [];
$csrf       = $csrf ?? '';
$types      = $types ?? ['string', 'integer', 'number', 'boolean', 'json'];
$modes      = $modes ?? ['ancestor_default_child_override', 'inherit_only', 'child_owned', 'not_inheritable'];

include __DIR__ . '/_locale.php';

// Preselect the write form to the currently-effective type/mode where known.
$curVal  = $config['value'] ?? null;
$curType = is_bool($curVal) ? 'boolean' : (is_int($curVal) ? 'integer' : (is_float($curVal) ? 'number' : (is_array($curVal) ? 'json' : 'string')));
$curMode = (string) ($config['inheritance_mode'] ?? 'ancestor_default_child_override');
$curEditable = static function ($v): string {
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_scalar($v)) {
        return (string) $v;
    }

    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$decisionLbl = static function (string $d): string {
    if ($d === '') {
        return '';
    }
    $v = lang('Admin.decision.' . $d);

    return $v === 'Admin.decision.' . $d ? ucwords(str_replace('_', ' ', $d)) : $v;
};
$inhLbl = static function (string $m): string {
    if ($m === '') {
        return '';
    }
    $v = lang('Admin.inheritance.' . $m);

    return $v === 'Admin.inheritance.' . $m ? ucwords(str_replace('_', ' ', $m)) : $v;
};
$decisionColor = static fn (string $d): string => match ($d) {
    'no_effective_value' => '#94a3b8',
    'inherited_from_ancestor' => '#38bdf8',
    'child_override' => '#f59e0b',
    default => '#22c55e',
};
// Render the effective value: booleans as localized Enabled/Disabled, scalars
// verbatim, structured values as compact JSON.
$renderValue = static function ($val) {
    if ($val === null) {
        return null;
    }
    if (is_bool($val)) {
        return $val ? lang('Admin.boolTrue') : lang('Admin.boolFalse');
    }
    if (is_scalar($val)) {
        return (string) $val;
    }

    return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

$decision = (string) ($config['decision'] ?? '');
$value    = $config['value'] ?? null;
$source   = $config['source_group_id'] ?? null;
$version  = $config['version'] ?? null;
$inhMode  = (string) ($config['inheritance_mode'] ?? '');
$hasValue = ($decision !== 'no_effective_value' && $value !== null) || $value !== null;
$shown    = $renderValue($value);
$dColor   = $decisionColor($decision);
?>

<?php ob_start(); ?>
<?= esc(lang('Admin.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:6px 18px; }


        .val { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.95rem; word-break:break-all; }


        h2 { font-size:1.05rem; margin:30px 0 10px; }


        .aform { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; align-items:end; background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:16px 18px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?php
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;
?>
    <main class="wrap">
        <h1><?= esc(lang('Admin.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Admin.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?><div class="flash ok"><?= esc((string) $flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?><div class="flash err"><?= esc((string) $flashErr) ?></div><?php endif; ?>

        <div class="ctx">
            <span class="pill"><?= esc(lang('Admin.capabilityLbl')) ?>: <b><?= esc($capability) ?></b></span>
            <span class="pill"><?= esc(lang('Admin.groupLbl')) ?>: <b><?= esc($group_id) ?></b></span>
        </div>

        <div class="card">
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.decisionLbl')) ?></span>
                <span class="v"><span class="chip" style="color:<?= esc($dColor, 'attr') ?>;border-color:<?= esc($dColor, 'attr') ?>55"><?= esc($decisionLbl($decision)) ?></span></span>
            </div>
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.valueLbl')) ?></span>
                <span class="v">
                    <?php if ($shown === null): ?>
                        <span class="muted"><?= esc(lang('Admin.noValue')) ?></span>
                    <?php else: ?>
                        <span class="val"><?= esc((string) $shown) ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.sourceLbl')) ?></span>
                <span class="v"><?= $source === null || $source === '' ? '<span class="muted">' . esc(lang('Admin.nullSource')) . '</span>' : '<span class="val">' . esc((string) $source) . '</span>' ?></span>
            </div>
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.versionLbl')) ?></span>
                <span class="v"><?= $version === null ? '<span class="muted">—</span>' : '<span class="val">' . esc((string) $version) . '</span>' ?></span>
            </div>
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.inheritanceLbl')) ?></span>
                <span class="v"><?= $inhMode === '' ? '<span class="muted">—</span>' : esc($inhLbl($inhMode)) ?></span>
            </div>
        </div>

        <h2><?= esc(lang('Admin.setForm.heading')) ?></h2>
        <p class="sub"><?= esc(lang('Admin.setForm.sub')) ?></p>
        <form class="aform" method="post" action="/admin/groups/<?= esc(rawurlencode($group_id), 'attr') ?>/config/<?= esc(rawurlencode($capability), 'attr') ?>">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div>
                <label><?= esc(lang('Admin.setForm.typeLabel')) ?></label>
                <select name="type">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= esc($t, 'attr') ?>"<?= $curType === $t ? ' selected' : '' ?>><?= esc(lang('Admin.setForm.type.' . $t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?= esc(lang('Admin.setForm.valueLabel')) ?></label>
                <input name="value" value="<?= esc($curEditable($curVal), 'attr') ?>" placeholder="<?= esc(lang('Admin.setForm.valuePh'), 'attr') ?>">
            </div>
            <div class="full">
                <label><?= esc(lang('Admin.setForm.modeLabel')) ?></label>
                <select name="inheritance_mode">
                    <?php foreach ($modes as $m): ?>
                        <option value="<?= esc($m, 'attr') ?>"<?= $curMode === $m ? ' selected' : '' ?>><?= esc($inhLbl($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn"><?= esc(lang('Admin.setForm.save')) ?></button>
            </div>
        </form>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
