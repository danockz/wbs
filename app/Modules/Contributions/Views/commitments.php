<?= $this->extend('layouts/app') ?>

<?php
/**
 * A subject's giving commitments (SRS FR-VBCS-*): recurring/one-time pledges
 * with their cadence, amount and next-due date. Server-rendered; JSON when
 * negotiated. Money is stored in minor units.
 *
 * Previously read-only: pledges could only be created/cancelled via the JSON
 * API. This adds the browser CRUD face — a "New commitment" button linking to
 * the capture form, and a per-row Cancel control (only on ACTIVE pledges) that
 * POSTs to the webcsrf-guarded cancel route and PRGs back here with a localized
 * flash. Cancel is a status flip (reminder-only pledges are never charged, so a
 * cancel just stops reminders); completed/cancelled rows show no control.
 *
 * @var list<array<string,mixed>> $result  [{id,cause_id,amount_minor,currency,frequency,status,next_due_at,created_at}]
 * @var string                    $title
 * @var string                    $subjectId
 * @var string                    $csrf
 */
include __DIR__ . '/_money.php';
$items     = is_array($result) ? $result : [];
$count     = count($items);
$subjectId = (string) ($subjectId ?? '');
$csrf      = $csrf ?? '';

$flashOk  = function_exists('session') ? (string) (session('success') ?? '') : '';
$flashErr = function_exists('session') ? (string) (session('error') ?? '') : '';

// Badge COLOR stays code-driven; the LABEL is localized (falls back to the raw
// stored value when a status has no translation, so unknown values never break).
$col = static fn (string $s): string => match ($s) {
    'active'    => '#4ade80',
    'completed' => '#a5b4fc',
    'cancelled' => '#f87171',
    default     => '#94a3b8',
};
$statusLabel = static function (string $s): string {
    if ($s === '') {
        return '';
    }
    $t = lang('Contributions.status.' . $s);

    return $t === 'Contributions.status.' . $s ? $s : $t;
};
$freqLabel = static function (string $f): string {
    if ($f === '') {
        return '';
    }
    $t = lang('Contributions.frequency.' . $f);

    return $t === 'Contributions.frequency.' . $f ? $f : $t;
};

$newHref = '/vbcs/commitments/new' . ($subjectId !== '' ? '?subject_id=' . rawurlencode($subjectId) : '');
$cf      = static fn (string $k): string => 'Contributions.commitmentForm.' . $k;
?>

<?= $this->section('content') ?>
    

    <div class="ci-head">
        <div class="ci-txt">
            <h1><?= esc(lang('Contributions.commitmentsTitle')) ?></h1>
            <div class="sub"><?= esc($subjectId) ?> · <?= $count ?> <?= esc($count === 1 ? lang('Contributions.commitment') : lang('Contributions.commitments')) ?></div>
        </div>
        <a class="ci-btn" href="<?= esc($newHref, 'attr') ?>">+ <?= esc(lang(($cf)('newCommitment'))) ?></a>
    </div>

    <?php if ($flashOk !== ''): ?><div class="ci-flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="ci-flash err"><?= esc($flashErr) ?></div><?php endif; ?>

    <?php if ($items === []): ?>
        <div class="empty"><?= esc(lang('Contributions.noCommitments')) ?></div>
        <p style="margin-top:14px"><a class="ci-btn" href="<?= esc($newHref, 'attr') ?>">+ <?= esc(lang(($cf)('newCommitment'))) ?></a></p>
    <?php else: ?>
        <?php foreach ($items as $c): ?>
            <?php
            $id     = (string) ($c['id'] ?? '');
            $ie     = rawurlencode($id);
            $status = (string) ($c['status'] ?? '');
            ?>
            <div class="card">
                <div class="row">
                    <span class="author"><?= $money((int) ($c['amount_minor'] ?? 0), $c['currency'] ?? null) ?>
                        <span class="muted"><?= esc(str_replace('{0}', $freqLabel((string) ($c['frequency'] ?? 'monthly')), lang('Contributions.perFrequency'))) ?></span>
                    </span>
                    <span class="pill" style="color:<?= $col($status) ?>;"><?= esc($statusLabel($status)) ?></span>
                </div>
                <div class="counts">
                    <?php if (! empty($c['next_due_at'])): ?><?= esc(str_replace('{0}', (string) $c['next_due_at'], lang('Contributions.nextDue'))) ?><?php endif; ?>
                    <?php if (! empty($c['created_at'])): ?> · <?= esc(str_replace('{0}', (string) $c['created_at'], lang('Contributions.started'))) ?><?php endif; ?>
                </div>
                <?php if ($id !== '' && $status === 'active'): ?>
                <div class="ci-acts">
                    <details class="ci-confirm">
                        <summary class="ci-act warn"><?= esc(lang(($cf)('cancel'))) ?></summary>
                        <form method="post" action="/vbcs/commitments/<?= esc($ie, 'attr') ?>/cancel" class="ci-drawer">
                            <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                            <?php if ($subjectId !== ''): ?><input type="hidden" name="subject_id" value="<?= esc($subjectId, 'attr') ?>"><?php endif; ?>
                            <span class="hint"><?= esc(lang(($cf)('cancelConfirm'))) ?></span>
                            <button type="submit" class="ci-act warn"><?= esc(lang(($cf)('cancelYes'))) ?></button>
                        </form>
                    </details>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?= $this->endSection() ?>
