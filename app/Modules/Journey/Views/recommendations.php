<?php

declare(strict_types=1);

/**
 * STAGE-AWARE RECOMMENDATIONS dashboard (journey/members/{id}/recommendations) —
 * the browser face of JourneyController::recommendations, which otherwise only
 * spoke JSON (and whose generic data-page render could not display the nested
 * per-stage structure). Shows, for one member in a context, the earning
 * activities / activity categories / follow-up types linked to WHERE THEY ARE on
 * the ladder (and the next stage) via the nullable stage_code on those three
 * catalogs — i.e. "what to do next to help this person grow."
 *
 * READ-ONLY surface: no mutations, so no CSRF form here. It deep-links back to
 * the member's journey detail (where the leader actually records moves) and to
 * the pipeline. A `scope` selector (current / next / both) is a plain GET form.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). UI copy is localized via
 * lang('Journey.admin.recommendations.*') with English fallback; the stage
 * `phase` vocabulary is localized with a raw-value fallback. The member id is
 * redacted for privacy. Progressive-enhancement: fully usable with no JavaScript.
 *
 * @var string                    $user_id
 * @var ?string                   $group_id
 * @var ?string                   $current_stage current stage code, or null
 * @var bool                      $has_journey
 * @var list<array<string,mixed>> $stages   target stages, each with grouped links
 * @var array<string,int>         $totals   {activities,categories,follow_up_types}
 * @var string                    $scope    current scope selector value
 * @var string                    $error    friendly failure message, or ''
 */
$user_id       = (string) ($user_id ?? '');
$group_id      = $group_id ?? null;
$current_stage = $current_stage ?? null;
$has_journey   = (bool) ($has_journey ?? false);
$stages        = is_array($stages ?? null) ? $stages : [];
$totals        = is_array($totals ?? null) ? $totals : [];
$scope         = (string) ($scope ?? 'both');
$error         = (string) ($error ?? '');

include __DIR__ . '/_locale.php';

if (! function_exists('redact_id')) {
    require_once dirname(__DIR__, 3) . '/Helpers/redactor_helper.php';
}

$L = static function (string $k): string {
    $key = 'Journey.admin.recommendations.' . $k;
    $v   = lang($key);
    return $v === $key ? $k : $v;
};
$phaseColor = static fn (string $p): string => match ($p) {
    'win'   => '#22d3ee',
    'build' => '#a78bfa',
    'send'  => '#f59e0b',
    default => '#64748b',
};
$phaseLbl = static function (string $p): string {
    if ($p === '') { return ''; }
    $v = lang('Journey.phase.' . $p);
    return $v === 'Journey.phase.' . $p ? ucfirst($p) : $v;
};
$posLbl = static function (string $p) use ($L): string {
    return match ($p) {
        'current' => $L('posCurrent'),
        'next'    => $L('posNext'),
        'ahead'   => $L('posAhead'),
        default   => $p,
    };
};
$ctx        = $group_id === null ? '' : (string) $group_id;
$ctxQuery   = $ctx !== '' ? '?group_id=' . rawurlencode($ctx) : '';
$memberHref = '/journey/members/' . rawurlencode($user_id) . $ctxQuery;
$totalAll   = (int) (($totals['activities'] ?? 0) + ($totals['categories'] ?? 0) + ($totals['follow_up_types'] ?? 0));
?>

<?php ob_start(); ?>
<?= esc($L('metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .grp { margin-top:14px; }


        .none { color:#64748b; font-size:.82rem; font-style:italic; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="<?= esc($memberHref, 'attr') ?>">&larr; <?= esc($L('backToMember')) ?></a>
        <h1><?= esc($L('heading')) ?></h1>
        <p class="sub"><?= esc($L('member')) ?>: <span class="mono"><?= esc($user_id !== '' ? redact_id($user_id) : '—') ?></span></p>

        <span class="scope"><?= esc($ctx === '' ? $L('orgWide') : $L('groupScoped')) ?></span>
        <?php if ($current_stage !== null && $current_stage !== ''): ?>
            <span class="scope"><?= esc($L('currentStage')) ?>: <span class="mono"><?= esc((string) $current_stage) ?></span></span>
        <?php elseif ($has_journey === false): ?>
            <span class="scope"><?= esc($L('noJourneyYet')) ?></span>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="flash err"><?= esc($error) ?></div>
        <?php endif; ?>

        <form method="get" action="<?= esc('/journey/members/' . rawurlencode($user_id) . '/recommendations', 'attr') ?>" class="toolbar">
            <?php if ($ctx !== ''): ?><input type="hidden" name="group_id" value="<?= esc($ctx, 'attr') ?>"><?php endif; ?>
            <div>
                <label for="scope"><?= esc($L('scopeLabel')) ?></label>
                <select id="scope" name="scope">
                    <?php foreach (['both', 'current', 'next'] as $opt): ?>
                        <option value="<?= esc($opt, 'attr') ?>"<?= $scope === $opt ? ' selected' : '' ?>><?= esc($L('scope_' . $opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn sec"><?= esc($L('applyBtn')) ?></button>
        </form>

        <?php if ($error === '' && ($stages === [] || $totalAll === 0)): ?>
            <div class="empty"><?= esc($L('empty')) ?></div>
        <?php endif; ?>

        <?php foreach ($stages as $st): ?>
            <?php
            $phase      = (string) ($st['phase'] ?? 'general');
            $position   = (string) ($st['position'] ?? '');
            $activities = is_array($st['activities'] ?? null) ? $st['activities'] : [];
            $categories = is_array($st['categories'] ?? null) ? $st['categories'] : [];
            $followUps  = is_array($st['follow_up_types'] ?? null) ? $st['follow_up_types'] : [];
            $stageEmpty = $activities === [] && $categories === [] && $followUps === [];
            ?>
            <section class="stagecard">
                <h2>
                    <?= esc((string) ($st['name'] ?? ($st['code'] ?? '—'))) ?>
                    <span class="chip" style="color:<?= esc($phaseColor($phase), 'attr') ?>;border-color:<?= esc($phaseColor($phase), 'attr') ?>55"><?= esc($phaseLbl($phase)) ?></span>
                    <?php if ($position !== ''): ?><span class="chip"><?= esc($posLbl($position)) ?></span><?php endif; ?>
                </h2>
                <div class="code mono"><?= esc((string) ($st['code'] ?? '')) ?></div>

                <?php if ($stageEmpty): ?>
                    <p class="none"><?= esc($L('stageEmpty')) ?></p>
                <?php else: ?>
                    <?php if ($activities !== []): ?>
                    <div class="grp">
                        <div class="gh"><?= esc($L('grpActivities')) ?></div>
                        <ul class="items">
                            <?php foreach ($activities as $a): ?>
                                <li>
                                    <?php if (! empty($a['icon'])): ?><span><?= esc((string) $a['icon']) ?></span><?php endif; ?>
                                    <span class="nm"><?= esc((string) ($a['name'] ?? ($a['code'] ?? ''))) ?></span>
                                    <span class="cd"><?= esc((string) ($a['code'] ?? '')) ?></span>
                                    <?php if (($a['points'] ?? null) !== null): ?>
                                        <span class="pts"><?= esc(str_replace('{0}', (string) ((int) $a['points']), $L('ptsTag'))) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ($categories !== []): ?>
                    <div class="grp">
                        <div class="gh"><?= esc($L('grpCategories')) ?></div>
                        <ul class="items">
                            <?php foreach ($categories as $c): ?>
                                <li>
                                    <?php if (! empty($c['icon'])): ?><span><?= esc((string) $c['icon']) ?></span><?php endif; ?>
                                    <span class="nm" style="<?= ! empty($c['color']) ? 'color:' . esc((string) $c['color'], 'attr') . ';' : '' ?>"><?= esc((string) ($c['name'] ?? ($c['code'] ?? ''))) ?></span>
                                    <span class="cd"><?= esc((string) ($c['code'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <?php if ($followUps !== []): ?>
                    <div class="grp">
                        <div class="gh"><?= esc($L('grpFollowUps')) ?></div>
                        <ul class="items">
                            <?php foreach ($followUps as $f): ?>
                                <li>
                                    <span class="nm"><?= esc((string) ($f['name'] ?? ($f['code'] ?? ''))) ?></span>
                                    <span class="cd"><?= esc((string) ($f['code'] ?? '')) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <p style="margin-top:20px"><a class="btn" href="<?= esc($memberHref, 'attr') ?>"><?= esc($L('goToJourney')) ?></a></p>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
