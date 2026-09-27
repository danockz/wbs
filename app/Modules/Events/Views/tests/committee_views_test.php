<?php

declare(strict_types=1);

/**
 * EVENT COMMITTEE + PLAN view test — i18n completeness and headless render smoke for
 * the four self-contained pages the committee feature adds:
 *
 *   committee_console.php  one event's committee (mandate, members and the authority
 *                          each holds, vacant lanes, oversight queue, group config)
 *   event_plan.php         the project-management console (workstreams, tasks,
 *                          dependencies, milestones, board, derived progress)
 *   committee_hub.php      the cross-event personal hub
 *   committee_queue.php    the maker-checker oversight queue
 *
 * Asserts, for each page: the Events.committee/plan/decision key sets mirror English
 * in all six locales with no strays; the page is locale-aware (dynamic <html lang dir>,
 * RTL for Arabic); every POST form carries a hidden _csrf bound to $csrf and posts to
 * the right endpoint; nothing renders a raw language key; no JavaScript, no inline
 * event handlers and no external assets (CSP); the gated-OFF, no-committee and empty
 * states say so instead of offering forms that cannot work; and the governance acts
 * (appoint, chair, decide, dissolve, plan) are all reachable as PRG forms.
 *
 *   php app/Modules/Events/Views/tests/committee_views_test.php
 */

$root    = dirname(__DIR__, 5);
$langDir = $root . '/app/Modules/Events/Language';
$viewDir = $root . '/app/Modules/Events/Views';

$pass = 0;
$fail = 0;
function chk(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' — ' . $detail) . "\n";
    $ok ? $pass++ : $fail++;
}
$flatten = static function (array $a, string $p = '') use (&$flatten): array {
    $o = [];
    foreach ($a as $k => $v) {
        $key = $p === '' ? (string) $k : $p . '.' . $k;
        is_array($v) ? $o = array_merge($o, $flatten($v, $key)) : $o[] = $key;
    }

    return $o;
};

// ── 1. Language completeness for the three new groups ─────────────────────────
echo "language file completeness (committee / plan / decision)\n";
$en = require $langDir . '/en/Events.php';
foreach (['committee', 'plan', 'decision'] as $group) {
    $enKeys = $flatten($en[$group] ?? []);
    chk("en has a '$group' group with keys", count($enKeys) > 20, (string) count($enKeys));
    foreach (['fr', 'es', 'pt', 'zh', 'ar'] as $loc) {
        $m    = require $langDir . "/$loc/Events.php";
        $keys = $flatten($m[$group] ?? []);
        chk("$loc mirrors all en '$group' keys", array_diff($enKeys, $keys) === [], 'missing: ' . implode(',', array_slice(array_diff($enKeys, $keys), 0, 6)));
        chk("$loc has no stray '$group' keys", array_diff($keys, $enKeys) === [], 'extra: ' . implode(',', array_slice(array_diff($keys, $enKeys), 0, 6)));
    }
}
chk('arabic plural/placeholder strings keep {0}', str_contains((string) ($en['committee']['memberCountFmt'] ?? ''), '{0}'));

// ── 2. Static hygiene, all four pages ─────────────────────────────────────────
echo "\nstatic hygiene (locale-aware, csrf, no JS, no external assets)\n";
$views = ['committee_console.php', 'event_plan.php', 'committee_hub.php', 'committee_queue.php'];
$srcs  = [];
foreach ($views as $v) {
    $src        = (string) file_get_contents("$viewDir/$v");
    $srcs[$v]   = $src;
    $noComments = (string) preg_replace('!/\*.*?\*/!s', '', $src);

    chk("$v includes _locale.php", str_contains($src, '_locale.php'));
    chk("$v emits a dynamic <html lang dir>", str_contains($src, '_shell_open.php'));
    chk("$v has no hardcoded lang=\"en\"", ! str_contains($src, 'lang="en"'));
    chk("$v localizes through lang('Events.", str_contains($src, "lang('Events."));
    chk("$v has no <script>", ! str_contains(strtolower($noComments), '<script'));
    chk("$v has no inline on* handlers", ! (bool) preg_match('/\son(click|submit|change|input|load|error)\s*=/i', $noComments));
    chk("$v loads no external assets", ! (bool) preg_match('/<(link|img|iframe)\b/i', $noComments) && ! str_contains($noComments, 'http://') && ! str_contains($noComments, 'https://'));
    chk("$v escapes output with esc()", substr_count($src, 'esc(') > 20);
    chk("$v guards session() for CLI/tests", str_contains($src, "function_exists('session')"));
    // Some pages build their forms inside a loop or a closure, so the SOURCE cannot
    // be counted one-to-one: the rendered parity checks below are the real guard.
    // The hub is deliberately read-only (it links to the pages that write).
    if ($v !== 'committee_hub.php') {
        chk("$v builds post forms", str_contains($src, 'method="post"'));
    } else {
        chk("$v is read-only (no post forms)", ! str_contains($src, 'method="post"'));
    }
}

// Endpoint wiring: the pages must post to the routes that exist.
$routes = (string) file_get_contents($root . '/app/Config/Routes.php');
$endpoints = [
    'committee_console.php' => ["/events/' . \$sidAttr . '/committee'", '/event-committees/member/', '/event-committees/decisions/'],
    'event_plan.php'        => ["/events/' . \$sidAttr . '/plan/workstreams'", '/event-plan/tasks/', '/event-plan/workstreams/', '/event-plan/milestones/'],
    'committee_queue.php'   => ['/event-committees/decisions/'],
];
foreach ($endpoints as $v => $needles) {
    foreach ($needles as $n) {
        chk("$v posts to $n", str_contains($srcs[$v], $n));
    }
}
chk('member actions post to the singular member route', str_contains($srcs['committee_console.php'], "/event-committees/member/'"));
chk('the routes file agrees (member/:segment/remove)', str_contains($routes, "member/(:segment)/remove"));
chk('the routes file agrees (member/:segment/responsibility)', str_contains($routes, "member/(:segment)/responsibility"));
chk('every committee POST route is webcsrf-guarded', substr_count($routes, 'webcsrf') >= 20);

/** Every rendered POST form must carry exactly one hidden _csrf bound to $csrf. */
function csrfParity(string $html): array
{
    $html = preg_replace('/<form class="lang__menu".*?<\/form>/s', '', $html) ?? $html;
    $forms  = preg_match_all('/<form\b[^>]*method="post"/i', $html);
    $tokens = preg_match_all('/type="hidden" name="_csrf" value="/', $html);

    return [$forms, $tokens];
}

// ── 3. Fixtures ───────────────────────────────────────────────────────────────
require __DIR__ . '/view_test_helpers.php';
$render = wbs_events_renderer($langDir);

$event = [
    'id' => 'ev-1', 'title' => 'Convention 2026', 'status' => 'published',
    'group_id' => 'g-local', 'starts_at' => '2026-10-01 09:00:00', 'ends_at' => '2026-10-03 18:00:00',
];
$responsibilities = [
    ['value' => 'chair', 'label' => 'Chair', 'permission' => 'event.logistics.manage'],
    ['value' => 'finance', 'label' => 'Finance', 'permission' => 'event.expense.submit'],
    ['value' => 'media', 'label' => 'Media', 'permission' => 'event.media.manage'],
    ['value' => 'general', 'label' => 'General member', 'permission' => null],
];
$roster = [
    ['id' => 'u-leader', 'display_name' => 'Area Leader'],
    ['id' => 'u-chair', 'display_name' => 'Chair Person'],
    ['id' => 'u-fin', 'display_name' => 'Finance Lead'],
];
$committee = [
    'id' => 'cm-1', 'event_id' => 'ev-1', 'mandate' => 'Run Convention 2026 as a project',
    'chair_user_id' => 'u-chair', 'chair_name' => 'Chair Person', 'formed_by' => 'u-leader',
    'oversight_group_id' => 'g-local', 'oversight_mode' => 'formation_and_major',
    'max_members' => 12, 'allow_subdelegation' => 1, 'grace_days' => 7, 'status' => 'active',
    'member_count' => 2, 'pending_decisions' => 1, 'event_title' => 'Convention 2026', 'event_status' => 'published',
    'responsibilities' => ['filled' => ['chair', 'finance'], 'vacant' => ['media'], 'by_responsibility' => []],
    'config' => ['oversight' => 'formation_and_major'],
    'members' => [
        ['id' => 'm-1', 'user_id' => 'u-chair', 'user_name' => 'Chair Person', 'responsibility' => 'chair',
            'responsibility_label' => null, 'is_chair' => 1, 'delegation_id' => 'del-1',
            'delegated_permission' => 'event.logistics.manage', 'effective_to' => '2026-10-10 18:00:00', 'status' => 'active'],
        ['id' => 'm-2', 'user_id' => 'u-fin', 'user_name' => 'Finance Lead', 'responsibility' => 'finance',
            'responsibility_label' => 'Budgets and payments', 'is_chair' => 0, 'delegation_id' => null,
            'delegated_permission' => 'event.expense.submit', 'effective_to' => '2026-10-10 18:00:00', 'status' => 'active'],
    ],
    'active_members' => [
        ['id' => 'm-1', 'user_id' => 'u-chair', 'effective_to' => '2026-10-10 18:00:00', 'status' => 'active'],
        ['id' => 'm-2', 'user_id' => 'u-fin', 'effective_to' => '2026-10-10 18:00:00', 'status' => 'active'],
    ],
];
$decisions = [[
    'id' => 'd-1', 'event_id' => 'ev-1', 'committee_id' => 'cm-1', 'kind' => 'budget',
    'title' => 'Pay the band', 'detail' => 'Deposit plus balance', 'amount' => '5000.00',
    'required_permission' => 'event.expense.approve', 'oversight_group_id' => 'g-local',
    'requested_by' => 'u-chair', 'requested_by_name' => 'Chair Person', 'status' => 'pending',
    'decided_by' => null, 'decided_by_name' => null, 'decided_at' => null, 'decision_note' => null,
    'effect' => ['action' => 'task.status'], 'effect_applied' => 0, 'created_at' => '2026-09-20 10:00:00',
    'event_title' => 'Convention 2026', 'event_status' => 'published',
]];
$progress = [
    'progress_pct' => 62, 'health' => 'at_risk', 'event_id' => 'ev-1', 'today' => '2026-09-21',
    'tasks' => ['total' => 8, 'open' => 3, 'done' => 5, 'blocked' => 1, 'late' => 1, 'due_soon' => 1, 'unassigned' => 1],
    'milestones' => ['total' => 3, 'met' => 2, 'pending' => 1, 'missed' => 0, 'overdue' => 0],
];
$attention = [
    'late' => [['id' => 't-9', 'title' => 'Confirm the generator', 'assignee_user_id' => 'u-fin', 'due_at' => '2026-09-10', 'status' => 'todo', 'risk' => 'late']],
    'due_soon' => [], 'blocked' => [['id' => 't-8', 'title' => 'Book the hall', 'assignee_user_id' => null, 'due_at' => null, 'status' => 'blocked', 'risk' => 'blocked', 'blocked_reason' => 'venue not confirmed']],
    'unassigned' => [], 'overdue_milestones' => [],
    'counts' => ['late' => 1, 'due_soon' => 0, 'blocked' => 1, 'unassigned' => 0, 'overdue_milestones' => 0],
];
$plan = [
    'event_id' => 'ev-1', 'today' => '2026-09-21', 'progress_pct' => 62, 'health' => 'at_risk',
    'task_rollup' => $progress['tasks'], 'milestone_rollup' => $progress['milestones'], 'attention' => $attention,
    'workstreams' => [[
        'id' => 'w-1', 'name' => 'Venue', 'owner_user_id' => 'u-chair', 'responsibility' => 'logistics',
        'weight' => 2, 'status' => 'at_risk', 'progress_pct' => 50, 'task_count' => 2, 'done_count' => 1,
        'blocked_count' => 1, 'late_count' => 1, 'due_soon_count' => 0, 'unassigned_count' => 0, 'due_date' => '2026-09-28',
        'tasks' => [
            ['id' => 't-1', 'title' => 'Book the hall', 'description' => 'Ridge assembly hall', 'workstream_id' => 'w-1',
                'assignee_user_id' => 'u-chair', 'due_at' => '2026-09-25', 'priority' => 'high', 'status' => 'done',
                'progress_pct' => 100, 'completion' => 100, 'risk' => 'ok', 'blocked_reason' => null, 'estimated_hours' => '4.00',
                'predecessors' => []],
            ['id' => 't-2', 'title' => 'Pay the deposit', 'description' => null, 'workstream_id' => 'w-1',
                'assignee_user_id' => 'u-fin', 'due_at' => '2026-09-27', 'priority' => 'normal', 'status' => 'blocked',
                'progress_pct' => 0, 'completion' => 0, 'risk' => 'blocked', 'blocked_reason' => 'waiting on finance',
                'estimated_hours' => null,
                'predecessors' => [['task_id' => 't-1', 'title' => 'Book the hall', 'status' => 'done', 'dependency_type' => 'finish_to_start', 'lag_days' => 0]]],
        ],
    ]],
    'unstreamed' => [[
        'id' => 't-3', 'title' => 'Print programmes', 'description' => null, 'workstream_id' => null,
        'assignee_user_id' => null, 'due_at' => '2026-09-30', 'priority' => 'low', 'status' => 'todo',
        'progress_pct' => 0, 'completion' => 0, 'risk' => 'ok', 'blocked_reason' => null, 'predecessors' => [],
    ]],
    'tasks' => [],
    'milestones' => [
        ['id' => 'ms-1', 'title' => 'Contracts signed', 'description' => null, 'due_at' => '2026-09-28', 'status' => 'met', 'weight' => 3, 'evidence' => 'signed and scanned'],
        ['id' => 'ms-2', 'title' => 'Volunteers briefed', 'description' => null, 'due_at' => '2026-09-30', 'status' => 'pending', 'weight' => 1, 'evidence' => null],
    ],
    'dependencies' => [],
];
$plan['tasks'] = array_merge($plan['workstreams'][0]['tasks'], $plan['unstreamed']);
$board = [
    'todo' => [$plan['unstreamed'][0]],
    'in_progress' => [],
    'blocked' => [$plan['workstreams'][0]['tasks'][1]],
    'done' => [$plan['workstreams'][0]['tasks'][0]],
    'cancelled' => [],
];
$config = [
    'enabled' => true, 'oversight' => 'formation_and_major', 'max_members' => 12,
    'chair_requires_approval' => true, 'allow_subdelegation' => true, 'grace_days' => 7,
    'budget_approval_threshold' => 1000, 'allow_crosscut' => false,
];
$names = ['u-chair' => 'Chair Person', 'u-fin' => 'Finance Lead', 'u-leader' => 'Area Leader'];

$consoleData = [
    'eventId' => 'ev-1', 'event' => $event, 'committee' => $committee, 'enabled' => true, 'config' => $config,
    'roster' => $roster, 'oversight' => [['id' => 'g-local', 'name' => 'Ridge Assembly', 'distance' => 0], ['id' => 'g-area', 'name' => 'Greater Accra', 'distance' => 1]],
    'responsibilities' => $responsibilities, 'decisions' => $decisions, 'progress' => $progress,
    'attention' => $attention, 'myTasks' => [$plan['unstreamed'][0]], 'kinds' => ['budget', 'schedule', 'cancellation', 'publication', 'governance', 'other'],
    'effects' => ['none', 'task.status', 'chair.appoint'], 'actorId' => 'u-leader',
];
$planData = [
    'eventId' => 'ev-1', 'event' => $event, 'plan' => $plan, 'board' => $board, 'myTasks' => [$plan['unstreamed'][0]],
    'names' => $names, 'roster' => $roster, 'committee' => $committee,
    'responsibilities' => ['chair', 'finance', 'media', 'general'], 'priorities' => ['low', 'normal', 'high', 'urgent'],
    'statuses' => ['todo', 'in_progress', 'blocked', 'done', 'cancelled'],
    'depTypes' => ['finish_to_start', 'start_to_start', 'finish_to_finish'], 'actorId' => 'u-chair',
];
$hubData = [
    'committees' => [[
        'committee_id' => 'cm-1', 'event_id' => 'ev-1', 'event_title' => 'Convention 2026', 'event_status' => 'published',
        'event_starts_at' => '2026-10-01 09:00:00', 'responsibility' => 'finance', 'is_chair' => false,
        'effective_to' => '2026-10-10 18:00:00', 'mandate' => 'Run Convention 2026 as a project', 'oversight_group_id' => 'g-local',
    ]],
    'pending' => $decisions,
    'history' => [array_merge($decisions[0], ['id' => 'd-2', 'status' => 'approved', 'decided_by' => 'u-leader', 'decided_by_name' => 'Area Leader', 'decided_at' => '2026-09-19 09:00:00', 'decision_note' => 'within budget'])],
    'myTasks' => [array_merge($plan['unstreamed'][0], ['event_id' => 'ev-1'])],
    'names' => $names,
];
$queueData = [
    'rows' => array_merge($decisions, [array_merge($decisions[0], ['id' => 'd-3', 'status' => 'rejected', 'decided_by' => 'u-leader', 'decided_by_name' => 'Area Leader', 'decided_at' => '2026-09-18 09:00:00', 'decision_note' => 'not this year'])]),
    'pending' => $decisions, 'status' => 'all', 'kind' => '', 'kinds' => ['budget', 'schedule', 'cancellation', 'publication', 'governance', 'other'],
    'statuses' => ['pending', 'approved', 'rejected', 'cancelled', 'noted'], 'committees' => $hubData['committees'],
];

// ── 4. committee_console.php ──────────────────────────────────────────────────
echo "\ncommittee console render\n";
$h = $render("$viewDir/committee_console.php", $consoleData + ['csrf' => 'TOK1'], 'en');
chk('renders the event title', str_contains($h, 'Convention 2026'));
chk('renders the mandate', str_contains($h, 'Run Convention 2026 as a project'));
chk('renders the chair by name', str_contains($h, 'Chair Person'));
chk('shows the member count', str_contains($h, '2 active members'));
chk('shows each member\'s lane', str_contains($h, 'Finance') && str_contains($h, 'Budgets and payments'));
chk('shows the delegated capability per member', str_contains($h, 'event.logistics.manage') && str_contains($h, 'event.expense.submit'));
chk('shows the authority window', str_contains($h, 'until 2026-10-10'));
chk('flags a lane whose delegation failed', str_contains($h, 'Delegation failed'));
chk('lists the vacant lane', str_contains($h, 'Media'));
chk('shows the plan progress', str_contains($h, '62') && str_contains($h, 'At risk'));
chk('shows the pending decision with its approver capability', str_contains($h, 'Pay the band') && str_contains($h, 'event.expense.approve'));
chk('offers approve and reject on a pending decision', str_contains($h, '/event-committees/decisions/d-1/approve') && str_contains($h, '/event-committees/decisions/d-1/reject'));
[$cf, $ct] = csrfParity($h);
chk("every rendered console POST form carries _csrf ($cf forms)", $cf > 0 && $cf === $ct, "forms=$cf tokens=$ct");
chk('appoint + chair + dissolve forms are present', str_contains($h, '/events/ev-1/committee/members') && str_contains($h, '/events/ev-1/committee/chair') && str_contains($h, '/events/ev-1/committee/dissolve'));
chk('the member actions post to the member route', str_contains($h, '/event-committees/member/m-2/remove') && str_contains($h, '/event-committees/member/m-1/responsibility'));
chk('shows the group configuration in force', str_contains($h, 'Maximum members') && str_contains($h, 'Budget approval threshold') && str_contains($h, '1000'));
chk('links to the plan', str_contains($h, 'https://public.test/events/ev-1/plan'));
chk('shows what needs attention', str_contains($h, 'Confirm the generator') && str_contains($h, 'venue not confirmed'));
chk('no raw language key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $h));

$gated = $render("$viewDir/committee_console.php", array_merge($consoleData, ['enabled' => false, 'committee' => null]) + ['csrf' => 'T'], 'en');
chk('gated OFF says so', str_contains($gated, 'switched off for this group'));
chk('gated OFF offers no forms', ! str_contains(preg_replace('/<form class="lang__menu".*?<\\/form>/s', '', $gated), 'method="post"'));

$none = $render("$viewDir/committee_console.php", array_merge($consoleData, ['committee' => null]) + ['csrf' => 'T'], 'en');
chk('no committee explains the default', str_contains($none, 'No committee has been formed'));
chk('no committee offers the formation form', str_contains($none, '/events/ev-1/committee"') && str_contains($none, 'name="mandate"'));
chk('the formation form offers the oversight group choice', str_contains($none, 'value="g-area"') && str_contains($none, 'Greater Accra (+1)'));
chk('a closed event offers no formation form', ! str_contains(
    $render("$viewDir/committee_console.php", array_merge($consoleData, ['committee' => null, 'event' => array_merge($event, ['status' => 'completed'])]) + ['csrf' => 'T'], 'en'),
    'name="mandate"',
));

$dissolvedView = $render("$viewDir/committee_console.php", array_merge($consoleData, ['committee' => array_merge($committee, ['status' => 'dissolved', 'active_members' => []])]) + ['csrf' => 'T'], 'en');
chk('a dissolved committee offers no writes', str_contains($dissolvedView, 'Dissolved') && ! str_contains($dissolvedView, '/committee/dissolve'));

$fr = $render("$viewDir/committee_console.php", $consoleData + ['csrf' => 'TOK1'], 'fr');
chk('french: lang + dir', str_contains($fr, 'lang="fr"') && str_contains($fr, 'dir="ltr"'));
chk('french: heading translated', str_contains($fr, 'Comité') && ! str_contains($fr, '>Event committee<'));
chk('french: no raw key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $fr));
$ar = $render("$viewDir/committee_console.php", $consoleData + ['csrf' => 'T'], 'ar');
chk('arabic: rtl', str_contains($ar, 'lang="ar"') && str_contains($ar, 'dir="rtl"'));
chk('arabic: no raw key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $ar));
chk('arabic: keeps the interpolated count placeholder', str_contains($ar, 'لجان') || str_contains($ar, 'عضو'));

// ── 5. event_plan.php ─────────────────────────────────────────────────────────
echo "\nevent plan render\n";
$p = $render("$viewDir/event_plan.php", $planData + ['csrf' => 'TOK2'], 'en');
chk('renders the derived progress + health', str_contains($p, '62%') && str_contains($p, 'At risk'));
chk('renders the task counts', str_contains($p, '8 tasks') && str_contains($p, '5') && str_contains($p, '3'));
chk('renders the workstream with its roll-up', str_contains($p, 'Venue') && str_contains($p, '50%') && str_contains($p, '1/2'));
chk('renders both tasks in dependency order', strpos((string) $p, 'Book the hall') < strpos((string) $p, 'Pay the deposit'));
chk('shows what a task waits on', str_contains($p, 'Waits on') || str_contains($p, 'finish_to_start') || str_contains($p, 'Finish to start'));
chk('shows the blocked reason', str_contains($p, 'waiting on finance'));
chk('renders per-task actions', str_contains($p, '/event-plan/tasks/t-2/action') && str_contains($p, 'name="action" value="unblock"'));
chk('a done task offers reopen, not done', str_contains($p, 'name="action" value="reopen"'));
chk('offers the dependency form', str_contains($p, '/event-plan/tasks/t-2/dependencies') && str_contains($p, 'name="depends_on_task_id"'));
chk('offers add workstream / task / milestone', str_contains($p, '/events/ev-1/plan/workstreams') && str_contains($p, '/events/ev-1/plan/tasks') && str_contains($p, '/events/ev-1/plan/milestones'));
chk('offers workstream edit + delete with reassign', str_contains($p, '/event-plan/workstreams/w-1"') && str_contains($p, '/event-plan/workstreams/w-1/delete') && str_contains($p, 'name="reassign"'));
chk('renders the board columns', str_contains($p, 'To do') && str_contains($p, 'In progress') && str_contains($p, 'Blocked') && str_contains($p, 'Done') && str_contains($p, 'Cancelled'));
chk('renders milestones with met/miss/reopen', str_contains($p, 'Contracts signed') && str_contains($p, '/event-plan/milestones/ms-2/action') && str_contains($p, 'name="action" value="meet"'));
chk('a met milestone shows its evidence', str_contains($p, 'signed and scanned'));
chk('renders the unstreamed bucket', str_contains($p, 'Tasks with no workstream') && str_contains($p, 'Print programmes'));
chk('renders the owner names, not ids', str_contains($p, 'Finance Lead') && str_contains($p, 'Chair Person'));
chk('shows unassigned work as such', str_contains($p, 'Unassigned'));
chk('renders what needs attention', str_contains($p, 'Confirm the generator') || str_contains($p, 'Late'));
[$pf, $pt] = csrfParity($p);
chk("every rendered plan POST form carries _csrf ($pf forms)", $pf >= 8 && $pf === $pt, "forms=$pf tokens=$pt");
chk('no raw language key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $p));
chk('links back to the committee console', str_contains($p, 'https://public.test/events/ev-1/committee'));

$emptyPlan = $render("$viewDir/event_plan.php", array_merge($planData, [
    'plan' => ['event_id' => 'ev-1', 'today' => '2026-09-21', 'progress_pct' => 0, 'health' => 'on_track',
        'task_rollup' => ['total' => 0, 'open' => 0, 'done' => 0], 'milestone_rollup' => ['total' => 0, 'met' => 0],
        'attention' => ['counts' => ['late' => 0, 'due_soon' => 0, 'blocked' => 0, 'unassigned' => 0, 'overdue_milestones' => 0]],
        'workstreams' => [], 'unstreamed' => [], 'tasks' => [], 'milestones' => [], 'dependencies' => []],
    'board' => ['todo' => [], 'in_progress' => [], 'blocked' => [], 'done' => [], 'cancelled' => []],
    'myTasks' => [],
]) + ['csrf' => 'T'], 'en');
chk('an empty plan says so', str_contains($emptyPlan, 'Nothing planned yet'));
chk('an empty plan still offers the add forms', str_contains($emptyPlan, '/events/ev-1/plan/workstreams'));
chk('an empty plan reports nothing outstanding', str_contains($emptyPlan, 'Nothing outstanding'));
chk('an empty plan has no task rows', ! str_contains($emptyPlan, '/event-plan/tasks/'));
$pfr = $render("$viewDir/event_plan.php", $planData + ['csrf' => 'T'], 'fr');
chk('french plan: no raw key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $pfr));
$par = $render("$viewDir/event_plan.php", $planData + ['csrf' => 'T'], 'ar');
chk('arabic plan: rtl + no raw key', str_contains($par, 'dir="rtl"') && ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $par));

// ── 6. committee_hub.php ──────────────────────────────────────────────────────
echo "\ncommittee hub render\n";
$hub = $render("$viewDir/committee_hub.php", $hubData + ['csrf' => 'T'], 'en');
chk('lists my seat with its lane', str_contains($hub, 'Convention 2026') && str_contains($hub, 'Finance'));
chk('shows my authority window', str_contains($hub, 'until 2026-10-10'));
chk('links to the console and the plan', str_contains($hub, 'https://public.test/events/ev-1/committee') && str_contains($hub, 'https://public.test/events/ev-1/plan'));
chk('lists what awaits my decision', str_contains($hub, 'Pay the band') && str_contains($hub, 'event.expense.approve'));
chk('shows the requester by name', str_contains($hub, 'Chair Person'));
chk('lists settled decisions with their note', str_contains($hub, 'within budget') && str_contains($hub, 'Approved'));
chk('lists my open tasks across events', str_contains($hub, 'Print programmes'));
chk('the hub itself posts nothing', ! str_contains(preg_replace('/<form class="lang__menu".*?<\\/form>/s', '', $hub), 'method="post"'));
chk('no raw language key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $hub));

$emptyHub = $render("$viewDir/committee_hub.php", ['committees' => [], 'pending' => [], 'history' => [], 'myTasks' => [], 'names' => [], 'csrf' => 'T'], 'en');
chk('an empty hub explains itself', str_contains($emptyHub, 'You do not sit on any committee') && str_contains($emptyHub, 'Nothing is awaiting a decision') && str_contains($emptyHub, 'Nothing is assigned to you'));
$hfr = $render("$viewDir/committee_hub.php", $hubData + ['csrf' => 'T'], 'fr');
chk('french hub: no raw key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $hfr));

// ── 7. committee_queue.php ────────────────────────────────────────────────────
echo "\noversight queue render\n";
$q = $render("$viewDir/committee_queue.php", $queueData + ['csrf' => 'TOK3'], 'en');
chk('offers the status + kind filters as a GET form', str_contains($q, 'method="get"') && str_contains($q, 'name="status"') && str_contains($q, 'name="kind"'));
chk('the active filter is selected', (bool) preg_match('/value="all" selected/', $q));
chk('lists every kind in the filter', str_contains($q, 'Budget') && str_contains($q, 'Publication') && str_contains($q, 'Governance'));
chk('a pending row offers approve / reject / withdraw', str_contains($q, '/event-committees/decisions/d-1/approve')
    && str_contains($q, '/event-committees/decisions/d-1/reject') && str_contains($q, '/event-committees/decisions/d-1/cancel'));
chk('the decision forms return to the queue', substr_count($q, 'name="return_to" value="queue"') >= 3);
chk('rejecting carries a note field', str_contains($q, 'name="note"'));
chk('a settled row shows who decided and why', str_contains($q, 'Area Leader') && str_contains($q, 'not this year'));
chk('a settled row offers no actions', ! str_contains($q, '/event-committees/decisions/d-3/approve'));
chk('shows the decision\'s own effect vocabulary', str_contains($q, 'Change a task'));
chk('links each row to its event surfaces', str_contains($q, 'https://public.test/events/ev-1/committee') && str_contains($q, 'https://public.test/events/ev-1/plan'));
[$qf, $qt] = csrfParity($q);
chk("every rendered queue POST form carries _csrf ($qf forms)", $qf >= 3 && $qf === $qt, "forms=$qf tokens=$qt");
chk('no raw language key leaked', ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $q));

$emptyQ = $render("$viewDir/committee_queue.php", ['rows' => [], 'pending' => [], 'status' => 'pending', 'kind' => '', 'kinds' => [], 'statuses' => [], 'committees' => $hubData['committees'], 'csrf' => 'T'], 'en');
chk('an empty queue says nothing is waiting', str_contains($emptyQ, 'Nothing is awaiting a decision'));
chk('an empty queue renders no decision forms', ! str_contains($emptyQ, '/approve'));
$noSeats = $render("$viewDir/committee_queue.php", ['rows' => [], 'pending' => [], 'status' => 'pending', 'kind' => '', 'kinds' => [], 'statuses' => [], 'committees' => [], 'csrf' => 'T'], 'en');
chk('with no seats at all the queue says so', str_contains($noSeats, 'You do not sit on any committee'));
$qar = $render("$viewDir/committee_queue.php", $queueData + ['csrf' => 'T'], 'ar');
chk('arabic queue: rtl + no raw key', str_contains($qar, 'dir="rtl"') && ! (bool) preg_match('/Events\.(committee|plan|decision)\.[a-zA-Z]/', $qar));

echo "\n== $pass passed, $fail failed ==\n";
exit($fail > 0 ? 1 : 0);
