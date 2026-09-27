<?php
/**
 * Achievement detail (GET /gamification/achievements/{code}) — the browser face
 * of AwardsController::showAchievement (was the generic admin console). Shows one
 * achievement definition: code, name, description, category, trigger, XP / bonus
 * points, secret flag and status. Not-found panel when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.achievementShow.*') with English fallback.
 *
 * A manual UNLOCK action form (webcsrf-guarded POST) lets an admin award this
 * achievement to a chosen member; a REEVALUATE form re-runs the automatic
 * criteria for a member. Both are no-JS forms; the write is scope-enforced.
 *
 * @var array<string,mixed>|null   $achievement achievement_definitions row, or null
 * @var list<array<string,mixed>>  $roster      active members for the subject picker
 * @var string                     $csrf        webcsrf double-submit token
 * @var string                     $title
 */
$a      = is_array($achievement ?? null) ? $achievement : null;
$roster = is_array($roster ?? null) ? $roster : [];
$csrf   = $csrf ?? '';
$title  = $title ?? lang('Gamification.admin.achievementShow.title');
$code   = (string) ($a['code'] ?? '');
$af     = static fn (string $k): string => lang('Gamification.admin.achievementShow.actions.' . $k);
$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
$memberOptions = static function (array $roster): void {
    foreach ($roster as $u) {
        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') { continue; }
        $rn = (string) ($u['display_name'] ?? '');
        echo '<option value="' . esc($uid, 'attr') . '">' . esc($rn !== '' ? $rn : $uid) . '</option>';
    }
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    
    <h1><?= esc(lang('Gamification.admin.achievementShow.title')) ?></h1>
    <?php if ($flashOk !== ''): ?><div class="aw-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="aw-flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <?php if ($a === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.achievementShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub" style="<?= ! empty($a['color']) ? 'color:' . esc($a['color'], 'attr') . ';' : '' ?>">
            <?php if (! empty($a['icon'])): ?><?= esc((string) $a['icon']) ?> <?php endif; ?>
            <?= esc((string) ($a['name'] ?? ($a['code'] ?? '—'))) ?>
            <?php if (! empty($a['secret'])): ?><span class="pill"><?= esc(lang('Gamification.admin.achievementShow.secret')) ?></span><?php endif; ?>
        </div>
        <?php if (! empty($a['description'])): ?><div class="card"><?= esc((string) $a['description']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.code')) ?></div><div class="v"><?= esc((string) ($a['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.category')) ?></div><div class="v"><?= esc((string) ($a['category'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.trigger')) ?></div><div class="v"><?= esc((string) ($a['trigger_type'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.xp')) ?></div><div class="v"><?= esc((string) (int) ($a['xp'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.bonusPoints')) ?></div><div class="v"><?= esc((string) (int) ($a['bonus_points'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.achievementShow.status')) ?></div><div class="v"><?= esc((string) ($a['status'] ?? '—')) ?></div></div>
        </div>

        <div class="aw-actions">
            <div class="aw-panel">
                <h2><?= esc($af('unlockHeading')) ?></h2>
                <p class="hint"><?= esc($af('unlockHint')) ?></p>
                <form method="post" action="/gamification/achievements/<?= esc(rawurlencode($code), 'attr') ?>/unlock">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <label for="unlock_subject"><?= esc($af('member')) ?></label>
                    <select id="unlock_subject" name="subject_id" required>
                        <option value=""><?= esc($af('memberPh')) ?></option>
                        <?php $memberOptions($roster); ?>
                    </select>
                    <label for="unlock_notes"><?= esc($af('notes')) ?></label>
                    <input id="unlock_notes" type="text" name="notes" maxlength="255" placeholder="<?= esc($af('notesPh'), 'attr') ?>">
                    <button type="submit" class="aw-btn grant"><?= esc($af('unlockBtn')) ?></button>
                </form>
            </div>
            <div class="aw-panel">
                <h2><?= esc($af('reevalHeading')) ?></h2>
                <p class="hint"><?= esc($af('reevalHint')) ?></p>
                <form method="post" action="/gamification/achievements/<?= esc(rawurlencode($code), 'attr') ?>/reevaluate">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <label for="reeval_subject"><?= esc($af('member')) ?></label>
                    <select id="reeval_subject" name="subject_id" required>
                        <option value=""><?= esc($af('memberPh')) ?></option>
                        <?php $memberOptions($roster); ?>
                    </select>
                    <button type="submit" class="aw-btn neutral"><?= esc($af('reevalBtn')) ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
