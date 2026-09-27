<?= $this->extend('layouts/app') ?>

<?php
/**
 * A cause's donor list (SRS FR-VBCS-*). Server-rendered; JSON when negotiated.
 * Recognition is respected upstream: anonymous/none gifts show as "Anonymous"
 * and never expose a subject id or name; email is never returned. Money in minor
 * units.
 *
 * @var list<array<string,mixed>> $result  [{subject_id,display_name,amount_minor,currency,given_at}]
 * @var string                    $title
 * @var string                    $causeId
 */
include __DIR__ . '/_money.php';
$donors = is_array($result) ? $result : [];
$count  = count($donors);
?>

<?= $this->section('content') ?>
    <h1><?= esc(lang('Contributions.donors')) ?></h1>
    <div class="sub"><?= esc($causeId ?? '') ?> · <?= $count ?> <?= esc($count === 1 ? lang('Contributions.gift') : lang('Contributions.gifts')) ?></div>

    <?php if ($donors === []): ?>
        <div class="empty"><?= esc(lang('Contributions.noGifts')) ?></div>
    <?php else: ?>
        <?php foreach ($donors as $d): ?>
            <div class="card">
                <div class="row">
                    <?php
                        // Named donors are shown semi-privately (first name + last
                        // initials); the service already collapses anonymous/none
                        // gifts to "Anonymous", which redact_name leaves intact.
                        // The stored sentinel is the literal "Anonymous"; we match
                        // that then display the localized label.
                        $name  = (string) ($d['display_name'] ?? lang('Contributions.memberFallback'));
                        $shown = $name === 'Anonymous' ? lang('Contributions.anonymous') : redact_name($name);
                    ?>
                    <span class="author"><?= esc($shown) ?></span>
                    <span class="v" style="font-size:1.05rem;font-weight:800;"><?= $money((int) ($d['amount_minor'] ?? 0), $d['currency'] ?? null) ?></span>
                </div>
                <?php if (! empty($d['given_at'])): ?>
                    <div class="counts"><?= time_tag($d['given_at']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
