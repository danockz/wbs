<?php
/**
 * Streak definition detail (GET /gamification/streaks/{code}) — the browser face
 * of AwardsController::showStreak (was the generic admin console). Shows one
 * streak definition: code, name, description, cadence, grace days, sort order and
 * status. Not-found panel when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.streakShow.*') with English fallback; cadence
 * vocabulary localized with a raw-value fallback.
 *
 * A manual RECORD action form (webcsrf-guarded POST) lets an admin log a streak
 * tick for a chosen member. No-JS form; the write is group-scope enforced.
 *
 * @var array<string,mixed>|null   $streak streak_definitions row, or null
 * @var list<array<string,mixed>>  $roster active members for the subject picker
 * @var string                     $csrf   webcsrf double-submit token
 * @var string                     $title
 */
$s      = is_array($streak ?? null) ? $streak : null;
$roster = is_array($roster ?? null) ? $roster : [];
$csrf   = $csrf ?? '';
$title  = $title ?? lang('Gamification.admin.streakShow.title');
$code   = (string) ($s['code'] ?? '');
$af     = static fn (string $k): string => lang('Gamification.admin.streakShow.actions.' . $k);
$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
$cadence = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $t = lang('Gamification.admin.streakShow.cadence.' . $v);
    return (is_string($t) && ! str_contains($t, 'Gamification.')) ? $t : $v;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    
    <h1><?= esc(lang('Gamification.admin.streakShow.title')) ?></h1>
    <?php if ($flashOk !== ''): ?><div class="aw-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="aw-flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <?php if ($s === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.streakShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub" style="<?= ! empty($s['color']) ? 'color:' . esc($s['color'], 'attr') . ';' : '' ?>">
            <?php if (! empty($s['icon'])): ?><?= esc((string) $s['icon']) ?> <?php endif; ?>
            <?= esc((string) ($s['name'] ?? ($s['code'] ?? '—'))) ?>
        </div>
        <?php if (! empty($s['description'])): ?><div class="card"><?= esc((string) $s['description']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.streakShow.code')) ?></div><div class="v"><?= esc((string) ($s['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.streakShow.cadenceLabel')) ?></div><div class="v"><?= esc($cadence((string) ($s['cadence'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.streakShow.grace')) ?></div><div class="v"><?= esc((string) (int) ($s['default_grace_days'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.streakShow.sortOrder')) ?></div><div class="v"><?= esc((string) ($s['sort_order'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.streakShow.status')) ?></div><div class="v"><?= esc((string) ($s['status'] ?? '—')) ?></div></div>
        </div>

        <div class="aw-actions">
            <div class="aw-panel">
                <h2><?= esc($af('recordHeading')) ?></h2>
                <p class="hint"><?= esc($af('recordHint')) ?></p>
                <form method="post" action="/gamification/streak-definitions/<?= esc(rawurlencode($code), 'attr') ?>/record">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <label for="rec_subject"><?= esc($af('member')) ?></label>
                    <select id="rec_subject" name="subject_id" required>
                        <option value=""><?= esc($af('memberPh')) ?></option>
                        <?php foreach ($roster as $u): ?>
                            <?php $uid = (string) ($u['id'] ?? ''); if ($uid === '') { continue; } $rn = (string) ($u['display_name'] ?? ''); ?>
                            <option value="<?= esc($uid, 'attr') ?>"><?= esc($rn !== '' ? $rn : $uid) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="rec_season"><?= esc($af('season')) ?></label>
                    <input id="rec_season" type="text" name="season_id" maxlength="60" placeholder="<?= esc($af('seasonPh'), 'attr') ?>">
                    <button type="submit" class="aw-btn"><?= esc($af('recordBtn')) ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
