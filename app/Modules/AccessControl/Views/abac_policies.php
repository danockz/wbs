<?php
/**
 * ABAC POLICY catalogue page (GET /access-control/abac-policies) — the browser
 * face of AbacPolicyController::index, which otherwise rendered the generic admin
 * console. Lists the org's attribute-based policies in evaluation order
 * (priority, then code), showing each policy's effect, action pattern and enabled
 * state.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('AccessControl.abacView.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. The effect
 * vocabulary is localized with a raw-value fallback. Policy code/action are server
 * data shown verbatim & escaped.
 *
 * @var list<array<string,mixed>> $policies policy rows (id,code,effect,action_pattern,priority,enabled)
 */
$policies = $policies ?? [];
$count    = count($policies);
$csrf     = $csrf ?? '';

include __DIR__ . '/_locale.php';

$url = static fn (string $path = ''): string => function_exists('base_url')
    ? base_url($path)
    : '/' . ltrim($path, '/');

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

$vocab = static function (string $group, string $value): string {
    if ($value === '') {
        return '—';
    }
    $s = lang('AccessControl.abacView.' . $group . '.' . $value);
    return (is_string($s) && ! str_contains($s, 'AccessControl.')) ? $s : $value;
};
?>

<?php ob_start(); ?>
<?= esc(lang('AccessControl.abacView.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1000px; margin: 0 auto; padding: 5vh 20px 60px; }


        thead th { text-align:left; padding:10px 12px; border-bottom:1px solid #1e293b; color:#22d3ee;
            font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; }


        tbody td { padding:10px 12px; border-bottom:1px solid #101a2e; vertical-align:top; }


        td.acts { white-space:nowrap; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <div class="head">
            <div class="txt">
                <h1><?= esc(lang('AccessControl.abacView.heading')) ?></h1>
                <p class="sub"><?= esc(lang('AccessControl.abacView.sub')) ?></p>
            </div>
            <a class="btn" href="<?= esc($url('abac-policies/new'), 'attr') ?>">+ <?= esc(lang('AccessControl.abacView.newPolicy')) ?></a>
        </div>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <?php if ($count === 0): ?>
            <p class="empty"><?= esc(lang('AccessControl.abacView.empty')) ?></p>
            <p style="text-align:center;margin-top:14px"><a class="btn" href="<?= esc($url('abac-policies/new'), 'attr') ?>">+ <?= esc(lang('AccessControl.abacView.newPolicy')) ?></a></p>
        <?php else: ?>
            <p class="count"><?= esc($li($count === 1 ? 'AccessControl.abacView.countOne' : 'AccessControl.abacView.count', (string) $count)) ?></p>
            <div class="tblwrap">
                <table>
                    <thead>
                        <tr>
                            <th><?= esc(lang('AccessControl.abacView.colPriority')) ?></th>
                            <th><?= esc(lang('AccessControl.abacView.colCode')) ?></th>
                            <th><?= esc(lang('AccessControl.abacView.colEffect')) ?></th>
                            <th><?= esc(lang('AccessControl.abacView.colAction')) ?></th>
                            <th><?= esc(lang('AccessControl.abacView.colState')) ?></th>
                            <th><?= esc(lang('AccessControl.abacView.colActions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($policies as $p): ?>
                            <?php
                            $enabled = ! empty($p['enabled']);
                            $effect  = strtolower((string) ($p['effect'] ?? ''));
                            $pid     = rawurlencode((string) ($p['id'] ?? ''));
                            ?>
                            <tr class="<?= $enabled ? '' : 'off' ?>">
                                <td><?= esc((string) ($p['priority'] ?? '—')) ?></td>
                                <td class="code"><?= esc((string) ($p['code'] ?? '')) ?></td>
                                <td><span class="pill <?= $effect === 'allow' ? 'allow' : ($effect === 'deny' ? 'deny' : '') ?>"><?= esc($vocab('effect', $effect)) ?></span></td>
                                <td class="mono"><?= esc((string) ($p['action_pattern'] ?? '*')) ?></td>
                                <td><span class="pill <?= $enabled ? 'on' : 'offb' ?>"><?= esc($enabled ? lang('AccessControl.abacView.enabled') : lang('AccessControl.abacView.disabled')) ?></span></td>
                                <td class="acts">
                                    <?php if ($pid !== ''): ?>
                                    <a class="act edit" href="<?= esc($url('abac-policies/' . $pid . '/edit'), 'attr') ?>"><?= esc(lang('AccessControl.abacView.edit')) ?></a>
                                    <form method="post" action="<?= esc($url('abac-policies/' . $pid . '/enabled'), 'attr') ?>">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                                        <button type="submit" class="act"><?= esc($enabled ? lang('AccessControl.abacView.disable') : lang('AccessControl.abacView.enable')) ?></button>
                                    </form>
                                    <form method="post" action="<?= esc($url('abac-policies/' . $pid . '/delete'), 'attr') ?>"
                                          onsubmit="return confirm('<?= esc(lang('AccessControl.abacView.deleteConfirm'), 'js') ?>');">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button type="submit" class="act danger"><?= esc(lang('AccessControl.abacView.delete')) ?></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
