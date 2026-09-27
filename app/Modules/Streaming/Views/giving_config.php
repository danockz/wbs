<?= $this->extend('layouts/app') ?>

<?php
/**
 * Stream giving config (GET /streaming/{id}/giving/config) — the browser face of
 * StreamGivingController::showConfig, which otherwise rendered the generic admin
 * console. Shows the organizer's raw giving configuration for a stream: whether
 * giving is enabled, the linked cause, widget / progress-bar toggles, suggested
 * amounts, min/max and currency, anonymity and acknowledgement options. When no
 * config row exists the service returns {enabled:false}, shown as a disabled
 * state rather than an error.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Streaming.givingConfig.*') with English fallback. Money fields are shown
 * as minor units with the currency verbatim.
 *
 * @var array<string,mixed> $config stream_giving_configs row (or {stream_id,enabled:false})
 * @var string              $streamId
 * @var string              $csrf
 * @var string              $title
 */
$c        = is_array($config ?? null) ? $config : [];
$causes   = is_array($causes ?? null) ? $causes : [];
$enabled  = ! empty($c['enabled']);
$title    = $title ?? lang('Streaming.givingConfig.title');
$streamId = $streamId ?? (string) ($c['stream_id'] ?? '');
$csrf     = $csrf ?? '';
$sidAttr  = rawurlencode((string) $streamId);
$suggested = $c['suggested_amounts'] ?? null;
if (is_string($suggested)) {
    $suggested = json_decode($suggested, true);
}
$suggested = is_array($suggested) ? $suggested : [];
$yn = static fn ($b): string => ! empty($b) ? lang('Streaming.givingConfig.yes') : lang('Streaming.givingConfig.no');
$chk = static fn ($b): string => ! empty($b) ? ' checked' : '';
$suggestedStr = implode(', ', array_map(static fn ($a) => is_scalar($a) ? (string) $a : '', $suggested));
$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Streaming.givingConfig.title')) ?></h1>
    <?php if (! empty($c['stream_id'])): ?>
        <div class="sub"><?= esc(lang('Streaming.givingConfig.streamLabel')) ?>: <?= esc((string) $c['stream_id']) ?></div>
    <?php endif; ?>

    <?php if (! $enabled): ?>
        <div class="card" style="border-color:#334155;color:#94a3b8"><?= esc(lang('Streaming.givingConfig.disabled')) ?></div>
    <?php else: ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.enabled')) ?></div><div class="v"><?= esc($yn(true)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.cause')) ?></div><div class="v"><?= esc((string) ($c['cause_id'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.widget')) ?></div><div class="v"><?= esc($yn($c['widget_enabled'] ?? false)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.progressBar')) ?></div><div class="v"><?= esc($yn($c['progress_bar_enabled'] ?? false)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.currency')) ?></div><div class="v"><?= esc((string) ($c['currency'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.minAmount')) ?></div><div class="v"><?= esc((string) ($c['min_amount_minor'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.maxAmount')) ?></div><div class="v"><?= esc((string) ($c['max_amount_minor'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.anonymous')) ?></div><div class="v"><?= esc($yn($c['allow_anonymous'] ?? false)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Streaming.givingConfig.ack')) ?></div><div class="v"><?= esc($yn($c['ack_enabled'] ?? false)) ?></div></div>
        </div>

        <?php if ($suggested !== []): ?>
            <h2><?= esc(lang('Streaming.givingConfig.suggestedAmounts')) ?></h2>
            <div class="card">
                <?php foreach ($suggested as $amt): ?><span class="pill"><?= esc(is_scalar($amt) ? (string) $amt : json_encode($amt, JSON_UNESCAPED_UNICODE)) ?></span> <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($flashOk !== null && $flashOk !== ''): ?>
        <div class="card" style="border-color:#22c55e55;background:#052e1b;color:#bbf7d0"><?= esc($flashOk) ?></div>
    <?php endif; ?>
    <?php if ($flashErr !== null && $flashErr !== ''): ?>
        <div class="card" style="border-color:#ef444455;background:#3f1d1d;color:#fecaca"><?= esc($flashErr) ?></div>
    <?php endif; ?>

    <?php if ($streamId !== ''): ?>
        
        <div class="gc">
            <h2><?= esc(lang('Streaming.givingConfig.editHeading')) ?></h2>
            <form method="post" action="<?= esc(base_url('streams/' . $sidAttr . '/giving/config'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <div class="fields">
                    <div class="full toggles">
                        <label><input type="checkbox" name="enabled" value="1"<?= $chk($c['enabled'] ?? false) ?>> <?= esc(lang('Streaming.givingConfig.enabled')) ?></label>
                        <label><input type="checkbox" name="widget_enabled" value="1"<?= $chk($c['widget_enabled'] ?? true) ?>> <?= esc(lang('Streaming.givingConfig.widget')) ?></label>
                        <label><input type="checkbox" name="progress_bar_enabled" value="1"<?= $chk($c['progress_bar_enabled'] ?? true) ?>> <?= esc(lang('Streaming.givingConfig.progressBar')) ?></label>
                        <label><input type="checkbox" name="allow_anonymous" value="1"<?= $chk($c['allow_anonymous'] ?? true) ?>> <?= esc(lang('Streaming.givingConfig.anonymous')) ?></label>
                        <label><input type="checkbox" name="ack_enabled" value="1"<?= $chk($c['ack_enabled'] ?? true) ?>> <?= esc(lang('Streaming.givingConfig.ack')) ?></label>
                    </div>
                    <div class="full">
                        <label for="cause_id"><?= esc(lang('Streaming.givingConfig.cause')) ?></label>
                        <?php $curCause = (string) ($c['cause_id'] ?? ''); ?>
                        <?php if ($causes !== []): ?>
                            <select id="cause_id" name="cause_id">
                                <option value=""><?= esc(lang('Streaming.givingConfig.causeNone')) ?></option>
                                <?php
                                $seen = false;
                                foreach ($causes as $cause):
                                    $cid = (string) ($cause['id'] ?? '');
                                    if ($cid === '') {
                                        continue;
                                    }
                                    $cname = trim((string) ($cause['name'] ?? ''));
                                    $clabel = $cname !== '' ? $cname : $cid;
                                    $sel = $cid === $curCause;
                                    $seen = $seen || $sel;
                                    ?>
                                    <option value="<?= esc($cid, 'attr') ?>"<?= $sel ? ' selected' : '' ?>><?= esc($clabel) ?></option>
                                <?php endforeach; ?>
                                <?php if ($curCause !== '' && ! $seen): // preserve a previously-set cause not in the active list ?>
                                    <option value="<?= esc($curCause, 'attr') ?>" selected><?= esc($curCause) ?></option>
                                <?php endif; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" id="cause_id" name="cause_id" maxlength="64" value="<?= esc($curCause, 'attr') ?>" placeholder="<?= esc(lang('Streaming.givingConfig.causePh'), 'attr') ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label><?= esc(lang('Streaming.givingConfig.currency')) ?></label>
                        <input type="text" name="currency" maxlength="3" value="<?= esc((string) ($c['currency'] ?? ''), 'attr') ?>" placeholder="GHS">
                    </div>
                    <div>
                        <label><?= esc(lang('Streaming.givingConfig.minAmount')) ?></label>
                        <input type="number" name="min_amount_minor" min="0" value="<?= esc((string) ($c['min_amount_minor'] ?? ''), 'attr') ?>">
                    </div>
                    <div>
                        <label><?= esc(lang('Streaming.givingConfig.maxAmount')) ?></label>
                        <input type="number" name="max_amount_minor" min="0" value="<?= esc((string) ($c['max_amount_minor'] ?? ''), 'attr') ?>">
                    </div>
                    <div class="full">
                        <label><?= esc(lang('Streaming.givingConfig.suggestedAmounts')) ?></label>
                        <input type="text" name="suggested_amounts" value="<?= esc($suggestedStr, 'attr') ?>" placeholder="<?= esc(lang('Streaming.givingConfig.suggestedPh'), 'attr') ?>">
                    </div>
                    <div class="full">
                        <button class="btn" type="submit"><?= esc(lang('Streaming.givingConfig.saveBtn')) ?></button>
                        <span class="counts"><?= esc(lang('Streaming.givingConfig.causeHint')) ?></span>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
