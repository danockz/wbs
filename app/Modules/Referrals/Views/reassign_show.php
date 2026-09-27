<?php
/**
 * Sponsor-reassignment DETAIL page (FR-MEM-002) — the browser face of
 * SponsorReassignmentController::show, which otherwise rendered the generic admin
 * console. Shows one reassignment request: the member and old/new sponsor, the
 * maker and nominated checker, the business justification, effective time,
 * status + eligibility, the before/after upline paths, the descendant-impact
 * snapshot, and (when decided) who decided it. When the request id is unknown a
 * not-found panel is shown instead.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy is localized via
 * lang('Referrals.reassignShow.*') with English fallback; status/eligibility are
 * FIXED vocabularies localized with a raw-value fallback. The service is PII-free
 * (ids only), so ids render verbatim and escaped.
 *
 * @var array<string,mixed>|null $request  the reassignment row, or null if not found
 * @var string                   $requestId
 */
$req       = is_array($request ?? null) ? $request : null;
$requestId = $requestId ?? '';

include __DIR__ . '/_locale.php';

$statusLbl = static function (string $s): string {
    $map = ['pending' => 'statusPending', 'approved' => 'statusApproved', 'rejected' => 'statusRejected', 'cancelled' => 'statusCancelled'];
    if (! isset($map[$s])) {
        return $s === '' ? '—' : ucfirst($s);
    }
    $v = lang('Referrals.reassignShow.' . $map[$s]);
    return $v === 'Referrals.reassignShow.' . $map[$s] ? ucfirst($s) : $v;
};
$statusColor = static fn (string $s): string => match ($s) {
    'approved'  => '#22c55e',
    'rejected'  => '#ef4444',
    'cancelled' => '#94a3b8',
    default     => '#f59e0b',
};
$eligLbl = static function (string $e): string {
    $key = $e === 'blocked' ? 'eligBlocked' : ($e === 'ok' ? 'eligOk' : '');
    if ($key === '') {
        return $e === '' ? '' : ucfirst($e);
    }
    $v = lang('Referrals.reassignShow.' . $key);
    return $v === 'Referrals.reassignShow.' . $key ? ucfirst($e) : $v;
};
$none = lang('Referrals.reassignShow.none');
$path = static function ($p) use ($none): string {
    if (is_string($p)) {
        $d = json_decode($p, true);
        $p = is_array($d) ? $d : [];
    }
    if (! is_array($p) || $p === []) {
        return $none;
    }
    return implode(' → ', array_map(static fn ($x): string => (string) $x, $p));
};
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.reassignShow.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 880px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:22px 0 12px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Referrals.reassignShow.heading')) ?></h1>
        <p class="sub"><?= esc($requestId) ?></p>

        <?php if ($req === null): ?>
            <div class="panel"><p class="empty"><?= esc(lang('Referrals.reassignShow.notFound')) ?></p></div>
        <?php else: ?>
            <?php
            $status = (string) ($req['status'] ?? 'pending');
            $elig   = (string) ($req['eligibility_state'] ?? '');
            $impact = $req['impact'] ?? null;
            if (is_string($impact)) { $impact = json_decode($impact, true); }
            $descN  = is_array($impact) && isset($impact['descendant_count']) ? (int) $impact['descendant_count'] : null;
            ?>
            <div class="panel">
                <div style="margin-bottom:14px;">
                    <span class="chip" style="color:<?= esc($statusColor($status), 'attr') ?>;border-color:<?= esc($statusColor($status), 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                    <?php if ($elig !== ''): ?>
                        <span class="chip" style="color:<?= $elig === 'blocked' ? '#ef4444' : '#22c55e' ?>;border-color:#334155"><?= esc($eligLbl($elig)) ?></span>
                    <?php endif; ?>
                </div>
                <div class="grid">
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.member')) ?></span><span class="v"><?= esc((string) ($req['member_id'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.currentSponsor')) ?></span><span class="v"><?= esc((string) ($req['current_sponsor_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.newSponsor')) ?></span><span class="v"><?= esc((string) ($req['new_sponsor_id'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.requestedBy')) ?></span><span class="v"><?= esc((string) ($req['requested_by'] ?? $none)) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.approver')) ?></span><span class="v"><?= esc((string) ($req['approver_id'] ?? '') ?: $none) ?></span></div>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.effectiveAt')) ?></span><span class="v"><?= esc((string) ($req['effective_at'] ?? '') ?: $none) ?></span></div>
                </div>

                <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.reason')) ?></span><span class="reason"><?= esc((string) ($req['reason'] ?? '')) ?></span></div>
                <?php if (! empty($req['eligibility_detail'])): ?>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.eligDetail')) ?></span><span class="reason"><?= esc((string) $req['eligibility_detail']) ?></span></div>
                <?php endif; ?>
            </div>

            <h2><?= esc(lang('Referrals.reassignShow.uplinePaths')) ?></h2>
            <div class="panel">
                <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.beforePath')) ?></span><span class="v"><?= esc($path($req['before_path'] ?? null)) ?></span></div>
                <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.afterPath')) ?></span><span class="v"><?= esc($path($req['after_path'] ?? null)) ?></span></div>
                <?php if ($descN !== null): ?>
                    <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.descendants')) ?></span><span class="v"><?= esc((string) $descN) ?></span></div>
                <?php endif; ?>
            </div>

            <?php if (! empty($req['decided_by']) || ! empty($req['decided_at'])): ?>
                <h2><?= esc(lang('Referrals.reassignShow.decision')) ?></h2>
                <div class="panel">
                    <div class="grid">
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.decidedBy')) ?></span><span class="v"><?= esc((string) ($req['decided_by'] ?? '') ?: $none) ?></span></div>
                        <div class="kv"><span class="k"><?= esc(lang('Referrals.reassignShow.decidedAt')) ?></span><span class="v"><?= esc((string) ($req['decided_at'] ?? '') ?: $none) ?></span></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
