<?php
/**
 * Prospect-transfer DETAIL page (FR-REF-7 review path) — the browser face of
 * ProspectTransferController::show. Shows one queued inactivity transfer: the
 * contact and the group/mentor move it proposes, the maker and nominated checker,
 * the business justification, the evaluation snapshot that produced it (policy
 * threshold vs observed quiet period, and the verdict), the append-only review
 * trail, and — once decided — who decided it and which transfer row it produced.
 * When the request id is unknown a not-found panel is shown instead.
 *
 * Read-only by design: the approve/reject/cancel forms live on the dashboard
 * (transfer_index) where the double-submit CSRF token is minted, so this page
 * never needs one and can be cached/linked safely.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy is localized via
 * lang('Referrals.transferShow.*') with English fallback; status, eligibility,
 * review actions and evaluation verdicts are FIXED vocabularies localized with a
 * raw-value fallback. The service is PII-free (ids only), so ids render verbatim
 * and escaped.
 *
 * @var array<string,mixed>|null $request   the request row (+ 'reviews'), or null
 * @var string                   $requestId
 */
$req       = is_array($request ?? null) ? $request : null;
$requestId = $requestId ?? '';

include __DIR__ . '/_locale.php';

/** Localize a fixed-vocabulary value, falling back to the raw string. */
$vocab = static function (string $value, array $map, string $fallback = '—'): string {
    if ($value === '') {
        return $fallback;
    }
    if (! isset($map[$value])) {
        return ucfirst(str_replace('_', ' ', $value));
    }
    $key = 'Referrals.transferShow.' . $map[$value];
    $v   = lang($key);

    return $v === $key ? ucfirst(str_replace('_', ' ', $value)) : $v;
};

$statusLbl = static fn (string $s): string => $vocab($s, [
    'pending'   => 'statusPending',
    'approved'  => 'statusApproved',
    'rejected'  => 'statusRejected',
    'cancelled' => 'statusCancelled',
]);
$statusColor = static fn (string $s): string => match ($s) {
    'approved'  => '#22c55e',
    'rejected'  => '#ef4444',
    'cancelled' => '#94a3b8',
    default     => '#f59e0b',
};
$eligLbl = static fn (string $e): string => $vocab($e, [
    'ok'      => 'eligOk',
    'blocked' => 'eligBlocked',
], '');
$actionLbl = static fn (string $a): string => $vocab($a, [
    'submit'  => 'actSubmit',
    'approve' => 'actApprove',
    'reject'  => 'actReject',
    'cancel'  => 'actCancel',
    'block'   => 'actBlock',
]);
// The evaluation verdicts come from ProspectTransferService::evaluate() — a
// closed set, so each has copy; anything new degrades to the raw token.
$verdictLbl = static fn (string $v): string => $vocab($v, [
    'inactive_threshold_met'  => 'vDue',
    'same_mentor'             => 'vSameMentor',
    'same_group'              => 'vSameGroup',
    'still_active'            => 'vStillActive',
    'transfer_disabled'       => 'vDisabled',
    'new_mentor_has_no_group' => 'vNoGroup',
    'no_activity_baseline'    => 'vNoBaseline',
    'no_new_mentor'           => 'vNoMentor',
]);

$evaluation = $req['evaluation'] ?? null;
if (is_string($evaluation)) {
    $decoded    = json_decode($evaluation, true);
    $evaluation = is_array($decoded) ? $decoded : null;
}
$reviews = is_array($req['reviews'] ?? null) ? $req['reviews'] : [];
$none    = lang('Referrals.transferShow.none');
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.transferShow.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 880px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:22px 0 12px; }


        .back { display:inline-block; margin-bottom:14px; font-size:.84rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <a class="back" href="/referrals/prospect-transfers/pending">← <?= esc(lang('Referrals.transferShow.backToQueue')) ?></a>
        <h1><?= esc(lang('Referrals.transferShow.heading')) ?></h1>
        <p class="sub"><?= esc($requestId) ?></p>

        <?php if ($req === null): ?>
            <div class="panel"><p class="empty"><?= esc(lang('Referrals.transferShow.notFound')) ?></p></div>
        <?php else: ?>
            <?php
            $status = (string) ($req['status'] ?? 'pending');
            $elig   = (string) ($req['eligibility_state'] ?? '');
            $days   = isset($req['days_inactive']) && $req['days_inactive'] !== null ? (int) $req['days_inactive'] : null;
            $weeks  = isset($req['threshold_weeks']) && $req['threshold_weeks'] !== null ? (int) $req['threshold_weeks'] : null;
            ?>
            <div class="panel">
                <div style="margin-bottom:14px;">
                    <span class="chip" style="color:<?= esc($statusColor($status), 'attr') ?>;border-color:<?= esc($statusColor($status), 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                    <?php if ($elig !== ''): ?>
                        <span class="chip" style="color:<?= $elig === 'blocked' ? '#ef4444' : '#22c55e' ?>;border-color:#334155"><?= esc($eligLbl($elig)) ?></span>
                    <?php endif; ?>
                    <?php if ($days !== null && $weeks !== null): ?>
                        <span class="chip"><?= esc($li('Referrals.transferShow.inactivity', (string) $days, (string) $weeks)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="grid">
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.contact')) ?></span><span class="v"><?= esc((string) ($req['prospect_id'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.linkedUser')) ?></span><span class="v"><?= esc((string) ($req['linked_user_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.fromGroup')) ?></span><span class="v"><?= esc((string) ($req['from_group_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.toGroup')) ?></span><span class="v"><?= esc((string) ($req['to_group_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.fromMentor')) ?></span><span class="v"><?= esc((string) ($req['from_owner_user_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.toMentor')) ?></span><span class="v"><?= esc((string) ($req['to_owner_user_id'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.requestedBy')) ?></span><span class="v"><?= esc((string) ($req['requested_by'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.approver')) ?></span><span class="v"><?= esc((string) ($req['approver_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.trigger')) ?></span><span class="v"><?= esc((string) ($req['trigger_type'] ?? '') ?: $none) ?><?= ! empty($req['trigger_id']) ? ' · ' . esc((string) $req['trigger_id']) : '' ?></span></div>
                </div>

                <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.reason')) ?></span><span class="reason"><?= esc((string) ($req['reason'] ?? '')) ?></span></div>
                <?php if (! empty($req['eligibility_detail'])): ?>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.eligDetail')) ?></span><span class="reason"><?= esc($verdictLbl((string) $req['eligibility_detail'])) ?></span></div>
                <?php endif; ?>
            </div>

            <h2><?= esc(lang('Referrals.transferShow.evaluationHeading')) ?></h2>
            <div class="panel">
                <?php if (! is_array($evaluation) || $evaluation === []): ?>
                    <p class="empty"><?= esc($none) ?></p>
                <?php else: ?>
                    <div class="grid">
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.verdict')) ?></span><span class="v"><?= esc($verdictLbl((string) ($evaluation['reason'] ?? ''))) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.threshold')) ?></span><span class="v"><?= esc((string) (int) ($evaluation['threshold_weeks'] ?? 0)) ?> <?= esc(lang('Referrals.transferShow.weeks')) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.daysInactive')) ?></span><span class="v"><?= ($evaluation['days_inactive'] ?? null) === null || ($evaluation['days_inactive'] ?? '') === '' ? esc($none) : esc((string) (int) $evaluation['days_inactive']) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.dueNow')) ?></span><span class="v"><?= esc(empty($evaluation['due']) ? lang('Referrals.transferShow.no') : lang('Referrals.transferShow.yes')) ?></span></div>
                    </div>
                <?php endif; ?>
            </div>

            <h2><?= esc(lang('Referrals.transferShow.trailHeading')) ?></h2>
            <div class="panel">
                <?php if ($reviews === []): ?>
                    <p class="empty"><?= esc(lang('Referrals.transferShow.trailEmpty')) ?></p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th><?= esc(lang('Referrals.transferShow.trailAction')) ?></th>
                                <th><?= esc(lang('Referrals.transferShow.trailActor')) ?></th>
                                <th><?= esc(lang('Referrals.transferShow.trailNote')) ?></th>
                                <th><?= esc(lang('Referrals.transferShow.trailAt')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviews as $rev): ?>
                                <tr>
                                    <td><?= esc($actionLbl((string) ($rev['action'] ?? ''))) ?></td>
                                    <td class="mono"><?= esc((string) ($rev['actor_id'] ?? '') ?: $none) ?></td>
                                    <td><?= esc((string) ($rev['note'] ?? '') ?: $none) ?></td>
                                    <td class="mono"><?= esc((string) ($rev['created_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if (! empty($req['decided_by']) || ! empty($req['decided_at']) || ! empty($req['transfer_id'])): ?>
                <h2><?= esc(lang('Referrals.transferShow.decision')) ?></h2>
                <div class="panel">
                    <div class="grid">
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.decidedBy')) ?></span><span class="v"><?= esc((string) ($req['decided_by'] ?? '') ?: $none) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.decidedAt')) ?></span><span class="v"><?= esc((string) ($req['decided_at'] ?? '') ?: $none) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.transferShow.transferId')) ?></span><span class="v"><?= esc((string) ($req['transfer_id'] ?? '') ?: $none) ?></span></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
