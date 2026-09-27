<?php
/**
 * A subject's points balance (SRS FR-GAM-*). Server-rendered; JSON when negotiated.
 *
 * @var array<string,mixed> $result  {subject_id, points}
 * @var string              $title
 */
$r = $result ?? [];
?>
<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Gamification.pointsBalance')) ?></h1>
    <div class="sub"><?= esc($r['subject_id'] ?? '') ?></div>

    <div class="grid">
        <div class="stat"><div class="k"><?= esc(lang('Gamification.points')) ?></div><div class="v"><?= (int) ($r['points'] ?? 0) ?></div></div>
    </div>
<?= $this->endSection() ?>
