<?= $this->extend('layouts/app') ?>

<?php
/**
 * WBS funnel dashboard (SRS FR-RPT-001/002). Server-rendered; JSON when
 * negotiated. Shows the mandatory metadata block (as-of, completeness, delayed
 * job warning) and honors small-cohort suppression already applied by the
 * service (a suppressed cell arrives as a string like "<5").
 *
 * Copy is localized via lang('Reporting.funnel.*') with English as the
 * guaranteed fallback. Metric VALUES are rendered verbatim (they are computed
 * data, including suppression markers like "<5"); the metadata as-of/source/
 * completeness values and the delayed-job warning text are service data and stay
 * verbatim too. {0} placeholders are interpolated in PHP so no ext-intl is
 * required.
 *
 * @var array<string,mixed> $result  {funnel:{win,build,send}, metadata:{...}}
 */
$funnel = $result['funnel'] ?? [];
$meta   = $result['metadata'] ?? [];

$li = static fn (string $key, string $arg): string => str_replace('{0}', $arg, lang($key));

$stat = static function (string $label, $value): string {
    $v = is_array($value) ? json_encode($value) : (string) $value;

    return '<div class="stat"><div class="k">' . esc($label) . '</div><div class="v">' . esc($v) . '</div></div>';
};
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Reporting.funnel.title')) ?></h1>
    <div class="sub"><?= esc(lang('Reporting.funnel.sub')) ?></div>

    <h2><?= esc(lang('Reporting.funnel.winHeading')) ?></h2>
    <div class="grid">
        <?php $win = $funnel['win'] ?? []; ?>
        <?= $stat(lang('Reporting.funnel.prospects'), $win['prospects'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.membersUnique'), $win['members_unique'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.referralConversions'), $win['referral_conversions'] ?? 0) ?>
    </div>

    <h2><?= esc(lang('Reporting.funnel.buildHeading')) ?></h2>
    <div class="grid">
        <?php $build = $funnel['build'] ?? []; ?>
        <?= $stat(lang('Reporting.funnel.courseEnrollments'), $build['course_enrollments'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.courseCompletions'), $build['course_completions'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.eventsHeld'), $build['events_held'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.uniqueAttendees'), $build['unique_attendees'] ?? 0) ?>
        <?= $stat(lang('Reporting.funnel.verifiedGiving'), $build['verified_giving_minor'] ?? 0) ?>
    </div>

    <h2><?= esc(lang('Reporting.funnel.sendHeading')) ?></h2>
    <div class="grid">
        <?php $send = $funnel['send'] ?? []; ?>
        <?= $stat(lang('Reporting.funnel.pointsAwarded'), $send['points_awarded'] ?? 0) ?>
    </div>

    <div class="meta">
        <div><?= esc($li('Reporting.funnel.asOf', (string) ($meta['as_of'] ?? lang('Reporting.funnel.na')))) ?>
            · <?= esc($li('Reporting.funnel.source', (string) ($meta['source_state'] ?? lang('Reporting.funnel.na')))) ?>
            · <?= esc($li('Reporting.funnel.completeness', (string) ($meta['data_completeness'] ?? lang('Reporting.funnel.na')))) ?>
            · <?= esc($li('Reporting.funnel.suppression', (string) ($meta['suppression_threshold'] ?? 5))) ?></div>
        <?php if (! empty($meta['delayed_job_warning'])): ?>
            <div class="warn">⚠ <?= esc($meta['delayed_job_warning']) ?></div>
        <?php endif; ?>
    </div>
<?= $this->endSection() ?>
