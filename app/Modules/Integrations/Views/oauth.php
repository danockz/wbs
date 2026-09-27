<?php
/**
 * STREAMING OAUTH consent dashboard (GET /integrations/oauth) — the browser face
 * of StreamingOAuthController::index, the redirect half of the S7 consent flow.
 *
 * It lists every stream/meeting connection whose adapter supports OAuth and lets
 * an operator start the provider consent redirect ("grant access"). Each control
 * is a webcsrf-guarded POST to /integrations/oauth/{provider}/authorize carrying
 * the connection_id; the controller stashes a single-use state in the session and
 * REDIRECTS the browser to the provider's consent screen. The provider then calls
 * back to the callback route, which exchanges the code, stores the refresh token
 * write-only in the vault, and PRG-redirects back here with a flash. When a
 * provider's client id/secret are not configured in this environment the control
 * is disabled with an explanatory note (no consent can start without them). No
 * token or secret is ever rendered.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.oauth.*') with English fallback; the {0} count is
 * interpolated in PHP via $li(). `status` is localized (shared connections.status
 * keys) with a raw-value fallback; connection id / adapter code / display name are
 * server data shown verbatim.
 *
 * @var list<array<string,mixed>> $connections  oauth-capable connections
 * @var string                    $csrf
 */
$connections = $connections ?? [];
$csrf        = $csrf ?? '';
$count       = count($connections);

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Integrations.connections.status.' . $s);

    return $v === 'Integrations.connections.status.' . $s ? ucwords(str_replace('_', ' ', $s)) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.oauth.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        
        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }

        .meta { font-size:.72rem; color:#94a3b8; margin:6px 0 2px; }

        .warn { font-size:.72rem; color:#fca5a5; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Integrations.oauth.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.oauth.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <?php if ($connections === []): ?>
            <p class="empty"><?= esc(lang('Integrations.oauth.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Integrations.oauth.countOne' : 'Integrations.oauth.count', (string) $count)) ?></p>
            <?php foreach ($connections as $c): ?>
                <?php
                $id       = (string) ($c['id'] ?? '');
                $code     = (string) ($c['adapter_code'] ?? '');
                $dn       = (string) ($c['display_name'] ?? $code);
                $status   = (string) ($c['status'] ?? '');
                $provider = (string) ($c['oauth_provider'] ?? strtolower($code));
                $ready    = (bool) ($c['oauth_ready'] ?? false);
                $idAttr   = esc(rawurlencode($id), 'attr');
                $pvAttr   = esc(rawurlencode($provider), 'attr');
                ?>
                <article class="card">
                    <div class="top">
                        <span class="dn"><?= esc($dn) ?></span>
                        <span class="chip"><?= esc($statusLbl($status)) ?></span>
                    </div>
                    <div class="code"><?= esc($code) ?></div>
                    <div class="meta"><b><?= esc(lang('Integrations.oauth.providerLabel')) ?>:</b> <?= esc(ucfirst($provider)) ?></div>

                    <?php if ($id !== ''): ?>
                        <div class="actions">
                            <?php if ($ready): ?>
                                <form method="post" action="/integrations/oauth/<?= $pvAttr ?>/authorize?connection_id=<?= $idAttr ?>">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <input type="hidden" name="connection_id" value="<?= esc($id, 'attr') ?>">
                                    <button class="btn go" type="submit"><?= esc(lang('Integrations.oauth.grantBtn')) ?></button>
                                </form>
                                <span class="rhint"><?= esc(lang('Integrations.oauth.grantHint')) ?></span>
                            <?php else: ?>
                                <button class="btn go" type="button" disabled><?= esc(lang('Integrations.oauth.grantBtn')) ?></button>
                                <span class="warn"><?= esc(lang('Integrations.oauth.notConfigured')) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
