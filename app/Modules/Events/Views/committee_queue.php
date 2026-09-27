<?php
/**
 * COMMITTEE OVERSIGHT queue (GET /event-committees/decisions) — the browser face of
 * CommitteeController::queue, and the maker-checker half of the committee feature.
 *
 * What the committee wants to do, and whether the group leader has to say yes first.
 * The rows are already bounded to the groups this actor's own authority reaches, so
 * the page never offers a decision to somebody who could not take it: approving or
 * rejecting asks the platform's PDP for the permission the decision itself names
 * (`event.expense.approve` for money, `event.schedule.approve` for schedule,
 * publication and cancellation, `event.create` for governance), over the decision's
 * oversight group — and nobody may settle a request they made themselves.
 *
 * Requests have NO expiry: they stay pending until a human decides, exactly like the
 * other review queues on the platform.
 *
 * SELF-CONTAINED: includes _locale.php for a locale-aware <html lang dir> (RTL for
 * Arabic); copy is localized via lang('Events.decision.*') with English fallback;
 * ids and names are server data, escaped. Every act is a no-JS PRG form.
 *
 * @var list<array<string,mixed>> $rows       decisions, newest first (already decorated)
 * @var list<array<string,mixed>> $pending    the pending subset
 * @var string                    $status     active status filter ('all' or a status)
 * @var string                    $kind       active kind filter ('' = any)
 * @var list<string>              $kinds      CommitteeOversight::KINDS
 * @var list<string>              $statuses   CommitteeDecisionService::STATUSES
 * @var list<array<string,mixed>> $committees this actor's seats (for the empty state)
 * @var string                    $csrf
 */
$rows       = $rows ?? [];
$pending    = $pending ?? [];
$status     = $status ?? 'pending';
$kind       = $kind ?? '';
$kinds      = $kinds ?? [];
$statuses   = $statuses ?? [];
$committees = $committees ?? [];
$csrf       = $csrf ?? '';

$queueUrl   = base_url('/event-committees/decisions');
$hubUrl     = base_url('/event-committees');
$decideUrl  = static fn (string $id, string $verb): string => base_url('/event-committees/decisions/' . rawurlencode($id) . '/' . $verb);
$eventUrl   = static fn (string $id, string $suffix): string => base_url('/events/' . rawurlencode($id) . $suffix);

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

// Views stay dependency-free (no module classes, no autoloader): the label keys are
// derived inline, mirroring CommitteeOversight::kindLabelKey()/statusLabelKey().
$kindKey   = static fn (string $k): string => 'Events.decision.kind' . ucfirst($k);
$statusKey = static fn (string $s): string => 'Events.decision.status' . ucfirst($s);
$effectKey = static fn (string $e): string => 'Events.decision.effect' . str_replace('.', '', ucwords($e, '.'));
?>

<?php ob_start(); ?>
<?= esc(lang('Events.decision.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1100px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        input:focus, select:focus { outline:2px solid #0d9488; border-color:#0d9488; }


        button { border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#0d9488; color:#fff; }


        button.tiny { padding:5px 10px; font-size:.76rem; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.decision.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.decision.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Filters (GET, no JS) -->
        <form class="card" method="get" action="<?= esc($queueUrl, 'attr') ?>">
            <div class="grid">
                <div>
                    <label for="f-status"><?= esc(lang('Events.decision.colStatus')) ?></label>
                    <select id="f-status" name="status">
                        <option value="all"<?= $status === 'all' ? ' selected' : '' ?>><?= esc(lang('Events.decision.historyHeading')) ?></option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?= esc((string) $s, 'attr') ?>"<?= $status === (string) $s ? ' selected' : '' ?>><?= esc(lang($statusKey((string) $s))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-kind"><?= esc(lang('Events.decision.colKind')) ?></label>
                    <select id="f-kind" name="kind">
                        <option value=""><?= esc(lang('Events.decision.kindAll')) ?></option>
                        <?php foreach ($kinds as $k): ?>
                            <option value="<?= esc((string) $k, 'attr') ?>"<?= $kind === (string) $k ? ' selected' : '' ?>><?= esc(lang($kindKey((string) $k))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><button type="submit"><?= esc(lang('Events.decision.filterBtn')) ?></button></div>
            </div>
        </form>

        <h2><?= esc(lang('Events.decision.queueHeading')) ?></h2>
        <p class="muted"><?= $li('Events.committee.pendingFmt', (string) count($pending)) ?> · <a href="<?= esc($hubUrl, 'attr') ?>"><?= esc(lang('Events.decision.myCommitteesHeading')) ?></a></p>

        <?php if ($rows === []): ?>
            <p class="empty"><?= esc($committees === [] ? lang('Events.decision.noCommittees') : lang('Events.decision.emptyQueue')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.decision.colKind')) ?></th>
                    <th><?= esc(lang('Events.decision.colTitle')) ?></th>
                    <th><?= esc(lang('Events.decision.colEvent')) ?></th>
                    <th><?= esc(lang('Events.decision.colRequestedBy')) ?></th>
                    <th><?= esc(lang('Events.decision.colRequired')) ?></th>
                    <th><?= esc(lang('Events.decision.colStatus')) ?></th>
                    <th><?= esc(lang('Events.decision.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($rows as $d):
                        $did     = (string) ($d['id'] ?? '');
                        $eid     = (string) ($d['event_id'] ?? '');
                        $dStatus = (string) ($d['status'] ?? 'pending');
                        $effect  = (array) ($d['effect'] ?? []);
                        $action  = (string) ($effect['action'] ?? 'none'); ?>
                        <tr>
                            <td><?= esc(lang($kindKey((string) ($d['kind'] ?? 'other')))) ?></td>
                            <td>
                                <?= esc((string) ($d['title'] ?? '')) ?>
                                <?php if (! empty($d['amount'])): ?><div class="mono"><?= esc((string) $d['amount']) ?></div><?php endif; ?>
                                <?php if (! empty($d['detail'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $d['detail']) ?></div><?php endif; ?>
                                <?php if ($action !== '' && $action !== 'none'): ?>
                                    <div class="mono"><?= esc(lang($effectKey($action))) ?><?= ! empty($effect['applied']) ? ' ✓' : '' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= esc((string) ($d['event_title'] ?? lang('Events.eventFallback'))) ?>
                                <?php if (! empty($d['event_status'])): ?>
                                    <span class="pill <?= esc((string) $d['event_status'], 'attr') ?>"><?= esc(lang('Events.status.' . (string) $d['event_status'])) ?></span>
                                <?php endif; ?>
                                <div class="row" style="margin-top:4px">
                                    <a class="mono" href="<?= esc($eventUrl($eid, '/committee'), 'attr') ?>"><?= esc(lang('Events.decision.openCommittee')) ?></a>
                                    <a class="mono" href="<?= esc($eventUrl($eid, '/plan'), 'attr') ?>"><?= esc(lang('Events.committee.openPlan')) ?></a>
                                </div>
                            </td>
                            <td>
                                <?= esc((string) ($d['requested_by_name'] ?? $d['requested_by'] ?? '—')) ?>
                                <div class="mono"><?= esc(substr((string) ($d['created_at'] ?? ''), 0, 10)) ?></div>
                            </td>
                            <td class="mono"><?= esc((string) ($d['required_permission'] ?? '—')) ?><div class="muted"><?= esc((string) ($d['oversight_group_id'] ?? '')) ?></div></td>
                            <td>
                                <span class="pill <?= esc($dStatus, 'attr') ?>"><?= esc(lang($statusKey($dStatus))) ?></span>
                                <?php if ($dStatus !== 'pending'): ?>
                                    <div class="mono"><?= esc(substr((string) ($d['decided_at'] ?? ''), 0, 10)) ?></div>
                                    <div class="muted" style="font-size:.8rem"><?= esc((string) ($d['decided_by_name'] ?? $d['decided_by'] ?? '')) ?></div>
                                    <?php if (! empty($d['decision_note'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $d['decision_note']) ?></div><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dStatus === 'pending'): ?>
                                    <form method="post" action="<?= esc($decideUrl($did, 'approve'), 'attr') ?>">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="return_to" value="queue">
                                        <button type="submit" class="tiny"><?= esc(lang('Events.decision.approveBtn')) ?></button>
                                    </form>
                                    <form method="post" action="<?= esc($decideUrl($did, 'reject'), 'attr') ?>" class="row" style="margin-top:6px">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="return_to" value="queue">
                                        <input type="text" name="note" maxlength="500" placeholder="<?= esc(lang('Events.decision.noteLbl'), 'attr') ?>" style="width:auto">
                                        <button type="submit" class="ghost tiny danger"><?= esc(lang('Events.decision.rejectBtn')) ?></button>
                                    </form>
                                    <form method="post" action="<?= esc($decideUrl($did, 'cancel'), 'attr') ?>" style="margin-top:6px">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="return_to" value="queue">
                                        <button type="submit" class="ghost tiny"><?= esc(lang('Events.decision.cancelBtn')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
