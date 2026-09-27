<?php
/**
 * Runtime config key detail (GET /gamification/config/{key}) — the browser face
 * of ConfigController::showConfig (was the generic admin console). Shows one
 * setting: key, cast value, type, editability and description. Not-found panel
 * when the key is absent.
 *
 * The service returns {key,value,type,description,is_editable,updated_at}.
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.configShow.*') with English fallback.
 *
 * @var array<string,mixed>|null $entry config entry, or null
 * @var string                   $title
 */
$e     = is_array($entry ?? null) ? $entry : null;
$title = $title ?? lang('Gamification.admin.configShow.title');
$fmt = static function ($v): string {
    if (is_bool($v)) { return $v ? 'true' : 'false'; }
    if (is_array($v)) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''; }
    return (string) $v;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.configShow.title')) ?></h1>
    <?php if ($e === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.configShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub"><?= esc((string) ($e['key'] ?? '—')) ?></div>
        <?php if (! empty($e['description'])): ?><div class="card"><?= esc((string) $e['description']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.configShow.value')) ?></div><div class="v"><?= esc($fmt($e['value'] ?? null)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.configShow.type')) ?></div><div class="v"><?= esc((string) ($e['type'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.configShow.editability')) ?></div><div class="v"><?= esc(empty($e['is_editable']) ? lang('Gamification.admin.configShow.readOnly') : lang('Gamification.admin.configShow.editable')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.configShow.updatedAt')) ?></div><div class="v"><?= esc((string) ($e['updated_at'] ?? '—')) ?></div></div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
