<?= $this->extend('layouts/app') ?>

<?php
/**
 * Partnership tier catalogue (SRS FR-VBCS-*): the admin-configured ladder of
 * partnership levels, ordered high to low. Server-rendered; JSON when negotiated.
 *
 * @var list<array<string,mixed>> $result  [{code,name,description,min_consecutive_months,min_pgv_minor,icon,color}]
 * @var string                    $title
 */
include __DIR__ . '/_money.php';
$tiers = is_array($result) ? $result : [];
$count = count($tiers);
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.partnershipTiers')) ?></h1>
    <div class="sub"><?= $count ?> <?= esc($count === 1 ? lang('Contributions.tier') : lang('Contributions.tiers')) ?></div>

    <?php if ($tiers === []): ?>
        <div class="empty"><?= esc(lang('Contributions.noTiers')) ?></div>
    <?php else: ?>
        <?php foreach ($tiers as $t): ?>
            <div class="card">
                <div class="row">
                    <span class="author" style="<?= ! empty($t['color']) ? 'color:' . esc($t['color']) . ';' : '' ?>">
                        <?php if (! empty($t['icon'])): ?><?= esc($t['icon']) ?> <?php endif; ?>
                        <?= esc($t['name'] ?? ($t['code'] ?? lang('Contributions.tierFallback'))) ?>
                    </span>
                    <span class="time"><?= esc(str_replace('{0}', $money((int) ($t['min_pgv_minor'] ?? 0)), lang('Contributions.minPgvSuffix'))) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($t['description'])): ?><?= esc($t['description']) ?> · <?php endif; ?>
                    <?= esc(str_replace('{0}', (string) (int) ($t['min_consecutive_months'] ?? 0), lang('Contributions.consecutiveMonths'))) ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
