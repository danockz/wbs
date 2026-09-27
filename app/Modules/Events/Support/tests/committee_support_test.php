<?php

declare(strict_types=1);

/**
 * EVENT COMMITTEE support-layer test — the pure vocabulary and arithmetic behind the
 * committee feature, with no database and no framework boot:
 *
 *   1. CommitteeResponsibility: the fixed lane vocabulary, the ONE existing permission
 *      bit each lane carries, and the two invariants that keep it honest — no lane may
 *      delegate an APPROVAL bit (segregation of duties survives the committee) and no
 *      lane may invent a bit outside the frozen 41-code catalogue.
 *   2. CommitteeOversight: the three configurable rungs, the decision kinds, which
 *      capability an approver must hold per kind, and the full requiresApproval matrix
 *      (including "no threshold configured ⇒ every budget decision is major").
 *   3. CommitteeConfig: default OFF, fail-closed parsing, clamping, bool coercion and
 *      an exact toArray/fromResolved round-trip.
 *   4. WorkPlan: derived progress (done ⇒ 100, in-flight clamped below it, cancelled
 *      excluded from every roll-up), late/at-risk detection, derived workstream status,
 *      milestone health, the DAG rules (cycle refused BEFORE the edge is written) and
 *      the topological order the plan renders in.
 *   5. Every i18n key the vocabulary hands to a view resolves in ALL SIX locales, so a
 *      lane or kind can never render as a raw key.
 *
 *   php app/Modules/Events/Support/tests/committee_support_test.php
 */

$root = dirname(__DIR__, 5);

require $root . '/app/Modules/Shared/Navigation/PermissionBits.php';
require $root . '/app/Modules/Events/Support/CommitteeOversight.php';
require $root . '/app/Modules/Events/Support/CommitteeResponsibility.php';
require $root . '/app/Modules/Events/Support/CommitteeConfig.php';
require $root . '/app/Modules/Events/Support/WorkPlan.php';

use WBS\Events\Support\CommitteeConfig;
use WBS\Events\Support\CommitteeOversight;
use WBS\Events\Support\CommitteeResponsibility;
use WBS\Events\Support\WorkPlan;
use WBS\Shared\Navigation\PermissionBits;

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}

/** Does a dotted key exist in a locale catalog? */
function hasKey(array $catalog, string $dotted): bool
{
    $v = $catalog;
    foreach (explode('.', $dotted) as $seg) {
        if (! is_array($v) || ! array_key_exists($seg, $v)) {
            return false;
        }
        $v = $v[$seg];
    }

    return is_string($v) && $v !== '';
}

$LOCALES = ['en', 'fr', 'es', 'pt', 'zh', 'ar'];
$catalogs = [];
foreach ($LOCALES as $loc) {
    $catalogs[$loc] = require $root . "/app/Modules/Events/Language/$loc/Events.php";
}
/** Assert a key resolves in every locale (never a raw key on screen). */
function allLocales(array $catalogs, string $dotted): string
{
    $missing = [];
    foreach ($catalogs as $loc => $c) {
        $key = str_starts_with($dotted, 'Events.') ? substr($dotted, strlen('Events.')) : $dotted;
        if (! hasKey($c, $key)) {
            $missing[] = $loc;
        }
    }

    return $missing === [] ? '' : 'missing in: ' . implode(',', $missing);
}

$bits = PermissionBits::all();

// ── 1. CommitteeResponsibility ────────────────────────────────────────────────
echo "responsibilities: vocabulary, delegation map, SoD + frozen budget\n";
$all = CommitteeResponsibility::ALL;
chk('eleven lanes', count($all) === 11, (string) count($all));
chk('lanes are unique', count(array_unique($all)) === count($all));
chk('lanes include chair + general', in_array('chair', $all, true) && in_array('general', $all, true));
chk('chairEligible() is every lane', CommitteeResponsibility::chairEligible() === $all);

chk('normalize trims + lowercases', CommitteeResponsibility::normalize('  FINANCE ') === 'finance');
chk('normalize unknown => general', CommitteeResponsibility::normalize('treasurer') === 'general');
chk('normalize null => general', CommitteeResponsibility::normalize(null) === 'general');
chk('isValid rejects unknown', CommitteeResponsibility::isValid('nope') === false);
chk('isValid accepts a lane', CommitteeResponsibility::isValid('logistics') === true);

$expectedPerms = [
    'chair'          => 'event.logistics.manage',
    'programme'      => 'event.logistics.manage',
    'logistics'      => 'event.logistics.manage',
    'finance'        => 'event.expense.submit',
    'communications' => 'notification.send',
    'registration'   => 'event.tickets.manage',
    'media'          => 'event.media.manage',
    'checkin'        => 'attendance.check_in',
    'certificates'   => 'event.certificate.manage',
    'feedback'       => 'event.feedback.manage',
    'general'        => null,
];
foreach ($expectedPerms as $lane => $perm) {
    chk("lane '$lane' carries " . ($perm ?? 'no bit'), CommitteeResponsibility::permissionFor($lane) === $perm,
        var_export(CommitteeResponsibility::permissionFor($lane), true));
}
chk('unknown lane carries no bit', CommitteeResponsibility::permissionFor('treasurer') === null);
chk('general does not delegate', CommitteeResponsibility::delegates('general') === false);
chk('finance does delegate', CommitteeResponsibility::delegates('finance') === true);

// The two invariants that keep the vocabulary from becoming a privilege ladder.
$approvalLeaks = [];
$unknownBits   = [];
foreach ($all as $lane) {
    $perm = CommitteeResponsibility::permissionFor($lane);
    if ($perm === null) {
        continue;
    }
    if (str_contains($perm, '.approve')) {
        $approvalLeaks[] = "$lane=>$perm";
    }
    if (! isset($bits[$perm])) {
        $unknownBits[] = "$lane=>$perm";
    }
}
chk('no lane delegates an APPROVAL bit (SoD)', $approvalLeaks === [], implode(',', $approvalLeaks));
chk('every delegated bit exists in the frozen catalogue', $unknownBits === [], implode(',', $unknownBits));
chk('permission budget still frozen at 41', PermissionBits::count() === 41, (string) PermissionBits::count());

foreach ($all as $lane) {
    $key = CommitteeResponsibility::labelKey($lane);
    chk("labelKey('$lane') resolves in all 6 locales", allLocales($catalogs, $key) === '', allLocales($catalogs, $key));
}
chk('labelKey shape', CommitteeResponsibility::labelKey('finance') === 'Events.committee.respFinance');

// ── 2. CommitteeOversight ─────────────────────────────────────────────────────
echo "\noversight: rungs, kinds, approver capability, approval matrix\n";
chk('three rungs', CommitteeOversight::ALL === ['formation_and_major', 'maker_checker_all', 'observe_only']);
chk('normalize unknown rung => formation_and_major', CommitteeOversight::normalize('nonsense') === 'formation_and_major');
chk('normalize is case/space tolerant', CommitteeOversight::normalize(' Observe_Only ') === 'observe_only');
chk('six decision kinds', count(CommitteeOversight::KINDS) === 6);
chk('normalizeKind unknown => other', CommitteeOversight::normalizeKind('vibes') === 'other');

$kindPerms = [
    'budget'       => 'event.expense.approve',
    'schedule'     => 'event.schedule.approve',
    'cancellation' => 'event.schedule.approve',
    'publication'  => 'event.schedule.approve',
    'governance'   => 'event.create',
    'other'        => 'event.create',
];
foreach ($kindPerms as $kind => $perm) {
    chk("approving '$kind' needs $perm", CommitteeOversight::permissionForKind($kind) === $perm,
        (string) CommitteeOversight::permissionForKind($kind));
    chk("'$perm' is a known bit", isset($bits[$perm]));
}
chk('unknown kind falls back to event.create', CommitteeOversight::permissionForKind('vibes') === 'event.create');

foreach (['schedule', 'cancellation', 'publication', 'governance'] as $major) {
    chk("'$major' is major", CommitteeOversight::isMajor($major) === true);
}
foreach (['budget', 'other'] as $minor) {
    chk("'$minor' is not major by kind alone", CommitteeOversight::isMajor($minor) === false);
}

// observe_only informs, never blocks.
chk('observe_only: budget above threshold needs no approval',
    CommitteeOversight::requiresApproval('observe_only', 'budget', 99999.0, 100.0) === false);
chk('observe_only: governance needs no approval',
    CommitteeOversight::requiresApproval('observe_only', 'governance') === false);
// maker_checker_all blocks everything.
chk('maker_checker_all: trivial other decision needs approval',
    CommitteeOversight::requiresApproval('maker_checker_all', 'other') === true);
chk('maker_checker_all: unamounted budget needs approval',
    CommitteeOversight::requiresApproval('maker_checker_all', 'budget', null, null) === true);
// formation_and_major (the default rung).
chk('default rung: schedule change needs approval',
    CommitteeOversight::requiresApproval('formation_and_major', 'schedule') === true);
chk('default rung: cancellation needs approval',
    CommitteeOversight::requiresApproval('formation_and_major', 'cancellation') === true);
chk('default rung: publication needs approval',
    CommitteeOversight::requiresApproval('formation_and_major', 'publication') === true);
chk('default rung: governance needs approval',
    CommitteeOversight::requiresApproval('formation_and_major', 'governance') === true);
chk('default rung: routine other decision does not',
    CommitteeOversight::requiresApproval('formation_and_major', 'other') === false);
chk('default rung: budget with no amount does not',
    CommitteeOversight::requiresApproval('formation_and_major', 'budget', null, 1000.0) === false);
chk('default rung: budget below threshold does not',
    CommitteeOversight::requiresApproval('formation_and_major', 'budget', 999.99, 1000.0) === false);
chk('default rung: budget AT threshold does (>=)',
    CommitteeOversight::requiresApproval('formation_and_major', 'budget', 1000.0, 1000.0) === true);
chk('default rung: budget above threshold does',
    CommitteeOversight::requiresApproval('formation_and_major', 'budget', 1500.0, 1000.0) === true);
chk('no threshold configured ⇒ EVERY budget decision is major',
    CommitteeOversight::requiresApproval('formation_and_major', 'budget', 1.0, null) === true);
chk('unknown rung is treated as the default',
    CommitteeOversight::requiresApproval('nonsense', 'schedule') === true);

foreach (CommitteeOversight::ALL as $rung) {
    $key = CommitteeOversight::labelKey($rung);
    chk("rung label '$rung' resolves in all 6 locales", allLocales($catalogs, $key) === '', allLocales($catalogs, $key));
}
foreach (CommitteeOversight::KINDS as $kind) {
    $key = CommitteeOversight::kindLabelKey($kind);
    chk("kind label '$kind' resolves in all 6 locales", allLocales($catalogs, $key) === '', $key . ' ' . allLocales($catalogs, $key));
}
chk('kindLabelKey points at the decision group', CommitteeOversight::kindLabelKey('budget') === 'Events.decision.kindBudget');
foreach (['pending', 'approved', 'rejected', 'cancelled', 'noted'] as $st) {
    $key = CommitteeOversight::statusLabelKey($st);
    chk("status label '$st' resolves in all 6 locales", allLocales($catalogs, $key) === '', $key . ' ' . allLocales($catalogs, $key));
}
chk('unknown status falls back to pending', CommitteeOversight::statusLabelKey('weird') === 'Events.decision.statusPending');

// ── 3. CommitteeConfig ────────────────────────────────────────────────────────
echo "\nconfig: default OFF, fail-closed parsing, clamping, round-trip\n";
chk('capability slug', CommitteeConfig::CAPABILITY === 'event_committee');
$off = CommitteeConfig::off();
chk('off() is disabled', $off->enabled === false);
chk('off() defaults: oversight', $off->oversight === 'formation_and_major');
chk('off() defaults: max members 12', $off->maxMembers === 12);
chk('off() defaults: chair needs approval', $off->chairRequiresApproval === true);
chk('off() defaults: sub-delegation allowed', $off->allowSubdelegation === true);
chk('off() defaults: grace 7 days', $off->graceDays === 7);
chk('off() defaults: no budget threshold', $off->budgetApprovalThreshold === null);
chk('off() defaults: no cross-cut', $off->allowCrosscut === false);

chk('null config fails closed', CommitteeConfig::fromResolved(null)->enabled === false);
chk('unparseable JSON fails closed', CommitteeConfig::fromResolved('{"enabled":')->enabled === false);
chk('empty array is off', CommitteeConfig::fromResolved([])->enabled === false);
chk('enabled=false ignores everything else the row says',
    CommitteeConfig::fromResolved(['enabled' => false, 'max_members' => 40, 'oversight' => 'observe_only'])->toArray() === $off->toArray());
chk('JSON string input parses', CommitteeConfig::fromResolved('{"enabled":true,"max_members":5}')->maxMembers === 5);

$on = CommitteeConfig::fromResolved(['enabled' => true]);
chk('enabled with defaults keeps 12 members', $on->maxMembers === 12);
chk('enabled with defaults keeps formation_and_major', $on->oversight === 'formation_and_major');
chk('max_members clamps low to 2', CommitteeConfig::fromResolved(['enabled' => true, 'max_members' => 1])->maxMembers === 2);
chk('max_members clamps high to 50', CommitteeConfig::fromResolved(['enabled' => true, 'max_members' => 999])->maxMembers === 50);
chk('max_members non-numeric falls back to 12', CommitteeConfig::fromResolved(['enabled' => true, 'max_members' => 'many'])->maxMembers === 12);
chk('grace_days clamps negative to 0', CommitteeConfig::fromResolved(['enabled' => true, 'grace_days' => -5])->graceDays === 0);
chk('grace_days clamps high to 90', CommitteeConfig::fromResolved(['enabled' => true, 'grace_days' => 500])->graceDays === 90);
chk('grace_days floors fractions', CommitteeConfig::fromResolved(['enabled' => true, 'grace_days' => 7.9])->graceDays === 7);
chk('oversight is normalized + lowercased',
    CommitteeConfig::fromResolved(['enabled' => true, 'oversight' => 'OBSERVE_ONLY'])->oversight === 'observe_only');
chk('unknown oversight falls back to the default rung',
    CommitteeConfig::fromResolved(['enabled' => true, 'oversight' => 'yolo'])->oversight === 'formation_and_major');
chk('threshold parses a numeric string',
    CommitteeConfig::fromResolved(['enabled' => true, 'budget_approval_threshold' => '1500'])->budgetApprovalThreshold === 1500.0);
chk('negative threshold is dropped', CommitteeConfig::fromResolved(['enabled' => true, 'budget_approval_threshold' => -5])->budgetApprovalThreshold === null);
chk('non-numeric threshold is dropped', CommitteeConfig::fromResolved(['enabled' => true, 'budget_approval_threshold' => 'lots'])->budgetApprovalThreshold === null);
chk('zero threshold is kept (every budget decision major)',
    CommitteeConfig::fromResolved(['enabled' => true, 'budget_approval_threshold' => 0])->budgetApprovalThreshold === 0.0);
chk('bool coercion: "yes"', CommitteeConfig::fromResolved(['enabled' => 'yes'])->enabled === true);
chk('bool coercion: "on"', CommitteeConfig::fromResolved(['enabled' => 'on'])->enabled === true);
chk('bool coercion: 1', CommitteeConfig::fromResolved(['enabled' => 1])->enabled === true);
chk('bool coercion: "off" is false', CommitteeConfig::fromResolved(['enabled' => 'off'])->enabled === false);
chk('bool coercion: "" falls back to the default', CommitteeConfig::fromResolved(['enabled' => ''])->enabled === false);
chk('allow_crosscut defaults false', CommitteeConfig::fromResolved(['enabled' => true])->allowCrosscut === false);
chk('allow_crosscut opt-in', CommitteeConfig::fromResolved(['enabled' => true, 'allow_crosscut' => true])->allowCrosscut === true);

$rich = CommitteeConfig::fromResolved([
    'enabled' => true, 'oversight' => 'maker_checker_all', 'max_members' => 20,
    'chair_requires_approval' => false, 'allow_subdelegation' => false, 'grace_days' => 3,
    'budget_approval_threshold' => 250.5, 'allow_crosscut' => true,
]);
chk('toArray/fromResolved round-trips exactly', CommitteeConfig::fromResolved($rich->toArray())->toArray() === $rich->toArray());
chk('toArray carries all eight keys', count($rich->toArray()) === 8);

// ── 4. WorkPlan: statuses, transitions, derived progress ──────────────────────
echo "\nwork plan: transitions, completion, risk\n";
chk('normalizeStatus unknown => todo', WorkPlan::normalizeStatus('wibble') === 'todo');
chk('normalizeStatus null => todo', WorkPlan::normalizeStatus(null) === 'todo');
chk('normalizePriority unknown => normal', WorkPlan::normalizePriority('whenever') === 'normal');
chk('normalizeDependencyType unknown => finish_to_start', WorkPlan::normalizeDependencyType('x') === 'finish_to_start');
chk('normalizeMilestoneStatus unknown => pending', WorkPlan::normalizeMilestoneStatus('x') === 'pending');

chk('todo -> in_progress allowed', WorkPlan::canTransition('todo', 'in_progress') === true);
chk('todo -> done allowed', WorkPlan::canTransition('todo', 'done') === true);
chk('todo -> cancelled allowed', WorkPlan::canTransition('todo', 'cancelled') === true);
chk('blocked -> done refused (unblock first)', WorkPlan::canTransition('blocked', 'done') === false);
chk('blocked -> in_progress allowed', WorkPlan::canTransition('blocked', 'in_progress') === true);
chk('done -> todo allowed (reopen)', WorkPlan::canTransition('done', 'todo') === true);
chk('done -> cancelled refused', WorkPlan::canTransition('done', 'cancelled') === false);
chk('cancelled -> todo allowed (reopen)', WorkPlan::canTransition('cancelled', 'todo') === true);
chk('cancelled -> done refused', WorkPlan::canTransition('cancelled', 'done') === false);
chk('same status is a no-op and allowed', WorkPlan::canTransition('blocked', 'blocked') === true);

chk('urgent outranks high', WorkPlan::priorityWeight('urgent') > WorkPlan::priorityWeight('high'));
chk('high outranks normal', WorkPlan::priorityWeight('high') > WorkPlan::priorityWeight('normal'));
chk('normal outranks low', WorkPlan::priorityWeight('normal') > WorkPlan::priorityWeight('low'));
chk('unknown priority sorts as normal', WorkPlan::priorityWeight('whenever') === WorkPlan::priorityWeight('normal'));

chk('done is 100% whatever it stored', WorkPlan::completionOf(['status' => 'done', 'progress_pct' => 10]) === 100);
chk('in-flight percentage is clamped below done', WorkPlan::completionOf(['status' => 'in_progress', 'progress_pct' => 150]) === 99);
chk('negative percentage clamps to 0', WorkPlan::completionOf(['status' => 'in_progress', 'progress_pct' => -20]) === 0);
chk('todo with no percentage is 0', WorkPlan::completionOf(['status' => 'todo']) === 0);
chk('cancelled contributes nothing', WorkPlan::completionOf(['status' => 'cancelled', 'progress_pct' => 80]) === 0);
chk('blocked work still counts its percentage', WorkPlan::completionOf(['status' => 'blocked', 'progress_pct' => 40]) === 40);

$today = '2026-09-21';
chk('done task is never at risk', WorkPlan::riskOf(['status' => 'done', 'due_at' => '2026-01-01'], $today) === 'ok');
chk('cancelled task is never at risk', WorkPlan::riskOf(['status' => 'cancelled', 'due_at' => '2026-01-01'], $today) === 'ok');
chk('blocked outranks lateness', WorkPlan::riskOf(['status' => 'blocked', 'due_at' => '2026-01-01'], $today) === 'blocked');
chk('past due is late', WorkPlan::riskOf(['status' => 'todo', 'due_at' => '2026-09-20'], $today) === 'late');
chk('due today is due soon', WorkPlan::riskOf(['status' => 'todo', 'due_at' => $today], $today) === 'due_soon');
chk('due inside the window is due soon', WorkPlan::riskOf(['status' => 'in_progress', 'due_at' => '2026-09-23'], $today, 3) === 'due_soon');
chk('due outside the window is ok', WorkPlan::riskOf(['status' => 'todo', 'due_at' => '2026-10-20'], $today, 3) === 'ok');
chk('a zero-day window has no due-soon band', WorkPlan::riskOf(['status' => 'todo', 'due_at' => '2026-09-23'], $today, 0) === 'ok');
chk('undated open work is ok (not late)', WorkPlan::riskOf(['status' => 'todo'], $today) === 'ok');
chk('datetime strings are compared by date', WorkPlan::riskOf(['status' => 'todo', 'due_at' => '2026-09-20 23:59:59'], $today) === 'late');

// ── 5. WorkPlan: roll-ups ─────────────────────────────────────────────────────
echo "\nwork plan: roll-up, workstreams, milestones, plan health\n";
$tasks = [
    ['id' => 't1', 'status' => 'done', 'progress_pct' => 10, 'assignee_user_id' => 'u1'],
    ['id' => 't2', 'status' => 'in_progress', 'progress_pct' => 50, 'assignee_user_id' => 'u2', 'due_at' => '2026-09-22'],
    ['id' => 't3', 'status' => 'todo', 'due_at' => '2026-09-01'],                       // late + unassigned
    ['id' => 't4', 'status' => 'blocked', 'assignee_user_id' => 'u3', 'blocked_reason' => 'venue'],
    ['id' => 't5', 'status' => 'cancelled', 'progress_pct' => 90, 'due_at' => '2026-09-01'],
];
$r = WorkPlan::rollUp($tasks, $today, 3);
chk('cancelled tasks leave the roll-up', $r['total'] === 4 && $r['cancelled'] === 1, json_encode($r));
chk('done counted', $r['done'] === 1);
chk('open = total - done', $r['open'] === 3);
chk('blocked counted', $r['blocked'] === 1);
chk('late counted', $r['late'] === 1);
chk('due soon counted', $r['due_soon'] === 1);
chk('unassigned counts open work only', $r['unassigned'] === 1, (string) $r['unassigned']);
chk('progress is the mean completion, floored', $r['progress_pct'] === 37, (string) $r['progress_pct']); // (100+50+0+0)/4
chk('a done task never counts as unassigned', WorkPlan::rollUp([['status' => 'done']], $today)['unassigned'] === 0);
chk('empty plan is 0%', WorkPlan::rollUp([], $today)['progress_pct'] === 0);

$streams = WorkPlan::byWorkstream(
    [
        ['id' => 'w1', 'name' => 'Venue', 'weight' => 3, 'owner_user_id' => 'u1'],
        ['id' => 'w2', 'name' => 'Comms', 'weight' => 1, 'status' => 'done'],
        ['id' => 'w3', 'name' => 'Empty', 'weight' => 0],
    ],
    [
        ['id' => 'a', 'workstream_id' => 'w1', 'status' => 'done'],
        ['id' => 'b', 'workstream_id' => 'w1', 'status' => 'blocked'],
        ['id' => 'c', 'workstream_id' => 'w2', 'status' => 'todo', 'due_at' => '2026-12-01'],
        ['id' => 'd', 'status' => 'todo'],
    ],
    $today,
);
$byName = [];
foreach ($streams['streams'] as $s) {
    $byName[$s['name']] = $s;
}
chk('blocked work makes the stream blocked', $byName['Venue']['status'] === 'blocked', (string) $byName['Venue']['status']);
chk('a stored "done" with open tasks is not done', $byName['Comms']['status'] !== 'done', (string) $byName['Comms']['status']);
chk('an empty stream reads on track', $byName['Empty']['status'] === 'on_track', (string) $byName['Empty']['status']);
chk('weight is clamped to at least 1', $byName['Empty']['weight'] === 1);
chk('stream counts its own tasks', $byName['Venue']['task_count'] === 2 && $byName['Venue']['done_count'] === 1);
chk('stream progress is derived', $byName['Venue']['progress_pct'] === 50);
chk('plan progress is weight-aware', $streams['progress_pct'] === 30, (string) $streams['progress_pct']); // (50*3 + 0*1 + 0*1) / (3+1+1)
chk('loose tasks count when there are no streams',
    WorkPlan::byWorkstream([], [['id' => 'x', 'status' => 'done']], $today)['progress_pct'] === 100);

$allDone = WorkPlan::byWorkstream([['id' => 'w', 'name' => 'W']], [['id' => 'a', 'workstream_id' => 'w', 'status' => 'done'], ['id' => 'b', 'workstream_id' => 'w', 'status' => 'done']], $today);
chk('every task done ⇒ stream done', $allDone['streams'][0]['status'] === 'done');
$lateStream = WorkPlan::byWorkstream([['id' => 'w', 'name' => 'W']], [['id' => 'a', 'workstream_id' => 'w', 'status' => 'todo', 'due_at' => '2026-09-01']], $today);
chk('a late task puts the stream at risk', $lateStream['streams'][0]['status'] === 'at_risk');
$soonStream = WorkPlan::byWorkstream([['id' => 'w', 'name' => 'W']], [['id' => 'a', 'workstream_id' => 'w', 'status' => 'todo', 'due_at' => '2026-09-22']], $today);
chk('a near deadline puts the stream at risk', $soonStream['streams'][0]['status'] === 'at_risk');

$ms = WorkPlan::milestoneRollUp([
    ['id' => 'm1', 'status' => 'met', 'weight' => 2, 'due_at' => '2026-09-01'],
    ['id' => 'm2', 'status' => 'pending', 'weight' => 1, 'due_at' => '2026-09-10'],   // overdue
    ['id' => 'm3', 'status' => 'pending', 'weight' => 1, 'due_at' => '2026-10-10'],
    ['id' => 'm4', 'status' => 'missed', 'weight' => 1],
    ['id' => 'm5', 'status' => 'cancelled', 'weight' => 9],
], $today);
chk('cancelled milestones leave the roll-up', $ms['total'] === 4 && $ms['cancelled'] === 1, json_encode($ms));
chk('met counted', $ms['met'] === 1);
chk('missed counted', $ms['missed'] === 1);
chk('pending counted', $ms['pending'] === 2);
chk('overdue counts pending milestones past their date', $ms['overdue'] === 1);
chk('milestone progress is weight-aware', $ms['progress_pct'] === 40, (string) $ms['progress_pct']); // 200/5
chk('next_due is the earliest PENDING date', $ms['next_due'] === '2026-09-10', var_export($ms['next_due'], true));

$plan = WorkPlan::planProgress([['status' => 'done'], ['status' => 'done']], [], $today);
chk('no milestones ⇒ task-only progress', $plan['progress_pct'] === 100);
chk('all done with no milestones ⇒ complete', $plan['health'] === 'complete', (string) $plan['health']);
$blended = WorkPlan::planProgress([['status' => 'done'], ['status' => 'todo']], [['status' => 'met'], ['status' => 'pending', 'due_at' => '2026-12-01']], $today);
chk('tasks and milestones blend 70/30', $blended['progress_pct'] === 50, (string) $blended['progress_pct']); // floor(50*.7 + 50*.3)
chk('blocked work makes the plan blocked',
    WorkPlan::planProgress([['status' => 'blocked'], ['status' => 'done']], [], $today)['health'] === 'blocked');
chk('late work makes the plan behind',
    WorkPlan::planProgress([['status' => 'todo', 'due_at' => '2026-09-01']], [], $today)['health'] === 'behind');
chk('a missed milestone makes the plan behind',
    WorkPlan::planProgress([['status' => 'done']], [['status' => 'missed']], $today)['health'] === 'behind');
chk('due-soon work makes the plan at risk',
    WorkPlan::planProgress([['status' => 'todo', 'due_at' => '2026-09-22']], [], $today)['health'] === 'at_risk');
chk('unassigned work makes the plan at risk',
    WorkPlan::planProgress([['status' => 'todo', 'due_at' => '2026-12-01']], [], $today)['health'] === 'at_risk');
chk('quiet plan is on track',
    WorkPlan::planProgress([['status' => 'todo', 'due_at' => '2026-12-01', 'assignee_user_id' => 'u1']], [], $today)['health'] === 'on_track');
chk('all tasks done but a milestone pending is not complete',
    WorkPlan::planProgress([['status' => 'done']], [['status' => 'pending', 'due_at' => '2026-12-01']], $today)['health'] !== 'complete');

// ── 6. WorkPlan: the dependency graph ─────────────────────────────────────────
echo "\nwork plan: DAG rules + topological order\n";
chk('a self-dependency is the smallest cycle', WorkPlan::wouldCreateCycle([], 't1', 't1') === true);
chk('empty ids are refused', WorkPlan::wouldCreateCycle([], '', 't2') === true);
chk('a first edge is fine', WorkPlan::wouldCreateCycle([], 't2', 't1') === false);
$edges = [['task_id' => 't2', 'depends_on_task_id' => 't1']];
chk('the reverse of an existing edge is a cycle', WorkPlan::wouldCreateCycle($edges, 't1', 't2') === true);
$chain = [['task_id' => 't2', 'depends_on_task_id' => 't1'], ['task_id' => 't3', 'depends_on_task_id' => 't2']];
chk('a transitive cycle is caught', WorkPlan::wouldCreateCycle($chain, 't1', 't3') === true);
chk('an unrelated edge is allowed', WorkPlan::wouldCreateCycle($chain, 't4', 't1') === false);
chk('a diamond is allowed', WorkPlan::wouldCreateCycle(
    [['task_id' => 't3', 'depends_on_task_id' => 't1'], ['task_id' => 't3', 'depends_on_task_id' => 't2']],
    't4',
    't3',
) === false);

$order = WorkPlan::topoSort(['t3', 't1', 't2'], $chain);
chk('predecessors come first', array_search('t1', $order, true) < array_search('t2', $order, true)
    && array_search('t2', $order, true) < array_search('t3', $order, true), implode(',', $order));
chk('every id appears exactly once', count($order) === 3 && count(array_unique($order)) === 3);
$cyclic = WorkPlan::topoSort(['t1', 't2'], [['task_id' => 't1', 'depends_on_task_id' => 't2'], ['task_id' => 't2', 'depends_on_task_id' => 't1']]);
chk('a cycle still yields every id (nothing is dropped)', count($cyclic) === 2 && count(array_unique($cyclic)) === 2, implode(',', $cyclic));
chk('edges naming unknown tasks are ignored',
    WorkPlan::topoSort(['t1'], [['task_id' => 't9', 'depends_on_task_id' => 't1']]) === ['t1']);
chk('no edges keeps the given order', WorkPlan::topoSort(['b', 'a'], []) === ['b', 'a']);

$byId = [
    'p1' => ['id' => 'p1', 'title' => 'Book venue', 'status' => 'in_progress'],
    'p2' => ['id' => 'p2', 'title' => 'Pay deposit', 'status' => 'done'],
    'p3' => ['id' => 'p3', 'title' => 'Cancelled thing', 'status' => 'cancelled'],
    'p4' => ['id' => 'p4', 'title' => 'Not started', 'status' => 'todo'],
];
$gates = [
    ['task_id' => 'x', 'depends_on_task_id' => 'p1', 'dependency_type' => 'finish_to_start'],
    ['task_id' => 'x', 'depends_on_task_id' => 'p2', 'dependency_type' => 'finish_to_start'],
    ['task_id' => 'x', 'depends_on_task_id' => 'p3', 'dependency_type' => 'finish_to_start'],
    ['task_id' => 'x', 'depends_on_task_id' => 'p4', 'dependency_type' => 'start_to_start'],
];
$open = WorkPlan::openPredecessors('x', $gates, $byId);
$openTitles = array_map(static fn (array $p): string => (string) $p['title'], $open);
chk('an unfinished predecessor gates', in_array('Book venue', $openTitles, true), implode('|', $openTitles));
chk('a finished predecessor gates nothing', ! in_array('Pay deposit', $openTitles, true));
chk('a cancelled predecessor gates nothing', ! in_array('Cancelled thing', $openTitles, true));
$ss = WorkPlan::openPredecessors('x', [['task_id' => 'x', 'depends_on_task_id' => 'p1', 'dependency_type' => 'start_to_start']], $byId);
chk('start-to-start does not gate an already-started predecessor', $ss === [], json_encode($ss));
$ssTodo = WorkPlan::openPredecessors('x', [['task_id' => 'x', 'depends_on_task_id' => 'p4', 'dependency_type' => 'start_to_start']], $byId);
chk('start-to-start gates a predecessor that has not started', count($ssTodo) === 1);
chk('predecessor rows carry the type + lag', ($open[0]['dependency_type'] ?? '') === 'finish_to_start' && array_key_exists('lag_days', $open[0]));
chk('another task sees no predecessors', WorkPlan::openPredecessors('y', $gates, $byId) === []);

// ── 7. WorkPlan: dates ────────────────────────────────────────────────────────
echo "\nwork plan: date handling\n";
chk('null date is null', WorkPlan::dateOf(null) === null);
chk('empty string is null', WorkPlan::dateOf('') === null);
chk('datetime string is reduced to a day', WorkPlan::dateOf('2026-09-21 10:30:00') === '2026-09-21');
chk('a plain date passes through', WorkPlan::dateOf('2026-09-21') === '2026-09-21');
chk('DateTimeInterface is formatted', WorkPlan::dateOf(new DateTimeImmutable('2026-09-21 23:59:59')) === '2026-09-21');
chk('garbage is null, never today', WorkPlan::dateOf('next tuesday') === null);

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
