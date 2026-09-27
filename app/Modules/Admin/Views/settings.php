<?php
/**
 * ORGANIZATION SETTINGS page (GET /admin) — the browser face of
 * AdminController::index, and the landing page for the Admin → Organization
 * settings menu item (previously a 404). Shows platform settings and feature
 * flags in two tables.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Admin.settings.*') with English fallback; the {0} counts are interpolated
 * in PHP via $li() with PHP singular/plural. Setting keys, versions and decoded
 * values are server data shown verbatim (structured values as compact JSON);
 * flag scope is org-wide (null group_id) or a group id shown verbatim.
 *
 * WRITE CONTROLS (new): the page now carries CSP-safe (no inline JS), no-JS forms
 * that POST to the webcsrf-guarded admin write routes —
 *   - "New setting" and per-row edit → POST /admin/settings[/{key}] with a value
 *     TYPE picker so the value keeps type fidelity (string/int/number/bool/json).
 *   - "New feature flag" and per-row toggle → POST /admin/flags[/{key}]; the flag
 *     SCOPE is a GROUP PICKER (entity reference), not a free-text group id, plus an
 *     org-wide option.
 * The $csrf token is minted by the global webcsrfissue filter; $types is the value
 * vocabulary and $groups the org roster [{id,label}] for the scope picker.
 *
 * @var list<array<string,mixed>> $settings
 * @var list<array<string,mixed>> $flags
 * @var string                    $csrf
 * @var list<string>              $types
 * @var list<array{id:string,label:string}> $groups
 */
$settings = $settings ?? [];
$flags    = $flags ?? [];
$csrf     = $csrf ?? '';
$types    = $types ?? ['string', 'integer', 'number', 'boolean', 'json'];
$groups   = $groups ?? [];
$sc       = count($settings);
$fc       = count($flags);

include __DIR__ . '/_locale.php';

// Best-effort inference of a value's capture type, to preselect the edit picker.
$inferType = static function ($v): string {
    if (is_bool($v)) {
        return 'boolean';
    }
    if (is_int($v)) {
        return 'integer';
    }
    if (is_float($v)) {
        return 'number';
    }
    if (is_array($v)) {
        return 'json';
    }

    return 'string';
};
// Render a value as an editable STRING for a form field (JSON for structures).
$editable = static function ($v): string {
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

$renderValue = static function (mixed $v): string {
    if ($v === null) {
        return '<span class="muted">' . esc(lang('Admin.settings.noValue')) . '</span>';
    }
    if (is_bool($v)) {
        return esc($v ? lang('Admin.settings.on') : lang('Admin.settings.off'));
    }
    if (is_scalar($v)) {
        return esc((string) $v);
    }

    return '<code>' . esc(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
};
$fmtDate = static function (mixed $raw): string {
    $raw = (string) ($raw ?? '');
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts === false ? $raw : date('Y-m-d H:i', $ts);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Admin.settings.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        h2 { font-size:1.1rem; margin:28px 0 10px; }


        details.panel { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; margin:6px 0 6px; }


        details.panel > summary { cursor:pointer; padding:11px 14px; font-size:.82rem; font-weight:700; color:#a5b4fc; list-style:none; }


        details.panel > summary::before { content:'\FF0B'; color:#22d3ee; margin-inline-end:8px; font-weight:700; }


        details.panel[open] > summary::before { content:'\FF0D'; }


        .aform { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; align-items:end; padding:4px 14px 16px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<?php
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;
?>
    <main class="wrap">
        <h1><?= esc(lang('Admin.settings.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Admin.settings.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?><div class="flash ok"><?= esc((string) $flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?><div class="flash err"><?= esc((string) $flashErr) ?></div><?php endif; ?>

        <h2><?= esc(lang('Admin.settings.settingsH')) ?>
            <span class="count"><?= esc($li($sc === 1 ? 'Admin.settings.settingsOne' : 'Admin.settings.settingsCount', (string) $sc)) ?></span>
        </h2>

        <details class="panel">
            <summary><?= esc(lang('Admin.settings.form.newSetting')) ?></summary>
            <form class="aform" method="post" action="/admin/settings">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label for="ns_key"><?= esc(lang('Admin.settings.form.keyLabel')) ?></label>
                    <input id="ns_key" name="key" required maxlength="160" placeholder="<?= esc(lang('Admin.settings.form.keyPh'), 'attr') ?>">
                </div>
                <div>
                    <label for="ns_type"><?= esc(lang('Admin.settings.form.typeLabel')) ?></label>
                    <select id="ns_type" name="type">
                        <?php foreach ($types as $t): ?>
                            <option value="<?= esc($t, 'attr') ?>"><?= esc(lang('Admin.settings.form.type.' . $t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="ns_value"><?= esc(lang('Admin.settings.form.valueLabel')) ?></label>
                    <input id="ns_value" name="value" placeholder="<?= esc(lang('Admin.settings.form.valuePh'), 'attr') ?>">
                </div>
                <div>
                    <button type="submit" class="btn"><?= esc(lang('Admin.settings.form.saveNew')) ?></button>
                </div>
                <p class="full sub" style="margin:0"><?= esc(lang('Admin.settings.form.newHint')) ?></p>
            </form>
        </details>
        <?php if ($settings === []): ?>
            <p class="empty"><?= esc(lang('Admin.settings.settingsEmpty')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Admin.settings.colKey')) ?></th>
                    <th><?= esc(lang('Admin.settings.colValue')) ?></th>
                    <th><?= esc(lang('Admin.settings.colVersion')) ?></th>
                    <th><?= esc(lang('Admin.settings.colUpdated')) ?></th>
                    <th><?= esc(lang('Admin.settings.form.editCol')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($settings as $s): ?>
                        <?php
                        $sk = (string) ($s['key'] ?? '');
                        $u  = $fmtDate($s['updated_at'] ?? '');
                        $st = $inferType($s['value'] ?? null);
                        ?>
                        <tr>
                            <td><span class="key"><?= esc($sk) ?></span></td>
                            <td><?= $renderValue($s['value'] ?? null) ?></td>
                            <td><?= esc((string) ($s['version'] ?? 0)) ?></td>
                            <td class="when"><?= $u === '' ? '<span class="muted">—</span>' : esc($u) ?></td>
                            <td>
                                <details class="rowedit">
                                    <summary><?= esc(lang('Admin.settings.form.edit')) ?></summary>
                                    <form class="miniform" method="post" action="/admin/settings/<?= esc(rawurlencode($sk), 'attr') ?>">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <div>
                                            <label><?= esc(lang('Admin.settings.form.typeLabel')) ?></label>
                                            <select name="type">
                                                <?php foreach ($types as $t): ?>
                                                    <option value="<?= esc($t, 'attr') ?>"<?= $st === $t ? ' selected' : '' ?>><?= esc(lang('Admin.settings.form.type.' . $t)) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label><?= esc(lang('Admin.settings.form.valueLabel')) ?></label>
                                            <input name="value" value="<?= esc($editable($s['value'] ?? null), 'attr') ?>">
                                        </div>
                                        <button type="submit" class="btn"><?= esc(lang('Admin.settings.form.saveEdit')) ?></button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?= esc(lang('Admin.settings.flagsH')) ?>
            <span class="count"><?= esc($li($fc === 1 ? 'Admin.settings.flagsOne' : 'Admin.settings.flagsCount', (string) $fc)) ?></span>
        </h2>

        <details class="panel">
            <summary><?= esc(lang('Admin.settings.form.newFlag')) ?></summary>
            <form class="aform" method="post" action="/admin/flags">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div>
                    <label for="nf_key"><?= esc(lang('Admin.settings.form.flagKeyLabel')) ?></label>
                    <input id="nf_key" name="flag_key" required maxlength="160" placeholder="<?= esc(lang('Admin.settings.form.flagKeyPh'), 'attr') ?>">
                </div>
                <div>
                    <label for="nf_scope"><?= esc(lang('Admin.settings.form.scopeLabel')) ?></label>
                    <select id="nf_scope" name="group_id">
                        <option value=""><?= esc(lang('Admin.settings.form.scopeOrgOption')) ?></option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= esc((string) $g['id'], 'attr') ?>"><?= esc((string) $g['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="chk">
                    <input type="checkbox" id="nf_enabled" name="enabled" value="1">
                    <label for="nf_enabled" style="margin:0"><?= esc(lang('Admin.settings.form.enabledLabel')) ?></label>
                </div>
                <div class="full">
                    <label for="nf_desc"><?= esc(lang('Admin.settings.form.descriptionLabel')) ?></label>
                    <input id="nf_desc" name="description" maxlength="255">
                </div>
                <div>
                    <button type="submit" class="btn"><?= esc(lang('Admin.settings.form.saveNew')) ?></button>
                </div>
            </form>
        </details>
        <?php if ($flags === []): ?>
            <p class="empty"><?= esc(lang('Admin.settings.flagsEmpty')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Admin.settings.colFlag')) ?></th>
                    <th><?= esc(lang('Admin.settings.colScope')) ?></th>
                    <th><?= esc(lang('Admin.settings.colState')) ?></th>
                    <th><?= esc(lang('Admin.settings.colUpdated')) ?></th>
                    <th><?= esc(lang('Admin.settings.form.editCol')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($flags as $f): ?>
                        <?php
                        $fkey = (string) ($f['flag_key'] ?? '');
                        $gid  = trim((string) ($f['group_id'] ?? ''));
                        $on   = (int) ($f['enabled'] ?? 0) === 1;
                        $u    = $fmtDate($f['updated_at'] ?? '');
                        $col  = $on ? '#22c55e' : '#94a3b8';
                        ?>
                        <tr>
                            <td><span class="key"><?= esc($fkey) ?></span></td>
                            <td><?= $gid === '' ? esc(lang('Admin.settings.scopeOrg')) : '<span class="key">' . esc($gid) . '</span>' ?></td>
                            <td><span class="pill" style="color:<?= esc($col, 'attr') ?>;border-color:<?= esc($col, 'attr') ?>55"><?= esc($on ? lang('Admin.settings.on') : lang('Admin.settings.off')) ?></span></td>
                            <td class="when"><?= $u === '' ? '<span class="muted">—</span>' : esc($u) ?></td>
                            <td>
                                <?php // Toggle preserves the SAME scope (group_id) so the override is edited in place. ?>
                                <form class="miniform" method="post" action="/admin/flags/<?= esc(rawurlencode($fkey), 'attr') ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <input type="hidden" name="group_id" value="<?= esc($gid, 'attr') ?>">
                                    <input type="hidden" name="enabled" value="<?= $on ? '0' : '1' ?>">
                                    <button type="submit" class="btn"><?= esc($on ? lang('Admin.settings.form.turnOff') : lang('Admin.settings.form.turnOn')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
