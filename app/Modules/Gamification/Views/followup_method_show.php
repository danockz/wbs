<?php
/**
 * Follow-up method detail (GET /gamification/follow-ups/methods/{code}) — the
 * browser face of FollowUpsController::showFollowUpMethod (was the generic admin
 * console). Shows one contact channel: code, name, point-multiplier key, sort
 * order and status. Not-found panel when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.followupMethodShow.*') with English fallback.
 *
 * @var array<string,mixed>|null $method follow_up_methods row, or null
 * @var string                   $title
 */
$m     = is_array($method ?? null) ? $method : null;
$title = $title ?? lang('Gamification.admin.followupMethodShow.title');
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.followupMethodShow.title')) ?></h1>
    <?php if ($m === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.followupMethodShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub"><?= esc((string) ($m['name'] ?? ($m['code'] ?? '—'))) ?></div>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupMethodShow.code')) ?></div><div class="v"><?= esc((string) ($m['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupMethodShow.multiplierKey')) ?></div><div class="v"><?= esc((string) ($m['multiplier_key'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupMethodShow.sortOrder')) ?></div><div class="v"><?= esc((string) ($m['sort_order'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupMethodShow.status')) ?></div><div class="v"><?= esc((string) ($m['status'] ?? '—')) ?></div></div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
