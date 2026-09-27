<?php
/**
 * Sponsor-reassignment MAKER–CHECKER dashboard (FR-MEM-002) — the browser face
 * of SponsorReassignmentController, which otherwise only spoke JSON. Lists the
 * requests awaiting the current user as checker and offers the maker form to
 * open a new request.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy is localized via
 * lang('Referrals.reassign.*') with English fallback; {0} placeholders are
 * interpolated in PHP via $li().
 *
 * The service is PII-free (member/sponsor IDs only), so ids render verbatim and
 * escaped. Status + eligibility are FIXED vocabularies, localized with a
 * raw-value fallback so an unknown value never breaks the page. Action forms
 * POST to the webcsrf-guarded routes and carry the double-submit _csrf token;
 * approve/reject are only shown for pending requests (the PDP still enforces the
 * sponsor.reassign.approve capability and blocks self-approval server-side).
 *
 * @var list<array<string,mixed>> $requests
 * @var string                    $csrf
 */
$requests = $requests ?? [];
$csrf     = $csrf ?? '';
$roster   = is_array($roster ?? null) ? $roster : [];

include __DIR__ . '/_locale.php';

// Render a roster-backed person <select> for an entity-reference field, else a
// bounded free-text fallback when no roster is available.
$personSelect = static function (string $id, string $name, bool $required, array $roster, string $noneLabel): string {
    if ($roster === []) {
        return '<input id="' . $id . '" type="text" name="' . $name . '"' . ($required ? ' required' : '') . ' maxlength="64">';
    }
    $html = '<select id="' . $id . '" name="' . $name . '"' . ($required ? ' required' : '') . '>';
    $html .= '<option value="">' . htmlspecialchars($noneLabel, ENT_QUOTES) . '</option>';
    foreach ($roster as $u) {
        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') {
            continue;
        }
        $uname  = trim((string) ($u['display_name'] ?? ''));
        $ulabel = $uname !== '' ? $uname : $uid;
        $html  .= '<option value="' . htmlspecialchars($uid, ENT_QUOTES) . '">' . htmlspecialchars($ulabel, ENT_QUOTES) . '</option>';
    }
    return $html . '</select>';
};

// Fixed-vocabulary status label, localized with raw-value fallback.
$statusLbl = static function (string $s): string {
    $map = ['pending' => 'statusPending', 'approved' => 'statusApproved', 'rejected' => 'statusRejected', 'cancelled' => 'statusCancelled'];
    if (! isset($map[$s])) {
        return $s === '' ? '' : ucfirst($s);
    }
    $v = lang('Referrals.reassign.' . $map[$s]);

    return $v === 'Referrals.reassign.' . $map[$s] ? ucfirst($s) : $v;
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
    $v = lang('Referrals.reassign.' . $key);

    return $v === 'Referrals.reassign.' . $key ? ucfirst($e) : $v;
};
$none = lang('Referrals.reassign.none');
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.reassign.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 1120px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:0 0 12px; }


        .reason { color:#cbd5e1; font-size:.86rem; margin:6px 0; }


        .actions form { display:flex; gap:6px; align-items:flex-end; }


        .fld { margin-bottom:12px; }


        button { border:0; border-radius:8px; padding:8px 14px; font:inherit; font-weight:700; cursor:pointer; }


        .approve { background:#16a34a; color:#fff; }


        .reject { background:#dc2626; color:#fff; }


        .cancel { background:#334155; color:#e2e8f0; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Referrals.reassign.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Referrals.reassign.sub')) ?></p>

        <div class="grid">
            <section class="panel">
                <h2><?= esc(lang('Referrals.reassign.pendingHeading')) ?></h2>

                <?php if ($requests === []): ?>
                    <p class="empty"><?= esc(lang('Referrals.reassign.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <?php
                        $id       = (string) ($r['id'] ?? '');
                        $status   = (string) ($r['status'] ?? 'pending');
                        $elig     = (string) ($r['eligibility_state'] ?? '');
                        $impact   = $r['impact'] ?? null;
                        $descN    = null;
                        if (is_array($impact) && isset($impact['descendant_count'])) {
                            $descN = (int) $impact['descendant_count'];
                        }
                        $isPending = ($status === 'pending');
                        ?>
                        <article class="req">
                            <div class="row">
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.reassign.colMember')) ?></span><span class="v"><?= esc((string) ($r['member_id'] ?? $none)) ?></span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.reassign.colCurrent')) ?></span><span class="v"><?= esc((string) ($r['current_sponsor_id'] ?? '') ?: $none) ?></span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.reassign.colNew')) ?></span><span class="v"><?= esc((string) ($r['new_sponsor_id'] ?? $none)) ?></span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.reassign.colRequestedBy')) ?></span><span class="v"><?= esc((string) ($r['requested_by'] ?? $none)) ?></span></span>
                            </div>
                            <div class="row">
                                <span class="chip" style="color:<?= esc($statusColor($status), 'attr') ?>;border-color:<?= esc($statusColor($status), 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                                <?php if ($elig !== ''): ?>
                                    <span class="chip"><?= esc($eligLbl($elig)) ?></span>
                                <?php endif; ?>
                                <?php if ($descN !== null): ?>
                                    <span class="chip"><?= esc($li('Referrals.reassign.impact', (string) $descN)) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (! empty($r['reason'])): ?>
                                <p class="reason"><?= esc(lang('Referrals.reassign.colReason')) ?>: <?= esc((string) $r['reason']) ?></p>
                            <?php endif; ?>

                            <?php if ($isPending): ?>
                                <div class="actions">
                                    <form method="post" action="/referrals/sponsor-reassignments/<?= esc($id, 'url') ?>/approve">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <div><label for="an-<?= esc($id, 'attr') ?>"><?= esc(lang('Referrals.reassign.noteLbl')) ?></label>
                                            <input class="note-in" id="an-<?= esc($id, 'attr') ?>" type="text" name="note"></div>
                                        <button class="approve" type="submit"><?= esc(lang('Referrals.reassign.approve')) ?></button>
                                    </form>
                                    <form method="post" action="/referrals/sponsor-reassignments/<?= esc($id, 'url') ?>/reject">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="reject" type="submit"><?= esc(lang('Referrals.reassign.reject')) ?></button>
                                    </form>
                                    <form method="post" action="/referrals/sponsor-reassignments/<?= esc($id, 'url') ?>/cancel">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="cancel" type="submit"><?= esc(lang('Referrals.reassign.cancel')) ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="sod"><?= esc(lang('Referrals.reassign.sodNote')) ?></p>
            </section>

            <aside class="panel">
                <h2><?= esc(lang('Referrals.reassign.newHeading')) ?></h2>
                <form method="post" action="/referrals/sponsor-reassignments">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <div class="fld"><label for="f-member"><?= esc(lang('Referrals.reassign.memberIdLbl')) ?></label>
                        <?= $personSelect('f-member', 'member_id', true, $roster, lang('Referrals.reassign.personNone')) ?></div>
                    <div class="fld"><label for="f-new"><?= esc(lang('Referrals.reassign.newSponsorIdLbl')) ?></label>
                        <?= $personSelect('f-new', 'new_sponsor_id', true, $roster, lang('Referrals.reassign.personNone')) ?></div>
                    <div class="fld"><label for="f-reason"><?= esc(lang('Referrals.reassign.reasonLbl')) ?></label>
                        <textarea id="f-reason" name="reason" required></textarea></div>
                    <div class="fld"><label for="f-approver"><?= esc(lang('Referrals.reassign.approverIdLbl')) ?></label>
                        <?= $personSelect('f-approver', 'approver_id', false, $roster, lang('Referrals.reassign.personNone')) ?></div>
                    <div class="fld"><label for="f-eff"><?= esc(lang('Referrals.reassign.effectiveAtLbl')) ?></label>
                        <input id="f-eff" type="text" name="effective_at" placeholder="YYYY-MM-DD"></div>
                    <button class="submit" type="submit"><?= esc(lang('Referrals.reassign.submit')) ?></button>
                </form>
            </aside>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
