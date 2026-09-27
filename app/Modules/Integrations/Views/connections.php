<?php
/**
 * PROVIDER CONNECTIONS dashboard (GET /integrations/connections) — the browser
 * face of ConnectionController::index, which previously had no view.
 *
 * It drives the FR-INT-005/006 connection lifecycle: a "connect a provider" form
 * (choosing an active catalogue adapter) and every connection shown with the
 * stage-appropriate controls — store a credential slot (write-only), record a
 * test (a pass moves draft → tested), submit for approval (tested →
 * pending_approval), and activate (payment/notification require a DIFFERENT
 * approver; other categories activate directly). Each control POSTs to a webcsrf-
 * guarded route; the controller PRG-redirects back here with a localized flash.
 * No-JS friendly (each action is its own form).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.connections.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. `status` and
 * `category` are localized with a raw-value fallback; adapter code / display name
 * / channels / sender identity are server data shown verbatim. NO secret material
 * is present in the payload (credentials are write-only in the vault).
 *
 * @var list<array<string,mixed>> $connections
 * @var list<array<string,mixed>> $adapters     active catalogue adapters
 * @var string                    $csrf
 */
$connections = $connections ?? [];
$adapters    = $adapters ?? [];
$csrf        = $csrf ?? '';
$count       = count($connections);

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

// Credential slots offered per category (mirrors the catalogue seeder).
$slotsFor = static function (string $category): array {
    return match ($category) {
        'payment'      => ['secret_key', 'merchant_id', 'webhook_secret'],
        'notification' => ['api_key'],
        'social_oidc'  => ['client_id', 'client_secret'],
        default         => ['api_key'],
    };
};
// All distinct slots across categories (for the per-connection credential form,
// which does not always know the category client-side).
$allSlots = ['api_key', 'secret_key', 'merchant_id', 'webhook_secret', 'client_id', 'client_secret'];
$approvalCategories = ['payment', 'notification'];

$statusColor = static fn (string $s): string => match ($s) {
    'active'           => '#22c55e',
    'pending_approval' => '#f59e0b',
    'tested'           => '#38bdf8',
    'draft'            => '#94a3b8',
    'disabled'         => '#ef4444',
    default            => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Integrations.connections.status.' . $s);

    return $v === 'Integrations.connections.status.' . $s ? ucwords(str_replace('_', ' ', $s)) : $v;
};
$categoryLbl = static function (string $c): string {
    if ($c === '') {
        return '';
    }
    $v = lang('Integrations.category.' . $c);

    return $v === 'Integrations.category.' . $c ? ucwords(str_replace('_', ' ', $c)) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.connections.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        select:focus, input:focus { outline:none; border-color:#38bdf8; }

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }

        .meta { font-size:.72rem; color:#94a3b8; margin:6px 0 2px; }

        .actions form { display:flex; gap:5px; align-items:center; flex-wrap:wrap; }

        .actions select, .actions input { width:auto; padding:5px 8px; font-size:.76rem; }

        .sod { font-size:.7rem; color:#64748b; width:100%; margin-top:2px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Integrations.connections.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.connections.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <!-- Connect a provider -->
        <details class="reg">
            <summary><?= esc(lang('Integrations.connections.connectHeading')) ?></summary>
            <?php if ($adapters === []): ?>
                <div style="padding:4px 16px 18px" class="hint"><?= esc(lang('Integrations.connections.noAdapters')) ?></div>
            <?php else: ?>
                <form class="form" method="post" action="/integrations/connections">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="full">
                        <label><?= esc(lang('Integrations.connections.adapterLabel')) ?></label>
                        <select name="adapter_code" onchange="var o=this.options[this.selectedIndex];document.getElementById('cat').value=o.getAttribute('data-cat')||'';">
                            <?php foreach ($adapters as $a): ?>
                                <option value="<?= esc((string) ($a['code'] ?? ''), 'attr') ?>" data-cat="<?= esc((string) ($a['category'] ?? ''), 'attr') ?>">
                                    <?= esc((string) ($a['display_name'] ?? $a['code'] ?? '')) ?> — <?= esc($categoryLbl((string) ($a['category'] ?? ''))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" id="cat" name="category" value="<?= esc((string) ($adapters[0]['category'] ?? ''), 'attr') ?>">
                    <div class="full">
                        <label><?= esc(lang('Integrations.connections.displayNameLabel')) ?></label>
                        <input name="display_name" maxlength="150" placeholder="<?= esc(lang('Integrations.connections.displayNamePh'), 'attr') ?>">
                    </div>
                    <div class="full">
                        <button class="btn go" type="submit"><?= esc(lang('Integrations.connections.connectBtn')) ?></button>
                        <div class="hint"><?= esc(lang('Integrations.connections.connectHint')) ?></div>
                    </div>
                </form>
            <?php endif; ?>
        </details>

        <?php if ($connections === []): ?>
            <p class="empty"><?= esc(lang('Integrations.connections.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Integrations.connections.countOne' : 'Integrations.connections.count', (string) $count)) ?></p>
            <?php foreach ($connections as $c): ?>
                <?php
                $id     = (string) ($c['id'] ?? '');
                $code   = (string) ($c['adapter_code'] ?? '');
                $dn     = (string) ($c['display_name'] ?? $code);
                $cat    = (string) ($c['category'] ?? '');
                $status = (string) ($c['status'] ?? '');
                $sender = (string) ($c['sender_identity'] ?? '');
                $tested = (string) ($c['tested_at'] ?? '');
                $sColor = $statusColor($status);
                $idAttr = esc(rawurlencode($id), 'attr');
                $needsApproval = in_array($cat, $approvalCategories, true);
                ?>
                <article class="card">
                    <div class="top">
                        <span class="dn"><?= esc($dn) ?></span>
                        <span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                    </div>
                    <div class="code"><?= esc($code) ?></div>
                    <div class="meta"><b><?= esc(lang('Integrations.custom.categoryLabel')) ?>:</b> <?= esc($categoryLbl($cat)) ?><?php if ($sender !== ''): ?> · <b><?= esc(lang('Integrations.connections.senderLabel')) ?>:</b> <?= esc($sender) ?><?php endif; ?></div>
                    <?php if ($tested !== ''): ?><div class="meta"><b><?= esc(lang('Integrations.connections.testedAtLabel')) ?>:</b> <?= esc($tested) ?></div><?php endif; ?>

                    <?php if ($id !== '' && $status !== 'disabled'): ?>
                        <div class="actions">
                            <!-- Store a credential slot (any pre-active stage) -->
                            <?php if (in_array($status, ['draft', 'tested', 'pending_approval'], true)): ?>
                                <form method="post" action="/integrations/connections/<?= $idAttr ?>/credentials">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <select name="slot" aria-label="<?= esc(lang('Integrations.connections.slotLabel'), 'attr') ?>">
                                        <?php foreach ($allSlots as $slot): ?>
                                            <option value="<?= esc($slot, 'attr') ?>"><?= esc(str_replace('_', ' ', $slot)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="password" name="secret" required autocomplete="off" placeholder="<?= esc(lang('Integrations.connections.secretPh'), 'attr') ?>">
                                    <button class="btn ghost" type="submit"><?= esc(lang('Integrations.connections.storeBtn')) ?></button>
                                </form>
                            <?php endif; ?>

                            <!-- Record a test (draft/tested) -->
                            <?php if (in_array($status, ['draft', 'tested'], true)): ?>
                                <form method="post" action="/integrations/connections/<?= $idAttr ?>/test">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <input type="hidden" name="pass" value="1">
                                    <input type="hidden" name="sandbox" value="1">
                                    <input type="hidden" name="operation" value="healthCheck">
                                    <button class="btn go" type="submit"><?= esc(lang('Integrations.connections.testBtn')) ?></button>
                                </form>
                            <?php endif; ?>

                            <!-- Submit for approval (tested) -->
                            <?php if ($status === 'tested'): ?>
                                <form method="post" action="/integrations/connections/<?= $idAttr ?>/submit">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button class="btn go" type="submit"><?= esc(lang('Integrations.connections.submitBtn')) ?></button>
                                </form>
                            <?php endif; ?>

                            <!-- Activate (tested for non-approval categories, or pending_approval) -->
                            <?php if ($status === 'pending_approval' || ($status === 'tested' && ! $needsApproval)): ?>
                                <form method="post" action="/integrations/connections/<?= $idAttr ?>/activate"
                                      onsubmit="return confirm('<?= esc(lang('Integrations.connections.activateConfirm'), 'attr') ?>');">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button class="btn ok" type="submit"><?= esc(lang('Integrations.connections.activateBtn')) ?></button>
                                </form>
                                <?php if ($needsApproval): ?><span class="sod"><?= esc(lang('Integrations.connections.sodHint')) ?></span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
