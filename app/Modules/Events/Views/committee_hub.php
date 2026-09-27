<?php
/**
 * EVENT COMMITTEE hub (GET /event-committees) — the cross-event entry point, and the
 * browser face of CommitteeController::hub.
 *
 * Four lists, each bounded to what this actor may actually see: the committees they
 * sit on (their seat, their responsibility, the window their authority runs to), the
 * decisions awaiting them as overseer, the decisions already settled in their scope,
 * and their own open tasks across every event plan. Nothing here writes: each row
 * links to the per-event console or the oversight queue, where the authoritative
 * check runs.
 *
 * SELF-CONTAINED: includes _locale.php for a locale-aware <html lang dir> (RTL for
 * Arabic); copy is localized via lang('Events.committee.*')/lang('Events.decision.*')
 * with English fallback; ids and names are server data, escaped. No JavaScript, no
 * external assets (CSP).
 *
 * @var list<array<string,mixed>> $committees CommitteeService::committeesForUser()
 * @var list<array<string,mixed>> $pending    decisions awaiting this actor
 * @var list<array<string,mixed>> $history    settled decisions in this actor's scope
 * @var list<array<string,mixed>> $myTasks    this actor's open tasks, any event
 * @var array<string,string>      $names      user id => display name (may be empty)
 * @var string                    $csrf
 */
$committees = $committees ?? [];
$pending    = $pending ?? [];
$history    = $history ?? [];
$myTasks    = $myTasks ?? [];
$names      = $names ?? [];
$csrf       = $csrf ?? '';

$queueUrl = base_url('/event-committees/decisions');
$eventUrl = static fn (string $id, string $suffix): string => base_url('/events/' . rawurlencode($id) . $suffix);
$name     = static function (?string $id) use ($names): string {
    $id = (string) ($id ?? '');
    if ($id === '') {
        return '—';
    }

    return (string) ($names[$id] ?? $id);
};

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

$kindKey   = static fn (string $k): string => 'Events.decision.kind' . ucfirst($k);
$statusKey = static fn (string $s): string => 'Events.decision.status' . ucfirst($s);
$respKey   = static fn (string $r): string => 'Events.committee.resp' . ucfirst($r);
$taskStatusKey = static fn (string $s): string => 'Events.plan.status' . str_replace('_', '', ucwords($s, '_'));
$riskKey   = static fn (string $r): string => 'Events.plan.risk' . str_replace('_', '', ucwords($r, '_'));
?>

<?php ob_start(); ?>
<?= esc(lang('Events.committee.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        
        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.committee.heading')) ?></h1>
        <p class="sub"><?= esc(lang('Events.committee.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <h2><?= esc(lang('Events.decision.myCommitteesHeading')) ?></h2>
        <?php if ($committees === []): ?>
            <p class="empty"><?= esc(lang('Events.decision.noCommittees')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.decision.colEvent')) ?></th>
                    <th><?= esc(lang('Events.committee.colResponsibility')) ?></th>
                    <th><?= esc(lang('Events.committee.colWindow')) ?></th>
                    <th><?= esc(lang('Events.committee.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($committees as $c):
                        $eid = (string) ($c['event_id'] ?? ''); ?>
                        <tr>
                            <td>
                                <?= esc((string) ($c['event_title'] ?? lang('Events.eventFallback'))) ?>
                                <?php if (! empty($c['event_status'])): ?>
                                    <span class="pill <?= esc((string) $c['event_status'], 'attr') ?>"><?= esc(lang('Events.status.' . (string) $c['event_status'])) ?></span>
                                <?php endif; ?>
                                <div class="mono"><?= esc($eid) ?><?= ! empty($c['event_starts_at']) ? ' · ' . esc(substr((string) $c['event_starts_at'], 0, 10)) : '' ?></div>
                                <?php if (! empty($c['mandate'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $c['mandate']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?= esc(lang($respKey((string) ($c['responsibility'] ?? 'general')))) ?>
                                <?php if (! empty($c['is_chair'])): ?><span class="pill active"><?= esc(lang('Events.committee.isChair')) ?></span><?php endif; ?>
                            </td>
                            <td class="mono"><?= ! empty($c['effective_to']) ? esc($li('Events.committee.windowFmt', substr((string) $c['effective_to'], 0, 10))) : '—' ?></td>
                            <td>
                                <a href="<?= esc($eventUrl($eid, '/committee'), 'attr') ?>"><?= esc(lang('Events.committee.heading')) ?></a>
                                · <a href="<?= esc($eventUrl($eid, '/plan'), 'attr') ?>"><?= esc(lang('Events.plan.heading')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?= esc(lang('Events.decision.queueHeading')) ?></h2>
        <?php if ($pending === []): ?>
            <p class="empty"><?= esc(lang('Events.decision.emptyQueue')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.decision.colKind')) ?></th>
                    <th><?= esc(lang('Events.decision.colTitle')) ?></th>
                    <th><?= esc(lang('Events.decision.colEvent')) ?></th>
                    <th><?= esc(lang('Events.decision.colRequestedBy')) ?></th>
                    <th><?= esc(lang('Events.decision.colRequired')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($pending as $d): ?>
                        <tr>
                            <td><?= esc(lang($kindKey((string) ($d['kind'] ?? 'other')))) ?></td>
                            <td>
                                <?= esc((string) ($d['title'] ?? '')) ?>
                                <?php if (! empty($d['amount'])): ?><div class="mono"><?= esc((string) $d['amount']) ?></div><?php endif; ?>
                                <?php if (! empty($d['detail'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $d['detail']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?= esc((string) ($d['event_title'] ?? lang('Events.eventFallback'))) ?>
                                <div><a class="mono" href="<?= esc($eventUrl((string) ($d['event_id'] ?? ''), '/committee'), 'attr') ?>"><?= esc(lang('Events.decision.openCommittee')) ?> →</a></div>
                            </td>
                            <td><?= esc((string) ($d['requested_by_name'] ?? $name(isset($d['requested_by']) ? (string) $d['requested_by'] : null))) ?></td>
                            <td class="mono"><?= esc((string) ($d['required_permission'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted"><a href="<?= esc($queueUrl, 'attr') ?>"><?= esc(lang('Events.committee.openQueue')) ?> →</a></p>
        <?php endif; ?>

        <h2><?= esc(lang('Events.decision.historyHeading')) ?></h2>
        <?php if ($history === []): ?>
            <p class="empty"><?= esc(lang('Events.decision.noHistory')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.decision.colKind')) ?></th>
                    <th><?= esc(lang('Events.decision.colTitle')) ?></th>
                    <th><?= esc(lang('Events.decision.colEvent')) ?></th>
                    <th><?= esc(lang('Events.decision.colStatus')) ?></th>
                    <th><?= esc(lang('Events.decision.colDecided')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($history as $d): ?>
                        <tr>
                            <td><?= esc(lang($kindKey((string) ($d['kind'] ?? 'other')))) ?></td>
                            <td>
                                <?= esc((string) ($d['title'] ?? '')) ?>
                                <?php if (! empty($d['decision_note'])): ?><div class="muted" style="font-size:.8rem"><?= esc((string) $d['decision_note']) ?></div><?php endif; ?>
                            </td>
                            <td><?= esc((string) ($d['event_title'] ?? lang('Events.eventFallback'))) ?></td>
                            <td><span class="pill <?= esc((string) ($d['status'] ?? ''), 'attr') ?>"><?= esc(lang($statusKey((string) ($d['status'] ?? '')))) ?></span></td>
                            <td>
                                <span class="mono"><?= esc(substr((string) ($d['decided_at'] ?? ''), 0, 10)) ?></span>
                                <div class="muted" style="font-size:.8rem"><?= esc((string) ($d['decided_by_name'] ?? $name(isset($d['decided_by']) ? (string) $d['decided_by'] : null))) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?= esc(lang('Events.plan.myTasksHeading')) ?></h2>
        <?php if ($myTasks === []): ?>
            <p class="empty"><?= esc(lang('Events.plan.noMyTasks')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.plan.colTask')) ?></th>
                    <th><?= esc(lang('Events.decision.colEvent')) ?></th>
                    <th><?= esc(lang('Events.plan.colDue')) ?></th>
                    <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                    <th><?= esc(lang('Events.plan.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($myTasks as $t): ?>
                        <tr>
                            <td><?= esc((string) ($t['title'] ?? '')) ?></td>
                            <td class="mono"><?= esc((string) ($t['event_id'] ?? '')) ?></td>
                            <td class="mono"><?= esc((string) ($t['due_at'] ?? lang('Events.plan.noDue'))) ?></td>
                            <td>
                                <span class="pill <?= esc((string) ($t['status'] ?? 'todo'), 'attr') ?>"><?= esc(lang($taskStatusKey((string) ($t['status'] ?? 'todo')))) ?></span>
                                <span class="pill <?= esc((string) ($t['risk'] ?? 'ok'), 'attr') ?>"><?= esc(lang($riskKey((string) ($t['risk'] ?? 'ok')))) ?></span>
                            </td>
                            <td><a href="<?= esc($eventUrl((string) ($t['event_id'] ?? ''), '/plan'), 'attr') ?>"><?= esc(lang('Events.committee.openPlan')) ?> →</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
