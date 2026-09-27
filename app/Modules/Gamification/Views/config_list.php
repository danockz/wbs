<?php
/**
 * Runtime config — admin list + in-page CRUD (GET /gamification/config) — the browser
 * face of ConfigController::listConfig. The engine's typed runtime settings as a
 * key/value table (key, cast value, type, editability, description), now with inline
 * create / edit / delete controls posting to the webcsrf-guarded config routes.
 *
 * set() is an UPSERT keyed on the config key (in the URL): the "New setting" form posts
 * to /gamification/config/{key} with the key typed by the admin; each editable row's
 * "Edit" form posts to the same path to overwrite the value/type/description. READ-ONLY
 * rows (is_editable=false) show no controls — the service refuses to overwrite them.
 * Delete posts to /gamification/config/{key}/delete (a no-JS alias for the DELETE verb).
 * Progressive-enhancement: edit panels are plain <details>, no JS.
 *
 * @var array<string,mixed> $config config map keyed by config key
 * @var string              $csrf   webcsrf double-submit token
 * @var string              $title
 */
$config = is_array($config ?? null) ? $config : [];
$csrf   = $csrf ?? '';
$count  = count($config);
$title  = $title ?? lang('Gamification.admin.config.title');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$TYPES = ['string', 'integer', 'float', 'boolean', 'json'];
$fmt   = static function ($v): string {
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    if (is_array($v)) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''; }
    return (string) $v;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    

    <h1><?= esc(lang('Gamification.admin.config.title')) ?></h1>
    <div class="sub"><?= esc(str_replace('{0}', (string) $count, $count === 1 ? lang('Gamification.admin.config.countOne') : lang('Gamification.admin.config.count'))) ?></div>

    <?php if ($flashOk !== ''): ?><div class="cf-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="cf-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <details class="cf-panel">
        <summary>+ <?= esc(lang('Gamification.admin.config.form.newSetting')) ?></summary>
        <form method="post" action="/gamification/config" class="cf-form"
              onsubmit="var k=this.elements['__key'].value.trim(); if(!k){return false;} this.action='/gamification/config/'+encodeURIComponent(k); return true;">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.config.form.keyLabel')) ?></label>
                <input name="__key" required placeholder="<?= esc(lang('Gamification.admin.config.form.keyPh'), 'attr') ?>">
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.config.form.typeLabel')) ?></label>
                <select name="type">
                    <?php foreach ($TYPES as $topt): ?>
                        <option value="<?= esc($topt, 'attr') ?>"><?= esc($topt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?= esc(lang('Gamification.admin.config.form.valueLabel')) ?></label>
                <input name="value">
            </div>
            <div class="fld wide">
                <label><?= esc(lang('Gamification.admin.config.form.descriptionLabel')) ?></label>
                <input name="description">
            </div>
            <div class="cf-actions">
                <button type="submit" class="cf-btn"><?= esc(lang('Gamification.admin.config.form.saveNew')) ?></button>
                <span class="cf-ro"><?= esc(lang('Gamification.admin.config.form.newHint')) ?></span>
            </div>
        </form>
    </details>

    <?php if ($config === []): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.config.empty')) ?></div>
    <?php else: ?>
        <?php foreach ($config as $key => $c): ?>
            <?php
            $k          = (string) $key;
            $kc         = rawurlencode($k);
            $isEditable = ! empty($c['is_editable']);
            $type       = (string) ($c['type'] ?? 'string');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= esc($k) ?></span>
                    <span class="pill"><?= esc($fmt($c['value'] ?? null)) ?></span>
                </div>
                <?php if (! empty($c['description'])): ?><div class="counts"><?= esc((string) $c['description']) ?></div><?php endif; ?>
                <div class="meta">
                    <?= esc(lang('Gamification.admin.config.type')) ?>: <?= esc($type) ?>
                    · <?= esc($isEditable ? lang('Gamification.admin.config.editable') : lang('Gamification.admin.config.readOnly')) ?>
                </div>
                <?php if ($isEditable): ?>
                <div class="cf-acts">
                    <form method="post" action="/gamification/config/<?= esc($kc, 'attr') ?>/delete"
                          onsubmit="return confirm('<?= esc(lang('Gamification.admin.config.form.deleteConfirm'), 'js') ?>');">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <button type="submit" class="cf-act warn"><?= esc(lang('Gamification.admin.config.form.delete')) ?></button>
                    </form>
                </div>
                <details class="cf-inline">
                    <summary><?= esc(lang('Gamification.admin.config.form.edit')) ?></summary>
                    <form method="post" action="/gamification/config/<?= esc($kc, 'attr') ?>" class="cf-form">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="fld">
                            <label><?= esc(lang('Gamification.admin.config.form.typeLabel')) ?></label>
                            <select name="type">
                                <?php foreach ($TYPES as $topt): ?>
                                    <option value="<?= esc($topt, 'attr') ?>" <?= $type === $topt ? 'selected' : '' ?>><?= esc($topt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="fld">
                            <label><?= esc(lang('Gamification.admin.config.form.valueLabel')) ?></label>
                            <input name="value" value="<?= esc($fmt($c['value'] ?? null), 'attr') ?>">
                        </div>
                        <div class="fld wide">
                            <label><?= esc(lang('Gamification.admin.config.form.descriptionLabel')) ?></label>
                            <input name="description" value="<?= esc((string) ($c['description'] ?? ''), 'attr') ?>">
                        </div>
                        <div class="cf-actions">
                            <button type="submit" class="cf-btn"><?= esc(lang('Gamification.admin.config.form.saveEdit')) ?></button>
                        </div>
                    </form>
                </details>
                <?php else: ?>
                <div class="meta"><span class="cf-ro"><?= esc(lang('Gamification.admin.config.form.readOnlyNote')) ?></span></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
