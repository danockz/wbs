<?= $this->extend('layouts/app') ?>

<?php
/**
 * Live expected-attendance report (SRS FR-EVT-006). Server-rendered; the same
 * controller returns JSON when negotiated. Deliberately distinguishes
 * invitations, responses, confirmed registrations and expected attendees, and
 * shows an uncertainty band rather than a single false-precise number.
 *
 * Localized via lang('Events.*'); numbers are interpolated in PHP so the report
 * renders correctly with or without ext-intl.
 *
 * @var array<string,mixed> $result  service payload from EventService::expectedAttendance
 */
$counts    = $result['counts'] ?? [];
$expected  = $result['expected'] ?? [];
$logistics = $result['logistics'] ?? [];
$enum = static function (string $group, string $value): string {
    if ($value === '') {
        return '';
    }
    $label = lang('Events.' . $group . '.' . $value);

    return (is_string($label) && $label !== 'Events.' . $group . '.' . $value) ? $label : $value;
};
$range = static function (int $lo, int $hi): string {
    return str_replace(['{0}', '{1}'], [(string) $lo, (string) $hi], lang('Events.range'));
};
?>

<?= $this->section('content') ?>
    <h1><?= esc($result['title'] ?? lang('Events.eventFallback')) ?></h1>
    <div class="sub">
        <?= esc(lang('Events.expectedReport')) ?> ·
        <span class="pill"><?= esc($enum('status', (string) ($result['status'] ?? ''))) ?></span>
        <?php if (($result['capacity'] ?? null) !== null): ?>
            · <?= esc(lang('Events.capacity')) ?> <?= (int) $result['capacity'] ?>
        <?php endif; ?>
    </div>

    <h2><?= esc(lang('Events.funnelHeading')) ?></h2>
    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Events.invitations')) ?></div><div class="v"><?= (int) ($counts['invitations'] ?? 0) ?></div>
            <?php if (array_key_exists('invitations_direct', $counts) || array_key_exists('invitations_link', $counts)): ?>
                <div class="k" style="opacity:.7;font-weight:400;"><?= esc(lang('Events.invitationsDirect')) ?> <?= (int) ($counts['invitations_direct'] ?? 0) ?> · <?= esc(lang('Events.invitationsLink')) ?> <?= (int) ($counts['invitations_link'] ?? 0) ?></div>
            <?php endif; ?>
        </div>
        <div class="stat"><div class="k"><?= esc(lang('Events.responses')) ?></div><div class="v"><?= (int) ($counts['responses'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.confirmedReg')) ?></div><div class="v"><?= (int) ($counts['confirmed_registrations'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.rsvpPending')) ?></div><div class="v"><?= (int) ($counts['positive_rsvp_pending'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.waitlisted')) ?></div><div class="v"><?= (int) ($counts['waitlisted'] ?? 0) ?></div></div>
    </div>

    <h2><?= esc(lang('Events.expectedHeading')) ?></h2>
    <div class="card">
        <div class="row">
            <span style="font-size:2rem;font-weight:800;color:#22d3ee;"><?= (int) ($expected['point_estimate'] ?? 0) ?></span>
            <span class="muted">
                <?= esc($range((int) ($expected['lower_bound'] ?? 0), (int) ($expected['upper_bound'] ?? 0))) ?>
                · <?= esc(str_replace('{0}', (string) ($expected['show_rate'] ?? 0.6), lang('Events.showRate'))) ?>
            </span>
        </div>
        <div class="counts"><?= esc(lang('Events.pointEstimateBlurb')) ?></div>
    </div>

    <h2><?= esc(lang('Events.logisticsHeading')) ?></h2>
    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Events.seating')) ?></div><div class="v"><?= (int) ($logistics['seating'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.materials')) ?></div><div class="v"><?= (int) ($logistics['materials'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Events.cateringLabel')) ?></div><div class="v"><?= (int) ($logistics['catering'] ?? 0) ?></div></div>
    </div>
<?= $this->endSection() ?>
