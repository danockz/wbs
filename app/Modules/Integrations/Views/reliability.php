<?php
/**
 * CIRCUIT-BREAKER dashboard (GET /integrations/circuits) — the browser face of
 * ReliabilityController::circuits, which previously returned raw JSON.
 *
 * It surfaces the FR-INT-012 provider reliability state: every circuit in the org
 * with its breaker state (closed / open / half-open), the consecutive-failure run
 * and lifetime failure/success tallies, the last provider error, when the circuit
 * opened, and when the next half-open probe is due. For any TRIPPED breaker (open
 * or half-open) it offers an operator "reset" control — a webcsrf-guarded POST
 * that forces the circuit closed after a fix; the controller PRG-redirects back
 * here with a localized flash. No-JS friendly (reset is its own form, guarded by
 * a confirm()).
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Integrations.reliability.*') with English fallback; the {0} count is
 * interpolated in PHP via $li() with singular/plural chosen in PHP. `state` is
 * localized with a raw-value fallback; scope key, tallies, timestamps and the
 * provider error text are server data shown verbatim. No secret material is ever
 * present (the breaker layer only ever sees the provider's own error text).
 *
 * @var list<array<string,mixed>> $circuits
 * @var string                    $csrf
 */
$circuits = $circuits ?? [];
$csrf     = $csrf ?? '';
$count    = count($circuits);
$open     = 0;
foreach ($circuits as $c) {
    if (in_array((string) ($c['state'] ?? ''), ['open', 'half_open'], true)) {
        $open++;
    }
}

include __DIR__ . '/_locale.php';

$flashOk  = function_exists('session') ? session('success') : null;
$flashErr = function_exists('session') ? session('error') : null;

$stateColor = static fn (string $s): string => match ($s) {
    'closed'    => '#22c55e',
    'half_open' => '#f59e0b',
    'open'      => '#ef4444',
    default     => '#94a3b8',
};
$stateLbl = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $v = lang('Integrations.reliability.state.' . $s);

    return $v === 'Integrations.reliability.state.' . $s ? ucwords(str_replace('_', ' ', $s)) : $v;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Integrations.reliability.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>

        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:14px 16px; margin-bottom:12px; }

        .stat { font-size:.72rem; color:#94a3b8; }

        .meta { font-size:.72rem; color:#94a3b8; margin:6px 0 2px; }

        .actions form { display:flex; gap:6px; align-items:center; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Integrations.reliability.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Integrations.reliability.sub')) ?></p>

        <?php if ($flashOk !== null && $flashOk !== ''): ?>
            <div class="flash ok"><?= esc($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr !== null && $flashErr !== ''): ?>
            <div class="flash err"><?= esc($flashErr) ?></div>
        <?php endif; ?>

        <?php if ($circuits === []): ?>
            <p class="empty"><?= esc(lang('Integrations.reliability.empty')) ?></p>
        <?php else: ?>
            <p class="count">
                <?= esc($li($count === 1 ? 'Integrations.reliability.countOne' : 'Integrations.reliability.count', (string) $count)) ?>
                <?php if ($open > 0): ?> · <span class="warn"><?= esc($li('Integrations.reliability.trippedCount', (string) $open)) ?></span><?php endif; ?>
            </p>
            <?php foreach ($circuits as $c): ?>
                <?php
                $scope   = (string) ($c['scope'] ?? '');
                $state   = (string) ($c['state'] ?? '');
                $runs    = (int) ($c['consecutive_failures'] ?? 0);
                $fails   = (int) ($c['failure_count'] ?? 0);
                $oks     = (int) ($c['success_count'] ?? 0);
                $lastErr = (string) ($c['last_error'] ?? '');
                $opened  = (string) ($c['opened_at'] ?? '');
                $probe   = (string) ($c['next_probe_at'] ?? '');
                $sColor  = $stateColor($state);
                $tripped = in_array($state, ['open', 'half_open'], true);
                $scAttr  = esc(rawurlencode($scope), 'attr');
                ?>
                <article class="card<?= $tripped ? ' tripped' : '' ?>">
                    <div class="top">
                        <span class="scope"><?= esc($scope) ?></span>
                        <span class="chip" style="color:<?= esc($sColor, 'attr') ?>;border-color:<?= esc($sColor, 'attr') ?>55"><?= esc($stateLbl($state)) ?></span>
                    </div>
                    <div class="stats">
                        <span class="stat"><b><?= esc((string) $runs) ?></b><?= esc(lang('Integrations.reliability.consecutiveLabel')) ?></span>
                        <span class="stat"><b><?= esc((string) $fails) ?></b><?= esc(lang('Integrations.reliability.failuresLabel')) ?></span>
                        <span class="stat"><b><?= esc((string) $oks) ?></b><?= esc(lang('Integrations.reliability.successesLabel')) ?></span>
                    </div>
                    <?php if ($opened !== ''): ?><div class="meta"><b><?= esc(lang('Integrations.reliability.openedAtLabel')) ?>:</b> <?= esc($opened) ?></div><?php endif; ?>
                    <?php if ($probe !== ''): ?><div class="meta"><b><?= esc(lang('Integrations.reliability.nextProbeLabel')) ?>:</b> <?= esc($probe) ?></div><?php endif; ?>
                    <?php if ($lastErr !== ''): ?><div class="err"><b><?= esc(lang('Integrations.reliability.lastErrorLabel')) ?>:</b> <?= esc($lastErr) ?></div><?php endif; ?>

                    <?php if ($scope !== '' && $tripped): ?>
                        <div class="actions">
                            <form method="post" action="/integrations/circuits/<?= $scAttr ?>/reset"
                                  onsubmit="return confirm('<?= esc(lang('Integrations.reliability.resetConfirm'), 'attr') ?>');">
                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                <button class="btn reset" type="submit"><?= esc(lang('Integrations.reliability.resetBtn')) ?></button>
                            </form>
                            <span class="rhint"><?= esc(lang('Integrations.reliability.resetHint')) ?></span>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
