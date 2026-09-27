<?php
/**
 * SINGLE SETTING page (GET /admin/settings/{key}) — the browser face of
 * AdminController::getSetting, which otherwise rendered the generic admin
 * console. Shows one organization setting: its key and resolved value (booleans
 * as localized Enabled/Disabled, scalars verbatim, structured values as compact
 * JSON), with an explicit "not set" state when the key has no stored value.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Admin.settingShow.*') with English fallback; the setting KEY and its
 * value are server/config data shown verbatim & escaped.
 *
 * @var string $key   the setting key
 * @var mixed  $value the resolved value (null when unset)
 */
$key   = $key ?? '';
$value = $value ?? null;

include __DIR__ . '/_locale.php';

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

    return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
};
$shown = $renderValue($value);
?>

<?php ob_start(); ?>
<?= esc(lang('Admin.settingShow.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 720px; margin: 0 auto; padding: 5vh 20px 60px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:6px 18px; }


        .val { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.95rem; word-break:break-all; white-space:pre-wrap; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Admin.settingShow.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Admin.settingShow.sub')) ?></p>

        <div class="card">
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.settingShow.keyLbl')) ?></span>
                <span class="v"><span class="val"><?= esc((string) $key) ?></span></span>
            </div>
            <div class="kv">
                <span class="k"><?= esc(lang('Admin.settingShow.valueLbl')) ?></span>
                <span class="v">
                    <?php if ($shown === null): ?>
                        <span class="muted"><?= esc(lang('Admin.settingShow.notSet')) ?></span>
                    <?php else: ?>
                        <span class="val"><?= esc((string) $shown) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
