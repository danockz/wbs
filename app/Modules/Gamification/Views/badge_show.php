<?php
/**
 * Badge definition detail (GET /gamification/badges/{code}) — the browser face of
 * AwardsController::showBadge (was the generic admin console). Shows one badge:
 * code, name, criteria, visibility, permanence, expiry policy and group scope.
 * Not-found panel when the code is unknown.
 *
 * Extends layouts/app (locale-aware <html lang dir>). Copy via
 * lang('Gamification.admin.badgeShow.*') with English fallback; visibility
 * vocabulary localized with a raw-value fallback.
 *
 * Manual GRANT / REVOKE action forms (webcsrf-guarded POSTs) let an admin award
 * or remove this badge for a member, chosen from the active-member picker. Both
 * are plain no-JS forms; the service still enforces group scope on the write.
 *
 * @var array<string,mixed>|null      $badge  badges row, or null
 * @var list<array<string,mixed>>     $roster active members for the subject picker
 * @var string                        $csrf   webcsrf double-submit token
 * @var string                        $title
 */
$b      = is_array($badge ?? null) ? $badge : null;
$roster = is_array($roster ?? null) ? $roster : [];
$csrf   = $csrf ?? '';
$title  = $title ?? lang('Gamification.admin.badgeShow.title');
$code   = (string) ($b['code'] ?? '');
$af     = static fn (string $k): string => lang('Gamification.admin.badgeShow.actions.' . $k);
$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';
$vis = static function (string $v): string {
    $v = strtolower(trim($v));
    if ($v === '') { return '—'; }
    $s = lang('Gamification.admin.badgeShow.visibility.' . $v);
    return (is_string($s) && ! str_contains($s, 'Gamification.')) ? $s : $v;
};
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    
    <h1><?= esc(lang('Gamification.admin.badgeShow.title')) ?></h1>
    <?php if ($flashOk !== ''): ?><div class="aw-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="aw-flash err"><?= esc($flashErr) ?></div><?php endif; ?>
    <?php if ($b === null): ?>
        <div class="empty"><?= esc(lang('Gamification.admin.badgeShow.notFound')) ?></div>
    <?php else: ?>
        <div class="sub"><?= esc((string) ($b['name'] ?? ($b['code'] ?? '—'))) ?></div>
        <?php if (! empty($b['criteria'])): ?><div class="card"><?= esc((string) $b['criteria']) ?></div><?php endif; ?>
        <div class="grid">
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.badgeShow.code')) ?></div><div class="v"><?= esc((string) ($b['code'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.badgeShow.visibilityLabel')) ?></div><div class="v"><?= esc($vis((string) ($b['visibility'] ?? ''))) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.badgeShow.permanence')) ?></div><div class="v"><?= esc(! empty($b['permanent']) ? lang('Gamification.admin.badgeShow.permanent') : lang('Gamification.admin.badgeShow.expiring')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.badgeShow.expiryPolicy')) ?></div><div class="v"><?= esc((string) ($b['expiry_policy'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k"><?= esc(lang('Gamification.admin.badgeShow.group')) ?></div><div class="v"><?= esc((string) ($b['group_id'] ?? lang('Gamification.admin.badgeShow.orgWide'))) ?></div></div>
        </div>

        <div class="aw-actions">
            <div class="aw-panel">
                <h2><?= esc($af('grantHeading')) ?></h2>
                <p class="hint"><?= esc($af('grantHint')) ?></p>
                <form method="post" action="/gamification/badges/<?= esc(rawurlencode($code), 'attr') ?>/grant">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <label for="grant_subject"><?= esc($af('member')) ?></label>
                    <select id="grant_subject" name="subject_id" required>
                        <option value=""><?= esc($af('memberPh')) ?></option>
                        <?php foreach ($roster as $u): ?>
                            <?php $uid = (string) ($u['id'] ?? ''); if ($uid === '') { continue; } $rn = (string) ($u['display_name'] ?? ''); ?>
                            <option value="<?= esc($uid, 'attr') ?>"><?= esc($rn !== '' ? $rn : $uid) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="grant_ref"><?= esc($af('sourceRef')) ?></label>
                    <input id="grant_ref" type="text" name="source_ref" maxlength="120" placeholder="<?= esc($af('sourceRefPh'), 'attr') ?>">
                    <button type="submit" class="aw-btn grant"><?= esc($af('grantBtn')) ?></button>
                </form>
            </div>
            <div class="aw-panel">
                <h2><?= esc($af('revokeHeading')) ?></h2>
                <p class="hint"><?= esc($af('revokeHint')) ?></p>
                <form method="post" action="/gamification/badges/<?= esc(rawurlencode($code), 'attr') ?>/revoke">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <label for="revoke_subject"><?= esc($af('member')) ?></label>
                    <select id="revoke_subject" name="subject_id" required>
                        <option value=""><?= esc($af('memberPh')) ?></option>
                        <?php foreach ($roster as $u): ?>
                            <?php $uid = (string) ($u['id'] ?? ''); if ($uid === '') { continue; } $rn = (string) ($u['display_name'] ?? ''); ?>
                            <option value="<?= esc($uid, 'attr') ?>"><?= esc($rn !== '' ? $rn : $uid) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="aw-btn revoke"><?= esc($af('revokeBtn')) ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?= $this->endSection() ?>
