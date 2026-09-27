<?php
/**
 * Membership-journey PIPELINE page (journey/pipeline) — the browser face of
 * JourneyController::pipeline, which otherwise only spoke JSON. A birds-eye of
 * how many active members sit at each stage of the discipleship ladder, ordered
 * by the ladder's own sort order, with a proportional bar per stage.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Journey.*') with English fallback; counts + share are computed in PHP
 * (no ICU runtime dep). The stage `phase` (win|build|send) is localized with a
 * raw-value fallback; stage names/codes are server data shown verbatim & escaped.
 *
 * A birds-eye TRIAGE board: each stage's active members are split into hot /
 * warm / cold by how long they have sat in their current stage, so a leader can
 * see at a glance who has momentum and who has stalled and needs a follow-up.
 *
 * The HOT/WARM/COLD split is driven by ONE of two bases (reported by
 * $triage_mode): the legacy "time in stage" or, when involvement-based triage is
 * switched on for the context, member INVOLVEMENT (recency + participation +
 * quantum of work, incl. rolled-up disciple effort). A badge names the active
 * basis and the triage caption adapts to it.
 *
 * @var ?string $group_id
 * @var int     $total
 * @var array{hot:int,warm:int,cold:int} $triage
 * @var string  $triage_mode  'involvement' | 'time_in_stage'
 * @var list<array{code:string,name:string,phase:string,order:int,count:int,hot:int,warm:int,cold:int}> $stages
 */
$group_id    = $group_id ?? null;
$total       = (int) ($total ?? 0);
$triage      = $triage ?? ['hot' => 0, 'warm' => 0, 'cold' => 0];
$triage_mode = ($triage_mode ?? 'time_in_stage') === 'involvement' ? 'involvement' : 'time_in_stage';
$stages      = $stages ?? [];
$byInvolve   = $triage_mode === 'involvement';
$csrf        = $csrf ?? '';
$flashOk     = function_exists('session') ? session('success') : null;
$flashErr    = function_exists('session') ? session('error') : null;

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
$pct = static fn (int $n): float => $total > 0 ? round($n * 100 / $total, 1) : 0.0;

// Fixed triage vocabulary → colour + localized label (raw-value fallback).
$tempColor = ['hot' => '#f87171', 'warm' => '#fbbf24', 'cold' => '#60a5fa'];
$tempLbl   = static function (string $t): string {
    $v = lang('Journey.triage.' . $t);

    return $v === 'Journey.triage.' . $t ? ucfirst($t) : $v;
};
// Proportional width of a segment within its own stage row (0 when empty).
$seg = static fn (int $part, int $whole): float => $whole > 0 ? round($part * 100 / $whole, 2) : 0.0;
// Drill-down URL into the per-stage roster, carrying the group context and an
// optional temperature filter so a leader can jump straight to "cold seekers".
$rosterUrl = static function (string $code, ?string $temp = null) use ($group_id): string {
    $qs = [];
    if ($group_id !== null && $group_id !== '') {
        $qs['group_id'] = (string) $group_id;
    }
    if ($temp !== null) {
        $qs['temperature'] = $temp;
    }
    $url = '/journey/stages/' . rawurlencode($code) . '/members';

    return $qs === [] ? $url : $url . '?' . http_build_query($qs);
};
?>

<?php ob_start(); ?>
<?= esc(lang('Journey.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .bar { height:10px; border-radius:6px; background:#1e293b; overflow:hidden; min-width:60px; display:flex; }


        .bar > i { display:block; height:100%; }


        .bar > i:first-child { border-radius:6px 0 0 6px; }


        .bar > i:last-child { border-radius:0 6px 6px 0; }


        .dot { width:9px; height:9px; border-radius:999px; display:inline-block; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Journey.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Journey.sub')) ?></p>

        <span class="scope">
            <?= $group_id === null || $group_id === ''
                ? esc(lang('Journey.orgWide'))
                : esc($li('Journey.groupScoped', (string) $group_id)) ?>
        </span>
        <span class="scope" style="color:<?= $byInvolve ? '#34d399' : '#94a3b8' ?>;border-color:<?= $byInvolve ? '#34d39955' : '#33415555' ?>;margin-inline-start:8px">
            <?= esc(lang('Journey.basisLabel')) ?>:
            <?= esc($byInvolve ? lang('Journey.basisInvolvement') : lang('Journey.basisTime')) ?>
        </span>
        <a class="scope" style="margin-inline-start:8px;color:#c4b5fd" href="/journey/involvement/config<?= $group_id !== null && $group_id !== '' ? '?group_id=' . esc(rawurlencode((string) $group_id), 'url') : '' ?>"><?= esc(lang('Journey.involvementSettings')) ?></a>
        <a class="scope" style="margin-inline-start:8px;color:#38bdf8;border-color:#38bdf855;text-decoration:none" href="/journey/funnel<?= $group_id !== null && $group_id !== '' ? '?group_id=' . esc(rawurlencode((string) $group_id), 'url') : '' ?>"><?= esc(lang('Journey.funnelLink')) ?></a>

        <?php if (is_string($flashOk) && $flashOk !== ''): ?>
            <p class="scope" style="display:block;color:#34d399;border-color:#34d39955;margin-top:12px"><?= esc($flashOk) ?></p>
        <?php elseif (is_string($flashErr) && $flashErr !== ''): ?>
            <p class="scope" style="display:block;color:#f87171;border-color:#f8717155;margin-top:12px"><?= esc($flashErr) ?></p>
        <?php endif; ?>

        <?php if ($byInvolve): ?>
            <form method="post" action="/journey/involvement/recompute" style="margin-top:12px">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <?php if ($group_id !== null && $group_id !== ''): ?>
                    <input type="hidden" name="group_id" value="<?= esc((string) $group_id, 'attr') ?>">
                <?php endif; ?>
                <button type="submit" class="scope" style="cursor:pointer;background:transparent;color:#34d399;border-color:#34d39955"><?= esc(lang('Journey.recomputeBtn')) ?></button>
            </form>
        <?php endif; ?>

        <div class="totalbar">
            <div class="n"><?= esc((string) $total) ?></div>
            <div class="k"><?= esc(lang('Journey.totalActive')) ?></div>
        </div>

        <?php if ($total > 0): ?>
            <div class="triage">
                <?php foreach (['hot', 'warm', 'cold'] as $t): ?>
                    <?php $tn = (int) ($triage[$t] ?? 0); $col = $tempColor[$t]; ?>
                    <div class="tcard" style="border-inline-start-color:<?= esc($col, 'attr') ?>">
                        <div class="n" style="color:<?= esc($col, 'attr') ?>"><?= esc((string) $tn) ?></div>
                        <div class="k"><span class="dot" style="background:<?= esc($col, 'attr') ?>"></span><?= esc($tempLbl($t)) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="sub" style="margin-top:-14px"><?= esc($byInvolve ? lang('Journey.triageSubInvolvement') : lang('Journey.triageSub')) ?></p>
        <?php endif; ?>

        <?php if ($stages === []): ?>
            <p class="empty"><?= esc(lang('Journey.empty')) ?></p>
        <?php elseif ($total === 0): ?>
            <p class="empty"><?= esc(lang('Journey.noMembers')) ?></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th><?= esc(lang('Journey.colStage')) ?></th>
                        <th><?= esc(lang('Journey.colPhase')) ?></th>
                        <th style="width:30%"><?= esc(lang('Journey.colTriage')) ?></th>
                        <th class="num" title="<?= esc($tempLbl('hot'), 'attr') ?>"><?= esc(lang('Journey.colHot')) ?></th>
                        <th class="num" title="<?= esc($tempLbl('warm'), 'attr') ?>"><?= esc(lang('Journey.colWarm')) ?></th>
                        <th class="num" title="<?= esc($tempLbl('cold'), 'attr') ?>"><?= esc(lang('Journey.colCold')) ?></th>
                        <th class="num"><?= esc(lang('Journey.colCount')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stages as $s): ?>
                        <?php
                        $code  = (string) ($s['code'] ?? '');
                        $name  = (string) ($s['name'] ?? $code);
                        $phase = (string) ($s['phase'] ?? '');
                        $count = (int) ($s['count'] ?? 0);
                        $h     = (int) ($s['hot'] ?? 0);
                        $w     = (int) ($s['warm'] ?? 0);
                        $c     = (int) ($s['cold'] ?? 0);
                        $share = $pct($count);
                        $color = $phaseColor($phase);
                        ?>
                        <tr>
                            <td><a class="stagelink" href="<?= esc($rosterUrl($code), 'attr') ?>"><?= esc($name) ?></a><span class="stagecode"><?= esc($code) ?></span></td>
                            <td><span class="chip" style="color:<?= esc($color, 'attr') ?>;border-color:<?= esc($color, 'attr') ?>55"><?= esc($phaseLbl($phase)) ?></span></td>
                            <td>
                                <div class="bar" role="img" aria-label="<?= esc(sprintf('%d hot, %d warm, %d cold', $h, $w, $c), 'attr') ?>">
                                    <?php if ($h > 0): ?><i style="width:<?= esc((string) $seg($h, $count), 'attr') ?>%;background:<?= esc($tempColor['hot'], 'attr') ?>"></i><?php endif; ?>
                                    <?php if ($w > 0): ?><i style="width:<?= esc((string) $seg($w, $count), 'attr') ?>%;background:<?= esc($tempColor['warm'], 'attr') ?>"></i><?php endif; ?>
                                    <?php if ($c > 0): ?><i style="width:<?= esc((string) $seg($c, $count), 'attr') ?>%;background:<?= esc($tempColor['cold'], 'attr') ?>"></i><?php endif; ?>
                                </div>
                            </td>
                            <td class="num"><?php if ($h > 0): ?><a class="tlink" style="color:<?= esc($tempColor['hot'], 'attr') ?>" href="<?= esc($rosterUrl($code, 'hot'), 'attr') ?>"><?= esc((string) $h) ?></a><?php else: ?><span class="stagecode">0</span><?php endif; ?></td>
                            <td class="num"><?php if ($w > 0): ?><a class="tlink" style="color:<?= esc($tempColor['warm'], 'attr') ?>" href="<?= esc($rosterUrl($code, 'warm'), 'attr') ?>"><?= esc((string) $w) ?></a><?php else: ?><span class="stagecode">0</span><?php endif; ?></td>
                            <td class="num"><?php if ($c > 0): ?><a class="tlink" style="color:<?= esc($tempColor['cold'], 'attr') ?>" href="<?= esc($rosterUrl($code, 'cold'), 'attr') ?>"><?= esc((string) $c) ?></a><?php else: ?><span class="stagecode">0</span><?php endif; ?></td>
                            <td class="num"><?= esc((string) $count) ?><span class="stagecode"><?= esc((string) $share) ?>%</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="legend">
                <?php foreach (['hot', 'warm', 'cold'] as $t): ?>
                    <span><span class="dot" style="background:<?= esc($tempColor[$t], 'attr') ?>"></span><?= esc($tempLbl($t)) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
