<?php
/**
 * Follow-up type detail (GET /gamification/follow-ups/types/{code}) — the browser
 * face of FollowUpsController::showFollowUpType (was the generic admin console).
 * Shows one follow-up type: code, name, description, phase, outcome requirement,
 * default next-days interval, award rule, group scope and status. Not-found panel
 * when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.followupTypeShow.*'); phase vocabulary raw-value fallback.
 *
 * @var array<string,mixed>|null $type follow_up_types row, or null
 * @var string                   $title
 */
$t     = is_array($type ?? null) ? $type : null;
$title = $title ?? lang('Gamification.admin.followupTypeShow.title');
$phaseLabel = static function (string $p): string {
    $p = strtolower(trim($p));
    if ($p === '') { return '—'; }
    $s = lang('Gamification.admin.followupTypeShow.phase.' . $p);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $p;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.admin.followupTypeShow.title')) ?></h1>
    <?php if ($t === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.followupTypeShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color'], 'attr') . ';' : '' ?>">
            <?php if (! empty($t['icon'])): ?><?= esc((string) $t['icon']) ?> <?php endif; ?>
            <?= esc((string) ($t['name'] ?? ($t['code'] ?? '—'))) ?>
        </div>
        <?php if (! empty($t['description'])): ?><div class="card"><?= esc((string) $t['description']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.code')) ?></div><div class="v"><?= esc((string) ($t['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.phaseLabel')) ?></div><div class="v"><?= esc($phaseLabel((string) ($t['phase'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.requiresOutcome')) ?></div><div class="v"><?= esc(! empty($t['requires_outcome']) ? lang('Gamification.admin.followupTypeShow.yes') : lang('Gamification.admin.followupTypeShow.no')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.nextDays')) ?></div><div class="v"><?= esc((string) ($t['default_next_days'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.awardRule')) ?></div><div class="v"><?= esc((string) ($t['award_rule_code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.status')) ?></div><div class="v"><?= esc((string) ($t['status'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.followupTypeShow.group')) ?></div><div class="v"><?= esc((string) ($t['group_id'] ?? lang('Gamification.admin.followupTypeShow.orgWide'))) ?></div></div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
