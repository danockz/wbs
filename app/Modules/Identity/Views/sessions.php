<?php
/**
 * Active-session review page. Behind the auth filter. Lets a user see every
 * active session on their account and revoke any of them. Self-contained,
 * sandbox-safe (no external assets).
 *
 * @var list<array<string,mixed>> $sessions each: id, mfa_level, risk_score,
 *                                created_at, last_seen_at, expires_at, current
 * @var string                    $csrf     CSRF token for the revoke forms
 */
$sessions = $sessions ?? [];
$csrf     = $csrf ?? '';
include __DIR__ . '/_locale.php';

$badge = static function (string $mfa): array {
    $col = match ($mfa) {
        'high'  => '#34d399',
        'low'   => '#fbbf24',
        'token' => '#818cf8',
        default => '#94a3b8',
    };
    $key = in_array($mfa, ['high', 'low', 'token'], true) ? $mfa : 'none';

    return [lang('Identity.assurance.' . $key), $col];
};
$fmt = static fn (?string $t): string => $t ? esc(substr((string) $t, 0, 16)) : '—';
?>

<?php ob_start(); ?>
<?= esc(lang('Identity.sessions.pageTitle')) ?> — <?= esc(lang('Identity.brand')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { width:100%; max-width:620px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:14px; padding:18px 20px; margin-bottom:12px; }


        .meta { font-size:.82rem; color:#94a3b8; margin-top:6px; line-height:1.5; }


        .back { display:inline-block; margin-bottom:18px; font-size:.9rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<div class="wrap">
        <a class="back" href="/me"><?= esc(lang('Identity.sessions.back')) ?></a>
        <h1><?= esc(lang('Identity.sessions.heading')) ?></h1>
        <div class="sub"><?= esc(lang('Identity.sessions.sub')) ?></div>

        <?php if ($sessions === []): ?>
            <div class="card empty"><?= esc(lang('Identity.sessions.empty')) ?></div>
        <?php else: ?>
            <?php foreach ($sessions as $s): ?>
                <?php [$label, $color] = $badge((string) ($s['mfa_level'] ?? 'none')); ?>
                <div class="card <?= ! empty($s['current']) ? 'current' : '' ?>">
                    <div class="row">
                        <div>
                            <span class="tag" style="color:<?= $color ?>; border-color:<?= $color ?>55;"><?= esc($label) ?></span>
                            <?php if (! empty($s['current'])): ?><span class="this"><?= esc(lang('Identity.sessions.thisDevice')) ?></span><?php endif; ?>
                            <div class="meta">
                                <?= esc(lang('Identity.sessions.lastActive')) ?>: <?= $fmt($s['last_seen_at'] ?? null) ?> UTC ·
                                <?= esc(lang('Identity.sessions.started')) ?>: <?= $fmt($s['created_at'] ?? null) ?> ·
                                <?= esc(lang('Identity.sessions.expires')) ?>: <?= $fmt($s['expires_at'] ?? null) ?><br>
                                <?= esc(lang('Identity.sessions.riskScore')) ?>: <?= (int) ($s['risk_score'] ?? 0) ?>
                            </div>
                        </div>
                        <form method="post" action="/me/sessions/<?= esc((string) $s['id']) ?>/revoke">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf) ?>">
                            <button class="btn <?= ! empty($s['current']) ? 'ghost' : '' ?>" type="submit">
                                <?= esc(! empty($s['current']) ? lang('Identity.sessions.signOut') : lang('Identity.sessions.revoke')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
