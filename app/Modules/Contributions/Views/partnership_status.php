<?= $this->extend('layouts/app') ?>

<?php
/**
 * A subject's partnership status (SRS FR-VBCS-*): current level, giving streak,
 * and PGV/GGV. Server-rendered; JSON when negotiated.
 *
 * @var array<string,mixed> $result  {subject_id, pgv_minor, ggv_minor, direct_recruits, downline_size, current_level_code, consecutive_months_given, last_giving_at}
 * @var string              $title
 */
include __DIR__ . '/_money.php';
$r = $result ?? [];
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.partnershipStatus')) ?></h1>
    <div class="sub"><?= esc($r['subject_id'] ?? '') ?>
        <?php if (! empty($r['last_giving_at'])): ?> · <?= esc(str_replace('{0}', (string) $r['last_giving_at'], lang('Contributions.lastGift'))) ?><?php endif; ?>
    </div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Contributions.level')) ?></div><div class="v" style="font-size:1.1rem;"><?= esc($r['current_level_code'] ?? '—') ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.givingStreak')) ?></div><div class="v"><?= (int) ($r['consecutive_months_given'] ?? 0) ?> <?= esc(lang('Contributions.monthsAbbr')) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.personalPgv')) ?></div><div class="v"><?= $money((int) ($r['pgv_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.groupGgv')) ?></div><div class="v"><?= $money((int) ($r['ggv_minor'] ?? 0)) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.directRecruits')) ?></div><div class="v"><?= (int) ($r['direct_recruits'] ?? 0) ?></div></div>
        <div class="stat"><div class="k"><?= esc(lang('Contributions.downlineSize')) ?></div><div class="v"><?= (int) ($r['downline_size'] ?? 0) ?></div></div>
    </div>
<?= $this->endSection() ?>
