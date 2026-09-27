<?php
/**
 * Point rule detail (GET /gamification/rules/{code}) — the browser face of
 * ConfigController::showRule (was the generic admin console). Shows the current
 * (highest) version of a rule with its key parameters, plus the full version
 * history newest-first. Not-found panel when the code is unknown.
 *
 * The service returns {code, current:{...}, versions:[{version,status,points,
 * effective_from,effective_to}]}. Extends layouts/app (locale-aware
 * <html lang dir>). Copy via lang('Gamification.admin.ruleShow.*').
 *
 * @var array<string,mixed>|null $rule {code,current,versions} or null
 * @var string                   $title
 */
$rule     = is_array($rule ?? null) ? $rule : null;
$current  = is_array($rule['current'] ?? null) ? $rule['current'] : [];
$versions = is_array($rule['versions'] ?? null) ? $rule['versions'] : [];
$title    = $title ?? lang('Gamification.admin.ruleShow.title');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.ruleShow.title')) ?></h1>
    <?php if ($rule === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.ruleShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub"><?= esc((string) ($rule['code'] ?? '—')) ?></div>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.ruleShow.version')) ?></div><div class="v"><?= esc((string) (int) ($current['version'] ?? 1)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.ruleShow.points')) ?></div><div class="v"><?= esc((string) (int) ($current['points'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.ruleShow.eventType')) ?></div><div class="v"><?= esc((string) ($current['event_type'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.ruleShow.status')) ?></div><div class="v"><?= esc((string) ($current['status'] ?? '—')) ?></div></div>
        </div>
        <?php if (! empty($current['explanation'])): ?><div class="card"><?= esc((string) $current['explanation']) ?></div><?php endif; ?>

        <h2><?= esc(lang('Gamification.admin.ruleShow.history')) ?></h2>
        <?php if ($versions === []): ?>
            <div class="empty"><?= esc(lang('Gamification.admin.ruleShow.noHistory')) ?></div>
        <?php else: ?>
            <?php foreach ($versions as $v): ?>
                <div class="card">
                    <div class="row">
                        <span class="author">v<?= esc((string) (int) ($v['version'] ?? 0)) ?></span>
                        <span class="pill"><?= esc(str_replace('{0}', (string) (int) ($v['points'] ?? 0), lang('Gamification.admin.ruleShow.points'))) ?></span>
                    </div>
                    <div class="meta">
                        <?= esc((string) ($v['status'] ?? '—')) ?>
                        <?php if (! empty($v['effective_from'])): ?> · <?= esc(lang('Gamification.admin.ruleShow.from')) ?> <?= esc((string) $v['effective_from']) ?><?php endif; ?>
                        <?php if (! empty($v['effective_to'])): ?> · <?= esc(lang('Gamification.admin.ruleShow.to')) ?> <?= esc((string) $v['effective_to']) ?><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
