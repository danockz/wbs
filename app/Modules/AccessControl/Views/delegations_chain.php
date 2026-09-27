<?php
/**
 * DELEGATION chain (GET /access-control/delegations/{id}/chain) — the browser face
 * of DelegationController::chain, which otherwise rendered the generic admin
 * console. Lists the full sub-tree of a delegation for traceability, ordered by
 * depth then creation, each entry showing depth, delegator → delegate, the
 * delegated permission, scope (group + descendants), status and effective window.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.delegationChainView.*') with English fallback; the {0} count
 * is interpolated in PHP via $li() with singular/plural chosen in PHP; the status
 * vocabulary is localized with a raw-value fallback. Ids/codes are server data
 * shown verbatim & escaped.
 *
 * @var list<array<string,mixed>> $delegations delegation sub-tree rows
 */
$delegations  = $delegations ?? [];
$count        = count($delegations);
$csrf         = $csrf ?? '';
$delegationId = $delegationId ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

// The relative path PRG returns to after an inline revoke from this page.
$returnPath = $delegationId !== ''
    ? '/delegations/' . rawurlencode($delegationId) . '/chain'
    : '/access-control';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vocab = static function (string $group, string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.delegationChainView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.delegationChainView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        

        .wrap { max-width: 1000px; margin: 0 auto; padding: 5vh 20px 60px; }

        .depth { font-size:.72rem; color:#5eead4; border:1px solid #115e59; border-radius:999px; padding:2px 9px; }

        .perm { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.84rem; font-weight:700; color:#99f6e4; }

        .flow { font-size:.86rem; margin-bottom:8px; }

        .flow .arr { color:#5eead4; margin:0 6px; }

        .meta { display:flex; flex-wrap:wrap; gap:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('AccessControl.delegationChainView.heading')) ?></h1>
        <p class="sub"><?= esc(lang('AccessControl.delegationChainView.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.delegationChainView.empty')) ?></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.delegationChainView.countOne' : 'AccessControl.delegationChainView.count', (string) $count)) ?></p>
            <?php foreach ($delegations as $d): ?>
                <?php
                $status  = strtolower((string) ($d['status'] ?? ''));
                $group   = (string) ($d['scope_group_id'] ?? '');
                $incDesc = ! empty($d['include_descendants']);
                ?>
                <article class="dl<?= in_array($status, ['revoked','expired'], true) ? ' revoked' : '' ?>">
                    <div class="top">
                        <span class="depth"><?= esc(lang('AccessControl.delegationChainView.depth')) ?> <?= esc((string) ($d['depth'] ?? '—')) ?></span>
                        <span class="perm"><?= esc((string) ($d['permission_code'] ?? '')) ?></span>
                        <span class="st <?= $status ?>"><?= esc($vocab('status', $status)) ?></span>
                    </div>
                    <div class="flow">
                        <span class="mono"><?= esc((string) ($d['delegator_id'] ?? '—')) ?></span>
                        <span class="arr">→</span>
                        <span class="mono"><?= esc((string) ($d['delegate_id'] ?? '—')) ?></span>
                    </div>
                    <div class="meta">
                        <span class="tag">
                            <?= esc(lang('AccessControl.delegationChainView.colScope')) ?>:
                            <?php if ($group === ''): ?>
                                <?= esc(lang('AccessControl.delegationChainView.orgWide')) ?>
                            <?php else: ?>
                                <span class="mono"><?= esc($group) ?></span><?= $incDesc ? ' ' . esc(lang('AccessControl.delegationChainView.withDescendants')) : '' ?>
                            <?php endif; ?>
                        </span>
                        <span class="tag"><?= esc(lang('AccessControl.delegationChainView.colFrom')) ?>: <?= esc((string) ($d['effective_from'] ?? '—')) ?></span>
                        <span class="tag"><?= esc(lang('AccessControl.delegationChainView.colTo')) ?>: <?= esc((string) ($d['effective_to'] ?? '—')) ?></span>
                    </div>
                    <?php $did = rawurlencode((string) ($d['id'] ?? '')); ?>
                    <?php if ($did !== '' && $status === 'active'): ?>
                    <div class="acts">
                        <form method="post" action="<?= esc($url('delegations/' . $did . '/revoke'), 'attr') ?>"
                              onsubmit="return confirm('<?= esc(lang('AccessControl.delegationChainView.revokeConfirm'), 'js') ?>');">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <input type="hidden" name="return" value="<?= esc($returnPath, 'attr') ?>">
                            <input type="text" name="reason" required
                                   placeholder="<?= esc(lang('AccessControl.delegationChainView.reasonPh'), 'attr') ?>">
                            <button type="submit" class="btn revoke"><?= esc(lang('AccessControl.delegationChainView.revoke')) ?></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
