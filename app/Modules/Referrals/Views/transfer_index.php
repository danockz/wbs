<?php
/**
 * Prospect-transfer MAKER–CHECKER dashboard (FR-REF-7 review path) — the browser
 * face of ProspectTransferController. Lists the inactivity transfers awaiting the
 * current user as checker and offers the maker form to propose one.
 *
 * This queue only fills where a subtree sets
 * `referrals.prospect_transfer.requires_review`; everywhere else a due transfer
 * applies on the spot and never appears here.
 *
 * SELF-CONTAINED page: renders its own <html>, so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). All UI copy is localized via
 * lang('Referrals.transfer.*') with English fallback; {0} placeholders are
 * interpolated in PHP via $li().
 *
 * The service is PII-free (contact/mentor/group IDs only), so ids render verbatim
 * and escaped — except in the maker's OWN picker, which lists the contacts they
 * already own. Status + eligibility are FIXED vocabularies, localized with a
 * raw-value fallback so an unknown value never breaks the page. Action forms POST
 * to the webcsrf-guarded routes and carry the double-submit _csrf token;
 * approve/reject are only shown for pending requests (the PDP still enforces the
 * sponsor.reassign.approve capability and blocks self-approval server-side).
 *
 * @var list<array<string,mixed>> $requests pending transfer requests
 * @var list<array<string,mixed>> $contacts the maker's own address book
 * @var list<array<string,mixed>> $roster   active org members (mentor/approver pickers)
 * @var string                    $csrf
 */
$requests = $requests ?? [];
$contacts = is_array($contacts ?? null) ? $contacts : [];
$roster   = is_array($roster ?? null) ? $roster : [];
$csrf     = $csrf ?? '';

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

// The maker proposes for a contact in THEIR OWN book (scoped by the controller),
// so this picker may show names; with an empty book it degrades to a plain id
// field rather than blocking the form.
$contactSelect = static function (string $id, string $name, array $contacts, string $noneLabel, string $idLabel): string {
    if ($contacts === []) {
        return '<input id="' . $id . '" type="text" name="' . $name . '" required maxlength="64" placeholder="'
            . htmlspecialchars($idLabel, ENT_QUOTES) . '">';
    }
    $html = '<select id="' . $id . '" name="' . $name . '" required>';
    $html .= '<option value="">' . htmlspecialchars($noneLabel, ENT_QUOTES) . '</option>';
    foreach ($contacts as $c) {
        $cid = (string) ($c['id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $cname  = trim((string) ($c['full_name'] ?? ''));
        $clabel = $cname !== '' ? $cname : $cid;
        $html  .= '<option value="' . htmlspecialchars($cid, ENT_QUOTES) . '">' . htmlspecialchars($clabel, ENT_QUOTES) . '</option>';
    }

    return $html . '</select>';
};

// Fixed-vocabulary status label, localized with raw-value fallback.
$statusLbl = static function (string $s): string {
    $map = [
        'pending'   => 'statusPending',
        'approved'  => 'statusApproved',
        'rejected'  => 'statusRejected',
        'cancelled' => 'statusCancelled',
    ];
    if (! isset($map[$s])) {
        return $s === '' ? '' : ucfirst($s);
    }
    $v = lang('Referrals.transfer.' . $map[$s]);

    return $v === 'Referrals.transfer.' . $map[$s] ? ucfirst($s) : $v;
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
    $v = lang('Referrals.transfer.' . $key);

    return $v === 'Referrals.transfer.' . $key ? ucfirst($e) : $v;
};
$none = lang('Referrals.transfer.none');
?>

<?php ob_start(); ?>
<?= esc(lang('Referrals.transfer.metaTitle')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        .wrap { max-width: 1120px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:0 0 12px; }


        .reason { color:#cbd5e1; font-size:.86rem; margin:6px 0; }


        .detail { font-size:.74rem; color:#f87171; margin:4px 0 0; }


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
        <h1><?= esc(lang('Referrals.transfer.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Referrals.transfer.sub')) ?></p>

        <div class="grid">
            <section class="panel">
                <h2><?= esc(lang('Referrals.transfer.pendingHeading')) ?></h2>

                <?php if ($requests === []): ?>
                    <p class="empty"><?= esc(lang('Referrals.transfer.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <?php
                        $id        = (string) ($r['id'] ?? '');
                        $status    = (string) ($r['status'] ?? 'pending');
                        $elig      = (string) ($r['eligibility_state'] ?? '');
                        $days      = isset($r['days_inactive']) && $r['days_inactive'] !== null ? (int) $r['days_inactive'] : null;
                        $weeks     = isset($r['threshold_weeks']) && $r['threshold_weeks'] !== null ? (int) $r['threshold_weeks'] : null;
                        $isPending = ($status === 'pending');
                        ?>
                        <article class="req">
                            <div class="row">
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.transfer.colContact')) ?></span><span class="v"><?= esc((string) ($r['prospect_id'] ?? $none)) ?></span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.transfer.colMove')) ?></span><span class="v">
                                    <?= esc((string) ($r['from_group_id'] ?? '') ?: $none) ?>
                                    <span class="arrow">→</span>
                                    <?= esc((string) ($r['to_group_id'] ?? '') ?: $none) ?>
                                </span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.transfer.colMentor')) ?></span><span class="v">
                                    <?= esc((string) ($r['from_owner_user_id'] ?? '') ?: $none) ?>
                                    <span class="arrow">→</span>
                                    <?= esc((string) ($r['to_owner_user_id'] ?? $none)) ?>
                                </span></span>
                                <span class="kv"><span class="k"><?= esc(lang('Referrals.transfer.colRequestedBy')) ?></span><span class="v"><?= esc((string) ($r['requested_by'] ?? $none)) ?></span></span>
                            </div>
                            <div class="row">
                                <span class="chip" style="color:<?= esc($statusColor($status), 'attr') ?>;border-color:<?= esc($statusColor($status), 'attr') ?>55"><?= esc($statusLbl($status)) ?></span>
                                <?php if ($elig !== ''): ?>
                                    <span class="chip" style="color:<?= $elig === 'blocked' ? '#ef4444' : '#22c55e' ?>"><?= esc($eligLbl($elig)) ?></span>
                                <?php endif; ?>
                                <?php if ($days !== null && $weeks !== null): ?>
                                    <span class="chip"><?= esc($li('Referrals.transfer.inactivity', (string) $days, (string) $weeks)) ?></span>
                                <?php endif; ?>
                                <span class="chip"><a href="/referrals/prospect-transfers/<?= esc($id, 'url') ?>"><?= esc(lang('Referrals.transfer.viewDetail')) ?></a></span>
                            </div>
                            <?php if (! empty($r['reason'])): ?>
                                <p class="reason"><?= esc(lang('Referrals.transfer.colReason')) ?>: <?= esc((string) $r['reason']) ?></p>
                            <?php endif; ?>
                            <?php if (! empty($r['eligibility_detail'])): ?>
                                <p class="detail"><?= esc((string) $r['eligibility_detail']) ?></p>
                            <?php endif; ?>

                            <?php if ($isPending): ?>
                                <div class="actions">
                                    <form method="post" action="/referrals/prospect-transfers/<?= esc($id, 'url') ?>/approve">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <div><label for="an-<?= esc($id, 'attr') ?>"><?= esc(lang('Referrals.transfer.noteLbl')) ?></label>
                                            <input class="note-in" id="an-<?= esc($id, 'attr') ?>" type="text" name="note"></div>
                                        <button class="approve" type="submit"><?= esc(lang('Referrals.transfer.approve')) ?></button>
                                    </form>
                                    <form method="post" action="/referrals/prospect-transfers/<?= esc($id, 'url') ?>/reject">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="reject" type="submit"><?= esc(lang('Referrals.transfer.reject')) ?></button>
                                    </form>
                                    <form method="post" action="/referrals/prospect-transfers/<?= esc($id, 'url') ?>/cancel">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <button class="cancel" type="submit"><?= esc(lang('Referrals.transfer.cancel')) ?></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
                <p class="sod"><?= esc(lang('Referrals.transfer.sodNote')) ?></p>
            </section>

            <aside class="panel">
                <h2><?= esc(lang('Referrals.transfer.newHeading')) ?></h2>
                <p class="sod" style="margin:0 0 12px;"><?= esc(lang('Referrals.transfer.newNote')) ?></p>
                <form method="post" action="/referrals/prospect-transfers">
                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                    <input type="hidden" name="trigger_type" value="manual">
                    <div class="fld"><label for="f-contact"><?= esc(lang('Referrals.transfer.contactLbl')) ?></label>
                        <?= $contactSelect('f-contact', 'prospect_id', $contacts, lang('Referrals.transfer.contactNone'), lang('Referrals.transfer.contactIdPh')) ?></div>
                    <div class="fld"><label for="f-mentor"><?= esc(lang('Referrals.transfer.mentorLbl')) ?></label>
                        <?= $personSelect('f-mentor', 'to_owner_user_id', true, $roster, lang('Referrals.transfer.personNone')) ?></div>
                    <div class="fld"><label for="f-reason"><?= esc(lang('Referrals.transfer.reasonLbl')) ?></label>
                        <textarea id="f-reason" name="reason" required></textarea></div>
                    <div class="fld"><label for="f-approver"><?= esc(lang('Referrals.transfer.approverLbl')) ?></label>
                        <?= $personSelect('f-approver', 'approver_id', false, $roster, lang('Referrals.transfer.personNone')) ?></div>
                    <button class="submit" type="submit"><?= esc(lang('Referrals.transfer.submit')) ?></button>
                </form>
            </aside>
        </div>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
