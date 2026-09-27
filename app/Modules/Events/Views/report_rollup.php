<?php
/**
 * EVENT group roll-up (GET /events/groups/{id}/report) — the browser face of
 * ReportController::groupRollup, which otherwise rendered the generic admin
 * console. Sums the latest immutable snapshot per event across a group into one
 * aggregate scorecard (events, invitations, responses, expected/actual
 * attendance, qualified streaming, contributions count/amount).
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.rollup.*') with English
 * fallback; numbers are server data shown verbatim & escaped. Aggregate-only.
 *
 * SCOPE (gap L5): a self/subtree toggle switches between this group's own events
 * and the whole subtree (self + descendant groups) — the platform's
 * credit-every-ancestor model. `$groupsCounted` reports how many groups the
 * figures span so an ancestor roll-up is transparent, not a mystery total.
 *
 * @var array<string,mixed> $rollup        aggregate map from ReportService::groupRollup
 * @var string              $groupId       the group these figures roll up
 * @var string              $scope         self|subtree — the active roll-up scope
 * @var int                 $groupsCounted how many groups the figures span
 */
$rollup        = $rollup ?? [];
$groupId       = $groupId ?? '';
$scope         = ($scope ?? 'subtree') === 'self' ? 'self' : 'subtree';
$groupsCounted = (int) ($groupsCounted ?? 1);

include __DIR__ . '/_locale.php';

$n = static fn ($v): string => $v === null ? '—' : number_format((float) $v);
$money = static fn ($minor): string => $minor === null ? '—' : number_format(((int) $minor) / 100, 2);
?>

<?php ob_start(); ?>
<?= esc(lang('Events.rollup.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 760px; margin: 0 auto; padding: 5vh 20px 60px; }


        .group { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#67e8f9;
            border:1px solid #155e75; border-radius:6px; padding:2px 8px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:18px 20px; margin-top:18px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.rollup.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.rollup.groupLabel')) ?>: <span class="group"><?= esc($groupId !== '' ? $groupId : '—') ?></span></p>
        <?php // L5 — self / subtree scope toggle (plain links, no-JS / CSP-safe). ?>
        <nav class="tabs">
            <a class="tab <?= $scope === 'subtree' ? 'on' : '' ?>" href="?scope=subtree"><?= esc(lang('Events.rollup.scopeSubtree')) ?></a>
            <a class="tab <?= $scope === 'self' ? 'on' : '' ?>" href="?scope=self"><?= esc(lang('Events.rollup.scopeSelf')) ?></a>
        </nav>
        <p class="scope-note">
            <?= $scope === 'subtree'
                ? esc(lang('Events.rollup.scopeSubtreeHint')) . ' ' . str_replace(':count', (string) $groupsCounted, esc(lang('Events.rollup.groupsCounted')))
                : esc(lang('Events.rollup.scopeSelfHint')) ?>
        </p>
        <div class="card">
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.events')) ?></span><span class="v"><?= esc($n($rollup['events'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.invitations')) ?></span><span class="v"><?= esc($n($rollup['invitations'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.responses')) ?></span><span class="v"><?= esc($n($rollup['responses'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.expectedAttendance')) ?></span><span class="v"><?= esc($n($rollup['expected_attendance'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.actualAttendance')) ?></span><span class="v"><?= esc($n($rollup['actual_attendance'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.qualifiedStreaming')) ?></span><span class="v"><?= esc($n($rollup['qualified_streaming'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.contribCount')) ?></span><span class="v"><?= esc($n($rollup['contributions_count'] ?? null)) ?></span></div>
            <div class="row"><span class="k"><?= esc(lang('Events.rollup.contribAmount')) ?></span><span class="v"><?= esc($money($rollup['contributions_minor'] ?? null)) ?></span></div>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
