<?php
/**
 * Simple signed-in home. Behind the auth filter (session cookie required).
 *
 * @var array<string,mixed>|null $user       the current user row, if resolvable
 * @var string|null              $mfa_level  assurance on the current session
 * @var string                   $csrf       CSRF token for the logout form
 */
$user  = $user ?? null;
$mfa   = $mfa_level ?? 'none';
include __DIR__ . '/_locale.php';
$name  = $user['display_name'] ?? ($user['email'] ?? lang('Identity.friend'));
// Resolved avatar: the member's photo when set, else a deterministic inline-SVG
// initials avatar (self-contained data-URI — renders even with no network).
$avatarSrc = \WBS\Shared\Support\Avatar::resolveUrl(is_array($user) ? $user : ['display_name' => (string) $name], 96);
$col   = match ($mfa) {
    'high'  => '#34d399',
    'low'   => '#fbbf24',
    'token' => '#818cf8',
    default => '#94a3b8',
};
$badgeKey = in_array($mfa, ['high', 'low', 'token'], true) ? $mfa : 'none';
$badge    = [lang('Identity.assurance.' . $badgeKey), $col];
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.me.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .card { width:100%; max-width:520px; background:#0f172aee; border:1px solid #1e293b; border-radius:16px; padding:32px 30px; }


        .avatar { width:64px; height:64px; border-radius:16px; margin-bottom:14px; display:block;
            border:1px solid #1e293b; object-fit:cover; background:#0b1120; }


        .links { display:grid; gap:10px; margin-bottom:24px; }


        .links a { display:block; padding:14px 16px; border:1px solid #1e293b; border-radius:12px; background:#0b1120;
            color:#e2e8f0; text-decoration:none; }


        .links a:hover { border-color:#22d3ee; }


        .logout { border:none; background:transparent; color:#f87171; cursor:pointer; font-size:.9rem; padding:0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="auth-screen auth-screen--wide">

<div class="card">
        <img class="avatar" src="<?= esc($avatarSrc, 'attr') ?>" alt="<?= esc(lang('Identity.me.avatarAlt')) ?>" width="64" height="64">
        <div class="hi"><?= esc($li('Identity.me.greeting', (string) $name)) ?></div>
        <div class="sub"><?= esc(lang('Identity.me.signedIn')) ?></div>

        <span class="badge" style="color:<?= $badge[1] ?>; border-color:<?= $badge[1] ?>55;">
            <?= esc($badge[0]) ?>
        </span>

        <div class="links">
            <a href="/me/profile"><?= esc(lang('Identity.me.profileEdit')) ?> <span class="arrow">→</span></a>
            <a href="/g"><?= esc(lang('Identity.me.browse')) ?> <span class="arrow">→</span></a>
            <a href="/me/contacts"><?= esc(lang('Identity.me.contacts')) ?> <span class="arrow">→</span></a>
            <a href="/g"><?= esc(lang('Identity.me.community')) ?> <span class="arrow">→</span></a>
            <a href="/me/sessions"><?= esc(lang('Identity.me.sessions')) ?> <span class="arrow">→</span></a>
        </div>

        <form method="post" action="/logout">
            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
            <button class="logout" type="submit"><?= esc(lang('Identity.me.signOut')) ?></button>
        </form>
    </div>

</div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
