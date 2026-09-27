<?php
/**
 * Member SELF-SERVICE profile editor (GET /me/profile). Behind the auth filter.
 * Wires the previously-orphaned AccountService::updateProfile to a no-JS,
 * CSP-safe browser page and folds in the existing photo set/remove controls.
 *
 *   - POST /me/profile        → display name + language + time zone
 *   - POST /me/photo          → set photo URL
 *   - POST /me/photo/remove   → clear photo (falls back to initials avatar)
 * Each form carries the `_csrf` field (WebCsrfFilter). Self-contained page (own
 * <html>, _locale.php); copy via lang('Identity.profile.*'). Email is shown
 * read-only (changing it is an admin/identity flow).
 *
 * @var array<string,mixed> $user      current user row
 * @var string              $avatarSrc resolved avatar (photo or inline initials)
 * @var list<string>        $locales   supported locale codes for the language select
 * @var string              $csrf      CSRF token for the forms
 */
$user      = $user ?? [];
$avatarSrc = $avatarSrc ?? '';
$locales   = $locales ?? ['en'];
$csrf      = $csrf ?? '';
include __DIR__ . '/_locale.php';

$displayName = (string) ($user['display_name'] ?? '');
$email       = (string) ($user['email'] ?? '');
$curLocale   = (string) ($user['locale'] ?? 'en');
$curTz       = (string) ($user['timezone'] ?? 'UTC');
$photoUrl    = (string) ($user['profile_photo_url'] ?? '');
// Verified identity fields (read-only self-service — see identityReadonlyNote).
$phoneRaw    = (string) ($user['phone'] ?? '');
$dobRaw      = (string) ($user['date_of_birth'] ?? '');
$countryRaw  = (string) ($user['country_code'] ?? '');

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

$langLabel = static function (string $code): string {
    $l = lang('Identity.profile.langName.' . $code);

    return is_string($l) && ! str_contains($l, 'Identity.profile.') ? $l : strtoupper($code);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.profile.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { width:100%; max-width:560px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:20px 22px; margin-bottom:16px; }


        .avatar { width:56px; height:56px; border-radius:14px; border:1px solid #1e293b; object-fit:cover; background:#0b1120; }


        input:focus, select:focus { outline:2px solid #22d3ee; border-color:#22d3ee; }


        input[readonly] { color:#94a3b8; cursor:not-allowed; }


        button { margin-top:16px; border:none; border-radius:9px; padding:11px 20px; font-size:.92rem; font-weight:600; cursor:pointer; background:#22d3ee; color:#04222a; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
        <a class="back" href="/me"><?= esc(lang('Identity.profile.back')) ?></a>
        <h1><?= esc(lang('Identity.profile.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Identity.profile.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Details -->
        <form class="card" method="post" action="/me/profile">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <div class="head">
                <img class="avatar" src="<?= esc($avatarSrc, 'attr') ?>" alt="" width="56" height="56">
                <h2><?= esc(lang('Identity.profile.heading')) ?></h2>
            </div>

            <label for="p-name"><?= esc(lang('Identity.profile.displayNameLbl')) ?></label>
            <input id="p-name" name="display_name" value="<?= esc($displayName, 'attr') ?>" maxlength="150" placeholder="<?= esc(lang('Identity.profile.displayNamePlaceholder'), 'attr') ?>">

            <label for="p-locale"><?= esc(lang('Identity.profile.localeLbl')) ?></label>
            <select id="p-locale" name="locale">
                <?php foreach ($locales as $code): ?>
                    <option value="<?= esc((string) $code, 'attr') ?>"<?= (string) $code === $curLocale ? ' selected' : '' ?>><?= esc($langLabel((string) $code)) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="p-tz"><?= esc(lang('Identity.profile.timezoneLbl')) ?></label>
            <input id="p-tz" name="timezone" value="<?= esc($curTz, 'attr') ?>" maxlength="64" placeholder="<?= esc(lang('Identity.profile.timezonePlaceholder'), 'attr') ?>">

            <label for="p-email"><?= esc(lang('Identity.profile.emailLbl')) ?></label>
            <input id="p-email" type="email" value="<?= esc($email, 'attr') ?>" readonly>
            <div class="readonly-note"><?= esc(lang('Identity.profile.emailReadonlyNote')) ?></div>

            <label for="p-phone"><?= esc(lang('Identity.profile.phoneLbl')) ?></label>
            <input id="p-phone" type="tel" value="<?= esc($phoneRaw, 'attr') ?>" readonly>

            <label for="p-dob"><?= esc(lang('Identity.profile.dobLbl')) ?></label>
            <input id="p-dob" type="date" value="<?= esc($dobRaw, 'attr') ?>" readonly>

            <label for="p-country"><?= esc(lang('Identity.profile.countryLbl')) ?></label>
            <input id="p-country" type="text" value="<?= esc($countryRaw, 'attr') ?>" readonly>
            <div class="readonly-note"><?= esc(lang('Identity.profile.identityReadonlyNote')) ?></div>

            <button type="submit"><?= esc(lang('Identity.profile.saveBtn')) ?></button>
        </form>

        <!-- Photo -->
        <form class="card" method="post" action="/me/photo">
            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
            <h2><?= esc(lang('Identity.profile.photoHeading')) ?></h2>
            <p class="hint"><?= esc(lang('Identity.profile.photoSub')) ?></p>
            <label for="p-photo"><?= esc(lang('Identity.profile.photoUrlLbl')) ?></label>
            <input id="p-photo" name="photo_url" value="<?= esc($photoUrl, 'attr') ?>" maxlength="512" placeholder="<?= esc(lang('Identity.profile.photoUrlPlaceholder'), 'attr') ?>">
            <div class="btnrow">
                <button type="submit"><?= esc(lang('Identity.profile.photoSaveBtn')) ?></button>
            </div>
        </form>
        <?php if ($photoUrl !== ''): ?>
            <form class="card" method="post" action="/me/photo/remove" style="padding:14px 22px;">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <button class="ghost" type="submit" style="margin-top:0;"><?= esc(lang('Identity.profile.photoRemoveBtn')) ?></button>
            </form>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
