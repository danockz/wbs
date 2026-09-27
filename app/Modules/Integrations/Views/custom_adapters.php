<?php
/**
 * CUSTOM-ADAPTER SDK dashboard (GET /integrations/custom-adapters) — the browser
 * face of CustomAdapterController::index, which previously rendered raw JSON.
 *
 * It surfaces the whole FR-INT-013 onboarding lifecycle for a non-conforming
 * provider: a register form (choosing from the ALLOWLISTED, reviewed impl
 * classes), and every registered adapter with the stage-appropriate controls —
 * run contract test (draft) · advance to security review (contract_tested) ·
 * approve (security_review, checker ≠ submitter) · activate (approved, publishes
 * to the shared catalogue) · revoke (any live stage). Each control POSTs to a
 * webcsrf-guarded route; the controller PRG-redirects back here with a localized
 * flash. No-JS friendly (each action is its own form + confirm()).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.custom.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. `status` and
 * `category` are localized with a raw-value fallback; code/display_name/family/
 * version/impl_class/submitter are server data shown verbatim.
 *
 * @var list<array<string,mixed>> $adapters
 * @var list<string>              $allowlist  allowlisted impl-class FQCNs
 * @var string                    $csrf
 */
$adapters  = $adapters ?? [];
$allowlist = $allowlist ?? [];
$csrf      = $csrf ?? '';
$count     = count($adapters);

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

$statusColor = static fn (string $s): string => match ($s) {
    'active'          => '#22c55e',
    'approved'        => '#38bdf8',
    'security_review' => '#a78bfa',
    'contract_tested' => '#f59e0b',
    'draft'           => '#94a3b8',
    'revoked'         => '#ef4444',
    'deprecated'      => '#64748b',
    default           => '#94a3b8',
};
$statusLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Integrations.custom.status.' . $s);

    return $v === 'Integrations.custom.status.' . $s ? ucwords(str_replace('_', ' ', $s)) : $v;
};
$categoryLbl = static function (string $c): string {
    if ($c === '') {
        return '';
    }
    $v = lang('Integrations.category.' . $c);

    return $v === 'Integrations.category.' . $c ? ucwords(str_replace('_', ' ', $c)) : $v;
};
$shortClass = static function (string $fqcn): string {
    $p = strrpos($fqcn, '\\');

    return $p === false ? $fqcn : substr($fqcn, $p + 1);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.custom.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        select:focus, input:focus { outline:none; border-color:#818cf8; }

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }

        .meta { font-size:.72rem; color:#94a3b8; margin:6px 0 2px; }

        .actions form { display:inline; }

        .sod { font-size:.7rem; color:#64748b; width:100%; margin-top:2px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: #818cf8">
        <h1><?= esc(lang('Integrations.custom.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.custom.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <!-- Register from the allowlist -->
        <details class="reg">
            <summary><?= esc(lang('Integrations.custom.registerHeading')) ?></summary>
            <?php if ($allowlist === []): ?>
                <div style="padding:4px 16px 18px" class="hint"><?= esc(lang('Integrations.custom.noAllowlist')) ?></div>
            <?php else: ?>
                <form class="regform" method="post" action="/integrations/custom-adapters">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="grow">
                        <label><?= esc(lang('Integrations.custom.implClassLabel')) ?></label>
                        <select name="impl_class" required>
                            <?php foreach ($allowlist as $cls): ?>
                                <option value="<?= esc($cls, 'attr') ?>"><?= esc($shortClass((string) $cls)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button class="btn go" type="submit"><?= esc(lang('Integrations.custom.registerBtn')) ?></button>
                    </div>
                    <div class="hint"><?= esc(lang('Integrations.custom.registerHint')) ?></div>
                </form>
            <?php endif; ?>
        </details>

        <?php if ($adapters === []): ?>
            <p class="empty"><?= esc(lang('Integrations.custom.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'Integrations.custom.countOne' : 'Integrations.custom.count', (string) $count)) ?></p>
            <?php foreach ($adapters as $a): ?>
                <?php
                $id     = (string) ($a['id'] ?? '');
                $code   = (string) ($a['code'] ?? '');
                $dn     = (string) ($a['display_name'] ?? $code);
                $ver    = (string) ($a['version'] ?? '');
                $cat    = (string) ($a['category'] ?? '');
                $fam    = (string) ($a['family'] ?? '');
                $impl   = (string) ($a['impl_class'] ?? '');
                $status = (string) ($a['status'] ?? '');
                $sub    = (string) ($a['submitted_by'] ?? '');
                $sColor = $statusColor($status);
                $idAttr = esc(rawurlencode($id), 'attr');
                ?>
                <article class="card">
                    <div class="top">
                        <span class="dn"><?= esc($dn) ?></span>
                        <?php if ($ver !== ''): ?><span class="ver"><?= esc($li('Integrations.version', $ver)) ?></span><?php endif; ?>
                        <span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                    </div>
                    <div class="code"><?= esc($code) ?></div>
                    <div class="meta"><b><?= esc(lang('Integrations.custom.categoryLabel')) ?>:</b> <?= esc($categoryLbl($cat)) ?><?php if ($fam !== ''): ?> · <b><?= esc(lang('Integrations.family')) ?>:</b> <?= esc(ucwords(str_replace('_', ' ', $fam))) ?><?php endif; ?></div>
                    <?php if ($impl !== ''): ?><div class="meta"><b><?= esc(lang('Integrations.custom.implClassLabel')) ?>:</b> <span class="code"><?= esc($impl) ?></span></div><?php endif; ?>

                    <?php if ($id !== '' && ! in_array($status, ['revoked', 'deprecated'], true)): ?>
                        <div class="actions">
                            <?php if ($status === 'draft'): ?>
                                <form method="post" action="/integrations/custom-adapters/<?= $idAttr ?>/contract-test">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button class="btn go" type="submit"><?= esc(lang('Integrations.custom.contractTestBtn')) ?></button>
                                </form>
                            <?php elseif ($status === 'contract_tested'): ?>
                                <form method="post" action="/integrations/custom-adapters/<?= $idAttr ?>/advance">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <input type="hidden" name="to_status" value="security_review">
                                    <button class="btn go" type="submit"><?= esc(lang('Integrations.custom.advanceBtn')) ?></button>
                                </form>
                            <?php elseif ($status === 'security_review'): ?>
                                <form method="post" action="/integrations/custom-adapters/<?= $idAttr ?>/approve"
                                      onsubmit="return confirm('<?= esc(lang('Integrations.custom.approveConfirm'), 'attr') ?>');">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button class="btn ok" type="submit"><?= esc(lang('Integrations.custom.approveBtn')) ?></button>
                                </form>
                                <span class="sod"><?= esc(lang('Integrations.custom.sodHint')) ?></span>
                            <?php elseif ($status === 'approved'): ?>
                                <form method="post" action="/integrations/custom-adapters/<?= $idAttr ?>/activate"
                                      onsubmit="return confirm('<?= esc(lang('Integrations.custom.activateConfirm'), 'attr') ?>');">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button class="btn ok" type="submit"><?= esc(lang('Integrations.custom.activateBtn')) ?></button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="/integrations/custom-adapters/<?= $idAttr ?>/revoke"
                                  onsubmit="return confirm('<?= esc(lang('Integrations.custom.revokeConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button class="btn danger" type="submit"><?= esc(lang('Integrations.custom.revokeBtn')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
