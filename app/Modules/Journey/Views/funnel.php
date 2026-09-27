<?php
/**
 * Discipleship FUNNEL & progression report (journey/funnel) — the browser face
 * of JourneyController::funnel. Where the pipeline board shows "who is at each
 * stage right now and who is stalled", this shows the COHORT shape: how far the
 * body progresses along the Win–Build–Send ladder and where it thins out.
 *
 * Two lenses, both from aggregates (the service computes them in TWO reads):
 *   - a monotonic "at or beyond" funnel bar per stage (reach %), with the
 *     stage's stall count and the share that advanced past it;
 *   - recent momentum: arrivals INTO each stage within the window.
 *
 * SELF-CONTAINED page: renders its own <html> and includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy via lang('Journey.
 * funnel.*') with English fallback; every number is computed server-side in PHP
 * (no ICU runtime dep). CSP-safe: no <script>, no inline on* handlers, styling
 * via a single style block only.
 *
 * @var ?string $group_id
 * @var int     $window_days
 * @var int     $total_active
 * @var int     $moves_in_window
 * @var list<array{code:string,name:string,phase:string,order:int,current:int,at_or_beyond:int,reach_pct:float,conversion_pct:float,stall_pct:float,is_last:bool,moves_in:int,movers_in:int}> $stages
 */
$group_id        = $group_id ?? null;
$window_days     = (int) ($window_days ?? 90);
$total_active    = (int) ($total_active ?? 0);
$moves_in_window = (int) ($moves_in_window ?? 0);
$stages          = $stages ?? [];

include __DIR__ . '/_locale.php';

$phaseColor = static fn (string $p): string => match ($p) {
    'win'   => '#22d3ee',
    'build' => '#a78bfa',
    'send'  => '#f59e0b',
    default => '#64748b',
};
// Fixed-vocabulary phase label, localized with raw-value fallback.
$phaseLbl = static function (string $p): string {
    if ($p === '') {
        return '';
    }
    $v = lang('Journey.phase.' . $p);

    return $v === 'Journey.phase.' . $p ? ucfirst($p) : $v;
};
$num = static fn (int $n): string => number_format($n);
?>

<?php ob_start(); ?>
<?= esc(lang('Journey.funnel.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 940px; margin: 0 auto; padding: 5vh 20px 60px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap" style="--wbs-accent: #a78bfa">
        <h1><?= esc(lang('Journey.funnel.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Journey.funnel.sub')) ?></p>

        <div>
            <span class="scope">
                <?= $group_id === null || $group_id === ''
                    ? esc(lang('Journey.orgWide'))
                    : esc($li('Journey.groupScoped', (string) $group_id)) ?>
            </span>
            <span class="scope" style="color:#94a3b8;border-color:#33415555"><?= esc($li('Journey.funnel.windowLabel', (string) $window_days)) ?></span>
            <a class="scope" href="/journey/pipeline<?= $group_id !== null && $group_id !== '' ? '?group_id=' . esc(rawurlencode((string) $group_id), 'url') : '' ?>"><?= esc(lang('Journey.funnel.backToPipeline')) ?></a>
        </div>

        <div class="kpis">
            <div class="kpi">
                <div class="n"><?= esc($num($total_active)) ?></div>
                <div class="k"><?= esc(lang('Journey.funnel.totalActive')) ?></div>
            </div>
            <div class="kpi">
                <div class="n" style="color:#38bdf8"><?= esc($num($moves_in_window)) ?></div>
                <div class="k"><?= esc(lang('Journey.funnel.movesInWindow')) ?></div>
            </div>
        </div>

        <?php if ($stages === []): ?>
            <p class="empty"><?= esc(lang('Journey.funnel.empty')) ?></p>
        <?php elseif ($total_active === 0): ?>
            <p class="empty"><?= esc(lang('Journey.funnel.noMembers')) ?></p>
        <?php else: ?>
            <?php foreach ($stages as $idx => $s): ?>
                <?php
                $code      = (string) ($s['code'] ?? '');
                $name      = (string) ($s['name'] ?? $code);
                $phase     = (string) ($s['phase'] ?? '');
                $current   = (int) ($s['current'] ?? 0);
                $reach     = (int) ($s['at_or_beyond'] ?? 0);
                $reachPct  = (float) ($s['reach_pct'] ?? 0.0);
                $convPct   = (float) ($s['conversion_pct'] ?? 0.0);
                $stallPct  = (float) ($s['stall_pct'] ?? 0.0);
                $isLast    = ! empty($s['is_last']);
                $movesIn   = (int) ($s['moves_in'] ?? 0);
                $moversIn  = (int) ($s['movers_in'] ?? 0);
                $color     = $phaseColor($phase);
                // Clamp the visual bar width to [0,100].
                $barW      = max(0.0, min(100.0, $reachPct));
                ?>
                <?php if ($idx > 0): ?><div class="arrow" aria-hidden="true">&#9662;</div><?php endif; ?>
                <section class="stage" style="border-inline-start-color:<?= esc($color, 'attr') ?>">
                    <div class="stagehd">
                        <div>
                            <span class="stagename"><?= esc($name) ?></span>
                            <span class="stagecode"><?= esc($code) ?></span>
                        </div>
                        <span class="chip" style="color:<?= esc($color, 'attr') ?>;border-color:<?= esc($color, 'attr') ?>55"><?= esc($phaseLbl($phase)) ?></span>
                    </div>

                    <div class="reachbar" role="img" aria-label="<?= esc($li('Journey.funnel.colReachPct') . ' ' . $reachPct . '%', 'attr') ?>">
                        <i style="width:<?= esc((string) $barW, 'attr') ?>%;background:<?= esc($color, 'attr') ?>"></i>
                        <b><?= esc($num($reach)) ?> · <?= esc((string) $reachPct) ?>%</b>
                    </div>

                    <div class="metrics">
                        <div class="metric">
                            <div class="n"><?= esc($num($reach)) ?></div>
                            <div class="k"><abbr title="<?= esc(lang('Journey.funnel.legendReach'), 'attr') ?>"><?= esc(lang('Journey.funnel.colReach')) ?></abbr></div>
                        </div>
                        <div class="metric<?= $current > 0 ? ' warnz' : '' ?>">
                            <div class="n"><?= esc($num($current)) ?><span style="font-size:.7rem;color:#94a3b8">&nbsp;·&nbsp;<?= esc((string) $stallPct) ?>%</span></div>
                            <div class="k"><abbr title="<?= esc(lang('Journey.funnel.legendStall'), 'attr') ?>"><?= esc(lang('Journey.funnel.colStall')) ?></abbr></div>
                        </div>
                        <?php if (! $isLast): ?>
                            <div class="metric good">
                                <div class="n"><?= esc((string) $convPct) ?>%</div>
                                <div class="k"><abbr title="<?= esc(lang('Journey.funnel.legendConversion'), 'attr') ?>"><?= esc(lang('Journey.funnel.colConversion')) ?></abbr></div>
                            </div>
                        <?php else: ?>
                            <div class="metric">
                                <div class="n" style="color:#64748b">—</div>
                                <div class="k"><?= esc(lang('Journey.funnel.terminalNote')) ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="metric flow">
                            <div class="n"><?= esc($num($movesIn)) ?><?php if ($moversIn > 0 && $moversIn !== $movesIn): ?><span style="font-size:.7rem;color:#94a3b8">&nbsp;·&nbsp;<?= esc($num($moversIn)) ?></span><?php endif; ?></div>
                            <div class="k"><abbr title="<?= esc(lang('Journey.funnel.legendMomentum'), 'attr') ?>"><?= esc(lang('Journey.funnel.colMomentum')) ?></abbr></div>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="legend">
                <div><b><?= esc(lang('Journey.funnel.colReach')) ?>:</b> <?= esc(lang('Journey.funnel.legendReach')) ?></div>
                <div><b><?= esc(lang('Journey.funnel.colConversion')) ?>:</b> <?= esc(lang('Journey.funnel.legendConversion')) ?></div>
                <div><b><?= esc(lang('Journey.funnel.colStall')) ?>:</b> <?= esc(lang('Journey.funnel.legendStall')) ?></div>
                <div><b><?= esc(lang('Journey.funnel.colMomentum')) ?>:</b> <?= esc(lang('Journey.funnel.legendMomentum')) ?></div>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
