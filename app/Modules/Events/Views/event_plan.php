<?php
/**
 * EVENT PLAN console (GET /events/{id}/plan) — the browser face of
 * EventPlanController::console, i.e. the project-management half of the event
 * committee feature.
 *
 * One page, no JavaScript: the plan's derived progress and health, what needs
 * attention today (late / due soon / blocked / unassigned / overdue milestones),
 * the actor's own open tasks, every workstream with its tasks in dependency order,
 * the tasks that belong to no workstream, a status board, and the milestones.
 * Each row carries the PRG forms that act on it — add, edit, start, block, unblock,
 * finish, cancel, reopen, delete, add/remove a dependency, meet/miss a milestone —
 * so the plan is workable from a phone in a hall without a single script.
 *
 * Progress is DERIVED (WorkPlan): a done task is 100%, in-flight work is clamped
 * below it, cancelled tasks leave every roll-up, and a workstream's status is
 * computed from its tasks rather than asserted. The page therefore cannot show a
 * number nobody earned.
 *
 * SELF-CONTAINED: includes _locale.php for a locale-aware <html lang dir> (RTL for
 * Arabic); copy is localized via lang('Events.plan.*') with English fallback; ids
 * and names are server data, escaped. No external assets (CSP).
 *
 * @var string                    $eventId
 * @var array<string,mixed>|null  $event
 * @var array<string,mixed>       $plan    EventWorkService::plan()
 * @var array<string,list<array<string,mixed>>> $board  tasks by status column
 * @var list<array<string,mixed>> $myTasks
 * @var array<string,string>      $names   user id => display name
 * @var list<array<string,mixed>> $roster  people to assign (id, display_name)
 * @var array<string,mixed>|null  $committee
 * @var list<string>              $responsibilities
 * @var list<string>              $priorities
 * @var list<string>              $statuses
 * @var list<string>              $depTypes
 * @var string                    $actorId
 * @var string                    $csrf
 */
$eventId          = $eventId ?? '';
$event            = $event ?? null;
$plan             = $plan ?? [];
$board            = $board ?? [];
$myTasks          = $myTasks ?? [];
$names            = $names ?? [];
$roster           = $roster ?? [];
$committee        = $committee ?? null;
$responsibilities = $responsibilities ?? [];
$priorities       = $priorities ?? [];
$statuses         = $statuses ?? [];
$depTypes         = $depTypes ?? [];
$actorId          = $actorId ?? '';
$csrf             = $csrf ?? '';

$sidAttr     = $eventId !== '' ? rawurlencode($eventId) : '';
$planUrl     = base_url('/events/' . $sidAttr . '/plan');
$committeeUrl = base_url('/events/' . $sidAttr . '/committee');
$taskUrl     = static fn (string $id, string $suffix = ''): string => base_url('/event-plan/tasks/' . rawurlencode($id) . $suffix);
$wsUrl       = static fn (string $id, string $suffix = ''): string => base_url('/event-plan/workstreams/' . rawurlencode($id) . $suffix);
$msUrl       = static fn (string $id, string $suffix = ''): string => base_url('/event-plan/milestones/' . rawurlencode($id) . $suffix);

$streams    = (array) ($plan['workstreams'] ?? []);
$unstreamed = (array) ($plan['unstreamed'] ?? []);
$tasks      = (array) ($plan['tasks'] ?? []);
$milestones = (array) ($plan['milestones'] ?? []);
$attention  = (array) ($plan['attention'] ?? []);
$taskRollup = (array) ($plan['task_rollup'] ?? []);
$msRollup   = (array) ($plan['milestone_rollup'] ?? []);
$health     = (string) ($plan['health'] ?? 'on_track');
$pct        = (int) ($plan['progress_pct'] ?? 0);
$today      = (string) ($plan['today'] ?? '');

// Task lookup for dependency pickers + predecessor titles.
$byId = [];
foreach ($tasks as $t) {
    $byId[(string) ($t['id'] ?? '')] = $t;
}
$personName = static function (?string $id) use ($names): string {
    $id = (string) ($id ?? '');
    if ($id === '') {
        return lang('Events.plan.unassigned');
    }

    return (string) ($names[$id] ?? $id);
};
$statusKey = static fn (string $s): string => 'Events.plan.status' . str_replace('_', '', ucwords($s, '_'));
$prioKey   = static fn (string $p): string => 'Events.plan.prio' . ucfirst($p);
$riskKey   = static fn (string $r): string => 'Events.plan.risk' . str_replace('_', '', ucwords($r, '_'));
$wsKey     = static fn (string $w): string => 'Events.plan.ws' . str_replace('_', '', ucwords($w, '_'));
$depKey    = static fn (string $d): string => 'Events.plan.dep' . str_replace('_', '', ucwords($d, '_'));
$msKey     = static fn (string $m): string => 'Events.plan.ms' . ucfirst($m);

$session  = function_exists('session') ? session() : null;
$flashOk  = $session ? (string) ($session->getFlashdata('success') ?? '') : '';
$flashErr = $session ? (string) ($session->getFlashdata('error') ?? '') : '';

include __DIR__ . '/_locale.php';

/**
 * One task row's action forms. Kept as a closure so the workstream sections, the
 * unstreamed section and the board all render identical controls.
 */
$taskActions = static function (array $t) use ($csrf, $taskUrl, $statusKey): string {
    $id     = (string) ($t['id'] ?? '');
    $status = (string) ($t['status'] ?? 'todo');
    $out    = '';
    $form   = static function (string $action, array $hidden, string $label, string $class = 'ghost tiny') use ($csrf, $taskUrl, $id): string {
        $fields = '';
        foreach ($hidden as $k => $v) {
            $fields .= '<input type="hidden" name="' . esc($k, 'attr') . '" value="' . esc((string) $v, 'attr') . '">';
        }

        return '<form method="post" action="' . esc($taskUrl($id, '/action'), 'attr') . '" style="display:inline">'
            . '<input type="hidden" name="_csrf" value="' . esc($csrf, 'attr') . '">'
            . '<input type="hidden" name="action" value="' . esc($action, 'attr') . '">' . $fields
            . '<button type="submit" class="' . esc($class, 'attr') . '">' . esc($label) . '</button></form>';
    };

    if ($status === 'todo') {
        $out .= $form('start', [], lang('Events.plan.startBtn'), 'tiny');
    }
    if ($status === 'in_progress' || $status === 'todo') {
        $out .= $form('done', [], lang('Events.plan.doneBtn'), 'tiny');
        $out .= $form('block', [], lang('Events.plan.blockBtn'));
    }
    if ($status === 'blocked') {
        $out .= $form('unblock', [], lang('Events.plan.unblockBtn'), 'tiny');
    }
    if ($status === 'done') {
        $out .= $form('reopen', [], lang('Events.plan.reopenBtn'));
    }
    if ($status !== 'cancelled' && $status !== 'done') {
        $out .= $form('cancel', [], lang('Events.plan.cancelBtn'));
    }
    $out .= $form('delete', [], lang('Events.plan.deleteBtn'), 'ghost tiny danger');

    return $out;
};
?>

<?php ob_start(); ?>
<?= esc(lang('Events.plan.heading')) ?>
<?php $wbsTitleHtml = trim((string) ob_get_clean()); ?>

<?php ob_start(); ?>
<style>


        
        
        
        .wrap { max-width: 1120px; margin: 0 auto; padding: 5vh 20px 60px; }


        h2 { font-size:1.05rem; margin:26px 0 12px; padding-top:16px; border-top:1px solid #1e293b; }


        h3 { font-size:.98rem; margin:0 0 10px; }


        h4 { font-size:.9rem; margin:0 0 8px; color:#cbd5e1; }


        .card { background:#0f172aee; border:1px solid #1e293b; border-radius:12px; padding:16px 18px; margin-bottom:14px; }


        .bar { flex:1 1 180px; min-width:150px; height:10px; background:#1e293b; border-radius:999px; overflow:hidden; }


        .bar > i { display:block; height:100%; background:#0d9488; }


        .pill.cancelled, .pill.todo, .pill.pending, .pill.open { border-color:#334155; color:#94a3b8; }


        button { margin-top:12px; border:0; border-radius:8px; padding:9px 18px; font-size:.9rem; font-weight:600; cursor:pointer; background:#0d9488; color:#fff; }


        button.tiny { margin:0 4px 0 0; padding:5px 10px; font-size:.76rem; }


        details { margin-top:6px; }
</style>
<?php $wbsHeadExtra = (string) ob_get_clean(); ?>

<?php include __DIR__ . '/../../Shared/Views/_shell_open.php'; ?>

<main class="wrap">
        <h1><?= esc(lang('Events.plan.heading')) ?></h1>
        <p class="sub">
            <?= esc((string) ($event['title'] ?? lang('Events.eventFallback'))) ?>
            <span class="event"><?= esc($eventId !== '' ? $eventId : '—') ?></span>
            · <a href="<?= esc($committeeUrl, 'attr') ?>"><?= esc(lang('Events.committee.heading')) ?></a>
        </p>
        <p class="muted" style="margin-top:0;font-size:.86rem"><?= esc(lang('Events.plan.sub')) ?></p>

        <?php if ($flashOk !== ''): ?><div class="flash ok"><?= esc($flashOk) ?></div><?php endif; ?>
        <?php if ($flashErr !== ''): ?><div class="flash err"><?= esc($flashErr) ?></div><?php endif; ?>

        <!-- Progress + health -->
        <div class="card">
            <div class="head">
                <strong><?= esc(lang('Events.plan.progressLbl')) ?></strong>
                <span class="bar" role="img" aria-label="<?= esc(lang('Events.plan.progressLbl')) ?>"><i style="width: <?= $pct ?>%"></i></span>
                <strong><?= $pct ?>%</strong>
                <span class="pill <?= esc($health, 'attr') ?>"><?= esc(lang('Events.plan.health' . str_replace('_', '', ucwords($health, '_')))) ?></span>
                <span class="muted"><?= $li('Events.plan.countsFmt', (string) (int) ($taskRollup['total'] ?? 0), (string) (int) ($taskRollup['done'] ?? 0), (string) (int) ($taskRollup['open'] ?? 0)) ?></span>
                <span class="muted"><?= esc(lang('Events.plan.milestonesHeading')) ?>: <?= (int) ($msRollup['met'] ?? 0) ?>/<?= (int) ($msRollup['total'] ?? 0) ?></span>
            </div>
        </div>

        <!-- Attention -->
        <h2><?= esc(lang('Events.plan.attentionHeading')) ?></h2>
        <?php $counts = (array) ($attention['counts'] ?? []); ?>
        <p class="row">
            <span class="pill late"><?= esc(lang('Events.plan.lateLbl')) ?>: <?= (int) ($counts['late'] ?? 0) ?></span>
            <span class="pill due_soon"><?= esc(lang('Events.plan.dueSoonLbl')) ?>: <?= (int) ($counts['due_soon'] ?? 0) ?></span>
            <span class="pill blocked"><?= esc(lang('Events.plan.blockedLbl')) ?>: <?= (int) ($counts['blocked'] ?? 0) ?></span>
            <span class="pill todo"><?= esc(lang('Events.plan.unassignedLbl')) ?>: <?= (int) ($counts['unassigned'] ?? 0) ?></span>
            <span class="pill missed"><?= esc(lang('Events.plan.overdueMilestonesLbl')) ?>: <?= (int) ($counts['overdue_milestones'] ?? 0) ?></span>
        </p>
        <?php foreach (['late', 'due_soon', 'blocked', 'unassigned'] as $bucket):
            $rows = (array) ($attention[$bucket] ?? []);
            if ($rows === []) { continue; } ?>
            <h4><?= esc(lang('Events.plan.' . ($bucket === 'due_soon' ? 'dueSoonLbl' : ($bucket === 'overdue_milestones' ? 'overdueMilestonesLbl' : $bucket . 'Lbl')))) ?></h4>
            <ul class="muted" style="margin:0 0 10px;padding-inline-start:18px;font-size:.86rem">
                <?php foreach ($rows as $t): ?>
                    <li>
                        <?= esc((string) ($t['title'] ?? '')) ?>
                        — <?= esc($personName(isset($t['assignee_user_id']) ? (string) $t['assignee_user_id'] : null)) ?>
                        <?php if (! empty($t['due_at'])): ?><span class="mono"><?= esc((string) $t['due_at']) ?></span><?php endif; ?>
                        <?php if ($bucket === 'blocked' && ! empty($t['blocked_reason'])): ?><span class="muted">· <?= esc((string) $t['blocked_reason']) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php if ((int) ($counts['late'] ?? 0) + (int) ($counts['due_soon'] ?? 0) + (int) ($counts['blocked'] ?? 0) + (int) ($counts['unassigned'] ?? 0) + (int) ($counts['overdue_milestones'] ?? 0) === 0): ?>
            <p class="empty"><?= esc(lang('Events.plan.noneLbl')) ?></p>
        <?php endif; ?>

        <!-- My tasks -->
        <?php if ($myTasks !== []): ?>
            <h2><?= esc(lang('Events.plan.myTasksHeading')) ?></h2>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.plan.colTask')) ?></th>
                    <th><?= esc(lang('Events.plan.colDue')) ?></th>
                    <th><?= esc(lang('Events.plan.colPriority')) ?></th>
                    <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                    <th><?= esc(lang('Events.plan.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($myTasks as $t): ?>
                        <tr>
                            <td><?= esc((string) ($t['title'] ?? '')) ?></td>
                            <td class="mono"><?= esc((string) ($t['due_at'] ?? lang('Events.plan.noDue'))) ?></td>
                            <td><span class="pill <?= esc((string) ($t['priority'] ?? 'normal'), 'attr') ?>"><?= esc(lang($prioKey((string) ($t['priority'] ?? 'normal')))) ?></span></td>
                            <td><span class="pill <?= esc((string) ($t['risk'] ?? 'ok'), 'attr') ?>"><?= esc(lang($riskKey((string) ($t['risk'] ?? 'ok')))) ?></span></td>
                            <td><?= $taskActions($t) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Workstreams -->
        <h2><?= esc(lang('Events.plan.workstreamsHeading')) ?></h2>
        <?php if ($streams === [] && $unstreamed === []): ?>
            <p class="empty"><?= esc(lang('Events.plan.emptyPlan')) ?></p>
        <?php endif; ?>

        <?php foreach ($streams as $ws):
            $wsId = (string) ($ws['id'] ?? '');
            $wsTasks = (array) ($ws['tasks'] ?? []); ?>
            <div class="card">
                <div class="head">
                    <h3 style="margin:0"><?= esc((string) ($ws['name'] ?? '')) ?></h3>
                    <span class="pill <?= esc((string) ($ws['status'] ?? 'open'), 'attr') ?>"><?= esc(lang($wsKey((string) ($ws['status'] ?? 'open')))) ?></span>
                    <span class="bar" role="img" aria-label="<?= esc(lang('Events.plan.progressLbl')) ?>"><i style="width: <?= (int) ($ws['progress_pct'] ?? 0) ?>%"></i></span>
                    <strong><?= (int) ($ws['progress_pct'] ?? 0) ?>%</strong>
                    <span class="muted"><?= (int) ($ws['done_count'] ?? 0) ?>/<?= (int) ($ws['task_count'] ?? 0) ?></span>
                    <span class="muted"><?= esc($personName(isset($ws['owner_user_id']) ? (string) $ws['owner_user_id'] : null)) ?></span>
                    <?php if (! empty($ws['due_date'])): ?><span class="mono"><?= esc((string) $ws['due_date']) ?></span><?php endif; ?>
                </div>
                <?php if (! empty($ws['responsibility'])): ?>
                    <p class="mono" style="margin:6px 0 0"><?= esc(lang('Events.committee.resp' . ucfirst((string) $ws['responsibility']))) ?></p>
                <?php endif; ?>

                <?php if ($wsTasks === []): ?>
                    <p class="empty"><?= esc(lang('Events.plan.noTasks')) ?></p>
                <?php else: ?>
                    <table>
                        <thead><tr>
                            <th><?= esc(lang('Events.plan.colTask')) ?></th>
                            <th><?= esc(lang('Events.plan.colOwner')) ?></th>
                            <th><?= esc(lang('Events.plan.colDue')) ?></th>
                            <th><?= esc(lang('Events.plan.colPriority')) ?></th>
                            <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                            <th class="num"><?= esc(lang('Events.plan.colProgress')) ?></th>
                            <th><?= esc(lang('Events.plan.colWaitsOn')) ?></th>
                            <th><?= esc(lang('Events.plan.colActions')) ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($wsTasks as $t):
                                $tid = (string) ($t['id'] ?? '');
                                $preds = (array) ($t['predecessors'] ?? []); ?>
                                <tr>
                                    <td>
                                        <?= esc((string) ($t['title'] ?? '')) ?>
                                        <?php if (! empty($t['description'])): ?><div class="muted" style="font-size:.78rem"><?= esc((string) $t['description']) ?></div><?php endif; ?>
                                        <?php if (! empty($t['blocked_reason'])): ?><div class="mono"><?= esc((string) $t['blocked_reason']) ?></div><?php endif; ?>
                                        <details>
                                            <summary><?= esc(lang('Events.plan.saveTaskBtn')) ?></summary>
                                            <form method="post" action="<?= esc($taskUrl($tid), 'attr') ?>" class="grid" style="margin-top:8px">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                <div class="full"><label><?= esc(lang('Events.plan.titleLbl')) ?></label><input name="title" maxlength="200" value="<?= esc((string) ($t['title'] ?? ''), 'attr') ?>"></div>
                                                <div>
                                                    <label><?= esc(lang('Events.plan.assigneeLbl')) ?></label>
                                                    <select name="assignee_user_id">
                                                        <option value="">—</option>
                                                        <?php foreach ($roster as $u): ?>
                                                            <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"<?= (string) ($t['assignee_user_id'] ?? '') === (string) ($u['id'] ?? '') ? ' selected' : '' ?>><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div><label><?= esc(lang('Events.plan.dueLbl')) ?></label><input type="date" name="due_at" value="<?= esc((string) ($t['due_at'] ?? ''), 'attr') ?>"></div>
                                                <div>
                                                    <label><?= esc(lang('Events.plan.priorityLbl')) ?></label>
                                                    <select name="priority">
                                                        <?php foreach ($priorities as $p): ?>
                                                            <option value="<?= esc((string) $p, 'attr') ?>"<?= (string) ($t['priority'] ?? '') === (string) $p ? ' selected' : '' ?>><?= esc(lang($prioKey((string) $p))) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div><label><?= esc(lang('Events.plan.progressPctLbl')) ?></label><input type="number" min="0" max="100" name="progress_pct" value="<?= (int) ($t['progress_pct'] ?? 0) ?>"></div>
                                                <div><label><?= esc(lang('Events.plan.estimatedLbl')) ?></label><input type="number" step="0.25" min="0" name="estimated_hours" value="<?= esc((string) ($t['estimated_hours'] ?? ''), 'attr') ?>"></div>
                                                <div class="full"><label><?= esc(lang('Events.plan.blockReasonLbl')) ?></label><input name="blocked_reason" maxlength="500" value="<?= esc((string) ($t['blocked_reason'] ?? ''), 'attr') ?>"></div>
                                                <div class="full"><button type="submit" class="tiny"><?= esc(lang('Events.plan.saveTaskBtn')) ?></button></div>
                                            </form>
                                        </details>
                                    </td>
                                    <td><?= esc($personName(isset($t['assignee_user_id']) ? (string) $t['assignee_user_id'] : null)) ?></td>
                                    <td class="mono"><?= esc((string) ($t['due_at'] ?? lang('Events.plan.noDue'))) ?></td>
                                    <td><span class="pill <?= esc((string) ($t['priority'] ?? 'normal'), 'attr') ?>"><?= esc(lang($prioKey((string) ($t['priority'] ?? 'normal')))) ?></span></td>
                                    <td>
                                        <span class="pill <?= esc((string) ($t['status'] ?? 'todo'), 'attr') ?>"><?= esc(lang($statusKey((string) ($t['status'] ?? 'todo')))) ?></span>
                                        <span class="pill <?= esc((string) ($t['risk'] ?? 'ok'), 'attr') ?>"><?= esc(lang($riskKey((string) ($t['risk'] ?? 'ok')))) ?></span>
                                    </td>
                                    <td class="num"><?= (int) ($t['completion'] ?? 0) ?>%</td>
                                    <td>
                                        <?php if ($preds === []): ?>
                                            <span class="muted">—</span>
                                        <?php else: ?>
                                            <?php foreach ($preds as $p): ?>
                                                <div class="mono"><?= esc((string) ($p['title'] ?? $p['task_id'] ?? '')) ?> (<?= esc(lang($statusKey((string) ($p['status'] ?? 'todo')))) ?>)</div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        <details>
                                            <summary><?= esc(lang('Events.plan.addDepBtn')) ?></summary>
                                            <form method="post" action="<?= esc($taskUrl($tid, '/dependencies'), 'attr') ?>" style="margin-top:6px">
                                                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                                <select name="depends_on_task_id" required>
                                                    <option value="">—</option>
                                                    <?php foreach ($tasks as $other):
                                                        if ((string) ($other['id'] ?? '') === $tid) { continue; } ?>
                                                        <option value="<?= esc((string) $other['id'], 'attr') ?>"><?= esc((string) ($other['title'] ?? '')) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="dependency_type" style="margin-top:6px">
                                                    <?php foreach ($depTypes as $dt): ?>
                                                        <option value="<?= esc((string) $dt, 'attr') ?>"><?= esc(lang($depKey((string) $dt))) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="number" name="lag_days" value="0" min="-365" max="365" style="margin-top:6px" aria-label="<?= esc(lang('Events.plan.lagLbl'), 'attr') ?>">
                                                <button type="submit" class="tiny"><?= esc(lang('Events.plan.addDepBtn')) ?></button>
                                            </form>
                                        </details>
                                    </td>
                                    <td><?= $taskActions($t) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <details>
                    <summary><?= esc(lang('Events.plan.addTaskHeading')) ?> · <?= esc(lang('Events.plan.deleteWorkstreamBtn')) ?></summary>
                    <form method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/plan/tasks'), 'attr') ?>" class="grid" style="margin-top:8px">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <input type="hidden" name="workstream_id" value="<?= esc($wsId, 'attr') ?>">
                        <div class="full"><label><?= esc(lang('Events.plan.titleLbl')) ?></label><input name="title" maxlength="200" required></div>
                        <div>
                            <label><?= esc(lang('Events.plan.assigneeLbl')) ?></label>
                            <select name="assignee_user_id">
                                <option value="">—</option>
                                <?php foreach ($roster as $u): ?>
                                    <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label><?= esc(lang('Events.plan.dueLbl')) ?></label><input type="date" name="due_at"></div>
                        <div>
                            <label><?= esc(lang('Events.plan.priorityLbl')) ?></label>
                            <select name="priority">
                                <?php foreach ($priorities as $p): ?>
                                    <option value="<?= esc((string) $p, 'attr') ?>"><?= esc(lang($prioKey((string) $p))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label><?= esc(lang('Events.committee.responsibilityLbl')) ?></label>
                            <select name="responsibility">
                                <option value="">—</option>
                                <?php foreach ($responsibilities as $r): ?>
                                    <option value="<?= esc((string) $r, 'attr') ?>"><?= esc(lang('Events.committee.resp' . ucfirst((string) $r))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="full"><button type="submit" class="tiny"><?= esc(lang('Events.plan.addTaskBtn')) ?></button></div>
                    </form>
                    <form method="post" action="<?= esc($wsUrl($wsId), 'attr') ?>" class="grid" style="margin-top:10px">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <div class="full"><label><?= esc(lang('Events.plan.nameLbl')) ?></label><input name="name" maxlength="160" value="<?= esc((string) ($ws['name'] ?? ''), 'attr') ?>"></div>
                        <div>
                            <label><?= esc(lang('Events.plan.ownerLbl')) ?></label>
                            <select name="owner_user_id">
                                <option value="">—</option>
                                <?php foreach ($roster as $u): ?>
                                    <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"<?= (string) ($ws['owner_user_id'] ?? '') === (string) ($u['id'] ?? '') ? ' selected' : '' ?>><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label><?= esc(lang('Events.plan.dueLbl')) ?></label><input type="date" name="due_date" value="<?= esc((string) ($ws['due_date'] ?? ''), 'attr') ?>"></div>
                        <div><label><?= esc(lang('Events.plan.weightLbl')) ?></label><input type="number" min="1" max="100" name="weight" value="<?= (int) ($ws['weight'] ?? 1) ?>"></div>
                        <div class="full"><button type="submit" class="tiny"><?= esc(lang('Events.plan.saveTaskBtn')) ?></button></div>
                    </form>
                    <form method="post" action="<?= esc($wsUrl($wsId, '/delete'), 'attr') ?>" class="row" style="margin-top:10px">
                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                        <label class="row" style="margin:0"><input type="checkbox" name="reassign" value="1" style="width:auto"> <?= esc(lang('Events.plan.reassignLbl')) ?></label>
                        <button type="submit" class="ghost tiny danger"><?= esc(lang('Events.plan.deleteWorkstreamBtn')) ?></button>
                    </form>
                </details>
            </div>
        <?php endforeach; ?>

        <?php if ($unstreamed !== []): ?>
            <h2><?= esc(lang('Events.plan.unstreamedHeading')) ?></h2>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.plan.colTask')) ?></th>
                    <th><?= esc(lang('Events.plan.colOwner')) ?></th>
                    <th><?= esc(lang('Events.plan.colDue')) ?></th>
                    <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                    <th class="num"><?= esc(lang('Events.plan.colProgress')) ?></th>
                    <th><?= esc(lang('Events.plan.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($unstreamed as $t): ?>
                        <tr>
                            <td><?= esc((string) ($t['title'] ?? '')) ?></td>
                            <td><?= esc($personName(isset($t['assignee_user_id']) ? (string) $t['assignee_user_id'] : null)) ?></td>
                            <td class="mono"><?= esc((string) ($t['due_at'] ?? lang('Events.plan.noDue'))) ?></td>
                            <td><span class="pill <?= esc((string) ($t['status'] ?? 'todo'), 'attr') ?>"><?= esc(lang($statusKey((string) ($t['status'] ?? 'todo')))) ?></span></td>
                            <td class="num"><?= (int) ($t['completion'] ?? 0) ?>%</td>
                            <td><?= $taskActions($t) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Add workstream / add task / add milestone -->
        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">
            <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/plan/workstreams'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.plan.addWorkstreamHeading')) ?></h3>
                <div class="grid">
                    <div class="full"><label><?= esc(lang('Events.plan.nameLbl')) ?></label><input name="name" maxlength="160" required></div>
                    <div>
                        <label><?= esc(lang('Events.plan.ownerLbl')) ?></label>
                        <select name="owner_user_id">
                            <option value="">—</option>
                            <?php foreach ($roster as $u): ?>
                                <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?= esc(lang('Events.committee.responsibilityLbl')) ?></label>
                        <select name="responsibility">
                            <option value="">—</option>
                            <?php foreach ($responsibilities as $r): ?>
                                <option value="<?= esc((string) $r, 'attr') ?>"><?= esc(lang('Events.committee.resp' . ucfirst((string) $r))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label><?= esc(lang('Events.plan.startLbl')) ?></label><input type="date" name="start_date"></div>
                    <div><label><?= esc(lang('Events.plan.dueLbl')) ?></label><input type="date" name="due_date"></div>
                    <div class="full"><label><?= esc(lang('Events.plan.descriptionLbl')) ?></label><textarea name="description" rows="2"></textarea></div>
                </div>
                <button type="submit"><?= esc(lang('Events.plan.addWorkstreamBtn')) ?></button>
            </form>

            <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/plan/tasks'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.plan.addTaskHeading')) ?></h3>
                <div class="grid">
                    <div class="full"><label><?= esc(lang('Events.plan.titleLbl')) ?></label><input name="title" maxlength="200" required></div>
                    <div>
                        <label><?= esc(lang('Events.plan.workstreamLbl')) ?></label>
                        <select name="workstream_id">
                            <option value="">—</option>
                            <?php foreach ($streams as $ws): ?>
                                <option value="<?= esc((string) ($ws['id'] ?? ''), 'attr') ?>"><?= esc((string) ($ws['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?= esc(lang('Events.plan.assigneeLbl')) ?></label>
                        <select name="assignee_user_id">
                            <option value="">—</option>
                            <?php foreach ($roster as $u): ?>
                                <option value="<?= esc((string) ($u['id'] ?? ''), 'attr') ?>"><?= esc((string) ($u['display_name'] ?? $u['id'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label><?= esc(lang('Events.plan.dueLbl')) ?></label><input type="date" name="due_at"></div>
                    <div>
                        <label><?= esc(lang('Events.plan.priorityLbl')) ?></label>
                        <select name="priority">
                            <?php foreach ($priorities as $p): ?>
                                <option value="<?= esc((string) $p, 'attr') ?>"><?= esc(lang($prioKey((string) $p))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full"><label><?= esc(lang('Events.plan.descriptionLbl')) ?></label><textarea name="description" rows="2"></textarea></div>
                </div>
                <button type="submit"><?= esc(lang('Events.plan.addTaskBtn')) ?></button>
            </form>

            <form class="card" method="post" action="<?= esc(base_url('/events/' . $sidAttr . '/plan/milestones'), 'attr') ?>">
                <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                <h3><?= esc(lang('Events.plan.addMilestoneHeading')) ?></h3>
                <div class="grid">
                    <div class="full"><label><?= esc(lang('Events.plan.colMilestone')) ?></label><input name="title" maxlength="200" required></div>
                    <div><label><?= esc(lang('Events.plan.colDate')) ?></label><input type="date" name="due_at" required></div>
                    <div><label><?= esc(lang('Events.plan.weightLbl')) ?></label><input type="number" min="1" max="100" name="weight" value="1"></div>
                    <div class="full">
                        <label><?= esc(lang('Events.plan.workstreamLbl')) ?></label>
                        <select name="workstream_id">
                            <option value="">—</option>
                            <?php foreach ($streams as $ws): ?>
                                <option value="<?= esc((string) ($ws['id'] ?? ''), 'attr') ?>"><?= esc((string) ($ws['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit"><?= esc(lang('Events.plan.addMilestoneBtn')) ?></button>
            </form>
        </div>

        <!-- Board -->
        <h2><?= esc(lang('Events.plan.boardHeading')) ?></h2>
        <div class="board">
            <?php foreach (['todo', 'in_progress', 'blocked', 'done', 'cancelled'] as $col):
                $rows = (array) ($board[$col] ?? []);
                $labelKey = $col === 'todo' ? 'boardTodo' : ($col === 'in_progress' ? 'boardInProgress' : ($col === 'blocked' ? 'boardBlocked' : ($col === 'done' ? 'boardDone' : 'boardCancelled'))); ?>
                <div class="col">
                    <h4><span><?= esc(lang('Events.plan.' . $labelKey)) ?></span><span class="muted"><?= count($rows) ?></span></h4>
                    <?php if ($rows === []): ?>
                        <p class="empty"><?= esc(lang('Events.plan.noTasks')) ?></p>
                    <?php else: ?>
                        <ul>
                            <?php foreach ($rows as $t): ?>
                                <li>
                                    <?= esc((string) ($t['title'] ?? '')) ?>
                                    <div class="muted" style="font-size:.76rem">
                                        <?= esc($personName(isset($t['assignee_user_id']) ? (string) $t['assignee_user_id'] : null)) ?>
                                        <?php if (! empty($t['due_at'])): ?> · <span class="mono"><?= esc((string) $t['due_at']) ?></span><?php endif; ?>
                                        · <span class="pill <?= esc((string) ($t['risk'] ?? 'ok'), 'attr') ?>"><?= esc(lang($riskKey((string) ($t['risk'] ?? 'ok')))) ?></span>
                                    </div>
                                    <div style="margin-top:6px"><?= $taskActions($t) ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Milestones -->
        <h2><?= esc(lang('Events.plan.milestonesHeading')) ?></h2>
        <?php if ($milestones === []): ?>
            <p class="empty"><?= esc(lang('Events.plan.noMilestones')) ?></p>
        <?php else: ?>
            <table>
                <thead><tr>
                    <th><?= esc(lang('Events.plan.colMilestone')) ?></th>
                    <th><?= esc(lang('Events.plan.colDate')) ?></th>
                    <th><?= esc(lang('Events.plan.colStatus')) ?></th>
                    <th><?= esc(lang('Events.plan.evidenceLbl')) ?></th>
                    <th><?= esc(lang('Events.plan.colActions')) ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($milestones as $m):
                        $mid = (string) ($m['id'] ?? '');
                        $mStatus = (string) ($m['status'] ?? 'pending'); ?>
                        <tr>
                            <td><?= esc((string) ($m['title'] ?? '')) ?><?php if (! empty($m['description'])): ?><div class="muted" style="font-size:.78rem"><?= esc((string) $m['description']) ?></div><?php endif; ?></td>
                            <td class="mono"><?= esc((string) ($m['due_at'] ?? '')) ?></td>
                            <td><span class="pill <?= esc($mStatus, 'attr') ?>"><?= esc(lang($msKey($mStatus))) ?></span></td>
                            <td class="muted"><?= esc((string) ($m['evidence'] ?? '')) ?></td>
                            <td>
                                <?php if ($mStatus === 'pending'): ?>
                                    <form method="post" action="<?= esc($msUrl($mid, '/action'), 'attr') ?>" class="row">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="action" value="meet">
                                        <input type="text" name="evidence" maxlength="500" placeholder="<?= esc(lang('Events.plan.evidenceLbl'), 'attr') ?>" style="width:auto">
                                        <button type="submit" class="tiny"><?= esc(lang('Events.plan.meetBtn')) ?></button>
                                    </form>
                                    <form method="post" action="<?= esc($msUrl($mid, '/action'), 'attr') ?>" style="display:inline">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="action" value="miss">
                                        <button type="submit" class="ghost tiny danger"><?= esc(lang('Events.plan.missBtn')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= esc($msUrl($mid, '/action'), 'attr') ?>" style="display:inline">
                                        <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                        <input type="hidden" name="action" value="reopen">
                                        <button type="submit" class="ghost tiny"><?= esc(lang('Events.plan.milestoneReopenBtn')) ?></button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc($msUrl($mid, '/delete'), 'attr') ?>" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= esc($csrf, 'attr') ?>">
                                    <button type="submit" class="ghost tiny danger"><?= esc(lang('Events.plan.deleteBtn')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($today !== ''): ?><p class="mono"><?= esc($today) ?></p><?php endif; ?>
    </main>

<?php include __DIR__ . '/../../Shared/Views/_shell_close.php'; ?>
