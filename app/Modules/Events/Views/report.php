<?php
/**
 * EVENT mobilization report (GET /events/{id}/report) — the browser face of
 * ReportController::build, which otherwise rendered the generic admin console.
 * Aggregate-only, permission-gated: shows the live funnel (invitations →
 * responses → attendance), qualified streaming, contributions, feedback, expenses,
 * media and points for one event. When the event is not found (Result::notFound)
 * the same page renders a localized not-found panel.
 *
 * SELF-CONTAINED page: includes _locale.php for a locale-aware <html lang dir>
 * (RTL for Arabic). Copy is localized via lang('Events.report.*') with English
 * fallback; numbers are server data shown verbatim & escaped. No identifiable
 * rows are shown — this is the aggregate report surface.
 *
 * @var array<string,mixed>|null $report metrics map from ReportService::build, or null
 */
$report = $report ?? null;
$found  = is_array($report);

include __DIR__ . '/_locale.php';

$n = static fn ($v): string => $v === null ? '—' : number_format((float) $v);
?>

<?php ob_start(); ?>
<?= esc(lang('Events.report.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1000px; margin: 0 auto; padding: 5vh 20px 60px; }


        .status { display:inline-block; font-size:.72rem; border:1px solid #334155; border-radius:999px; padding:2px 10px; color:#cbd5e1; margin-bottom:14px; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; }


        h2 { font-size:.95rem; color:#93c5fd; margin:0 0 10px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <?php if (! $found): ?>
            <h1><?= esc(lang('Events.report.heading')) ?></h1>
            <p class="notfound"><?= esc(lang('Events.report.notFound')) ?></p>
        <?php else: ?>
            <?php
            $mob = (array) ($report['mobilization'] ?? []);
            $att = (array) ($report['attendance'] ?? []);
            $str = (array) ($report['streaming'] ?? []);
            $con = (array) ($report['contributions'] ?? []);
            $fb  = (array) ($report['feedback'] ?? []);
            $exp = (array) ($report['expenses'] ?? []);
            $med = (array) ($report['media'] ?? []);
            $pts = (array) ($report['points'] ?? []);
            $cur = (string) ($exp['currency'] ?? 'GHS');
            $money = static fn ($minor): string => $minor === null ? '—' : number_format(((int) $minor) / 100, 2);
            ?>
            <h1><?= esc((string) ($report['title'] ?? lang('Events.report.heading'))) ?></h1>
            <p class="sub"><?= esc(lang('Events.report.sub')) ?></p>
            <span class="status"><?= esc(lang('Events.report.statusLabel')) ?>: <?= esc((string) ($report['status'] ?? '—')) ?></span>

            <div class="grid">
                <div class="card">
                    <h2><?= esc(lang('Events.report.mobHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.invitations')) ?></span><span class="v"><?= esc($n($mob['invitations'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.convertedProspects')) ?></span><span class="v"><?= esc($n($mob['converted_prospects'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.responses')) ?></span><span class="v"><?= esc($n($mob['responses'] ?? null)) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.attHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.confirmed')) ?></span><span class="v"><?= esc($n($att['confirmed_registrations'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.waitlisted')) ?></span><span class="v"><?= esc($n($att['waitlisted'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.expected')) ?></span><span class="v"><?= esc($n($att['expected'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.actual')) ?></span><span class="v"><?= esc($n($att['actual'] ?? null)) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.streamHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.streams')) ?></span><span class="v"><?= esc($n($str['streams'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.qualified')) ?></span><span class="v"><?= esc($n($str['qualified'] ?? null)) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.contribHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.contribCount')) ?></span><span class="v"><?= esc($n($con['count'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.contribAmount')) ?></span><span class="v"><?= esc($money($con['amount_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.feedbackHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.forms')) ?></span><span class="v"><?= esc($n($fb['forms'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.responses')) ?></span><span class="v"><?= esc($n($fb['responses'] ?? null)) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.expenseHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.budget')) ?></span><span class="v"><?= esc($money($exp['budget_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.committed')) ?></span><span class="v"><?= esc($money($exp['committed_minor'] ?? null)) ?> <?= esc($cur) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.mediaHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.mediaTotal')) ?></span><span class="v"><?= esc($n($med['total'] ?? null)) ?></span></div>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.mediaApproved')) ?></span><span class="v"><?= esc($n($med['approved'] ?? null)) ?></span></div>
                </div>
                <div class="card">
                    <h2><?= esc(lang('Events.report.pointsHeading')) ?></h2>
                    <div class="row"><span class="k"><?= esc(lang('Events.report.pointsAwarded')) ?></span><span class="v"><?= esc($n($pts['awarded'] ?? null)) ?></span></div>
                </div>
            </div>
            <p class="genat"><?= esc(lang('Events.report.generatedAt')) ?>: <?= esc((string) ($report['generated_at'] ?? '—')) ?></p>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
